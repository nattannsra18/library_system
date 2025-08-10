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

// Get user's current borrowed books count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND status IN ('borrowed', 'overdue')");
$stmt->execute([$user_id]);
$user_borrowed_count = $stmt->fetchColumn();

// Get user's total borrowed books count (all time)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ?");
$stmt->execute([$user_id]);
$user_total_borrowed = $stmt->fetchColumn();

// Get user's reservations count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'active'");
$stmt->execute([$user_id]);
$user_reservations = $stmt->fetchColumn();

// Get recommended books with proper available copies calculation
$stmt = $pdo->prepare("
    SELECT b.*, c.category_name, p.publisher_name,
           GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors,
           b.cover_image,
           -- Calculate available copies properly
           CASE 
               WHEN b.status = 'available' THEN 
                   GREATEST(0, b.total_copies - (
                       SELECT COUNT(*) FROM borrowing br 
                       WHERE br.book_id = b.book_id AND br.status IN ('borrowed', 'overdue')
                   ))
               ELSE 0
           END as available_copies,
           -- Count active reservations
           (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.book_id AND r.status = 'active') as reserved_count,
           -- Check if current user has reserved this book
           (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.book_id AND r.user_id = ? AND r.status = 'active') as user_reserved
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN publishers p ON b.publisher_id = p.publisher_id
    LEFT JOIN book_authors ba ON b.book_id = ba.book_id
    LEFT JOIN authors a ON ba.author_id = a.author_id
    WHERE b.status IN ('available', 'unavailable', 'reserved')
    GROUP BY b.book_id, b.title, b.isbn, b.publisher_id, b.category_id, b.status, b.total_copies, b.cover_image, c.category_name, p.publisher_name
    ORDER BY 
        CASE 
            WHEN b.status = 'available' THEN 1 
            WHEN b.status = 'reserved' THEN 2
            ELSE 3 
        END,
        RAND()
    LIMIT 12
");

$stmt->execute([$user_id]);
$recommended_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    return !empty($cover_image) && file_exists("../" . $cover_image);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าแรก - ห้องสมุดดิจิทัล</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            /* Primary Gradients */
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            --success-gradient: linear-gradient(135deg, #28a745, #20c997);
            --warning-gradient: linear-gradient(135deg, #ffc107, #ffca2c);
            --danger-gradient: linear-gradient(135deg, #dc3545, #e55353);
            --info-gradient: linear-gradient(135deg, #17a2b8, #20c997);

            /* Background Colors */
            --bg-body: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            --bg-card: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            --bg-glass: rgba(255, 255, 255, 0.95);

            /* Text Colors */
            --text-primary: #2d3748;
            --text-secondary: #4a5568;
            --text-muted: #718096;
            --text-light: #a0aec0;

            /* Component Colors */
            --shadow-light: 0 4px 16px rgba(139, 95, 191, 0.1);
            --shadow-medium: 0 8px 25px rgba(139, 95, 191, 0.15);
            --shadow-strong: 0 15px 35px rgba(139, 95, 191, 0.2);
            --border-light: rgba(139, 95, 191, 0.1);
            --border-medium: rgba(139, 95, 191, 0.2);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: var(--text-primary);
            background: var(--bg-body);
            min-height: 100vh;
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
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
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

        .logo i { margin-right: 10px; font-size: 2rem; }

        .nav-links {
            display: flex;
            list-style: none;
            gap: 2rem;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .nav-links a:hover, .nav-links a.active {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.8rem 1.5rem;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 25px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-logout:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
        }

        /* Dashboard Container */
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            margin-top: 100px;
            padding-bottom: 4rem;
        }

        /* Enhanced tooltips for status */
        .status-badge[title] {
            position: relative;
            cursor: help;
        }

        .status-badge[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(33, 37, 41, 0.95);
            color: white;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 5px;
            backdrop-filter: blur(5px);
        }

        .status-badge[title]:hover::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: rgba(33, 37, 41, 0.95);
            margin-bottom: -5px;
            z-index: 1000;
        }d Modal Styles for Accurate Status Display */

        /* User Status Alert Styles */
        .user-alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            border-left: 4px solid;
        }

        .user-alert.success {
            background-color: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }

        .user-alert.warning {
            background-color: #fff3cd;
            color: #856404;
            border-left-color: #ffc107;
        }

        .user-alert.info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-left-color: #17a2b8;
        }

        .alert-icon {
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .alert-message {
            flex: 1;
        }

        /* Enhanced Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-available {
            background-color: #d4edda;
            color: #155724;
        }

        .status-unavailable {
            background-color: #f8d7da;
            color: #721c24;
        }

        .status-reserved {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-user-reserved {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            animation: pulse 2s ease-in-out infinite;
        }

        .status-user-borrowed {
            background: linear-gradient(135deg, #ffc107, #ffca2c);
            color: white;
            font-weight: 700;
        }

        .status-damaged {
            background-color: #f5c6cb;
            color: #721c24;
        }

        .status-lost {
            background-color: #d6d8db;
            color: #383d41;
        }

        /* User Status in Meta */
        .user-status {
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.85rem;
        }

        .user-status.user-reserved {
            background: #d4edda;
            color: #155724;
        }

        .user-status.user-borrowed {
            background: #fff3cd;
            color: #856404;
        }

        .user-status.user-limit {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* Welcome Section */
        .welcome-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2.5rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            display: flex;
            flex-direction: column; /* เปลี่ยนจาก row เป็น column */
            align-items: center; /* จัดให้อยู่ตรงกลางแนวนอน */
            justify-content: center; /* จัดให้อยู่ตรงกลางแนวตั้ง */
            text-align: center; /* จัดข้อความให้อยู่กึ่งกลาง */
            gap: 2rem;
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
            min-height: 200px; /* กำหนดความสูงขั้นต่ำ */
        }


        .welcome-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(2deg); }
        }

        .profile-img {
            width: 80px;
            height: 80px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            flex-shrink: 0;
            box-shadow: var(--shadow-medium);
            position: relative;
            z-index: 2;
        }

        .welcome-text {
            position: relative;
            z-index: 2;
            text-align: center; /* จัดข้อความให้อยู่กึ่งกลาง */
        }

        .welcome-text h1 {
            font-size: 2.8rem;
            margin-bottom: 0.8rem;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center; /* บังคับให้หัวข้ออยู่กึ่งกลาง */
        }

        .welcome-text p { 
            opacity: 0.95; 
            font-size: 1.1rem; 
            margin-bottom: 0.3rem;
            text-align: center; /* บังคับให้ข้อความย่อยอยู่กึ่งกลาง */
        }

        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            text-align: center;
            border: 1px solid var(--border-light);
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.6s ease;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-strong);
        }

        .stat-card:hover::before {
            left: 100%;
        }

        .stat-card.borrowed { box-shadow: 0 8px 25px rgba(40, 167, 69, 0.2); }
        .stat-card.returned { box-shadow: 0 8px 25px rgba(40, 167, 69, 0.2); }
        .stat-card.overdue { box-shadow: 0 8px 25px rgba(40, 167, 69, 0.2); }
        .stat-card.pending { box-shadow: 0 8px 25px rgba(40, 167, 69, 0.2); }
        .stat-card.reserved { box-shadow: 0 8px 25px rgba(40, 167, 69, 0.2); }

        .stat-card i {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
        }

        .stat-card.borrowed i { color: #667eea; }
        .stat-card.returned i { color: #28a745; }
        .stat-card.overdue i { color: #dc3545; }
        .stat-card.pending i { color: #ffc107; }
        .stat-card.reserved i { color: #764ba2; }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .stat-label {
            font-size: 1rem;
            color: var(--text-secondary);
            font-weight: 500;
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

        /* Card */
        .card {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-medium);
            margin-bottom: 2rem;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-strong);
        }

        .card-header {
            background: var(--primary-gradient);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
        }

        .card-header i {
            font-size: 1.5rem;
        }

        .card-content {
            padding: 2rem;
        }

        /* Search Form */
        .search-form-wrapper {
            position: relative;
            margin-bottom: 2rem;
        }

        .search-form {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 8px;
            display: flex;
            box-shadow: var(--shadow-medium);
            position: relative;
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            border: 2px solid var(--border-light);
        }

        .search-form.focused {
            box-shadow: var(--shadow-strong);
            transform: translateY(-4px);
            border-color: var(--border-medium);
        }

        .search-input-wrapper {
            flex: 1;
            position: relative;
        }

        .search-input {
            width: 100%;
            border: none;
            padding: 1.4rem 1.8rem 1.4rem 4rem;
            font-size: 1.1rem;
            border-radius: 12px;
            outline: none;
            background: transparent;
            transition: all 0.3s ease;
            font-weight: 500;
            color: var(--text-primary);
        }

        .search-input:focus {
            padding-left: 4.5rem;
        }

        .search-input::placeholder {
            color: var(--text-muted);
            font-weight: 400;
        }

        .search-input-icon {
            position: absolute;
            left: 1.8rem;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 1.4rem;
            transition: all 0.3s ease;
        }

        .search-input:focus + .search-input-icon {
            left: 2.2rem;
            color: #764ba2;
            transform: translateY(-50%) scale(1.1);
        }

        .search-clear-btn {
            position: absolute;
            right: 1.4rem;
            top: 50%;
            transform: translateY(-50%);
            background: #f8f9fa;
            border: none;
            color: #667eea;
            cursor: pointer;
            padding: 0.8rem;
            border-radius: 50%;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .search-clear-btn.visible {
            opacity: 1;
            visibility: visible;
        }

        .search-clear-btn:hover {
            background: #667eea;
            color: white;
            transform: translateY(-50%) scale(1.1);
        }

        /* Advanced Filters */
        .advanced-filters {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-medium);
        }

        .filters-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-light);
        }

        .filters-header h3 {
            font-size: 1.6rem;
            color: var(--text-primary);
            font-weight: 600;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .filters-header i {
            color: #667eea;
            font-size: 1.6rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .filter-label i {
            color: #667eea;
        }

        .filter-select {
            padding: 1.2rem 1.5rem;
            border: 2px solid var(--border-light);
            border-radius: 12px;
            font-size: 1rem;
            outline: none;
            background: white;
            transition: all 0.3s ease;
            font-weight: 500;
            color: var(--text-primary);
        }

        .filter-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        /* Books Grid */
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        /* Book Item */
        .book-item {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--shadow-medium);
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            position: relative;
            border: 1px solid var(--border-light);
        }

        .book-item.filtered-out {
            opacity: 0;
            transform: scale(0.8);
            pointer-events: none;
        }

        .book-item:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: var(--shadow-strong);
        }

        .book-cover {
            height: 220px;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3.5rem;
            position: relative;
            overflow: hidden;
        }

        .book-cover::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 30% 30%, rgba(255,255,255,0.15) 0%, transparent 60%);
            opacity: 0.8;
        }

        .book-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: all 0.4s ease;
        }

        .book-item:hover .book-cover img {
            transform: scale(1.1);
        }

        .book-availability {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(0,0,0,0.85);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            font-size: 0.85rem;
            z-index: 2;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .book-details {
            padding: 2rem;
        }

        .book-details h4 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-primary);
            line-height: 1.4;
            height: 3.6rem;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .book-details p {
            color: var(--text-secondary);
            margin-bottom: 0.8rem;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .book-details i {
            color: #667eea;
            width: 16px;
        }

        .book-category {
            display: inline-block;
            background: #f8f9fa;
            color: #667eea;
            padding: 0.6rem 1.4rem;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            font-weight: 600;
            border: 1px solid var(--border-light);
        }

        .book-actions {
            display: flex;
            gap: 1rem;
        }

        /* Due Date Badges */
        .due-date {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            flex: 1;
            justify-content: center;
        }

        .due-date.normal { background: var(--success-gradient); color: white; }
        .due-date.due-soon { background: var(--warning-gradient); color: white; }
        .due-date.overdue { background: var(--danger-gradient); color: white; }
        .due-date.pending-return { background: var(--info-gradient); color: white; }
        .due-date.reserved-status { background: var(--primary-gradient); color: white; }
        .due-date.reserved-status i {color: white !important; }
        .due-date.overdue i {
            color: white !important;
        }

        .due-date.normal i {
            color: white !important;
        }

        .due-date.due-soon i {
            color: white !important;
        }

        .due-date.pending-return i {
            color: white !important;
        }

        .due-date.reserved-status i {
            color: white !important;
}
        /* Buttons */
        .btn-return, .btn-cancel {
            flex: 1;
            border: none;
            padding: 1.2rem 1.5rem;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
            font-weight: 600;
            text-decoration: none;
        }

        .btn-return {
            flex: 1;
            border: none;
            padding: 1.2rem 1.5rem;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            background: var(--success-gradient);
            color: white; /* ตัวอักษรสีขาว */
            box-shadow: 0 6px 18px rgba(40, 167, 69, 0.3);
        }

        .btn-return i {
            color: white !important; /* บังคับให้ไอคอนเป็นสีขาว */
        }

        .btn-return:hover:not(:disabled) {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
        }

        .btn-cancel {
            background: var(--danger-gradient);
            color: white;
            box-shadow: 0 6px 18px rgba(220, 53, 69, 0.3);
        }

        .btn-cancel:hover:not(:disabled) {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.4);
        }

        .btn-return:disabled, .btn-cancel:disabled {
            background: linear-gradient(135deg, #E0E0E0, #BDBDBD);
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none;
            color: var(--text-muted);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(45, 45, 45, 0.8);
            backdrop-filter: blur(12px);
        }

        .modal-content {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            margin: 5% auto;
            border-radius: 24px;
            width: 90%;
            max-width: 650px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.3);
            animation: modalAppear 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            overflow: hidden;
            border: 1px solid var(--border-light);
        }

        @keyframes modalAppear {
            from { opacity: 0; transform: translateY(-50px) scale(0.9); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-header {
            background: var(--primary-gradient);
            color: white;
            padding: 2rem;
            position: relative;
        }

        .modal-header i {
            color: white !important; /* ไอคอนใน header ของ modal สีขาว */
        }        

        .modal-header h3 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .close {
            position: absolute;
            right: 2rem;
            top: 50%;
            transform: translateY(-50%);
            color: white;
            font-size: 2rem;
            font-weight: 300;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-50%) rotate(90deg) scale(1.1);
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-book-info {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 16px;
            border-left: 4px solid #667eea;
        }

        .modal-book-cover {
            width: 80px;
            height: 100px;
            background: var(--primary-gradient);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            box-shadow: var(--shadow-medium);
            flex-shrink: 0;
        }

        .modal-book-details h4 {
            color: var(--text-primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .modal-book-details p {
            margin: 0.5rem 0;
            color: var(--text-secondary);
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .modal-book-details i {
            color: #667eea;
            width: 16px;
        }

        .info-box {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.08), rgba(32, 201, 151, 0.05));
            border: 2px solid rgba(40, 167, 69, 0.15);
            border-left: 4px solid #28a745;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }

        .warning-box {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.08), rgba(255, 202, 44, 0.05));
            border-color: rgba(255, 193, 7, 0.15);
            border-left-color: #ffc107;
        }

        .info-box h4 {
            color: #28a745;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .warning-box h4 {
            color: #ffc107;
        }

        .info-box p {
            margin: 0.8rem 0;
            color: var(--text-secondary);
            font-size: 1rem;
            line-height: 1.6;
            padding-left: 1.5rem;
            position: relative;
        }

        .info-box p::before {
            content: "•";
            color: #28a745;
            position: absolute;
            left: 0.5rem;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .warning-box p::before {
            color: #ffc107;
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
            border-radius: 12px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            min-width: 140px;
            justify-content: center;
        }

        .btn-modal-secondary {
            background: rgba(108, 117, 125, 0.1);
            color: var(--text-secondary);
            border: 2px solid rgba(108, 117, 125, 0.2);
        }

        .btn-modal-secondary:hover {
            background: rgba(108, 117, 125, 0.15);
            transform: translateY(-2px);
        }

        .btn-modal-primary {
            background: var(--primary-gradient);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .btn-modal-primary i {
            color: white !important; 
}        
        .btn-modal-primary:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: var(--shadow-strong);
        }

        .btn-modal-danger {
            background: var(--danger-gradient);
            color: white;
            box-shadow: 0 6px 18px rgba(220, 53, 69, 0.3);
        }

        .btn-modal-danger:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.4);
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 100px;
            right: 25px;
            padding: 1.5rem 2rem;
            border-radius: 16px;
            color: white;
            font-weight: 600;
            z-index: 9999;
            transform: translateX(100%);
            transition: transform 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            display: flex;
            align-items: center;
            gap: 1rem;
            max-width: 400px;
            box-shadow: var(--shadow-strong);
            backdrop-filter: blur(15px);
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success { background: var(--success-gradient); }
        .notification.error { background: var(--danger-gradient); }
        .notification.warning { background: var(--warning-gradient); }
        .notification.info { background: var(--info-gradient); }

        /* Loading */
        .loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(45,45,45,0.9);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            color: white;
            flex-direction: column;
            backdrop-filter: blur(8px);
        }

        .loading.show {
            display: flex;
        }

        .loading-content {
            text-align: center;
        }

        .spinner {
            border: 4px solid rgba(255,255,255,0.3);
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin-bottom: 1.5rem;
            margin: 0 auto 1.5rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading p {
            font-size: 1.1rem;
            font-weight: 500;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 5rem;
            background: linear-gradient(135deg, #E0E0E0, #BDBDBD);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1.5rem;
            display: block;
        }

        .empty-state h4 {
            font-size: 1.6rem;
            margin-bottom: 1rem;
            color: var(--text-primary);
            font-weight: 600;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }

        .empty-state-suggestions {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
        }

        .suggestion-chip {
            background: #f8f9fa;
            color: #667eea;
            padding: 0.8rem 1.5rem;
            border-radius: 25px;
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            border: 1px solid var(--border-light);
            font-weight: 600;
        }

        .suggestion-chip:hover {
            background: var(--primary-gradient);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
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
            font-size: 3.5rem;
            background: var(--primary-gradient);
        }

        /* Search Suggestions */
        .search-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            border-radius: 12px;
            margin-top: 8px;
            box-shadow: var(--shadow-strong);
            border: 1px solid var(--border-light);
            max-height: 300px;
            overflow-y: auto;
            z-index: 100;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .search-suggestions.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .search-suggestion {
            padding: 1rem 1.5rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-light);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .search-suggestion:hover {
            background: #f8f9fa;
            color: #667eea;
        }

        .search-suggestion:last-child {
            border-bottom: none;
        }

        .search-suggestion i {
            color: #667eea;
            width: 16px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .nav-links { display: none; }
            .dashboard-container { 
                padding: 0 1rem; 
                margin-top: 80px; 
            }
            .welcome-section {
                flex-direction: column;
                text-align: center;
                padding: 2rem;
            }
            .welcome-text h1 { font-size: 2rem; }
            .books-grid { grid-template-columns: 1fr; }
            .quick-actions { grid-template-columns: 1fr; }
            .quick-stats { grid-template-columns: repeat(2, 1fr); }
            .modal-content { width: 95%; margin: 10% auto; }
            .modal-book-info { flex-direction: column; text-align: center; }
            .modal-actions { flex-direction: column; }
            .filter-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            .welcome-section { padding: 1.5rem; }
            .welcome-text h1 { font-size: 1.8rem; }
            .stat-card { padding: 1.5rem; }
            .card-content { padding: 1.5rem; }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <p>กำลังดำเนินการ...</p>
        </div>
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
                    <h4><i class="fas fa-info-circle"></i> ขั้นตอนการจองหนังสือ</h4>
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

    <?php include 'book_details_modal.php'; ?>

    <script src="book_details.js"></script>
    
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

    <!-- Dashboard Container -->
    <div class="dashboard-container">
        <!-- Welcome Section -->
        <section class="welcome-section">
            <div class="welcome-text">
                <h1>ยินดีต้อนรับ <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>!</h1>
                <p>ห้องสมุดดิจิทัล วิทยาลัยเทคนิคหาดใหญ่</p>
            </div>
        </section>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="dashboard.php" class="quick-action">
                <i class="fas fa-tachometer-alt"></i>
                <h4>แดชบอร์ด</h4>
                <p>ดูสถานะการยืม ประวัติการใช้งาน และข้อมูลส่วนตัวของคุณ</p>
            </a>
            <a href="profile.php" class="quick-action">
                <i class="fas fa-user-cog"></i>
                <h4>แก้ไขโปรไฟล์</h4>
                <p>จัดการข้อมูลส่วนตัว เปลี่ยนรหัสผ่าน และการตั้งค่าต่างๆ</p>
            </a>
        </div>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card borrowed">
                <i class="fas fa-book-reader"></i>
                <div class="stat-number"><?php echo $user_borrowed_count; ?>/<?php echo $max_borrow_books; ?></div>
                <div class="stat-label">กำลังยืมอยู่</div>
            </div>
            <div class="stat-card reserved">
                <i class="fas fa-bookmark"></i>
                <div class="stat-number" id="user-reservations-count"><?php echo $user_reservations; ?></div>
                <div class="stat-label">กำลังจองอยู่</div>
            </div>
            <div class="stat-card returned">
                <i class="fas fa-history"></i>
                <div class="stat-number"><?php echo $user_total_borrowed; ?></div>
                <div class="stat-label">ยืมทั้งหมด</div>
            </div>
            <div class="stat-card pending">
                <i class="fas fa-calendar-day"></i>
                <div class="stat-number"><?php echo $max_borrow_days; ?></div>
                <div class="stat-label">วันที่อนุญาตให้ยืม</div>
            </div>
        </div>

        <!-- Search Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-search"></i>
                <h3>ค้นหาหนังสือ</h3>
            </div>
            <div class="card-content">
                <!-- Enhanced Search Form -->
                <div class="search-form-wrapper">
                    <div class="search-form" id="searchForm">
                        <div class="search-input-wrapper">
                            <input type="text" class="search-input" id="searchInput"
                                   placeholder="ค้นหาชื่อหนังสือ ผู้แต่ง หมวดหมู่ สำนักพิมพ์ หรือ ISBN..." 
                                   autocomplete="off">
                            <i class="fas fa-search search-input-icon"></i>
                            <button type="button" class="search-clear-btn" id="searchClearBtn">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="search-suggestions" id="searchSuggestions"></div>
                </div>

                <!-- Advanced Filters -->
                <div class="advanced-filters">
                    <div class="filters-header">
                        <i class="fas fa-filter"></i>
                        <h3>ตัวกรองขั้นสูง</h3>
                    </div>
                    
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
        </div>

        <!-- Books Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-book" id="sectionIcon"></i>
                <h3 id="sectionTitle">หนังสือแนะนำ</h3>
            </div>
            <div class="card-content">
                <!-- Books Grid -->
                <div class="books-grid" id="booksGrid">
                    <?php foreach ($recommended_books as $book): 
                        // Check if user already borrowed this book
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND book_id = ? AND status IN ('borrowed', 'overdue')");
                        $stmt->execute([$user_id, $book['book_id']]);
                        $already_borrowed = $stmt->fetchColumn() > 0;
                        
                        $already_reserved = $book['user_reserved'] > 0;
                        $book_reserved_by_others = $book['reserved_count'] > 0 && !$already_reserved;
                      
                        // Check if cover image exists
                        $has_cover_image = checkCoverImageExists($book['cover_image']);
                    ?>
                        <div class="book-item" 
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

                            <div class="book-cover">
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
                            
                            <div class="book-details">
                                <h4 title="<?php echo htmlspecialchars($book['title']); ?>">
                                    <?php echo htmlspecialchars($book['title']); ?>
                                </h4>
                                
                                <p>
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($book['authors'] ?: 'ไม่ระบุผู้เขียน'); ?>
                                </p>

                                <?php if (!empty($book['isbn'])): ?>
                                <p>
                                    <i class="fas fa-barcode"></i>
                                    <?php echo htmlspecialchars($book['isbn']); ?>
                                </p>
                                <?php endif; ?>
                                
                                <div class="book-category">
                                    <?php echo htmlspecialchars($book['category_name'] ?: 'ไม่ระบุหมวดหมู่'); ?>
                                </div>
                                
                                <div class="book-actions">
                                    <?php if ($already_borrowed): ?>
                                        <span class="due-date overdue">
                                            <i class="fas fa-times"></i>
                                            ไม่ว่าง
                                        </span>
                                    <?php elseif ($already_reserved): ?>
                                        <span class="due-date reserved-status">
                                            <i class="fas fa-bookmark"></i>
                                            จองแล้ว
                                        </span>
                                    <?php elseif ($user_borrowed_count >= $max_borrow_books): ?>
                                        <button class="btn-return" disabled title="ยืมครบจำนวนสูงสุดแล้ว">
                                            <i class="fas fa-ban"></i>
                                            ยืมครบแล้ว
                                        </button>
                                    <?php elseif ($book_reserved_by_others): ?>
                                        <span class="due-date overdue">
                                            <i class="fas fa-bookmark"></i>
                                            ถูกจองแล้ว
                                        </span>
                                    <?php elseif ($book['available_copies'] <= 0): ?>
                                        <span class="due-date overdue">
                                            <i class="fas fa-times"></i>
                                            ไม่มีเล่มว่าง
                                        </span>
                                    <?php else: ?>
                                        <button class="btn-return" onclick="showReserveModal(<?php echo $book['book_id']; ?>, '<?php echo addslashes($book['title']); ?>', '<?php echo addslashes($book['authors'] ?: 'ไม่ระบุผู้เขียน'); ?>', '<?php echo addslashes($book['category_name'] ?: 'ไม่ระบุหมวดหมู่'); ?>', '<?php echo addslashes($book['isbn'] ?: 'ไม่ระบุ ISBN'); ?>')">
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
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Enhanced Search and Filter System
        let searchTimeout, isSearching = false;
        let allBooks = <?php echo json_encode($recommended_books); ?>;
        let filteredBooks = [...allBooks];
        let activeFilters = {};
        let currentBookId = null;

        // Search suggestions data
        let searchSuggestions = [];

        // Initialize system
        document.addEventListener('DOMContentLoaded', function() {
            initializeSearch();
            initializeFilters();
            buildSearchSuggestions();
            console.log('🎉 Enhanced Digital Library System Ready!');
        });

        // Build search suggestions from database data
        function buildSearchSuggestions() {
            const suggestions = new Set();
            
            allBooks.forEach(book => {
                // Add book titles
                if (book.title) suggestions.add(book.title);
                
                // Add authors
                if (book.authors) {
                    book.authors.split(', ').forEach(author => {
                        suggestions.add(author.trim());
                    });
                }
                
                // Add categories
                if (book.category_name) suggestions.add(book.category_name);
                
                // Add publishers
                if (book.publisher_name) suggestions.add(book.publisher_name);
                
                // Add ISBN
                if (book.isbn) suggestions.add(book.isbn);
            });
            
            searchSuggestions = Array.from(suggestions).sort();
        }

        // Enhanced Search System
        function initializeSearch() {
            const searchInput = document.getElementById('searchInput');
            const searchClearBtn = document.getElementById('searchClearBtn');
            const searchForm = document.getElementById('searchForm');
            const suggestionsContainer = document.getElementById('searchSuggestions');

            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                searchClearBtn.classList.toggle('visible', query.length > 0);

                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (query.length >= 2) {
                        performSearch(query);
                        showSearchSuggestions(query);
                    } else if (query.length === 0) {
                        clearSearch();
                        hideSearchSuggestions();
                    } else if (query.length >= 1) {
                        showSearchSuggestions(query);
                    } else {
                        hideSearchSuggestions();
                    }
                }, 200);
            });

            searchInput.addEventListener('focus', () => {
                searchForm.classList.add('focused');
                const query = searchInput.value.trim();
                if (query.length >= 1) {
                    showSearchSuggestions(query);
                }
            });

            searchInput.addEventListener('blur', () => {
                searchForm.classList.remove('focused');
                setTimeout(() => hideSearchSuggestions(), 200);
            });

            searchClearBtn.addEventListener('click', function() {
                searchInput.value = '';
                searchInput.focus();
                clearSearch();
                hideSearchSuggestions();
                this.classList.remove('visible');
            });

            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const query = this.value.trim();
                    if (query) {
                        performSearch(query);
                        hideSearchSuggestions();
                    }
                }
                
                if (e.key === 'Escape') {
                    hideSearchSuggestions();
                    this.blur();
                }
            });

            // Handle suggestion clicks
            suggestionsContainer.addEventListener('click', function(e) {
                if (e.target.classList.contains('search-suggestion') || e.target.closest('.search-suggestion')) {
                    const suggestion = e.target.closest('.search-suggestion') || e.target;
                    const suggestionText = suggestion.textContent.trim();
                    searchInput.value = suggestionText;
                    performSearch(suggestionText);
                    hideSearchSuggestions();
                    searchInput.focus();
                }
            });
        }

        function showSearchSuggestions(query) {
            const suggestionsContainer = document.getElementById('searchSuggestions');
            const matchingSuggestions = searchSuggestions
                .filter(suggestion => 
                    suggestion.toLowerCase().includes(query.toLowerCase())
                )
                .slice(0, 8);

            if (matchingSuggestions.length > 0) {
                const suggestionsHtml = matchingSuggestions.map(suggestion => {
                    const highlightedSuggestion = suggestion.replace(
                        new RegExp(`(${query})`, 'gi'),
                        '<strong>$1</strong>'
                    );
                    
                    let icon = 'fas fa-book';
                    if (suggestion.includes('ISBN') || /^\d+/.test(suggestion)) {
                        icon = 'fas fa-barcode';
                    } else if (allBooks.some(book => book.category_name === suggestion)) {
                        icon = 'fas fa-tag';
                    } else if (allBooks.some(book => book.publisher_name === suggestion)) {
                        icon = 'fas fa-building';
                    } else if (allBooks.some(book => book.authors && book.authors.includes(suggestion))) {
                        icon = 'fas fa-user';
                    }
                    
                    return `
                        <div class="search-suggestion">
                            <i class="${icon}"></i>
                            <span>${highlightedSuggestion}</span>
                        </div>
                    `;
                }).join('');

                suggestionsContainer.innerHTML = suggestionsHtml;
                suggestionsContainer.classList.add('show');
            } else {
                hideSearchSuggestions();
            }
        }

        function hideSearchSuggestions() {
            const suggestionsContainer = document.getElementById('searchSuggestions');
            suggestionsContainer.classList.remove('show');
            setTimeout(() => {
                suggestionsContainer.innerHTML = '';
            }, 300);
        }

        function performSearch(query) {
            if (isSearching) return;
            isSearching = true;

            const sectionTitle = document.getElementById('sectionTitle');
            const sectionIcon = document.getElementById('sectionIcon');
            
            sectionIcon.className = 'fas fa-search';
            sectionTitle.innerHTML = `ผลการค้นหา "${query}"`;

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
            displayBooks(filteredBooks);
            isSearching = false;

            console.log(`🔍 Search: "${query}" - Found ${searchResults.length} results`);
        }

        function clearSearch() {
            const sectionTitle = document.getElementById('sectionTitle');
            const sectionIcon = document.getElementById('sectionIcon');
            
            sectionIcon.className = 'fas fa-book';
            sectionTitle.innerHTML = 'หนังสือแนะนำ';
            
            filteredBooks = [...allBooks];
            displayBooks(filteredBooks);
        }

        // Enhanced Filter System
        function initializeFilters() {
            ['categoryFilter', 'publisherFilter', 'authorFilter', 'statusFilter'].forEach(filterId => {
                const filterElement = document.getElementById(filterId);
                if (filterElement) {
                    filterElement.addEventListener('change', function() {
                        updateActiveFilter(filterId, this.value, this.options[this.selectedIndex].text);
                        applyFilters();
                    });
                }
            });
        }

        function updateActiveFilter(filterId, value, text) {
            const filterKey = filterId.replace('Filter', '');
            
            if (value) {
                activeFilters[filterKey] = { value, text };
            } else {
                delete activeFilters[filterKey];
            }

            console.log('Active filters:', activeFilters);
        }

        function applyFilters() {
            filteredBooks = allBooks.filter(book => {
                for (const [filterKey, filter] of Object.entries(activeFilters)) {
                    switch (filterKey) {
                        case 'category':
                            if (book.category_id != filter.value) return false;
                            break;
                        case 'publisher':
                            if (book.publisher_id != filter.value) return false;
                            break;
                        case 'author':
                            if (!book.authors || !book.authors.toLowerCase().includes(filter.text.toLowerCase())) {
                                return false;
                            }
                            break;
                        case 'status':
                            const isAvailable = book.available_copies > 0 && book.status !== 'reserved';
                            const isReserved = book.status === 'reserved' || book.reserved_count > 0;
                            
                            if (filter.value === 'available' && !isAvailable) return false;
                            if (filter.value === 'unavailable' && isAvailable) return false;
                            if (filter.value === 'reserved' && !isReserved) return false;
                            break;
                    }
                }
                return true;
            });
            
            displayBooks(filteredBooks);
            updateSectionTitle();
        }

        function updateSectionTitle() {
            const sectionTitle = document.getElementById('sectionTitle');
            const sectionIcon = document.getElementById('sectionIcon');
            const filterCount = Object.keys(activeFilters).length;
            
            if (filterCount > 0) {
                const filterLabels = Object.values(activeFilters).map(filter => filter.text);
                sectionIcon.className = 'fas fa-filter';
                sectionTitle.innerHTML = `กรองตาม: ${filterLabels.join(', ')}`;
            } else {
                sectionIcon.className = 'fas fa-book';
                sectionTitle.innerHTML = 'หนังสือแนะนำ';
            }
        }

        function clearAllFilters() {
            activeFilters = {};
            
            ['categoryFilter', 'publisherFilter', 'authorFilter', 'statusFilter'].forEach(filterId => {
                const filterElement = document.getElementById(filterId);
                if (filterElement) filterElement.value = '';
            });
            
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.value = '';
                document.getElementById('searchClearBtn').classList.remove('visible');
            }
            
            clearSearch();
            hideSearchSuggestions();
        }

        // Enhanced Display System
        function displayBooks(books) {
            const booksGrid = document.getElementById('booksGrid');
            const emptyState = document.getElementById('emptyState');
            const allCards = document.querySelectorAll('.book-item');
            
            if (books.length === 0) {
                allCards.forEach(card => {
                    card.style.display = 'none';
                    card.classList.add('filtered-out');
                });
                emptyState.style.display = 'block';
                return;
            }
            
            emptyState.style.display = 'none';
            
            // Hide all cards first
            allCards.forEach(card => {
                card.style.display = 'none';
                card.classList.add('filtered-out');
            });
            
            // Show matching cards with animation
            books.forEach((book, index) => {
                const card = document.querySelector(`[data-book-id="${book.book_id}"]`);
                if (card) {
                    setTimeout(() => {
                        card.style.display = 'block';
                        card.classList.remove('filtered-out');
                    }, index * 50);
                }
            });
        }

        // Modal System
        function showReserveModal(bookId, title, author, category, isbn) {
            currentBookId = bookId;
            
            document.getElementById('modal-book-title').textContent = title;
            document.getElementById('modal-book-author').innerHTML = `<i class="fas fa-user"></i> ${author}`;
            document.getElementById('modal-book-category').innerHTML = `<i class="fas fa-tag"></i> ${category}`;
            document.getElementById('modal-book-isbn').innerHTML = `<i class="fas fa-barcode"></i> ${isbn}`;
            
            const modal = document.getElementById('reserveModal');
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                currentBookId = null;
            }
        }
        // Enhanced Book Reservation with Real-time Updates
        async function confirmReserve() {
            if (!currentBookId) {
                showNotification('เกิดข้อผิดพลาด: ไม่พบข้อมูลหนังสือ', 'error');
                return;
            }

            showLoading('กำลังจองหนังสือ...');
            
            const confirmBtn = document.getElementById('confirm-reserve-btn');
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังประมวลผล...';

            try {
                const response = await fetch('reserve_book.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        book_id: currentBookId,
                        action: 'reserve'
                    })
                });

                if (!response.ok) {
                    throw new Error(`HTTP Error: ${response.status}`);
                }

                const result = await response.json();

                if (result.success) {
                    showNotification('จองหนังสือสำเร็จ! รอการอนุมัติจากแอดมิน', 'success');
                    
                    // อัปเดตการ์ดหนังสือด้วยข้อมูลใหม่จากเซิร์ฟเวอร์
                    if (result.data) {
                        updateBookCardWithServerData(currentBookId, result.data);
                    } else {
                        // Fallback to client-side update
                        updateBookCardAfterReserve(currentBookId);
                    }
                    
                    // อัปเดตสถิติผู้ใช้
                    updateUserStats();
                    
                    // อัปเดต modal หากเปิดอยู่
                    if (typeof updateModalAfterReservation === 'function') {
                        updateModalAfterReservation(currentBookId, result.data);
                    }
                    
                    closeModal('reserveModal');
                } else {
                    throw new Error(result.message || 'เกิดข้อผิดพลาดในการจองหนังสือ');
                }

            } catch (error) {
                console.error('Reservation Error:', error);
                let errorMessage = 'เกิดข้อผิดพลาดในการเชื่อมต่อ';
                
                if (error.message) {
                    errorMessage = error.message;
                }
                
                showNotification(errorMessage, 'error');
            } finally {
                hideLoading();
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-bookmark"></i> ยืนยันการจอง';
            }
        }

        // อัปเดตการ์ดหนังสือด้วยข้อมูลจากเซิร์ฟเวอร์
        function updateBookCardWithServerData(bookId, serverData) {
            const bookCard = document.querySelector(`[data-book-id="${bookId}"]`);
            if (!bookCard) return;

            try {
                // อัปเดตแสดงจำนวนหนังสือ
                const availabilityEl = bookCard.querySelector('.book-availability');
                if (availabilityEl && serverData.available_copies !== undefined) {
                    availabilityEl.textContent = `${serverData.available_copies}/${serverData.total_copies} เล่ม`;
                }
                
                // อัปเดตสถานะปุ่ม
                const actionContainer = bookCard.querySelector('.book-actions');
                if (actionContainer) {
                    actionContainer.innerHTML = `
                        <span class="due-date reserved-status">
                            <i class="fas fa-bookmark"></i>
                            จองแล้ว
                        </span>
                    `;
                }
                
                // อัปเดตข้อมูลใน memory
                updateInMemoryBookData(bookId, serverData);
                
                console.log(`✅ Updated book card ${bookId} with server data:`, serverData);
                
            } catch (error) {
                console.error('Error updating book card:', error);
                // Fallback ใช้การอัปเดตแบบเก่า
                updateBookCardAfterReserve(bookId);
            }
        }

        // อัปเดตข้อมูลหนังสือใน memory
        function updateInMemoryBookData(bookId, serverData) {
            // อัปเดตใน allBooks array
            const allBooksIndex = allBooks.findIndex(book => book.book_id == bookId);
            if (allBooksIndex !== -1) {
                allBooks[allBooksIndex] = {
                    ...allBooks[allBooksIndex],
                    available_copies: serverData.available_copies,
                    total_copies: serverData.total_copies,
                    reserved_count: serverData.reserved_count,
                    user_reserved: serverData.user_reserved ? 1 : 0,
                    status: serverData.status
                };
            }
            
            // อัปเดตใน filteredBooks array
            const filteredBooksIndex = filteredBooks.findIndex(book => book.book_id == bookId);
            if (filteredBooksIndex !== -1) {
                filteredBooks[filteredBooksIndex] = {
                    ...filteredBooks[filteredBooksIndex],
                    available_copies: serverData.available_copies,
                    total_copies: serverData.total_copies,
                    reserved_count: serverData.reserved_count,
                    user_reserved: serverData.user_reserved ? 1 : 0,
                    status: serverData.status
                };
            }
        }



        // เพิ่มฟังก์ชันนี้เพื่อแสดงสถานะการอัปเดตข้อมูล
        function showDataFreshIndicator() {
            // เพิ่ม indicator ที่แสดงว่าข้อมูลถูกรีเฟรชแล้ว
            const modal = document.getElementById('bookDetailsModal');
            if (modal && modal.style.display === 'block') {
                let indicator = modal.querySelector('.data-fresh-indicator');
                
                if (!indicator) {
                    indicator = document.createElement('div');
                    indicator.className = 'data-fresh-indicator';
                    const modalContent = modal.querySelector('.modal-content-minimal');
                    if (modalContent) {
                        modalContent.appendChild(indicator);
                    }
                }
                
                indicator.textContent = '🔄 กำลังอัปเดตข้อมูล...';
                indicator.className = 'data-fresh-indicator refreshing show';
                
                setTimeout(() => {
                    indicator.textContent = '✅ ข้อมูลล่าสุด';
                    indicator.className = 'data-fresh-indicator show';
                    
                    setTimeout(() => {
                        indicator.classList.remove('show');
                    }, 3000);
                }, 1000);
            }
        }

        function updateBookCardAfterReserve(bookId) {
            const bookCard = document.querySelector(`[data-book-id="${bookId}"]`);
            if (bookCard) {
                // Update availability display
                const availabilityEl = bookCard.querySelector('.book-availability');
                if (availabilityEl) {
                    const currentText = availabilityEl.textContent;
                    const match = currentText.match(/(\d+)\/(\d+)/);
                    if (match) {
                        const available = Math.max(0, parseInt(match[1]) - 1);
                        const total = parseInt(match[2]);
                        availabilityEl.textContent = `${available}/${total} เล่ม`;
                    }
                }
                
                // Update action button
                const actionContainer = bookCard.querySelector('.book-actions');
                if (actionContainer) {
                    actionContainer.innerHTML = `
                        <span class="due-date reserved-status">
                            <i class="fas fa-bookmark"></i>
                            จองแล้ว
                        </span>
                    `;
                }
                
                // Update in-memory data (basic update)
                const bookIndex = allBooks.findIndex(book => book.book_id == bookId);
                if (bookIndex !== -1 && allBooks[bookIndex].available_copies > 0) {
                    allBooks[bookIndex].available_copies -= 1;
                    allBooks[bookIndex].user_reserved = 1;
                }
            }
        }

        // เพิ่มฟังก์ชันตรวจสอบสถานะการเชื่อมต่อ
        function checkConnectionStatus() {
            return navigator.onLine;
        }

        // ฟังก์ชันสำหรับรอการเชื่อมต่อกลับมา
        function waitForConnection() {
            return new Promise((resolve) => {
                if (navigator.onLine) {
                    resolve();
                } else {
                    const handleOnline = () => {
                        window.removeEventListener('online', handleOnline);
                        resolve();
                    };
                    window.addEventListener('online', handleOnline);
                }
            });
        }


        async function updateUserStats() {
            try {
                const response = await fetch('get_user_stats.php');
                
                if (!response.ok) {
                    throw new Error(`HTTP Error: ${response.status}`);
                }
                
                const result = await response.json();
                
                if (result.success) {
                    // อัปเดตจำนวนการจอง
                    const reservationsCount = document.getElementById('user-reservations-count');
                    if (reservationsCount && result.data.reservations_count !== undefined) {
                        reservationsCount.textContent = result.data.reservations_count;
                    }
                    
                    // อัปเดตสถิติอื่นๆ ถ้ามี
                    if (result.data.borrowed_count !== undefined) {
                        const borrowedCount = document.querySelector('.stat-card.borrowed .stat-number');
                        if (borrowedCount) {
                            const maxBooks = borrowedCount.textContent.split('/')[1] || '5';
                            borrowedCount.textContent = `${result.data.borrowed_count}/${maxBooks}`;
                        }
                    }
                    
                    if (result.data.total_borrowed !== undefined) {
                        const totalBorrowed = document.querySelector('.stat-card.returned .stat-number');
                        if (totalBorrowed) {
                            totalBorrowed.textContent = result.data.total_borrowed;
                        }
                    }
                    
                    console.log('✅ User stats updated successfully');
                } else {
                    console.warn('Failed to update user stats:', result.message);
                }
            } catch (error) {
                console.error('Error updating user stats:', error);
                // ไม่แสดง error notification เพราะเป็นการอัปเดตที่ไม่สำคัญมาก
            }
        }


        // Enhanced Notification System
        function showNotification(message, type = 'success', duration = 6000, extraData = null) {
            const existingNotification = document.querySelector('.notification');
            if (existingNotification) existingNotification.remove();

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            let icon = 'fas fa-check-circle';
            switch (type) {
                case 'error': icon = 'fas fa-exclamation-circle'; break;
                case 'warning': icon = 'fas fa-exclamation-triangle'; break;
                case 'info': icon = 'fas fa-info-circle'; break;
            }
            
            let notificationContent = `<i class="${icon}"></i><span>${message}</span>`;
            
            // เพิ่มข้อมูลเสริมถ้ามี
            if (extraData) {
                notificationContent += `<small style="display: block; margin-top: 0.5rem; opacity: 0.8;">${extraData}</small>`;
            }
            
            notification.innerHTML = notificationContent;
            document.body.appendChild(notification);

            setTimeout(() => notification.classList.add('show'), 100);
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 400);
            }, duration);
        }

        // Debug function สำหรับตรวจสอบข้อมูล
        window.libraryDebug = {
            ...window.libraryDebug,
            checkBookData: (bookId) => {
                const book = allBooks.find(b => b.book_id == bookId);
                console.log('Book data:', book);
                return book;
            },
            showReservationStatus: () => {
                const reservedBooks = allBooks.filter(book => book.user_reserved > 0);
                console.table(reservedBooks);
                return reservedBooks;
            },
            simulateReservation: (bookId) => {
                console.log(`🧪 Simulating reservation for book ${bookId}`);
                updateBookCardAfterReserve(bookId);
            }
        };


        // Loading System
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

        // Utility Functions
        function handleImageError(img) {
            img.style.display = 'none';
            const fallbackIcon = img.parentElement.querySelector('.book-fallback-icon');
            if (fallbackIcon) fallbackIcon.style.display = 'flex';
        }

        function showPopularBooks() {
            clearAllFilters();
            const sectionTitle = document.getElementById('sectionTitle');
            const sectionIcon = document.getElementById('sectionIcon');
            sectionIcon.className = 'fas fa-fire';
            sectionTitle.innerHTML = 'หนังสือยอดนิยม';
        }

        function showRecentBooks() {
            clearAllFilters();
            const sectionTitle = document.getElementById('sectionTitle');
            const sectionIcon = document.getElementById('sectionIcon');
            sectionIcon.className = 'fas fa-clock';
            sectionTitle.innerHTML = 'หนังสือใหม่ล่าสุด';
        }

        // Enhanced Event Listeners
        window.onclick = function(event) {
            const modal = document.getElementById('reserveModal');
            if (event.target === modal) closeModal('reserveModal');
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modal = document.getElementById('reserveModal');
                if (modal && modal.style.display === 'block') {
                    closeModal('reserveModal');
                }
                hideSearchSuggestions();
            }
            
            // Enhanced keyboard shortcuts
            if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
                event.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            // Clear search with Ctrl/Cmd + Backspace
            if ((event.ctrlKey || event.metaKey) && event.key === 'Backspace') {
                const searchInput = document.getElementById('searchInput');
                if (searchInput && document.activeElement === searchInput) {
                    event.preventDefault();
                    searchInput.value = '';
                    clearSearch();
                    hideSearchSuggestions();
                    document.getElementById('searchClearBtn').classList.remove('visible');
                }
            }
        });

        // Performance and Error Monitoring
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.error);
            showNotification('เกิดข้อผิดพลาดในระบบ กรุณาลองรีเฟรชหน้า', 'error');
        });

        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled Promise Rejection:', e.reason);
            showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่อีกครั้ง', 'error');
        });

        window.addEventListener('online', () => {
            showNotification('เชื่อมต่ออินเทอร์เน็ตแล้ว', 'success');
        });
        
        window.addEventListener('offline', () => {
            showNotification('ขาดการเชื่อมต่ออินเทอร์เน็ต', 'warning');
        });

        // Performance logging
        window.addEventListener('load', function() {
            if (window.performance) {
                const loadTime = window.performance.timing.loadEventEnd - window.performance.timing.navigationStart;
                console.log(`⚡ Page loaded in ${loadTime}ms`);
            }
        });

        // Debug utilities
        window.libraryDebug = {
            showAllBooks: () => console.table(allBooks),
            showFilteredBooks: () => console.table(filteredBooks),
            showActiveFilters: () => console.log(activeFilters),
            showSuggestions: () => console.log(searchSuggestions),
            clearFilters: clearAllFilters
        };
    </script>
</body>
</html>

