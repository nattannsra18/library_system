<?php
// get_book_details.php - Enhanced API with user-specific status
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit();
}

$user_id = $_SESSION['user_id'];

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
    // Get enhanced book information with real-time availability calculation
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
            p.website as publisher_website,
            -- Calculate REAL-TIME available copies
            GREATEST(0, b.total_copies - (
                SELECT COUNT(*) FROM borrowing br 
                WHERE br.book_id = b.book_id 
                AND br.status IN ('borrowed', 'overdue')
            )) as available_copies,
            -- Count active reservations
            (SELECT COUNT(*) FROM reservations r 
             WHERE r.book_id = b.book_id AND r.status = 'active') as total_reservations,
            -- Check if current user has reserved this book
            (SELECT COUNT(*) FROM reservations r 
             WHERE r.book_id = b.book_id AND r.user_id = ? AND r.status = 'active') as user_has_reserved,
            -- Check if current user is currently borrowing this book
            (SELECT COUNT(*) FROM borrowing br 
             WHERE br.book_id = b.book_id AND br.user_id = ? AND br.status IN ('borrowed', 'overdue')) as user_is_borrowing,
            -- Get user's current borrowed count
            (SELECT COUNT(*) FROM borrowing br2 
             WHERE br2.user_id = ? AND br2.status IN ('borrowed', 'overdue')) as user_borrowed_count
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.category_id
        LEFT JOIN publishers p ON b.publisher_id = p.publisher_id
        WHERE b.book_id = ?
    ");
    
    $stmt->execute([$user_id, $user_id, $user_id, $book_id]);
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
    
    // Get system settings for borrowing limits
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key = 'max_borrow_books'");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $max_borrow_books = (int)($settings['max_borrow_books'] ?? 5);
    
    // Calculate user-specific status
    $user_status = [
        'can_reserve' => false,
        'reason' => '',
        'status_type' => 'unknown'
    ];
    
    if ($book['user_is_borrowing'] > 0) {
        $user_status['reason'] = 'คุณกำลังยืมหนังสือเล่มนี้อยู่';
        $user_status['status_type'] = 'user_borrowing';
    } elseif ($book['user_has_reserved'] > 0) {
        $user_status['reason'] = 'คุณได้จองหนังสือเล่มนี้แล้ว';
        $user_status['status_type'] = 'user_reserved';
    } elseif ($book['user_borrowed_count'] >= $max_borrow_books) {
        $user_status['reason'] = 'คุณยืมหนังสือครบจำนวนแล้ว (' . $max_borrow_books . ' เล่ม)';
        $user_status['status_type'] = 'user_limit_reached';
    } elseif ($book['available_copies'] <= 0) {
        $user_status['reason'] = 'ไม่มีเล่มว่างให้ยืม';
        $user_status['status_type'] = 'no_copies_available';
    } elseif ($book['status'] !== 'available') {
        $user_status['reason'] = 'หนังสือไม่พร้อมให้บริการ';
        $user_status['status_type'] = 'book_unavailable';
    } else {
        $user_status['can_reserve'] = true;
        $user_status['reason'] = 'สามารถจองได้';
        $user_status['status_type'] = 'can_reserve';
    }
    
    // Get reservation details if user has reserved this book
    $reservation_details = null;
    if ($book['user_has_reserved'] > 0) {
        $stmt = $pdo->prepare("
            SELECT reservation_date, expiry_date, status 
            FROM reservations 
            WHERE book_id = ? AND user_id = ? AND status = 'active'
            ORDER BY reservation_date DESC LIMIT 1
        ");
        $stmt->execute([$book_id, $user_id]);
        $reservation_details = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get borrowing details if user is currently borrowing this book
    $borrowing_details = null;
    if ($book['user_is_borrowing'] > 0) {
        $stmt = $pdo->prepare("
            SELECT borrow_date, due_date, status 
            FROM borrowing 
            WHERE book_id = ? AND user_id = ? AND status IN ('borrowed', 'overdue')
            ORDER BY borrow_date DESC LIMIT 1
        ");
        $stmt->execute([$book_id, $user_id]);
        $borrowing_details = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Format the enhanced response
    $response = [
        'success' => true,
        'data' => [
            'book' => $book,
            'authors' => $authors,
            'user_status' => $user_status,
            'reservation_details' => $reservation_details,
            'borrowing_details' => $borrowing_details,
            'system_info' => [
                'max_borrow_books' => $max_borrow_books,
                'user_borrowed_count' => $book['user_borrowed_count']
            ]
        ],
        'timestamp' => date('Y-m-d H:i:s')
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