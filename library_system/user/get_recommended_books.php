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

$user_id = $_SESSION['user_id'];

try {
    // Get recommended books
    $stmt = $pdo->prepare("
        SELECT b.*, c.category_name, 
               GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.category_id
        LEFT JOIN book_authors ba ON b.book_id = ba.book_id
        LEFT JOIN authors a ON ba.author_id = a.author_id
        WHERE b.status = 'available'
        GROUP BY b.book_id
        ORDER BY RAND()
        LIMIT 12
    ");
    $stmt->execute();
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add additional info for each book
    foreach ($books as &$book) {
        // Check if user already borrowed this book
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND book_id = ? AND status IN ('borrowed', 'overdue')");
        $stmt->execute([$user_id, $book['book_id']]);
        $book['already_borrowed'] = $stmt->fetchColumn() > 0;

        // Check if user already reserved this book
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND book_id = ? AND status = 'active'");
        $stmt->execute([$user_id, $book['book_id']]);
        $book['already_reserved'] = $stmt->fetchColumn() > 0;

        // Check if book is reserved by someone else
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE book_id = ? AND status = 'active'");
        $stmt->execute([$book['book_id']]);
        $book['book_reserved_by_others'] = $stmt->fetchColumn() > 0 && !$book['already_reserved'];
    }

    echo json_encode([
        'success' => true,
        'books' => $books
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>