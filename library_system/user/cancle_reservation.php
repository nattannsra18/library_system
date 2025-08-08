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
$reservation_id = isset($input['reservation_id']) ? (int)$input['reservation_id'] : 0;
$user_id = $_SESSION['user_id'];

if ($reservation_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลการจองไม่ถูกต้อง']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Check if reservation exists and belongs to user
    $stmt = $pdo->prepare("
        SELECT r.*, b.title, b.book_id 
        FROM reservations r 
        JOIN books b ON r.book_id = b.book_id 
        WHERE r.reservation_id = ? AND r.user_id = ?
    ");
    $stmt->execute([$reservation_id, $user_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        throw new Exception('ไม่พบข้อมูลการจองหรือไม่ใช่การจองของคุณ');
    }

    // Check if reservation is still active
    if ($reservation['status'] !== 'active') {
        throw new Exception('การจองนี้ไม่สามารถยกเลิกได้แล้ว');
    }

    // Update reservation status to cancelled
    $stmt = $pdo->prepare("
        UPDATE reservations 
        SET status = 'cancelled', 
            notes = CONCAT(COALESCE(notes, ''), '\nยกเลิกโดยผู้ใช้เมื่อ: ', NOW()),
            updated_at = NOW()
        WHERE reservation_id = ?
    ");
    $stmt->execute([$reservation_id]);

    // Update book status back to available
    $stmt = $pdo->prepare("
        UPDATE books 
        SET status = 'available' 
        WHERE book_id = ? AND status = 'reserved'
    ");
    $stmt->execute([$reservation['book_id']]);

    // Log activity
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
        VALUES (?, 'cancel_reservation', 'reservations', ?, ?, ?, ?, ?)
    ");
    
    $old_values = json_encode(['status' => 'active']);
    $new_values = json_encode([
        'status' => 'cancelled',
        'cancelled_date' => date('Y-m-d H:i:s'),
        'book_title' => $reservation['title']
    ]);
    
    $stmt->execute([
        $user_id,
        $reservation_id,
        $old_values,
        $new_values,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);

    // Create notification for user
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, sent_date) 
        VALUES (?, 'reservation_cancelled', 'ยกเลิกการจอง', ?, NOW())
    ");
    
    $notification_message = "คุณได้ยกเลิกการจองหนังสือ \"{$reservation['title']}\" เรียบร้อยแล้ว";
    $stmt->execute([$user_id, $notification_message]);

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'ยกเลิกการจองเรียบร้อยแล้ว',
        'data' => [
            'reservation_id' => $reservation_id,
            'book_title' => $reservation['title'],
            'cancelled_date' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>