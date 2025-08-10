<?php
// get_book_details.php - API endpoint to fetch basic book details only
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit();
}

// Database connection
$host = 'localhost';
$dbname = 'library_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล']);
    exit();
}

// Get book ID from request
$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;

if (!$book_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ไม่พบรหัสหนังสือ']);
    exit();
}

try {
    // Get basic book information with related data
    $stmt = $pdo->prepare("
        SELECT 
            b.book_id,
            b.isbn,
            b.title,
            b.subtitle,
            b.publication_year,
            b.edition,
            b.pages,
            b.language,
            b.description,
            b.cover_image,
            b.location,
            b.total_copies,
            b.available_copies,
            b.price,
            b.acquisition_date,
            b.status,
            b.created_at,
            b.updated_at,
            c.category_name,
            c.category_code,
            p.publisher_name,
            p.address as publisher_address,
            p.phone as publisher_phone,
            p.email as publisher_email,
            p.website as publisher_website
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.category_id
        LEFT JOIN publishers p ON b.publisher_id = p.publisher_id
        WHERE b.book_id = ?
    ");
    
    $stmt->execute([$book_id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$book) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบหนังสือที่ต้องการ']);
        exit();
    }
    
    // Get authors information
    $stmt = $pdo->prepare("
        SELECT 
            a.author_id,
            a.first_name,
            a.last_name,
            a.biography,
            a.birth_date,
            a.nationality,
            ba.role
        FROM authors a
        INNER JOIN book_authors ba ON a.author_id = ba.author_id
        WHERE ba.book_id = ?
        ORDER BY 
            CASE ba.role 
                WHEN 'author' THEN 1 
                WHEN 'co-author' THEN 2 
                WHEN 'editor' THEN 3 
                WHEN 'translator' THEN 4 
                ELSE 5 
            END,
            a.first_name, a.last_name
    ");
    $stmt->execute([$book_id]);
    $authors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response with basic book data only
    $response = [
        'success' => true,
        'data' => [
            'book' => $book,
            'authors' => $authors
        ]
    ];
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("Database error in get_book_details.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูล'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("General error in get_book_details.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาดในระบบ'
    ], JSON_UNESCAPED_UNICODE);
}
?>