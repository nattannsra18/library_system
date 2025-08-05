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

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อน']);
    exit();
}

$user_id = $_SESSION['user_id'];

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

if (!isset($input_data['book_id']) || !is_numeric($input_data['book_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ข้อมูลหนังสือไม่ถูกต้อง']);
    exit();
}

$book_id = (int)$input_data['book_id'];

try {
    $pdo->beginTransaction();

    // Get system settings with default values
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('max_borrow_books', 'max_borrow_days', 'fine_per_day')");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $max_borrow_books = (int)($settings['max_borrow_books'] ?? 5);
    $max_borrow_days = (int)($settings['max_borrow_days'] ?? 14);
    $fine_per_day = (float)($settings['fine_per_day'] ?? 5.00);

    // Check if user exists and is active
    $stmt = $pdo->prepare("SELECT status, first_name, last_name FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('ไม่พบข้อมูลผู้ใช้');
    }

    if ($user['status'] !== 'active') {
        throw new Exception('บัญชีของคุณไม่ได้รับการเปิดใช้งาน');
    }

    // Check if book exists and is available
    $stmt = $pdo->prepare("SELECT title, available_copies, total_copies, status FROM books WHERE book_id = ?");
    $stmt->execute([$book_id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$book) {
        throw new Exception('ไม่พบหนังสือที่ต้องการยืม');
    }

    if ($book['status'] !== 'available') {
        throw new Exception('หนังสือเล่มนี้ไม่พร้อมให้บริการในขณะนี้');
    }

    if ($book['available_copies'] <= 0) {
        throw new Exception('หนังสือเล่มนี้ถูกยืมหมดแล้ว');
    }

    // Check if user already borrowed this book and hasn't returned it
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND book_id = ? AND status IN ('borrowed', 'overdue')");
    $stmt->execute([$user_id, $book_id]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('คุณได้ยืมหนังสือเล่มนี้อยู่แล้ว');
    }

    // Check current borrowed books count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND status IN ('borrowed', 'overdue')");
    $stmt->execute([$user_id]);
    $current_borrowed = $stmt->fetchColumn();

    if ($current_borrowed >= $max_borrow_books) {
        throw new Exception("คุณยืมหนังสือถึงจำนวนสูงสุดแล้ว ({$max_borrow_books} เล่ม)");
    }

    // Check for unpaid fines (if fines table exists) - แต่ไม่บล็อกการยืม
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM fines WHERE user_id = ? AND status = 'unpaid'");
        $stmt->execute([$user_id]);
        $unpaid_fines = $stmt->fetchColumn();
        
        // เพิ่ม warning แต่ไม่หยุดการยืม
        if ($unpaid_fines > 0) {
            error_log("User {$user_id} has unpaid fines but borrowing is allowed");
        }
    } catch (PDOException $e) {
        // If fines table doesn't exist, continue without fine check
        error_log("Fines table not found, skipping fine check: " . $e->getMessage());
    }

    // Calculate due date
    $borrow_date = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime("+{$max_borrow_days} days"));

    // Insert borrowing record
    $stmt = $pdo->prepare("
        INSERT INTO borrowing (user_id, book_id, borrow_date, due_date, status, notes, created_at) 
        VALUES (?, ?, ?, ?, 'borrowed', 'ยืมผ่านระบบออนไลน์', NOW())
    ");
    $stmt->execute([$user_id, $book_id, $borrow_date, $due_date]);
    $borrow_id = $pdo->lastInsertId();

    if (!$borrow_id) {
        throw new Exception('ไม่สามารถบันทึกข้อมูลการยืมได้');
    }

    // Update book available copies
    $stmt = $pdo->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE book_id = ? AND available_copies > 0");
    $stmt->execute([$book_id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('ไม่สามารถอัปเดตจำนวนหนังสือที่พร้อมให้บริการได้');
    }

    // Log activity (if activity_logs table exists)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
            VALUES (?, 'borrow_book', 'borrowing', ?, ?, ?, ?, NOW())
        ");
        
        $new_values = json_encode([
            'book_id' => $book_id,
            'book_title' => $book['title'],
            'borrow_date' => $borrow_date,
            'due_date' => $due_date
        ]);
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt->execute([$user_id, $borrow_id, $new_values, $ip_address, $user_agent]);
    } catch (PDOException $e) {
        // If activity_logs table doesn't exist, continue without logging
        error_log("Activity logs table not found, skipping activity log: " . $e->getMessage());
    }

    // Create notification for user (if notifications table exists)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, sent_date, status)
            VALUES (?, 'borrow_success', 'ยืมหนังสือสำเร็จ', ?, NOW(), 'unread')
        ");
        
        $message = "คุณได้ยืมหนังสือ '{$book['title']}' เรียบร้อยแล้ว กรุณาคืนภายในวันที่ " . 
                   date('d/m/Y', strtotime($due_date));
        
        $stmt->execute([$user_id, $message]);
    } catch (PDOException $e) {
        // If notifications table doesn't exist, continue without notification
        error_log("Notifications table not found, skipping notification: " . $e->getMessage());
    }

    $pdo->commit();

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'ยืมหนังสือสำเร็จ! กรุณาคืนภายในกำหนด',
        'data' => [
            'borrow_id' => $borrow_id,
            'book_id' => $book_id,
            'book_title' => $book['title'],
            'borrower_name' => $user['first_name'] . ' ' . $user['last_name'],
            'borrow_date' => date('d/m/Y', strtotime($borrow_date)),
            'due_date' => date('d/m/Y', strtotime($due_date)),
            'days_allowed' => $max_borrow_days,
            'remaining_copies' => $book['available_copies'] - 1
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollback();
    
    // Log error
    error_log("Borrow book error for user {$user_id}, book {$book_id}: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => [
            'user_id' => $user_id,
            'book_id' => $book_id,
            'error_file' => __FILE__,
            'error_line' => __LINE__
        ]
    ]);
} catch (PDOException $e) {
    $pdo->rollback();
    
    error_log("Database error in borrow_book.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในฐานข้อมูล กรุณาลองใหม่อีกครั้ง'
    ]);
}
?>