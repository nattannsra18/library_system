<?php
session_start();

// Database connection
$host = 'localhost';
$dbname = 'library_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user information
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get search parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_id = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 12;
$offset = ($page - 1) * $per_page;

// Get all categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY category_name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build query for books
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(b.title LIKE :search OR b.subtitle LIKE :search OR b.isbn LIKE :search OR 
                          a.first_name LIKE :search OR a.last_name LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($category_id > 0) {
    $where_conditions[] = "b.category_id = :category_id";
    $params[':category_id'] = $category_id;
}

$where_conditions[] = "b.status IN ('available', 'unavailable')";

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get books with pagination
$sql = "SELECT DISTINCT b.*, c.category_name, p.publisher_name,
               GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors,
               CASE 
                   WHEN b.cover_image IS NOT NULL AND b.cover_image != '' 
                   THEN CONCAT('uploads/covers/', b.cover_image)
                   ELSE NULL 
               END as cover_url
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.category_id
        LEFT JOIN publishers p ON b.publisher_id = p.publisher_id
        LEFT JOIN book_authors ba ON b.book_id = ba.book_id
        LEFT JOIN authors a ON ba.author_id = a.author_id
        $where_sql
        GROUP BY b.book_id
        ORDER BY 
            CASE WHEN b.status = 'available' THEN 1 ELSE 2 END,
            b.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
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
$total_books = $stmt->fetchColumn();
$total_pages = ceil($total_books / $per_page);

// Get library stats
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM books WHERE status = 'available') as total_books,
    (SELECT COUNT(*) FROM users WHERE status = 'active') as total_users,
    (SELECT COUNT(*) FROM borrowing) as total_borrows,
    (SELECT COUNT(*) FROM categories) as total_categories";
$stmt = $pdo->query($stats_query);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user's current borrowed books count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND status IN ('borrowed', 'overdue')");
$stmt->execute([$user_id]);
$user_borrowed_count = $stmt->fetchColumn();

// Get user's total borrowed books count (all time)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ?");
$stmt->execute([$user_id]);
$user_total_borrowed = $stmt->fetchColumn();

// Get user's recent activity count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND borrow_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stmt->execute([$user_id]);
$recent_activity_count = $stmt->fetchColumn();

