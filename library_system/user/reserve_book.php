<?php
session_start();
header('Content-Type: application/json');

// *** ตั้งค่า timezone เป็นประเทศไทย ***
date_default_timezone_set('Asia/Bangkok');

// Database connection
$host = 'localhost';
$dbname = 'library_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // *** ตั้งค่า timezone ใน MySQL เป็นเวลาไทย ***
    $pdo->exec("SET time_zone = '+07:00'");
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$book_id = isset($input['book_id']) ? (int)$input['book_id'] : 0;
$user_id = $_SESSION['user_id'];

if ($book_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลหนังสือไม่ถูกต้อง']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Get system settings
    $stmt = $pdo->prepare("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE setting_key IN ('max_borrow_books', 'max_reservations')
    ");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $max_borrow_books = (int)($settings['max_borrow_books'] ?? 5);
    $max_reservations = (int)($settings['max_reservations'] ?? 3);

    // Check if user has unpaid fines
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fines WHERE user_id = ? AND status = 'unpaid'");
    $stmt->execute([$user_id]);
    $unpaid_fines = $stmt->fetchColumn();

    if ($unpaid_fines > 0) {
        throw new Exception('คุณมีค่าปรับที่ยังไม่ได้ชำระ');
    }

    // Check user's current borrowed books count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM borrowing 
        WHERE user_id = ? AND status IN ('borrowed', 'overdue', 'pending_return')
    ");
    $stmt->execute([$user_id]);
    $user_borrowed_count = $stmt->fetchColumn();

    if ($user_borrowed_count >= $max_borrow_books) {
        throw new Exception('คุณยืมหนังสือครบจำนวนสูงสุดแล้ว');
    }

    // Check user's current reservations count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reservations 
        WHERE user_id = ? AND status = 'active'
    ");
    $stmt->execute([$user_id]);
    $user_reservations_count = $stmt->fetchColumn();

    if ($user_reservations_count >= $max_reservations) {
        throw new Exception('คุณจองหนังสือครบจำนวนสูงสุดแล้ว');
    }

    // Check if user already borrowed this book
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM borrowing 
        WHERE user_id = ? AND book_id = ? AND status IN ('borrowed', 'overdue', 'pending_return')
    ");
    $stmt->execute([$user_id, $book_id]);
    $already_borrowed = $stmt->fetchColumn();

    if ($already_borrowed > 0) {
        throw new Exception('คุณยืมหนังสือเล่มนี้อยู่แล้ว');
    }

    // Check if user already reserved this book
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reservations 
        WHERE user_id = ? AND book_id = ? AND status = 'active'
    ");
    $stmt->execute([$user_id, $book_id]);
    $already_reserved = $stmt->fetchColumn();

    if ($already_reserved > 0) {
        throw new Exception('คุณจองหนังสือเล่มนี้อยู่แล้ว');
    }

    // Check if book exists and is available
    $stmt = $pdo->prepare("SELECT * FROM books WHERE book_id = ?");
    $stmt->execute([$book_id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$book) {
        throw new Exception('ไม่พบหนังสือที่ต้องการจอง');
    }

    if ($book['status'] !== 'available') {
        throw new Exception('หนังสือไม่พร้อมให้จองในขณะนี้');
    }

    if ($book['available_copies'] <= 0) {
        throw new Exception('หนังสือหมดแล้ว');
    }

    // Check if book is already reserved by someone else
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reservations 
        WHERE book_id = ? AND status = 'active'
    ");
    $stmt->execute([$book_id]);
    $book_reserved_count = $stmt->fetchColumn();

    if ($book_reserved_count > 0) {
        throw new Exception('หนังสือเล่มนี้ถูกจองแล้ว');
    }

    // Calculate expiry date (1 day from now) - ใช้เวลาไทยแล้ว
    $reservation_date = date('Y-m-d H:i:s');
    $expiry_date = date('Y-m-d H:i:s', strtotime('+1 day'));

    // Insert reservation
    $stmt = $pdo->prepare("
        INSERT INTO reservations (user_id, book_id, reservation_date, expiry_date, status, notes) 
        VALUES (?, ?, ?, ?, 'active', 'จองผ่านระบบออนไลน์')
    ");
    $stmt->execute([$user_id, $book_id, $reservation_date, $expiry_date]);
    $reservation_id = $pdo->lastInsertId();

    // อัพเดตสถานะหนังสือเป็น reserved
    $stmt = $pdo->prepare("UPDATE books SET status = 'reserved' WHERE book_id = ?");
    $stmt->execute([$book_id]);

    // Log activity
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent) 
        VALUES (?, 'reserve_book', 'reservations', ?, ?, ?, ?)
    ");
    
    $log_data = json_encode([
        'book_id' => $book_id,
        'book_title' => $book['title'],
        'reservation_date' => $reservation_date,
        'expiry_date' => $expiry_date,
        'book_status_changed' => 'available -> reserved'
    ]);
    
    $stmt->execute([
        $user_id,
        $reservation_id,
        $log_data,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);

    // Create notification for user - ใช้เวลาไทยแล้ว
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, sent_date) 
        VALUES (?, 'reservation_pending', 'การจองรอดำเนินการ', ?, ?)
    ");
    
    $notification_message = "คุณได้จองหนังสือ \"{$book['title']}\" เรียบร้อยแล้ว รอแอดมินอนุมัติ กำหนดหมดอายุ: " . date('d/m/Y H:i', strtotime($expiry_date));
    $stmt->execute([$user_id, $notification_message, $reservation_date]);

    // ส่งข้อมูลหนังสือที่อัพเดตแล้วกลับไป
    $stmt = $pdo->prepare("
        SELECT b.*, 
               (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.book_id AND r.status = 'active') as reserved_count,
               (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.book_id AND r.user_id = ? AND r.status = 'active') as user_reserved
        FROM books b 
        WHERE b.book_id = ?
    ");
    $stmt->execute([$user_id, $book_id]);
    $updated_book = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'จองหนังสือสำเร็จ',
        'data' => [
            'reservation_id' => $reservation_id,
            'reservation_date' => $reservation_date,
            'expiry_date' => $expiry_date,
            'book_title' => $book['title'],
            // ข้อมูลหนังสือที่อัพเดตแล้วสำหรับอัพเดต UI
            'available_copies' => $updated_book['available_copies'],
            'total_copies' => $updated_book['total_copies'],
            'status' => $updated_book['status'],
            'reserved_count' => $updated_book['reserved_count'],
            'user_reserved' => $updated_book['user_reserved'] > 0
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>