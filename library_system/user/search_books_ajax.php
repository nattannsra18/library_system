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
$search = isset($_POST['search']) ? trim($_POST['search']) : '';

if (empty($search)) {
    echo json_encode(['success' => false, 'message' => 'No search term provided']);
    exit();
}

try {
    // Build query for books
    $where_conditions = [];
    $params = [];

    $where_conditions[] = "(b.title LIKE :search OR b.subtitle LIKE :search OR b.isbn LIKE :search OR 
                          a.first_name LIKE :search OR a.last_name LIKE :search)";
    $params[':search'] = "%$search%";

    $where_conditions[] = "b.status = 'available'";

    $where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    // Get books
    $sql = "SELECT DISTINCT b.*, c.category_name, p.publisher_name,
                   GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.category_id
            LEFT JOIN publishers p ON b.publisher_id = p.publisher_id
            LEFT JOIN book_authors ba ON b.book_id = ba.book_id
            LEFT JOIN authors a ON ba.author_id = a.author_id
            $where_sql
            GROUP BY b.book_id
            ORDER BY b.created_at DESC
            LIMIT 50";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count
    $count_sql = "SELECT COUNT(DISTINCT b.book_id) as total
                  FROM books b
                  LEFT JOIN book_authors ba ON b.book_id = ba.book_id
                  LEFT JOIN authors a ON ba.author_id = a.author_id
                  $where_sql";

    $stmt = $pdo->prepare($count_sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $total = $stmt->fetchColumn();

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
        'books' => $books,
        'total' => (int)$total
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>