<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Database connection
$host = 'localhost';
$dbname = 'library_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล'
    ]);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'กรุณาเข้าสู่ระบบ'
    ]);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    // Get user's current borrowed books count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM borrowing 
        WHERE user_id = ? AND status IN ('borrowed', 'overdue')
    ");
    $stmt->execute([$user_id]);
    $borrowed_count = $stmt->fetchColumn();

    // Get user's total borrowed books count (all time)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM borrowing 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $total_borrowed = $stmt->fetchColumn();

    // Get user's active reservations count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reservations 
        WHERE user_id = ? AND status = 'active'
    ");
    $stmt->execute([$user_id]);
    $reservations_count = $stmt->fetchColumn();

    // Get user's pending returns count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM borrowing 
        WHERE user_id = ? AND status = 'pending_return'
    ");
    $stmt->execute([$user_id]);
    $pending_returns_count = $stmt->fetchColumn();

    // Get overdue books count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM borrowing 
        WHERE user_id = ? AND status = 'overdue'
    ");
    $stmt->execute([$user_id]);
    $overdue_count = $stmt->fetchColumn();

    // Get system settings
    $stmt = $pdo->prepare("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE setting_key IN ('max_borrow_books', 'max_borrow_days')
    ");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $max_borrow_books = (int)($settings['max_borrow_books'] ?? 5);
    $max_borrow_days = (int)($settings['max_borrow_days'] ?? 14);

    // Calculate remaining borrowing capacity
    $remaining_capacity = max(0, $max_borrow_books - $borrowed_count);

    // Response
    echo json_encode([
        'success' => true,
        'data' => [
            'borrowed_count' => (int)$borrowed_count,
            'total_borrowed' => (int)$total_borrowed,
            'reservations_count' => (int)$reservations_count,
            'pending_returns_count' => (int)$pending_returns_count,
            'overdue_count' => (int)$overdue_count,
            'remaining_capacity' => (int)$remaining_capacity,
            'max_borrow_books' => (int)$max_borrow_books,
            'max_borrow_days' => (int)$max_borrow_days,
            'can_borrow_more' => $remaining_capacity > 0
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูล'
    ]);
    
    // Log error
    error_log("Get User Stats Error: " . $e->getMessage());
}
?>