// Get recommended books based on user's history or popular books
$stmt = $pdo->prepare("
    SELECT b.*, c.category_name, 
           GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors,
           CASE 
               WHEN b.cover_image IS NOT NULL AND b.cover_image != '' 
               THEN CONCAT('uploads/covers/', b.cover_image)
               ELSE NULL 
           END as cover_url
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN book_authors ba ON b.book_id = ba.book_id
    LEFT JOIN authors a ON ba.author_id = a.author_id
    WHERE b.status IN ('available', 'unavailable')
    GROUP BY b.book_id
    ORDER BY 
        CASE WHEN b.status = 'available' THEN 1 ELSE 2 END,
        RAND()
    LIMIT 6
");

$stmt->execute();
$recommended_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get notifications
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = FALSE 
    ORDER BY sent_date DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get system settings for borrowing limits
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('max_borrow_books', 'max_borrow_days')");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$max_borrow_books = (int)($settings['max_borrow_books'] ?? 5);
$max_borrow_days = (int)($settings['max_borrow_days'] ?? 14);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าแรก - ห้องสมุดดิจิทัล</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 2rem;
        }

        .logo {
            display: flex;
            align-items: center;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .logo i {
            margin-right: 10px;
            font-size: 2rem;
        }

        .nav-links {
            display: flex;
            list-style: none;
            gap: 2rem;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            padding: 0.5rem 1rem;
            border-radius: 8px;
        }

        .nav-links a:hover, .nav-links a.active {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.8rem 1.5rem;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 25px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-logout:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 120px 0 60px;
            text-align: center;
        }

        .welcome-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .welcome-banner h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .welcome-banner p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }

        .user-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .user-stat-card {
            background: rgba(255,255,255,0.15);
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .user-stat-card i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .user-stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
        }

        .user-stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* Enhanced Search Section */
        .search-section {
            background: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .search-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 2rem;
            position: relative;
        }

        .search-section h2 {
            text-align: center;
            font-size: 2rem;
            color: #333;
            margin-bottom: 2rem;
        }

        .search-form {
            background: #f8f9fa;
            border-radius: 50px;
            padding: 10px;
            display: flex;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            position: relative;
        }

        .search-input {
            flex: 1;
            border: none;
            padding: 1rem 1.5rem;
            font-size: 1.1rem;
            border-radius: 40px;
            outline: none;
            background: transparent;
        }

        .search-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 40px;
            cursor: pointer;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .category-filters {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .category-btn {
            background: #f8f9fa;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .category-btn:hover, .category-btn.active {
            background: #667eea;
            color: white;
        }

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .content-wrapper {
            width: 100%;
        }

        .section {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .section-header h3 {
            font-size: 1.5rem;
            color: #333;
        }

        .section-header i {
            color: #667eea;
            font-size: 1.5rem;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .quick-action {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .quick-action:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }

        .quick-action i {
            font-size: 2.5rem;
            color: #667eea;
            margin-bottom: 1rem;
        }

        .quick-action h4 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .quick-action p {
            color: #666;
            font-size: 0.9rem;
        }

        /* Books Grid */
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 2rem;
        }

        .book-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
            border: 1px solid #f0f0f0;
        }

        .book-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .book-image {
            height: 200px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            position: relative;
            overflow: hidden;
        }

        .book-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: opacity 0.3s ease;
        }

        .book-availability {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            z-index: 2;
        }

        .book-info {
            padding: 1.5rem;
        }

        .book-title {
            font-size: 1.1rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #333;
            line-height: 1.3;
            height: 2.6rem;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .book-author {
            color: #666;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .book-isbn {
            color: #888;
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .book-category {
            display: inline-block;
            background: #e3f2fd;
            color: #1976d2;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            margin-bottom: 1rem;
        }

        .book-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-reserve {
            flex: 1;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.8rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-reserve:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-reserve:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-reserve.reserved {
            background: #28a745;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: #fefefe;
            margin: 5% auto;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalAppear 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            overflow: hidden;
        }

        @keyframes modalAppear {
            from { 
                opacity: 0; 
                transform: translateY(-30px) scale(0.9); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1); 
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            position: relative;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .close {
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: white;
            font-size: 2rem;
            font-weight: 300;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-50%) rotate(90deg);
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-book-info {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8f9ff 0%, #e8f4fd 100%);
            border-radius: 15px;
            border-left: 4px solid #667eea;
        }

        .modal-book-cover {
            width: 80px;
            height: 100px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .modal-book-details h4 {
            color: #2c3e50;
            font-size: 1.2rem;
            margin-bottom: 0.75rem;
            font-weight: 600;
        }

        .modal-book-details p {
            margin: 0.5rem 0;
            color: #7f8c8d;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-book-details i {
            color: #667eea;
            width: 16px;
        }

        .info-box {
            background: linear-gradient(135deg, #e8f5e8 0%, #f0f8f0 100%);
            border: 1px solid #c3e6c3;
            border-left: 4px solid #27ae60;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }

        .info-box h4 {
            color: #27ae60;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-box p {
            margin: 0.75rem 0;
            color: #2d5a3d;
            font-size: 0.95rem;
            line-height: 1.5;
            padding-left: 1.5rem;
            position: relative;
        }

        .info-box p::before {
            content: "•";
            color: #27ae60;
            position: absolute;
            left: 0.5rem;
            font-weight: bold;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .btn-modal {
            padding: 1rem 2rem;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 140px;
            justify-content: center;
        }

        .btn-modal-secondary {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px solid #dee2e6;
        }

        .btn-modal-secondary:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }

        .btn-modal-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-modal-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination a, .pagination span {
            padding: 0.8rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .pagination a {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .pagination a:hover {
            background: #667eea;
            color: white;
        }

        .pagination .current {
            background: #667eea;
            color: white;
            border: 2px solid #667eea;
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 100px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            z-index: 9999;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            max-width: 400px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            background: #4caf50;
        }

        .notification.error {
            background: #f44336;
        }

        .notification.warning {
            background: #ff9800;
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            color: white;
            flex-direction: column;
        }

        .loading-overlay.show {
            display: flex;
        }

        .spinner {
            border: 4px solid rgba(255,255,255,0.3);
            border-top: 4px solid white;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin-bottom: 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 1rem;
        }

        .empty-state h4 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }

            .welcome-banner h1 {
                font-size: 2rem;
            }

            .books-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .user-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .nav-container, .welcome-container, .search-container, .main-content {
                padding: 0 1rem;
            }

            .modal-content {
                width: 95%;
                margin: 10% auto;
            }

            .modal-book-info {
                flex-direction: column;
                text-align: center;
            }

            .modal-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <p>กำลังดำเนินการ...</p>
    </div>

    <!-- Reserve Book Modal -->
    <div id="reserveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-bookmark"></i> จองหนังสือ</h3>
                <button class="close" onclick="closeModal('reserveModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-book-info">
                    <div class="modal-book-cover" id="modal-book-cover">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="modal-book-details" id="modal-book-details">
                        <h4 id="modal-book-title">หนังสือ</h4>
                        <p id="modal-book-author"><i class="fas fa-user"></i> ผู้เขียน</p>
                        <p id="modal-book-category"><i class="fas fa-tag"></i> หมวดหมู่</p>
                        <p id="modal-book-isbn"><i class="fas fa-barcode"></i> ISBN</p>
                    </div>
                </div>
                <div class="info-box">
                    <h4>
                        <i class="fas fa-info-circle"></i> ขั้นตอนการจองหนังสือ
                    </h4>
                    <p>เมื่อคุณกดยืนยัน ระบบจะบันทึกการจองหนังสือไว้ในระบบ</p>
                    <p>แอดมินจะตรวจสอบและอนุมัติการจองของคุณ</p>
                    <p>หลังจากอนุมัติแล้ว คุณจะได้รับแจ้งเตือนให้มารับหนังสือ</p>
                    <p>การจองจะหมดอายุใน 1 วัน หากไม่ได้รับการอนุมัติ</p>
                    <p>ระยะเวลาการยืมจะเริ่มนับเมื่อแอดมินอนุมัติและส่งมอบหนังสือ</p>
                </div>
                <div class="modal-actions">
                    <button class="btn-modal btn-modal-secondary" onclick="closeModal('reserveModal')">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button class="btn-modal btn-modal-primary" id="confirm-reserve-btn" onclick="confirmReserve()">
                        <i class="fas fa-bookmark"></i> ยืนยันการจอง
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Header -->
    <header class="header">
        <nav class="nav-container">
            <div class="logo">
                <i class="fas fa-book-open"></i>
                <span>ห้องสมุดดิจิทัล</span>
            </div>
            <ul class="nav-links">
                <li><a href="dashboard.php">แดชบอร์ด</a></li>
                <li><a href="#" class="active">หน้าแรก</a></li>
                <li><a href="profile.php">โปรไฟล์</a></li>
                <li><a href="history.php">ประวัติการยืม</a></li>
                <li><a href="search.php">ค้นหาหนังสือ</a></li>
            </ul>
            <div class="user-menu">
                <a href="../logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    ออกจากระบบ
                </a>
            </div>
        </nav>
    </header>

    <!-- Welcome Banner -->
    <section class="welcome-banner">
        <div class="welcome-container">
            <h1>สวัสดี, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>!</h1>
            <p>ยินดีต้อนรับสู่ห้องสมุดดิจิทัล วิทยาลัยเทคนิคหาดใหญ่</p>
            
            <div class="user-stats">
                <div class="user-stat-card">
                    <i class="fas fa-book-reader"></i>
                    <div class="user-stat-number"><?php echo $user_borrowed_count; ?>/<?php echo $max_borrow_books; ?></div>
                    <div class="user-stat-label">กำลังยืมอยู่</div>
                </div>
                <div class="user-stat-card">
                    <i class="fas fa-bookmark"></i>
                    <div class="user-stat-number" id="user-reservations-count">
                        <?php 
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'active'");
                        $stmt->execute([$user_id]);
                        $user_reservations = $stmt->fetchColumn();
                        echo $user_reservations;
                        ?>
                    </div>
                    <div class="user-stat-label">กำลังจองอยู่</div>
                </div>
                <div class="user-stat-card">
                    <i class="fas fa-history"></i>
                    <div class="user-stat-number"><?php echo $user_total_borrowed; ?></div>
                    <div class="user-stat-label">ยืมทั้งหมด</div>
                </div>
                <div class="user-stat-card">
                    <i class="fas fa-calendar-day"></i>
                    <div class="user-stat-number"><?php echo $max_borrow_days; ?></div>
                    <div class="user-stat-label">วันที่อนุญาตให้ยืม</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Search Section -->
    <section class="search-section">
        <div class="search-container">
            <h2><i class="fas fa-search"></i> ค้นหาหนังสือ</h2>
            
            <form class="search-form" method="GET" action="" id="searchForm">
                <input type="text" name="search" class="search-input" id="searchInput"
                       placeholder="ค้นหาหนังสือ ผู้เขียน หรือ ISBN..." 
                       value="<?php echo htmlspecialchars($search); ?>"
                       autocomplete="off">
                <button type="submit" class="search-btn">
                    <i class="fas fa-search"></i>
                </button>
            </form>

            <div class="category-filters">
                <a href="?" class="category-btn <?php echo $category_id == 0 ? 'active' : ''; ?>">
                    ทั้งหมด
                </a>
                <?php foreach ($categories as $category): ?>
                    <a href="?category=<?php echo $category['category_id']; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
                       class="category-btn <?php echo $category_id == $category['category_id'] ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($category['category_name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="dashboard.php" class="quick-action">
                <i class="fas fa-tachometer-alt"></i>
                <h4>แดชบอร์ด</h4>
                <p>ดูสถานะการยืมและข้อมูลส่วนตัว</p>
            </a>
            <a href="search.php" class="quick-action">
                <i class="fas fa-search-plus"></i>
                <h4>ค้นหาขั้นสูง</h4>
                <p>ค้นหาหนังสือด้วยตัวกรองหลากหลาย</p>
            </a>
            <a href="history.php" class="quick-action">
                <i class="fas fa-history"></i>
                <h4>ประวัติการยืม</h4>
                <p>ดูประวัติการยืมและคืนหนังสือ</p>
            </a>
            <a href="profile.php" class="quick-action">
                <i class="fas fa-user-cog"></i>
                <h4>แก้ไขโปรไฟล์</h4>
                <p>จัดการข้อมูลส่วนตัวและการตั้งค่า</p>
            </a>
        </div>

        <!-- Content Wrapper -->
        <div class="content-wrapper">
            <!-- Books Section -->
            <div class="section">
                <div class="section-header">
                    <i class="fas fa-book"></i>
                    <h3>
                        <?php 
                        if (!empty($search) || $category_id > 0) {
                            echo "ผลการค้นหา (" . number_format($total_books) . " เล่ม)";
                        } else {
                            echo "หนังสือทั้งหมด";
                        }
                        ?>
                    </h3>
                </div>

                <?php if (empty($books)): ?>
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h4>ไม่พบหนังสือที่ค้นหา</h4>
                        <p>ลองใช้คำค้นหาอื่น หรือเลือกหมวดหมู่ที่แตกต่างกัน</p>
                    </div>
                <?php else: ?>
                    <div class="books-grid">
                        <?php 
                        $display_books = !empty($search) || $category_id > 0 ? $books : $recommended_books;
                        foreach ($display_books as $book): 
                            // Check if user already borrowed this book
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND book_id = ? AND status IN ('borrowed', 'overdue')");
                            $stmt->execute([$user_id, $book['book_id']]);
                            $already_borrowed = $stmt->fetchColumn() > 0;

                            // Check if user already reserved this book
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND book_id = ? AND status = 'active'");
                            $stmt->execute([$user_id, $book['book_id']]);
                            $already_reserved = $stmt->fetchColumn() > 0;

                            // Check if book is reserved by someone else
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE book_id = ? AND status = 'active'");
                            $stmt->execute([$book['book_id']]);
                            $book_reserved_by_others = $stmt->fetchColumn() > 0 && !$already_reserved;
                        ?>
                            <div class="book-card" data-book-id="<?php echo $book['book_id']; ?>">
                                <div class="book-image">
                                    <div class="book-availability">
                                        <?php echo $book['available_copies']; ?>/<?php echo $book['total_copies']; ?> เล่ม
                                    </div>
                                    <?php if (!empty($book['cover_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($book['cover_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($book['title']); ?>"
                                             onerror="this.style.display='none';">
                                    <?php else: ?>
                                        <i class="fas fa-book"></i>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="book-info">
                                    <h3 class="book-title" title="<?php echo htmlspecialchars($book['title']); ?>">
                                        <?php echo htmlspecialchars($book['title']); ?>
                                    </h3>
                                    
                                    <div class="book-author">
                                        <i class="fas fa-user"></i>
                                        <?php echo htmlspecialchars($book['authors'] ?: 'ไม่ระบุผู้เขียน'); ?>
                                    </div>

                                    <?php if (!empty($book['isbn'])): ?>
                                    <div class="book-isbn">
                                        <i class="fas fa-barcode"></i>
                                        <?php echo htmlspecialchars($book['isbn']); ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="book-category">
                                        <?php echo htmlspecialchars($book['category_name'] ?: 'ไม่ระบุหมวดหมู่'); ?>
                                    </div>
                                    
                                    <div class="book-actions">
                                        <?php if ($already_borrowed): ?>
                                            <button class="btn-reserve reserved" disabled>
                                                <i class="fas fa-check"></i>
                                                ยืมอยู่
                                            </button>
                                        <?php elseif ($already_reserved): ?>
                                            <button class="btn-reserve reserved" disabled>
                                                <i class="fas fa-bookmark"></i>
                                                จองแล้ว
                                            </button>
                                        <?php elseif ($user_borrowed_count >= $max_borrow_books): ?>
                                            <button class="btn-reserve" disabled title="ยืมครบจำนวนสูงสุดแล้ว">
                                                <i class="fas fa-ban"></i>
                                                ยืมครบแล้ว
                                            </button>
                                        <?php elseif ($book_reserved_by_others): ?>
                                            <button class="btn-reserve" disabled title="หนังสือถูกจองแล้ว">
                                                <i class="fas fa-bookmark"></i>
                                                ถูกจองแล้ว
                                            </button>
                                        <?php elseif ($book['available_copies'] <= 0): ?>
                                            <button class="btn-reserve" disabled>
                                                <i class="fas fa-times"></i>
                                                ไม่ว่าง
                                            </button>
                                        <?php else: ?>
                                            <button class="btn-reserve" onclick="showReserveModal(<?php echo $book['book_id']; ?>, '<?php echo addslashes($book['title']); ?>', '<?php echo addslashes($book['authors'] ?: 'ไม่ระบุผู้เขียน'); ?>', '<?php echo addslashes($book['category_name'] ?: 'ไม่ระบุหมวดหมู่'); ?>', '<?php echo addslashes($book['isbn'] ?: 'ไม่ระบุ ISBN'); ?>')">
                                                <i class="fas fa-bookmark"></i>
                                                จองหนังสือ
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination (only show if searching) -->
                    <?php if ((!empty($search) || $category_id > 0) && $total_pages > 1): ?>
                        <div class="pagination">
                            <?php
                            $query_params = [];
                            if (!empty($search)) $query_params['search'] = $search;
                            if ($category_id > 0) $query_params['category'] = $category_id;
                            
                            // Previous page
                            if ($page > 1) {
                                $query_params['page'] = $page - 1;
                                $url = '?' . http_build_query($query_params);
                                echo '<a href="' . $url . '"><i class="fas fa-chevron-left"></i></a>';
                            }
                            
                            // Page numbers
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                                $query_params['page'] = $i;
                                $url = '?' . http_build_query($query_params);
                            ?>
                                <?php if ($i == $page): ?>
                                    <span class="current"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo $url; ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php
                            // Next page
                            if ($page < $total_pages) {
                                $query_params['page'] = $page + 1;
                                $url = '?' . http_build_query($query_params);
                                echo '<a href="' . $url . '"><i class="fas fa-chevron-right"></i></a>';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // ========================
        // CORE SYSTEM VARIABLES
        // ========================
        let isProcessing = false;
        let currentBookId = null;

        // ========================
        // NOTIFICATION SYSTEM
        // ========================
        function showNotification(message, type = 'success') {
            // Remove existing notifications
            document.querySelectorAll('.notification').forEach(n => n.remove());

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            const icon = {
                'success': 'check-circle',
                'error': 'exclamation-triangle', 
                'warning': 'exclamation-circle'
            }[type] || 'info-circle';
            
            notification.innerHTML = `<i class="fas fa-${icon}"></i><span>${message}</span>`;
            document.body.appendChild(notification);
            
            // Show with animation
            setTimeout(() => notification.classList.add('show'), 100);
            
            // Auto hide after 5 seconds
            setTimeout(() => hideNotification(notification), 5000);
        }

        function hideNotification(notification) {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }

        // ========================
        // LOADING SYSTEM
        // ========================
        function showLoading(message = 'กำลังดำเนินการ...') {
            const loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) {
                const loadingText = loadingOverlay.querySelector('p');
                if (loadingText) loadingText.textContent = message;
                loadingOverlay.classList.add('show');
            }
        }

        function hideLoading() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) loadingOverlay.classList.remove('show');
        }

        // ========================
        // MODAL SYSTEM
        // ========================
        function showReserveModal(bookId, title, author, category, isbn) {
            currentBookId = bookId;
            
            // Update modal content
            const elements = {
                'modal-book-title': title,
                'modal-book-author': `<i class="fas fa-user"></i> ${author}`,
                'modal-book-category': `<i class="fas fa-tag"></i> ${category}`,
                'modal-book-isbn': `<i class="fas fa-barcode"></i> ${isbn}`
            };
            
            Object.entries(elements).forEach(([id, content]) => {
                const element = document.getElementById(id);
                if (element) element.innerHTML = content;
            });
            
            // Show modal
            const modal = document.getElementById('reserveModal');
            if (modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
            currentBookId = null;
        }

        // ========================
        // BOOK RESERVATION
        // ========================
        function confirmReserve() {
            if (!currentBookId || isProcessing) return;

            isProcessing = true;
            showLoading('กำลังดำเนินการจองหนังสือ...');

            fetch('reserve_book.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ book_id: currentBookId })
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                isProcessing = false;
                
                if (data.success) {
                    closeModal('reserveModal');
                    showNotification('จองหนังสือสำเร็จ! รอแอดมินอนุมัติ', 'success');
                    
                    // Update UI
                    updateBookCardAfterReserve(currentBookId);
                    updateUserStats();
                    
                    // Auto refresh after 3 seconds
                    setTimeout(() => window.location.reload(), 3000);
                } else {
                    showNotification(`ไม่สามารถจองหนังสือได้: ${data.message}`, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                isProcessing = false;
                console.error('Reserve book error:', error);
                showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่อีกครั้ง', 'error');
            });
        }

        function updateBookCardAfterReserve(bookId) {
            const bookCard = document.querySelector(`[data-book-id="${bookId}"]`);
            if (bookCard) {
                const reserveBtn = bookCard.querySelector('.btn-reserve');
                if (reserveBtn) {
                    reserveBtn.disabled = true;
                    reserveBtn.classList.add('reserved');
                    reserveBtn.innerHTML = '<i class="fas fa-bookmark"></i> จองแล้ว';
                }
            }
        }

        function updateUserStats() {
            const reservationsCount = document.getElementById('user-reservations-count');
            if (reservationsCount) {
                const currentCount = parseInt(reservationsCount.textContent) || 0;
                reservationsCount.textContent = currentCount + 1;
            }
        }

        // ========================
        // EVENT LISTENERS
        // ========================
        document.addEventListener('DOMContentLoaded', function() {
            // Modal close on outside click
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal')) {
                    closeModal(e.target.id);
                }
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Escape to close modal
                if (e.key === 'Escape') {
                    const reserveModal = document.getElementById('reserveModal');
                    if (reserveModal?.style.display === 'block') {
                        closeModal('reserveModal');
                    }
                }
                
                // Ctrl/Cmd + K to focus search
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    const searchInput = document.getElementById('searchInput');
                    if (searchInput) searchInput.focus();
                }
            });

            // Enhanced image error handling
            document.querySelectorAll('.book-image img').forEach(img => {
                img.addEventListener('error', function() {
                    this.style.display = 'none';
                    const parent = this.parentElement;
                    if (parent && !parent.querySelector('.fas.fa-book')) {
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-book';
                        parent.appendChild(icon);
                    }
                });
            });

            // Search form enhancement
            const searchForm = document.getElementById('searchForm');
            if (searchForm) {
                searchForm.addEventListener('submit', function(e) {
                    const searchInput = document.getElementById('searchInput');
                    if (searchInput && searchInput.value.trim() === '') {
                        e.preventDefault();
                        searchInput.focus();
                        showNotification('กรุณาใส่คำค้นหา', 'warning');
                    }
                });
            }

            // Add hover effects to book cards
            document.querySelectorAll('.book-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    if (!this.classList.contains('processing')) {
                        this.style.transform = 'translateY(-8px)';
                    }
                });
                
                card.addEventListener('mouseleave', function() {
                    if (!this.classList.contains('processing')) {
                        this.style.transform = 'translateY(0)';
                    }
                });
            });

            console.log('📚 ห้องสมุดดิจิทัล - System Initialized Successfully!');
        });

        // Global error handler
        window.addEventListener('error', function(e) {
            console.error('Global error:', e.error);
            showNotification('เกิดข้อผิดพลาดระบบ กรุณาลองรีเฟรชหน้า', 'error');
        });
    </script>
</body>
</html>