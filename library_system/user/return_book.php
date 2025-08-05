<?php
session_start();
header('Content-Type: application/json');

// Database connection
$host = 'localhost';
$dbname = 'library_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'การเชื่อมต่อฐานข้อมูลล้มเหลว: ' . $e->getMessage()]);
    exit();
}

// Check if user is logged in (admin or user)
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อน']);
    exit();
}

// Get input data
$input_data = null;
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($content_type, 'application/json') !== false) {
    // JSON input
    $input_data = json_decode(file_get_contents('php://input'), true);
} else {
    // Form input
    $input_data = $_POST;
}

if (!isset($input_data['borrow_id']) || !is_numeric($input_data['borrow_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ข้อมูลการยืมไม่ถูกต้อง']);
    exit();
}

$borrow_id = (int)$input_data['borrow_id'];
$return_notes = $input_data['notes'] ?? 'คืนผ่านระบบออนไลน์';
$book_condition = $input_data['condition'] ?? 'good'; // good, damaged, lost

try {
    $pdo->beginTransaction();

    // Get borrowing information
    $stmt = $pdo->prepare("
        SELECT br.*, b.title, b.book_id, u.first_name, u.last_name, u.user_id
        FROM borrowing br
        JOIN books b ON br.book_id = b.book_id
        JOIN users u ON br.user_id = u.user_id
        WHERE br.borrow_id = ?
    ");
    $stmt->execute([$borrow_id]);
    $borrowing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$borrowing) {
        throw new Exception('ไม่พบข้อมูลการยืมหนังสือ');
    }

    if ($borrowing['status'] === 'returned') {
        throw new Exception('หนังสือเล่มนี้ได้รับการคืนแล้ว');
    }

    if ($borrowing['status'] === 'lost') {
        throw new Exception('หนังสือเล่มนี้ถูกรายงานว่าสูญหาย');
    }

    // Check permission (user can only return their own books, admin can return any)
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $borrowing['user_id']) {
        throw new Exception('คุณไม่มีสิทธิ์คืนหนังสือเล่มนี้');
    }

    $return_date = date('Y-m-d');
    $due_date = $borrowing['due_date'];
    $admin_id = $_SESSION['admin_id'] ?? null;

    // Calculate fine if overdue (แต่ไม่บังคับชำระ)
    $fine_amount = 0;
    $days_overdue = 0;
    
    if ($return_date > $due_date) {
        $days_overdue = ceil((strtotime($return_date) - strtotime($due_date)) / (60 * 60 * 24));
        
        // Get fine rate from settings
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'fine_per_day'");
            $stmt->execute();
            $fine_per_day = (float)($stmt->fetchColumn() ?? 5.00);
        } catch (PDOException $e) {
            $fine_per_day = 5.00; // Default fine rate
        }
        
        $fine_amount = $days_overdue * $fine_per_day;
    }

    // Handle damaged or lost books (ไม่ต้องบังคับค่าปรับ)
    if ($book_condition === 'damaged') {
        $fine_amount += 50.00; // Additional fine for damage (ไว้แต่ไม่บังคับ)
    } elseif ($book_condition === 'lost') {
        $fine_amount += 200.00; // Fine for lost book (ไว้แต่ไม่บังคับ)
        
        // Update book status and total copies
        $stmt = $pdo->prepare("
            UPDATE books 
            SET total_copies = GREATEST(total_copies - 1, 0),
                available_copies = GREATEST(available_copies - 1, 0)
            WHERE book_id = ?
        ");
        $stmt->execute([$borrowing['book_id']]);
        
        // Update borrowing status to lost
        $stmt = $pdo->prepare("
            UPDATE borrowing 
            SET status = 'lost', 
                return_date = ?, 
                notes = CONCAT(COALESCE(notes, ''), ' - หนังสือสูญหาย: ', ?)
            WHERE borrow_id = ?
        ");
        $stmt->execute([$return_date, $return_notes, $borrow_id]);
    } else {
        // Normal return - update borrowing status
        $stmt = $pdo->prepare("
            UPDATE borrowing 
            SET status = 'returned', 
                return_date = ?, 
                notes = CONCAT(COALESCE(notes, ''), ' - คืนหนังสือ: ', ?)
            WHERE borrow_id = ?
        ");
        $stmt->execute([$return_date, $return_notes, $borrow_id]);

        // Update book available copies (only if not lost)
        $stmt = $pdo->prepare("UPDATE books SET available_copies = available_copies + 1 WHERE book_id = ?");
        $stmt->execute([$borrowing['book_id']]);
    }

    // Create fine record if applicable (if fines table exists) แต่ไม่บังคับชำระ
    if ($fine_amount > 0) {
        try {
            $fine_type = ($book_condition === 'lost') ? 'lost' : 
                        (($book_condition === 'damaged') ? 'damage' : 'overdue');
            
            $fine_description = '';
            switch($fine_type) {
                case 'overdue':
                    $fine_description = "ค่าปรับเกินกำหนด {$days_overdue} วัน";
                    break;
                case 'damage':
                    $fine_description = "ค่าปรับหนังสือชำรุด";
                    break;
                case 'lost':
                    $fine_description = "ค่าปรับหนังสือสูญหาย";
                    break;
            }

            // สร้างบันทึกค่าปรับแต่ไม่บังคับชำระ
            $stmt = $pdo->prepare("
                INSERT INTO fines (user_id, borrow_id, fine_type, amount, days_overdue, description, fine_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'waived')
            ");
            $stmt->execute([$borrowing['user_id'], $borrow_id, $fine_type, $fine_amount, $days_overdue, $fine_description, $return_date]);
        } catch (PDOException $e) {
            // If fines table doesn't exist, log warning but continue
            error_log("Fines table not found, skipping fine creation: " . $e->getMessage());
        }
    }

    // Log activity (if activity_logs table exists)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, admin_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
            VALUES (?, ?, 'return_book', 'borrowing', ?, ?, ?, ?, NOW())
        ");
        
        $new_values = json_encode([
            'book_id' => $borrowing['book_id'],
            'book_title' => $borrowing['title'],
            'return_date' => $return_date,
            'condition' => $book_condition,
            'fine_amount' => $fine_amount,
            'days_overdue' => $days_overdue
        ]);
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt->execute([$borrowing['user_id'], $admin_id, $new_values, $ip_address, $user_agent]);
    } catch (PDOException $e) {
        // If activity_logs table doesn't exist, continue without logging
        error_log("Activity logs table not found, skipping activity log: " . $e->getMessage());
    }

    // Create notification for user (if notifications table exists)
    try {
        $notification_title = ($book_condition === 'lost') ? 'รายงานหนังสือสูญหาย' : 'คืนหนังสือสำเร็จ';
        $notification_message = "หนังสือ '{$borrowing['title']}' ได้รับการ" . 
                              (($book_condition === 'lost') ? 'รายงานว่าสูญหาย' : 'คืนเรียบร้อยแล้ว');
        
        if ($fine_amount > 0) {
            $notification_message .= " (ค่าปรับ " . number_format($fine_amount, 2) . " บาท - ได้รับการยกเว้น)";
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, sent_date, status)
            VALUES (?, ?, ?, ?, NOW(), 'unread')
        ");
        
        $notification_type = 'return_success';
        $stmt->execute([$borrowing['user_id'], $notification_type, $notification_title, $notification_message]);
    } catch (PDOException $e) {
        // If notifications table doesn't exist, continue without notification
        error_log("Notifications table not found, skipping notification: " . $e->getMessage());
    }

    $pdo->commit();

    // Return success response
    $message = ($book_condition === 'lost') ? 'รายงานหนังสือสูญหายเรียบร้อย' : 'คืนหนังสือสำเร็จ!';
    if ($fine_amount > 0) {
        $message .= ' (ค่าปรับ ' . number_format($fine_amount, 2) . ' บาท - ได้รับการยกเว้น)';
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'borrow_id' => $borrow_id,
            'book_id' => $borrowing['book_id'],
            'book_title' => $borrowing['title'],
            'user_name' => $borrowing['first_name'] . ' ' . $borrowing['last_name'],
            'borrow_date' => date('d/m/Y', strtotime($borrowing['borrow_date'])),
            'due_date' => date('d/m/Y', strtotime($due_date)),
            'return_date' => date('d/m/Y', strtotime($return_date)),
            'days_overdue' => $days_overdue,
            'fine_amount' => $fine_amount,
            'condition' => $book_condition,
            'fine_waived' => true
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollback();
    
    // Log error
    error_log("Return book error for borrow_id {$borrow_id}: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => [
            'borrow_id' => $borrow_id,
            'error_file' => __FILE__,
            'error_line' => __LINE__
        ]
    ]);
} catch (PDOException $e) {
    $pdo->rollback();
    
    error_log("Database error in return_book.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในฐานข้อมูล กรุณาลองใหม่อีกครั้ง'
    ]);
}
?>