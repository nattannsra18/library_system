<?php
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in response
ini_set('log_errors', 1);

// Database connection
$host = 'localhost';
$dbname = 'library_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit();
}

// Get JSON input with better error handling
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);

// Log for debugging
error_log("Raw input: " . $raw_input);
error_log("Decoded input: " . print_r($input, true));

// Validate input
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data: ' . json_last_error_msg()]);
    exit();
}

if (!$input || !isset($input['reservation_id'])) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลการจองที่ต้องการยกเลิก']);
    exit();
}

$reservation_id = (int)$input['reservation_id'];
$user_id = $_SESSION['user_id'];

if ($reservation_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'รหัสการจองไม่ถูกต้อง: ' . $reservation_id]);
    exit();
}

try {
    $pdo->beginTransaction();

    // Check if reservation exists and belongs to user
    $stmt = $pdo->prepare("
        SELECT r.*, b.title, b.book_id, b.status as book_status, b.available_copies, b.total_copies 
        FROM reservations r 
        JOIN books b ON r.book_id = b.book_id 
        WHERE r.reservation_id = ? AND r.user_id = ?
    ");
    $stmt->execute([$reservation_id, $user_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลการจองหรือไม่ใช่การจองของคุณ']);
        exit();
    }

    // Check if reservation is still active
    if ($reservation['status'] !== 'active') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'การจองนี้ไม่สามารถยกเลิกได้แล้ว สถานะปัจจุบัน: ' . $reservation['status']]);
        exit();
    }

    // Update reservation status to cancelled
    $stmt = $pdo->prepare("
        UPDATE reservations 
        SET status = 'cancelled', 
            notes = CONCAT(COALESCE(notes, ''), '\nยกเลิกโดยผู้ใช้เมื่อ: ', NOW()),
            updated_at = NOW()
        WHERE reservation_id = ?
    ");
    $result = $stmt->execute([$reservation_id]);
    
    if (!$result) {
        throw new Exception('ไม่สามารถอัปเดตสถานะการจองได้');
    }

    // Update book status logic - SIMPLIFIED AND FIXED
    $book_id = $reservation['book_id'];
    
    // Check if there are any remaining active reservations for this book
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as active_reservations
        FROM reservations 
        WHERE book_id = ? AND status = 'active'
    ");
    $stmt->execute([$book_id]);
    $active_reservations = (int)$stmt->fetchColumn();

    // Check current borrowing status
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as current_borrows
        FROM borrowing 
        WHERE book_id = ? AND status IN ('borrowed', 'overdue', 'pending_return')
    ");
    $stmt->execute([$book_id]);
    $current_borrows = (int)$stmt->fetchColumn();

    // Calculate truly available copies (total - currently borrowed)
    $truly_available = $reservation['total_copies'] - $current_borrows;

    // Update book status based on the situation
    $new_book_status = 'available'; // Default to available

    if ($truly_available <= 0) {
        // No copies available at all - unavailable
        $new_book_status = 'unavailable';
    } elseif ($active_reservations > 0) {
        // There are still active reservations - reserved
        $new_book_status = 'reserved';
    } else {
        // No active reservations and copies available - available
        $new_book_status = 'available';
    }

    // Update book status
    $stmt = $pdo->prepare("
        UPDATE books 
        SET status = ?,
            available_copies = ?
        WHERE book_id = ?
    ");
    $stmt->execute([$new_book_status, $truly_available, $book_id]);
    
    // Log the book status update
    error_log("Book ID $book_id status updated to '$new_book_status' after reservation cancellation. Available copies: $truly_available, Active reservations: $active_reservations, Current borrows: $current_borrows");

    // Log activity
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
            VALUES (?, 'cancel_reservation', 'reservations', ?, ?, ?, ?, ?)
        ");
        
        $old_values = json_encode(['status' => 'active']);
        $new_values = json_encode([
            'status' => 'cancelled',
            'cancelled_date' => date('Y-m-d H:i:s'),
            'book_title' => $reservation['title'],
            'book_status_updated_to' => $new_book_status
        ]);
        
        $stmt->execute([
            $user_id,
            $reservation_id,
            $old_values,
            $new_values,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        // Log error but don't fail the transaction
        error_log("Failed to log activity: " . $e->getMessage());
    }

    // Create notification for user
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, sent_date) 
            VALUES (?, 'reservation_cancelled', 'ยกเลิกการจอง', ?, NOW())
        ");
        
        $notification_message = "คุณได้ยกเลิกการจองหนังสือ \"{$reservation['title']}\" เรียบร้อยแล้ว";
        $stmt->execute([$user_id, $notification_message]);
    } catch (Exception $e) {
        // Log error but don't fail the transaction
        error_log("Failed to create notification: " . $e->getMessage());
    }

    // If there are other people waiting and the book becomes available, notify the next person
    if ($new_book_status === 'available' && $active_reservations > 0) {
        try {
            // Find the next person in queue
            $stmt = $pdo->prepare("
                SELECT r.user_id, r.reservation_id, u.first_name, u.last_name
                FROM reservations r
                JOIN users u ON r.user_id = u.user_id
                WHERE r.book_id = ? AND r.status = 'active'
                ORDER BY r.reservation_date ASC, r.priority_number ASC
                LIMIT 1
            ");
            $stmt->execute([$book_id]);
            $next_reservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($next_reservation) {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, sent_date) 
                    VALUES (?, 'reservation_ready', 'หนังสือพร้อมให้ยืม', ?, NOW())
                ");
                
                $ready_message = "หนังสือ \"{$reservation['title']}\" ที่คุณจองไว้พร้อมให้ยืมแล้ว กรุณามารับภายใน 24 ชั่วโมง";
                $stmt->execute([$next_reservation['user_id'], $ready_message]);
            }
        } catch (Exception $e) {
            error_log("Failed to notify next person in queue: " . $e->getMessage());
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'ยกเลิกการจองเรียบร้อยแล้ว',
        'data' => [
            'reservation_id' => $reservation_id,
            'book_title' => $reservation['title'],
            'book_id' => $book_id,
            'cancelled_date' => date('Y-m-d H:i:s'),
            'book_status' => $new_book_status,
            'active_reservations' => $active_reservations,
            'available_copies' => $truly_available,
            'current_borrows' => $current_borrows
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Cancel reservation error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
?>