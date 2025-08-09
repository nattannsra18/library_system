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
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    // Get user's current borrowed books count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND status IN ('borrowed', 'overdue')");
    $stmt->execute([$user_id]);
    $borrowed_count = $stmt->fetchColumn();

    // Get user's total borrowed books count (all time)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_borrowed = $stmt->fetchColumn();

    // Get user's active reservations count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    $reservations_count = $stmt->fetchColumn();

    // Get user's recent activity count (last 30 days)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND borrow_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute([$user_id]);
    $recent_activity_count = $stmt->fetchColumn();

    // Get system settings
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('max_borrow_books', 'max_borrow_days')");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $max_borrow_books = (int)($settings['max_borrow_books'] ?? 5);
    $max_borrow_days = (int)($settings['max_borrow_days'] ?? 14);

    // Return success response with updated stats
    echo json_encode([
        'success' => true,
        'data' => [
            'borrowed_count' => $borrowed_count,
            'total_borrowed' => $total_borrowed,
            'reservations_count' => $reservations_count,
            'recent_activity_count' => $recent_activity_count,
            'max_borrow_books' => $max_borrow_books,
            'max_borrow_days' => $max_borrow_days
        ]
    ]);

} catch (PDOException $e) {
    error_log("Database Error in get_user_stats: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred'
    ]);
}
?>