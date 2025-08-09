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

// Get all categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY category_name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all publishers for advanced filtering
$stmt = $pdo->query("SELECT DISTINCT p.publisher_id, p.publisher_name FROM publishers p 
                     INNER JOIN books b ON p.publisher_id = b.publisher_id 
                     ORDER BY p.publisher_name");
$publishers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all authors for advanced filtering
$stmt = $pdo->query("SELECT DISTINCT a.author_id, a.first_name, a.last_name FROM authors a 
                     INNER JOIN book_authors ba ON a.author_id = ba.author_id
                     ORDER BY a.first_name, a.last_name");
$authors = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    SELECT b.*, c.category_name, p.publisher_name,
           GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors,
           b.cover_image,
           -- เช็คว่าหนังสือถูกจองโดยใครบ้าง
           (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.book_id AND r.status = 'active') as reserved_count,
           -- เช็คว่าหนังสือถูกจองโดยผู้ใช้คนนี้หรือไม่
           (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.book_id AND r.user_id = ? AND r.status = 'active') as user_reserved
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
        RAND()
    LIMIT 6
");

$stmt->execute([$user_id]);
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

// Function to check if cover image exists
function checkCoverImageExists($cover_image) {
    if (!empty($cover_image)) {
        $image_path = "../" . $cover_image;
        return file_exists($image_path);
    }
    return false;
}
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
            max-width: 900px;
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

        .search-form-wrapper {
            position: relative;
            margin-bottom: 2rem;
        }

        .search-form {
            background: #f8f9fa;
            border-radius: 50px;
            padding: 10px;
            display: flex;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            position: relative;
            transition: all 0.3s ease;
        }

        .search-form.focused {
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.2);
            transform: translateY(-2px);
        }

        .search-input-wrapper {
            flex: 1;
            position: relative;
        }

        .search-input {
            width: 100%;
            border: none;
            padding: 1rem 1.5rem 1rem 3rem;
            font-size: 1.1rem;
            border-radius: 40px;
            outline: none;
            background: transparent;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            padding-left: 3.5rem;
        }

        .search-input-icon {
            position: absolute;
            left: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }

        .search-input:focus + .search-input-icon {
            left: 1.8rem;
            color: #764ba2;
        }

        .search-clear-btn {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .search-clear-btn.visible {
            opacity: 1;
            visibility: visible;
        }

        .search-clear-btn:hover {
            background: #f0f0f0;
            color: #667eea;
        }

        .search-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            z-index: 1000;
            margin-top: 0.5rem;
            max-height: 300px;
            overflow-y: auto;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .search-suggestions.visible {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .suggestion-item {
            padding: 1rem 1.5rem;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .suggestion-item:hover {
            background: #f8f9fa;
            padding-left: 2rem;
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-icon {
            color: #667eea;
            width: 20px;
        }

        .suggestion-text {
            flex: 1;
        }

        .suggestion-type {
            font-size: 0.8rem;
            color: #999;
            background: #f0f0f0;
            padding: 0.2rem 0.8rem;
            border-radius: 10px;
        }

        .advanced-filters {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .advanced-filters.expanded {
            border-color: #667eea;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.1);
        }

        .filters-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
            cursor: pointer;
        }

        .filters-toggle {
            background: none;
            border: none;
            color: #667eea;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .filters-toggle:hover {
            background: rgba(102, 126, 234, 0.1);
        }

        .filters-toggle i {
            transition: transform 0.3s ease;
        }

        .filters-toggle.expanded i {
            transform: rotate(180deg);
        }

        .filters-content {
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .filters-content.expanded {
            max-height: 500px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-select {
            padding: 0.8rem;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 0.9rem;
            outline: none;
            background: white;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .category-filters {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .category-btn {
            background: #f8f9fa;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 0.6rem 1.2rem;
            border-radius: 25px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
        }

        .category-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: left 0.3s ease;
            z-index: -1;
        }

        .category-btn:hover::before,
        .category-btn.active::before {
            left: 0;
        }

        .category-btn:hover,
        .category-btn.active {
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .results-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding: 1rem 0;
            border-bottom: 2px solid #f0f0f0;
        }

        .results-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .results-count {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }

        .results-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .active-filter {
            background: #667eea;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-remove {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            padding: 0;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s ease;
        }

        .filter-remove:hover {
            background: rgba(255,255,255,0.2);
        }

        .sort-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .sort-select {
            padding: 0.5rem 1rem;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            outline: none;
            background: white;
            transition: all 0.3s ease;
        }

        .sort-select:focus {
            border-color: #667eea;
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
            position: relative;
            overflow: hidden;
        }

        .quick-action::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            transition: left 0.3s ease;
            z-index: 0;
        }

        .quick-action:hover::before {
            left: 0;
        }

        .quick-action:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }

        .quick-action i,
        .quick-action h4,
        .quick-action p {
            position: relative;
            z-index: 1;
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
            opacity: 1;
            transform: scale(1);
        }

        .book-card.filtered-out {
            opacity: 0;
            transform: scale(0.8);
            pointer-events: none;
        }

        .book-card:hover {
            transform: translateY(-8px) scale(1.02);
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

        .book-fallback-icon {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: none;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            padding: 3rem 2rem;
            color: #666;
        }

        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 1rem;
        }

        .empty-state h4 {
            font-size: 1.4rem;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }

        .empty-state-suggestions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
        }

        .suggestion-chip {
            background: #e3f2fd;
            color: #1976d2;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .suggestion-chip:hover {
            background: #1976d2;
            color: white;
            transform: translateY(-2px);
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

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .search-suggestions {
                margin: 0 1rem;
            }

            .results-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .sort-controls {
                width: 100%;
                justify-content: space-between;
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
                <li><a href="#" class="active">หน้าแรก</a></li>
                <li><a href="dashboard.php">แดชบอร์ด</a></li>
                <li><a href="profile.php">โปรไฟล์</a></li>
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

    <!-- Enhanced Search Section -->
    <section class="search-section">
        <div class="search-container">
            <h2><i class="fas fa-search"></i> ค้นหาหนังสือ</h2>
            
            <div class="search-form-wrapper">
                <div class="search-form" id="searchForm">
                    <div class="search-input-wrapper">
                        <input type="text" class="search-input" id="searchInput"
                               placeholder="ค้นหาหนังสือ ผู้เขียน หรือ ISBN..." 
                               autocomplete="off">
                        <i class="fas fa-search search-input-icon"></i>
                        <button type="button" class="search-clear-btn" id="searchClearBtn">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Search Suggestions -->
                <div class="search-suggestions" id="searchSuggestions">
                    <!-- Dynamic suggestions will be populated here -->
                </div>
            </div>

            <!-- Advanced Filters -->
            <div class="advanced-filters" id="advancedFilters">
                <div class="filters-header">
                    <button class="filters-toggle" id="filtersToggle">
                        <i class="fas fa-filter"></i>
                        <span>ตัวกรองขั้นสูง</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                
                <div class="filters-content" id="filtersContent">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label class="filter-label">
                                <i class="fas fa-tag"></i> หมวดหมู่
                            </label>
                            <select class="filter-select" id="categoryFilter">
                                <option value="">ทุกหมวดหมู่</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['category_id']; ?>">
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">
                                <i class="fas fa-building"></i> สำนักพิมพ์
                            </label>
                            <select class="filter-select" id="publisherFilter">
                                <option value="">ทุกสำนักพิมพ์</option>
                                <?php foreach ($publishers as $publisher): ?>
                                    <option value="<?php echo $publisher['publisher_id']; ?>">
                                        <?php echo htmlspecialchars($publisher['publisher_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">
                                <i class="fas fa-user-edit"></i> ผู้เขียน
                            </label>
                            <select class="filter-select" id="authorFilter">
                                <option value="">ทุกผู้เขียน</option>
                                <?php foreach ($authors as $author): ?>
                                    <option value="<?php echo $author['author_id']; ?>">
                                        <?php echo htmlspecialchars($author['first_name'] . ' ' . $author['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">
                                <i class="fas fa-check-circle"></i> สถานะ
                            </label>
                            <select class="filter-select" id="statusFilter">
                                <option value="">ทุกสถานะ</option>
                                <option value="available">ว่าง</option>
                                <option value="unavailable">ไม่ว่าง</option>
                                <option value="reserved">ถูกจองแล้ว</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Category Filters -->
            <div class="category-filters" id="categoryFilters">
                <a href="#" class="category-btn active" data-category="">
                    <i class="fas fa-globe"></i> ทั้งหมด
                </a>
                <?php foreach (array_slice($categories, 0, 100) as $category): ?>
                    <a href="#" class="category-btn" data-category="<?php echo $category['category_id']; ?>">
                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($category['category_name']); ?>
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
                    <h3 id="sectionTitle">หนังสือแนะนำ</h3>
                </div>

                <!-- Results Header -->
                <div class="results-header" id="resultsHeader" style="display: none;">
                    <div class="results-info">
                        <div class="results-count" id="resultsCount">
                            พบ 0 เล่ม
                        </div>
                        <div class="results-filters" id="activeFilters">
                            <!-- Active filters will be shown here -->
                        </div>
                    </div>
                    <div class="sort-controls">
                        <label for="sortSelect">เรียงตาม:</label>
                        <select class="sort-select" id="sortSelect">
                            <option value="relevance">ความเกี่ยวข้อง</option>
                            <option value="title">ชื่อหนังสือ A-Z</option>
                            <option value="title_desc">ชื่อหนังสือ Z-A</option>
                            <option value="author">ผู้เขียน A-Z</option>
                            <option value="category">หมวดหมู่</option>
                            <option value="newest">ใหม่ล่าสุด</option>
                            <option value="available">ว่างก่อน</option>
                        </select>
                    </div>
                </div>

                <div class="books-grid" id="booksGrid">
                    <?php foreach ($recommended_books as $book): 
                        // Check if user already borrowed this book
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND book_id = ? AND status IN ('borrowed', 'overdue')");
                        $stmt->execute([$user_id, $book['book_id']]);
                        $already_borrowed = $stmt->fetchColumn() > 0;
                        
                        // ใช้ข้อมูลที่ดึงมาจาก query แล้ว
                        $already_reserved = $book['user_reserved'] > 0;
                        $book_reserved_by_others = $book['reserved_count'] > 0 && !$already_reserved;
                      
                        // Check if cover image exists
                        $has_cover_image = checkCoverImageExists($book['cover_image']);
                    ?>
                        <div class="book-card" 
                            data-book-id="<?php echo $book['book_id']; ?>"
                            data-title="<?php echo strtolower(htmlspecialchars($book['title'])); ?>"
                            data-author="<?php echo strtolower(htmlspecialchars($book['authors'] ?: '')); ?>"
                            data-category="<?php echo $book['category_id'] ?: ''; ?>"
                            data-category-name="<?php echo strtolower(htmlspecialchars($book['category_name'] ?: '')); ?>"
                            data-publisher="<?php echo $book['publisher_id'] ?: ''; ?>"
                            data-isbn="<?php echo strtolower(htmlspecialchars($book['isbn'] ?: '')); ?>"
                            data-status="<?php 
                                if ($book['status'] === 'reserved') {
                                    echo 'reserved';
                                } else {
                                    echo $book['available_copies'] > 0 ? 'available' : 'unavailable';
                                }
                            ?>">

                            
                            <div class="book-image">
                                <div class="book-availability">
                                    <?php echo $book['available_copies']; ?>/<?php echo $book['total_copies']; ?> เล่ม
                                </div>
                                <?php if ($has_cover_image): ?>
                                    <img src="../<?php echo htmlspecialchars($book['cover_image']); ?>" 
                                         alt="<?php echo htmlspecialchars($book['title']); ?>"
                                         onerror="handleImageError(this);">
                                    <div class="book-fallback-icon">
                                        <i class="fas fa-book"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="book-fallback-icon" style="display: flex;">
                                        <i class="fas fa-book"></i>
                                    </div>
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

                <!-- Empty State -->
                <div class="empty-state" id="emptyState" style="display: none;">
                    <i class="fas fa-search"></i>
                    <h4>ไม่พบหนังสือที่ค้นหา</h4>
                    <p>ลองปรับเปลี่ยนคำค้นหาหรือตัวกรอง</p>
                    <div class="empty-state-suggestions">
                        <a href="#" class="suggestion-chip" onclick="clearAllFilters()">ล้างตัวกรอง</a>
                        <a href="#" class="suggestion-chip" onclick="showPopularBooks()">หนังสือยอดนิยม</a>
                        <a href="#" class="suggestion-chip" onclick="showRecentBooks()">หนังสือใหม่</a>
                    </div
                </div>
            </div>
        </div>
    </div>  
    
             
          
    <script>
        // ========================
        // ENHANCED SEARCH SYSTEM
        // ========================
        let searchTimeout;
        let isSearching = false;
        let allBooks = <?php echo json_encode($recommended_books); ?>;
        let filteredBooks = [...allBooks];
        let activeFilters = {};

        // Initialize search functionality
        document.addEventListener('DOMContentLoaded', function() {
            initializeSearch();
            initializeFilters();
            initializeCategories();
            bindEventListeners();
        });

        function initializeSearch() {
            const searchInput = document.getElementById('searchInput');
            const searchClearBtn = document.getElementById('searchClearBtn');
            const searchForm = document.getElementById('searchForm');
            const searchSuggestions = document.getElementById('searchSuggestions');

            // Search input events
            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                // Show/hide clear button
                if (query.length > 0) {
                    searchClearBtn.classList.add('visible');
                } else {
                    searchClearBtn.classList.remove('visible');
                    hideSuggestions();
                }

                // Debounced search
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (query.length >= 2) {
                        performSearch(query);
                        showSuggestions(query);
                    } else if (query.length === 0) {
                        clearSearch();
                    }
                }, 300);
            });

            // Focus and blur events
            searchInput.addEventListener('focus', function() {
                searchForm.classList.add('focused');
                const query = this.value.trim();
                if (query.length >= 2) {
                    showSuggestions(query);
                }
            });

            searchInput.addEventListener('blur', function() {
                setTimeout(() => {
                    searchForm.classList.remove('focused');
                    hideSuggestions();
                }, 200);
            });

            // Clear button
            searchClearBtn.addEventListener('click', function() {
                searchInput.value = '';
                searchInput.focus();
                clearSearch();
                this.classList.remove('visible');
                hideSuggestions();
            });

            // Prevent form submission
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const query = this.value.trim();
                    if (query) {
                        performSearch(query);
                    }
                    hideSuggestions();
                }
            });
        }

        function performSearch(query) {
            if (isSearching) return;
            isSearching = true;

            // Update UI
            document.getElementById('sectionTitle').textContent = `ผลการค้นหา "${query}"`;
            document.getElementById('resultsHeader').style.display = 'flex';

            // Filter books based on search query
            const searchResults = allBooks.filter(book => {
                const searchableText = [
                    book.title || '',
                    book.authors || '',
                    book.category_name || '',
                    book.publisher_name || '',
                    book.isbn || ''
                ].join(' ').toLowerCase();

                return searchableText.includes(query.toLowerCase());
            });

            filteredBooks = searchResults;
            // เพิ่มบรรทัดนี้เพื่อให้แสดงเฉพาะหนังสือที่ค้นหาได้
            displayBooks(filteredBooks);
            updateResultsCount();

            isSearching = false;
        }

        function clearSearch() {
            document.getElementById('sectionTitle').textContent = 'หนังสือแนะนำ';
            document.getElementById('resultsHeader').style.display = 'none';
            
            filteredBooks = [...allBooks];
            // แสดงหนังสือทั้งหมดกลับมา
            const allCards = document.querySelectorAll('.book-card');
            allCards.forEach(card => {
                card.style.display = 'block';
            });
            document.getElementById('emptyState').style.display = 'none';
        }

        function showSuggestions(query) {
            const suggestions = generateSuggestions(query);
            const suggestionsContainer = document.getElementById('searchSuggestions');
            
            if (suggestions.length === 0) {
                hideSuggestions();
                return;
            }

            suggestionsContainer.innerHTML = suggestions.map(suggestion => `
                <div class="suggestion-item" onclick="selectSuggestion('${suggestion.text}', '${suggestion.type}')">
                    <i class="fas ${suggestion.icon} suggestion-icon"></i>
                    <span class="suggestion-text">${suggestion.text}</span>
                    <span class="suggestion-type">${suggestion.type}</span>
                </div>
            `).join('');

            suggestionsContainer.classList.add('visible');
        }

        function hideSuggestions() {
            const suggestionsContainer = document.getElementById('searchSuggestions');
            suggestionsContainer.classList.remove('visible');
        }

        function generateSuggestions(query) {
            const suggestions = [];
            const queryLower = query.toLowerCase();
            const maxSuggestions = 6;

            // Book titles
            const titleMatches = allBooks.filter(book => 
                book.title && book.title.toLowerCase().includes(queryLower)
            ).slice(0, 3);

            titleMatches.forEach(book => {
                suggestions.push({
                    text: book.title,
                    type: 'หนังสือ',
                    icon: 'fa-book'
                });
            });

            // Authors
            const authors = [...new Set(allBooks.map(book => book.authors).filter(Boolean))];
            const authorMatches = authors.filter(author => 
                author.toLowerCase().includes(queryLower)
            ).slice(0, 2);

            authorMatches.forEach(author => {
                suggestions.push({
                    text: author,
                    type: 'ผู้เขียน',
                    icon: 'fa-user'
                });
            });

            // Categories
            const categories = [...new Set(allBooks.map(book => book.category_name).filter(Boolean))];
            const categoryMatches = categories.filter(category => 
                category.toLowerCase().includes(queryLower)
            ).slice(0, 2);

            categoryMatches.forEach(category => {
                suggestions.push({
                    text: category,
                    type: 'หมวดหมู่',
                    icon: 'fa-tag'
                });
            });

            return suggestions.slice(0, maxSuggestions);
        }

        function selectSuggestion(text, type) {
            const searchInput = document.getElementById('searchInput');
            searchInput.value = text;
            performSearch(text);
            hideSuggestions();
        }

        // ========================
        // FILTER SYSTEM
        // ========================
        function initializeFilters() {
            const filtersToggle = document.getElementById('filtersToggle');
            const filtersContent = document.getElementById('filtersContent');
            const advancedFilters = document.getElementById('advancedFilters');

            filtersToggle.addEventListener('click', function() {
                const isExpanded = filtersContent.classList.contains('expanded');
                
                if (isExpanded) {
                    filtersContent.classList.remove('expanded');
                    advancedFilters.classList.remove('expanded');
                    filtersToggle.classList.remove('expanded');
                } else {
                    filtersContent.classList.add('expanded');
                    advancedFilters.classList.add('expanded');
                    filtersToggle.classList.add('expanded');
                }
            });

            // Filter change events
            ['categoryFilter', 'publisherFilter', 'authorFilter', 'statusFilter'].forEach(filterId => {
                const filterElement = document.getElementById(filterId);
                if (filterElement) {
                    filterElement.addEventListener('change', function() {
                        updateActiveFilter(filterId, this.value, this.options[this.selectedIndex].text);
                        applyFilters();
                    });
                }
            });

            // Sort change event
            const sortSelect = document.getElementById('sortSelect');
            if (sortSelect) {
                sortSelect.addEventListener('change', function() {
                    sortBooks(this.value);
                });
            }
        }

        function updateActiveFilter(filterId, value, text) {
            const filterKey = filterId.replace('Filter', '');
            
            if (value) {
                activeFilters[filterKey] = { value, text };
            } else {
                delete activeFilters[filterKey];
            }
            
            displayActiveFilters();
        }

        function displayActiveFilters() {
            const activeFiltersContainer = document.getElementById('activeFilters');
            
            const filterChips = Object.entries(activeFilters).map(([key, filter]) => `
                <div class="active-filter">
                    <span>${filter.text}</span>
                    <button class="filter-remove" onclick="removeActiveFilter('${key}')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `).join('');
            
            activeFiltersContainer.innerHTML = filterChips;
        }

        function removeActiveFilter(filterKey) {
            delete activeFilters[filterKey];
            
            // Reset corresponding filter select
            const filterElement = document.getElementById(filterKey + 'Filter');
            if (filterElement) {
                filterElement.value = '';
            }
            
            displayActiveFilters();
            applyFilters();
        }

        function applyFilters() {
            applyActiveFilters();
            displayBooks(filteredBooks);
            updateResultsCount();
        }

        function applyActiveFilters() {
            filteredBooks = allBooks.filter(book => {
                // Apply active filters
                for (const [filterKey, filter] of Object.entries(activeFilters)) {
                    switch (filterKey) {
                        case 'category':
                            if (book.category_id != filter.value) return false;
                            break;
                        case 'publisher':
                            if (book.publisher_id != filter.value) return false;
                            break;
                        case 'author':
                            // This would need to be implemented based on book-author relationships
                            break;
                        case 'status':
                            const isAvailable = book.available_copies > 0;
                            if (filter.value === 'available' && !isAvailable) return false;
                            if (filter.value === 'unavailable' && isAvailable) return false;
                            break;
                        case 'status':
                            const bookStatus = book.status;
                            const bookAvailable = book.available_copies > 0 && book.status !== 'reserved';
                            const bookReserved = book.status === 'reserved' || book.reserved_count > 0;
                            
                            if (filter.value === 'available' && !bookAvailable) return false;
                            if (filter.value === 'unavailable' && bookAvailable) return false;
                            if (filter.value === 'reserved' && !bookReserved) return false;
                            break;
                    }
                }
                
                return true;
            });
        }

        function clearAllFilters() {
            activeFilters = {};
            
            // Reset all filter selects
            ['categoryFilter', 'publisherFilter', 'authorFilter', 'statusFilter'].forEach(filterId => {
                const filterElement = document.getElementById(filterId);
                if (filterElement) filterElement.value = '';
            });
            
            displayActiveFilters();
            
            // Clear search
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.value = '';
                document.getElementById('searchClearBtn').classList.remove('visible');
            }
            
            // Reset to default view
            clearSearch();
        }

        // ========================
        // CATEGORY SYSTEM
        // ========================
        function initializeCategories() {
            const categoryBtns = document.querySelectorAll('.category-btn');
            
            categoryBtns.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Update active state
                    categoryBtns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Apply category filter
                    const categoryId = this.dataset.category;
                    applyCategoryFilter(categoryId);
                });
            });
        }

        function applyCategoryFilter(categoryId) {
            if (categoryId) {
                // Set category filter
                const categoryFilter = document.getElementById('categoryFilter');
                if (categoryFilter) {
                    categoryFilter.value = categoryId;
                    const selectedText = categoryFilter.options[categoryFilter.selectedIndex].text;
                    updateActiveFilter('categoryFilter', categoryId, selectedText);
                }
            } else {
                // Clear category filter
                delete activeFilters.category;
                const categoryFilter = document.getElementById('categoryFilter');
                if (categoryFilter) categoryFilter.value = '';
            }
            
            displayActiveFilters();
            applyFilters();
            
            // Update section title
            const categoryName = categoryId ? 
                document.querySelector(`[data-category="${categoryId}"]`).textContent.replace(/.*\s/, '') : 
                'ทั้งหมด';
            
            document.getElementById('sectionTitle').textContent = 
                categoryId ? `หมวดหมู่: ${categoryName}` : 'หนังสือแนะนำ';
            
            // Show/hide results header
            const resultsHeader = document.getElementById('resultsHeader');
            resultsHeader.style.display = categoryId ? 'flex' : 'none';
        }

        // ========================
        // BOOK DISPLAY SYSTEM
        // ========================
        function displayBooks(books) {
            const booksGrid = document.getElementById('booksGrid');
            const emptyState = document.getElementById('emptyState');
            const allCards = document.querySelectorAll('.book-card');
            
            if (books.length === 0) {
                // ซ่อนการ์ดทั้งหมด
                allCards.forEach(card => {
                    card.style.display = 'none';
                });
                emptyState.style.display = 'block';
                return;
            }
            
            emptyState.style.display = 'none';
            
            // ซ่อนการ์ดทั้งหมดก่อน
            allCards.forEach(card => {
                card.style.display = 'none';
            });
            
            // แสดงเฉพาะการ์ดที่ตรงกับผลการค้นหา
            books.forEach(book => {
                const card = document.querySelector(`[data-book-id="${book.book_id}"]`);
                if (card) {
                    card.style.display = 'block';
                }
            });
        }


        function updateResultsCount() {
            const resultsCount = document.getElementById('resultsCount');
            if (resultsCount) {
                resultsCount.textContent = `พบ ${filteredBooks.length.toLocaleString()} เล่ม`;
            }
        }

        function sortBooks(sortBy) {
            const sortedBooks = [...filteredBooks];
            
            switch (sortBy) {
                case 'title':
                    sortedBooks.sort((a, b) => a.title.localeCompare(b.title, 'th'));
                    break;
                case 'title_desc':
                    sortedBooks.sort((a, b) => b.title.localeCompare(a.title, 'th'));
                    break;
                case 'author':
                    sortedBooks.sort((a, b) => (a.authors || '').localeCompare(b.authors || '', 'th'));
                    break;
                case 'category':
                    sortedBooks.sort((a, b) => (a.category_name || '').localeCompare(b.category_name || '', 'th'));
                    break;
                case 'available':
                    sortedBooks.sort((a, b) => b.available_copies - a.available_copies);
                    break;
                case 'newest':
                    sortedBooks.sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
                    break;
                case 'relevance':
                default:
                    // Keep current order for relevance
                    break;
            }
            
            filteredBooks = sortedBooks;
            displayBooks(filteredBooks);
        }

        // ========================
        // QUICK ACTION FUNCTIONS
        // ========================
        function showPopularBooks() {
            clearAllFilters();
            // This would typically load popular books from server
            document.getElementById('sectionTitle').textContent = 'หนังสือยอดนิยม';
        }

        function showRecentBooks() {
            clearAllFilters();
            sortBooks('newest');
            document.getElementById('sectionTitle').textContent = 'หนังสือใหม่ล่าสุด';
        }

        // ========================
        // EVENT LISTENERS
        // ========================
        function bindEventListeners() {
            // Global keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + K to focus search
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    const searchInput = document.getElementById('searchInput');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }
                
                // Escape to clear search and filters
                if (e.key === 'Escape') {
                    const searchInput = document.getElementById('searchInput');
                    if (searchInput === document.activeElement) {
                        searchInput.blur();
                    } else {
                        clearAllFilters();
                    }
                }
            });

            // Smooth scroll for anchor links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });

            // Enhanced scroll effects
            let lastScrollTop = 0;
            window.addEventListener('scroll', function() {
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                const header = document.querySelector('.header');
                
                if (scrollTop > lastScrollTop && scrollTop > 100) {
                    // Scrolling down
                    header.style.transform = 'translateY(-100%)';
                } else {
                    // Scrolling up
                    header.style.transform = 'translateY(0)';
                }
                
                lastScrollTop = scrollTop;
            });

            console.log('🎉 Enhanced Library System Initialized Successfully!');
            console.log('🔍 Advanced search and filtering system ready');
            console.log('⌨️ Keyboard shortcuts: Ctrl+K (search), Escape (clear)');
        }

        // ========================
        // UTILITY FUNCTIONS
        // ========================
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        function throttle(func, limit) {
            let inThrottle;
            return function() {
                const args = arguments;
                const context = this;
                if (!inThrottle) {
                    func.apply(context, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            }
        }

        // ========================
        // ERROR HANDLING
        // ========================
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.error);
            showNotification('เกิดข้อผิดพลาดในระบบ กรุณาลองรีเฟรชหน้า', 'error');
        });

        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled Promise Rejection:', e.reason);
            showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่อีกครั้ง', 'error');
        });

        // ========================
        // MODAL MANAGEMENT FUNCTIONS
        // ========================

        let currentBookId = null;

        /**
         * แสดง Modal สำหรับการจองหนังสือ
         */
        function showReserveModal(bookId, title, author, category, isbn) {
            currentBookId = bookId;
            
            // อัพเดทข้อมูลหนังสือใน Modal
            document.getElementById('modal-book-title').textContent = title;
            document.getElementById('modal-book-author').innerHTML = `<i class="fas fa-user"></i> ${author}`;
            document.getElementById('modal-book-category').innerHTML = `<i class="fas fa-tag"></i> ${category}`;
            document.getElementById('modal-book-isbn').innerHTML = `<i class="fas fa-barcode"></i> ${isbn}`;
            
            // แสดง Modal
            const modal = document.getElementById('reserveModal');
            modal.style.display = 'block';
            
            // เพิ่ม animation effect
            setTimeout(() => {
                modal.style.opacity = '1';
            }, 10);
            
            // ป้องกันการ scroll ของ body
            document.body.style.overflow = 'hidden';
        }

        /**
         * ปิด Modal
         */
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                currentBookId = null;
            }
        }

        /**
         * ปิด Modal เมื่อคลิกนอกพื้นที่ Modal
         */
        window.onclick = function(event) {
            const modal = document.getElementById('reserveModal');
            if (event.target === modal) {
                closeModal('reserveModal');
            }
        }

        // ปิด Modal ด้วยปุ่ม ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modal = document.getElementById('reserveModal');
                if (modal && modal.style.display === 'block') {
                    closeModal('reserveModal');
                }
            }
        });

        // ========================
        // BOOK RESERVATION FUNCTIONS
        // ========================

        /**
         * ยืนยันการจองหนังสือ
         */
        async function confirmReserve() {
            if (!currentBookId) {
                showNotification('เกิดข้อผิดพลาด: ไม่พบข้อมูลหนังสือ', 'error');
                return;
            }

            showLoading();
            
            const confirmBtn = document.getElementById('confirm-reserve-btn');
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังประมวลผล...';

            try {
                const response = await fetch('reserve_book.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        book_id: currentBookId,
                        action: 'reserve'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showNotification('จองหนังสือสำเร็จ! รอการอนุมัติจากแอดมิน', 'success');
                    
                    // อัพเดท UI ทันที
                    updateBookCardAfterReserve(currentBookId);
                    updateUserStats();
                    
                    // รีเฟรชข้อมูลหนังสือเพื่อให้แน่ใจว่าข้อมูลล่าสุด
                    await refreshBookDisplay();
                    
                    closeModal('reserveModal');
                    
                } else {
                    throw new Error(result.message || 'เกิดข้อผิดพลาดในการจองหนังสือ');
                }

            } catch (error) {
                console.error('Reservation Error:', error);
                showNotification(error.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
            } finally {
                hideLoading();
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-bookmark"></i> ยืนยันการจอง';
            }
        }

        /**
         * อัพเดทการ์ดหนังสือหลังจากจองสำเร็จ
         */
        function updateBookCardAfterReserve(bookId) {
            const bookCard = document.querySelector(`[data-book-id="${bookId}"]`);
            if (bookCard) {
                // อัพเดทสถานะของการ์ด
                bookCard.setAttribute('data-status', 'reserved');
                
                // อัพเดทปุ่มจอง
                const reserveBtn = bookCard.querySelector('.btn-reserve');
                if (reserveBtn) {
                    reserveBtn.className = 'btn-reserve reserved';
                    reserveBtn.disabled = true;
                    reserveBtn.innerHTML = '<i class="fas fa-bookmark"></i> จองแล้ว';
                }
                
                // อัพเดทสถานะในข้อมูล allBooks array สำหรับการค้นหา/กรอง
                const bookIndex = allBooks.findIndex(book => book.book_id == bookId);
                if (bookIndex !== -1) {
                    allBooks[bookIndex].user_reserved = 1;
                    allBooks[bookIndex].reserved_count = 1;
                }
                
                // อัพเดท filteredBooks array ถ้าหนังสือนี้อยู่ในผลการค้นหาปัจจุบัน
                const filteredIndex = filteredBooks.findIndex(book => book.book_id == bookId);
                if (filteredIndex !== -1) {
                    filteredBooks[filteredIndex].user_reserved = 1;
                    filteredBooks[filteredIndex].reserved_count = 1;
                }
            }
        }

        // เพิ่มฟังก์ชันสำหรับรีเฟรช UI หลังจากการจอง
        async function refreshBookDisplay() {
            try {
                const response = await fetch('get_updated_books.php');
                const result = await response.json();
                
                if (result.success) {
                    // อัพเดทข้อมูลหนังสือ
                    allBooks = result.books;
                    
                    // หากมีการค้นหาหรือกรองอยู่ ให้ทำการกรองใหม่
                    const searchInput = document.getElementById('searchInput');
                    if (searchInput.value.trim()) {
                        performSearch(searchInput.value.trim());
                    } else if (Object.keys(activeFilters).length > 0) {
                        applyFilters();
                    } else {
                        // แสดงหนังสือทั้งหมดใหม่
                        displayBooks(allBooks);
                    }
                }
            } catch (error) {
                console.error('Error refreshing book display:', error);
            }
        }

        /**
         * อัพเดทสถิติผู้ใช้
         */
        async function updateUserStats() {
            try {
                const response = await fetch('get_user_stats.php');
                const stats = await response.json();
                
                if (stats.success) {
                    // อัพเดทจำนวนการจอง
                    const reservationsCount = document.getElementById('user-reservations-count');
                    if (reservationsCount) {
                        reservationsCount.textContent = stats.data.reservations_count || 0;
                    }
                }
            } catch (error) {
                console.error('Error updating user stats:', error);
            }
        }

        // ========================
        // NOTIFICATION SYSTEM
        // ========================

        /**
         * แสดงการแจ้งเตือน
         */
        function showNotification(message, type = 'success') {
            // ลบ notification เก่าถ้ามี
            const existingNotification = document.querySelector('.notification');
            if (existingNotification) {
                existingNotification.remove();
            }

            // สร้าง notification ใหม่
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            // เลือก icon ตามประเภท
            let icon = 'fas fa-check-circle';
            switch (type) {
                case 'error':
                    icon = 'fas fa-exclamation-circle';
                    break;
                case 'warning':
                    icon = 'fas fa-exclamation-triangle';
                    break;
                case 'info':
                    icon = 'fas fa-info-circle';
                    break;
            }
            
            notification.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
            `;

            // เพิ่มเข้า DOM
            document.body.appendChild(notification);

            // แสดง notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);

            // ซ่อนหลังจาก 5 วินาที
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 5000);
        }

        // ========================
        // LOADING OVERLAY FUNCTIONS
        // ========================

        /**
         * แสดง Loading overlay
         */
        function showLoading(message = 'กำลังดำเนินการ...') {
            const loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) {
                const loadingText = loadingOverlay.querySelector('p');
                if (loadingText) {
                    loadingText.textContent = message;
                }
                loadingOverlay.classList.add('show');
            }
        }

        /**
         * ซ่อน Loading overlay
         */
        function hideLoading() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) {
                loadingOverlay.classList.remove('show');
            }
        }

        // ========================
        // IMAGE ERROR HANDLING
        // ========================

        /**
         * จัดการกรณีที่รูปภาพโหลดไม่ได้
         */
        function handleImageError(img) {
            // ซ่อนรูปภาพ
            img.style.display = 'none';
            
            // แสดง fallback icon
            const fallbackIcon = img.parentElement.querySelector('.book-fallback-icon');
            if (fallbackIcon) {
                fallbackIcon.style.display = 'flex';
            }
        }

        // ========================
        // UTILITY FUNCTIONS
        // ========================

        /**
         * ตรวจสอบการเชื่อมต่อเครือข่าย
         */
        function checkNetworkConnection() {
            if (!navigator.onLine) {
                showNotification('ไม่มีการเชื่อมต่ออินเทอร์เน็ต กรุณาตรวจสอบการเชื่อมต่อ', 'error');
                return false;
            }
            return true;
        }

        /**
         * Format วันที่เป็นภาษาไทย
         */
        function formatThaiDate(date) {
            const options = { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                locale: 'th-TH'
            };
            return new Date(date).toLocaleDateString('th-TH', options);
        }

        /**
         * เลื่อนไปยังตำแหน่งที่กำหนด
         */
        function scrollToElement(elementId, offset = 0) {
            const element = document.getElementById(elementId);
            if (element) {
                const elementPosition = element.offsetTop - offset;
                window.scrollTo({
                    top: elementPosition,
                    behavior: 'smooth'
                });
            }
        }

        // ========================
        // EVENT LISTENERS
        // ========================

        // เมื่อ DOM โหลดเสร็จ
        document.addEventListener('DOMContentLoaded', function() {
            console.log('📚 Book Reservation System Initialized');
            
            // ตรวจสอบการเชื่อมต่อเครือข่าย
            window.addEventListener('online', function() {
                showNotification('เชื่อมต่ออินเทอร์เน็ตแล้ว', 'success');
            });
            
            window.addEventListener('offline', function() {
                showNotification('ขาดการเชื่อมต่ออินเทอร์เน็ต', 'warning');
            });
        });

        // ========================
        // ERROR HANDLING
        // ========================

        /**
         * จัดการ errors ที่ไม่คาดคิด
         */
        window.addEventListener('error', function(event) {
            console.error('JavaScript Error:', event.error);
            
            // แสดงข้อความข้อผิดพลาดให้ผู้ใช้ (เฉพาะใน development)
            if (window.location.hostname === 'localhost') {
                showNotification(`Error: ${event.error.message}`, 'error');
            }
        });

        /**
         * จัดการ Promise rejections
         */
        window.addEventListener('unhandledrejection', function(event) {
            console.error('Unhandled Promise Rejection:', event.reason);
            showNotification('เกิดข้อผิดพลาดในการประมวลผล กรุณาลองใหม่อีกครั้ง', 'error');
        });

        // ========================
        // PERFORMANCE MONITORING
        // ========================

        // วัดเวลาในการโหลดหน้า
        window.addEventListener('load', function() {
            if (window.performance) {
                const loadTime = window.performance.timing.loadEventEnd - window.performance.timing.navigationStart;
                console.log(`⚡ Page loaded in ${loadTime}ms`);
            }
        });
        

        // ========================
        // ACCESSIBILITY ENHANCEMENTS
        // ========================

        // เพิ่ม keyboard navigation สำหรับ modal
        document.addEventListener('keydown', function(event) {
            const modal = document.getElementById('reserveModal');
            if (modal && modal.style.display === 'block') {
                // Tab trap inside modal
                if (event.key === 'Tab') {
                    const focusableElements = modal.querySelectorAll(
                        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
                    );
                    const firstElement = focusableElements[0];
                    const lastElement = focusableElements[focusableElements.length - 1];
                    
                    if (event.shiftKey && document.activeElement === firstElement) {
                        event.preventDefault();
                        lastElement.focus();
                    } else if (!event.shiftKey && document.activeElement === lastElement) {
                        event.preventDefault();
                        firstElement.focus();
                    }
                }
            }
        });
    </script>

    <!-- Additional Scripts -->
    <script>
        // Performance monitoring
        window.addEventListener('load', function() {
            if ('performance' in window) {
                const loadTime = performance.now();
                console.log(`📊 Page loaded in ${loadTime.toFixed(2)}ms`);
            }
        });

        // Service Worker registration (for future PWA features)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                // Uncomment when service worker is implemented
                // navigator.serviceWorker.register('/sw.js');
            });
        }
    </script>
</body>
</html>