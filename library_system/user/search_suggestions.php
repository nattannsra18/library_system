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

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (empty($query) || strlen($query) < 2) {
    echo json_encode(['success' => false, 'message' => 'Query too short']);
    exit();
}

try {
    $suggestions = [];
    $searchTerm = "%$query%";
    
    // Search books by title
    $stmt = $pdo->prepare("
        SELECT DISTINCT b.title, b.subtitle, 'book' as type, b.title as value
        FROM books b
        WHERE b.status = 'available' AND (b.title LIKE ? OR b.subtitle LIKE ?)
        ORDER BY b.title
        LIMIT 5
    ");
    $stmt->execute([$searchTerm, $searchTerm]);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($books as $book) {
        $suggestions[] = [
            'type' => 'book',
            'title' => $book['title'],
            'subtitle' => $book['subtitle'] ? $book['subtitle'] : 'หนังสือ',
            'value' => $book['title']
        ];
    }
    
    // Search authors
    $stmt = $pdo->prepare("
        SELECT DISTINCT CONCAT(a.first_name, ' ', a.last_name) as author_name, 
               'author' as type,
               CONCAT(a.first_name, ' ', a.last_name) as value
        FROM authors a
        WHERE CONCAT(a.first_name, ' ', a.last_name) LIKE ?
        ORDER BY author_name
        LIMIT 3
    ");
    $stmt->execute([$searchTerm]);
    $authors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($authors as $author) {
        $suggestions[] = [
            'type' => 'author',
            'title' => $author['author_name'],
            'subtitle' => 'ผู้เขียน',
            'value' => $author['author_name']
        ];
    }
    
    // Search categories
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.category_name, 'category' as type, c.category_name as value
        FROM categories c
        WHERE c.category_name LIKE ?
        ORDER BY c.category_name
        LIMIT 3
    ");
    $stmt->execute([$searchTerm]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($categories as $category) {
        $suggestions[] = [
            'type' => 'category',
            'title' => $category['category_name'],
            'subtitle' => 'หมวดหมู่',
            'value' => $category['category_name']
        ];
    }
    
    // Search ISBN
    if (preg_match('/^\d+/', $query)) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT b.isbn, b.title, 'isbn' as type, b.isbn as value
            FROM books b
            WHERE b.isbn LIKE ? AND b.status = 'available'
            ORDER BY b.isbn
            LIMIT 2
        ");
        $stmt->execute([$searchTerm]);
        $isbns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($isbns as $isbn) {
            $suggestions[] = [
                'type' => 'isbn',
                'title' => $isbn['isbn'],
                'subtitle' => $isbn['title'],
                'value' => $isbn['isbn']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'suggestions' => $suggestions
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>