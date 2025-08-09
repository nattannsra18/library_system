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
    // Get updated book information with reservation status
    $stmt = $pdo->prepare("
        SELECT b.*, c.category_name, p.publisher_name,
               GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors,
               b.cover_image,
               -- เช็คว่าหนังสือถูกจองโดยใครบ้าง
               (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.book_id AND r.status = 'active') as reserved_count,
               -- เช็คว่าหนังสือถูกจองโดยผู้ใช้คนนี้หรือไม่
               (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.book_id AND r.user_id = ? AND r.status = 'active') as user_reserved,
               -- เช็คว่าผู้ใช้ยืมหนังสือนี้อยู่หรือไม่
               (SELECT COUNT(*) FROM borrowing br WHERE br.book_id = b.book_id AND br.user_id = ? AND br.status IN ('borrowed', 'overdue')) as user_borrowed
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.category_id
        LEFT JOIN publishers p ON b.publisher_id = p.publisher_id
        LEFT JOIN book_authors ba ON b.book_id = ba.book_id
        LEFT JOIN authors a ON ba.author_id = a.author_id
        WHERE b.status IN ('available', 'unavailable', 'reserved')
        GROUP BY b.book_id
        ORDER BY 
            CASE 
                WHEN b.status = 'available' THEN 1 
                WHEN b.status = 'reserved' THEN 2
                ELSE 3 
            END,
            b.title
    ");
    
    $stmt->execute([$user_id, $user_id]);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'books' => $books,
        'timestamp' => time()
    ]);

} catch (PDOException $e) {
    error_log("Database Error in get_updated_books: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred'
    ]);
}
?>