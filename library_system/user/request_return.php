<?php
session_start();
header('Content-Type: application/json');

// เพิ่ม error reporting สำหรับ debug
error_reporting(E_ALL);
ini_set('display_errors', 0); // ปิดการแสดงผลใน browser
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
    error_log("Database connection failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้']);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit();
}

// Get and validate JSON input
$json_input = file_get_contents('php://input');
$input = json_decode($json_input, true);

// ตรวจสอบ JSON parsing
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error: " . json_last_error_msg());
    echo json_encode(['success' => false, 'message' => 'ข้อมูลที่ส่งมาไม่ถูกต้อง']);
    exit();
}

$borrow_id = isset($input['borrow_id']) ? (int)$input['borrow_id'] : 0;
$user_id = $_SESSION['user_id'];

// Log ข้อมูลที่ได้รับ
error_log("Return request - User ID: $user_id, Borrow ID: $borrow_id");

if ($borrow_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลการยืมไม่ถูกต้อง']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Check if borrowing record exists and belongs to user
    $stmt = $pdo->prepare("
        SELECT b.*, bk.title, bk.book_id 
        FROM borrowing b 
        JOIN books bk ON b.book_id = bk.book_id 
        WHERE b.borrow_id = ? AND b.user_id = ?
    ");
    $stmt->execute([$borrow_id, $user_id]);
    $borrow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$borrow) {
        throw new Exception('ไม่พบข้อมูลการยืมหรือไม่ใช่การยืมของคุณ');
    }

    // Log สถานะปัจจุบัน
    error_log("Current borrow status: " . $borrow['status']);

    // Check if already requested return or returned
    if ($borrow['status'] === 'pending_return') {
        throw new Exception('คุณได้แจ้งคืนหนังสือเล่มนี้แล้ว');
    }

    if ($borrow['status'] === 'returned') {
        throw new Exception('หนังสือเล่มนี้คืนแล้ว');
    }

    if ($borrow['status'] !== 'borrowed' && $borrow['status'] !== 'overdue') {
        throw new Exception('สถานะการยืมไม่ถูกต้อง (สถานะปัจจุบัน: ' . $borrow['status'] . ')');
    }

    // Update borrowing status to pending_return
    $stmt = $pdo->prepare("
        UPDATE borrowing 
        SET status = 'pending_return', 
            notes = CONCAT(COALESCE(notes, ''), '\nแจ้งคืนเมื่อ: ', NOW(), ' (รอแอดมินยืนยัน)'),
            updated_at = NOW()
        WHERE borrow_id = ?
    ");
    $result = $stmt->execute([$borrow_id]);
    
    if (!$result) {
        throw new Exception('ไม่สามารถอัพเดทสถานะการยืมได้');
    }

    // Log activity
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
        VALUES (?, 'request_return', 'borrowing', ?, ?, ?, ?, ?)
    ");
    
    $old_values = json_encode(['status' => $borrow['status']]);
    $new_values = json_encode([
        'status' => 'pending_return',
        'request_date' => date('Y-m-d H:i:s'),
        'book_title' => $borrow['title']
    ]);
    
    $stmt->execute([
        $user_id,
        $borrow_id,
        $old_values,
        $new_values,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);

    // Create notification for user
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, sent_date) 
        VALUES (?, 'return_request', 'คำขอคืนหนังสือ', ?, NOW())
    ");
    
    $notification_message = "คุณได้แจ้งคืนหนังสือ \"{$borrow['title']}\" เรียบร้อยแล้ว กรุณานำหนังสือไปคืนที่เคาน์เตอร์ห้องสมุด รอแอดมินยืนยันการคืน";
    $stmt->execute([$user_id, $notification_message]);

    // Create notification for admin (ตรวจสอบว่ามี admin หรือไม่)
    $admin_check = $pdo->query("SELECT COUNT(*) FROM admins WHERE status = 'active' AND role IN ('super_admin', 'librarian')");
    $admin_count = $admin_check->fetchColumn();
    
    if ($admin_count > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (admin_id, type, title, message, sent_date) 
            SELECT admin_id, 'return_pending', 'มีคำขอคืนหนังสือใหม่', ?, NOW()
            FROM admins 
            WHERE status = 'active' AND role IN ('super_admin', 'librarian')
        ");
        
        $admin_message = "ผู้ใช้รหัส " . ($_SESSION['student_id'] ?? 'Unknown') . " แจ้งคืนหนังสือ \"{$borrow['title']}\" กรุณาตรวจสอบและยืนยันการคืน";
        $stmt->execute([$admin_message]);
    }

    $pdo->commit();

    // Log สำเร็จ
    error_log("Return request successful - Borrow ID: $borrow_id");

    echo json_encode([
        'success' => true, 
        'message' => 'แจ้งคืนหนังสือสำเร็จ',
        'data' => [
            'borrow_id' => $borrow_id,
            'book_title' => $borrow['title'],
            'request_date' => date('Y-m-d H:i:s'),
            'status' => 'pending_return'
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Return request failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Database error in return request: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในฐานข้อมูล']);
}
?>