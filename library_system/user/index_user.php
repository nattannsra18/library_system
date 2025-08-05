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

$where_conditions[] = "b.status = 'available'";

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get books with pagination
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
           GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN book_authors ba ON b.book_id = ba.book_id
    LEFT JOIN authors a ON ba.author_id = a.author_id
    WHERE b.status = 'available'
    GROUP BY b.book_id
    ORDER BY RAND()
    LIMIT 6
");
$stmt->execute();
$recommended_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user's recent activity
$stmt = $pdo->prepare("
    SELECT bo.title, bo.cover_image, br.borrow_date, br.status
    FROM borrowing br
    JOIN books bo ON br.book_id = bo.book_id
    WHERE br.user_id = ?
    ORDER BY br.borrow_date DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

        /* Search Section */
        .search-section {
            background: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }

        .search-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 2rem;
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
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
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

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .section {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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

        /* Books Section */
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 2rem;
        }

        .book-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
        }

        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
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

        .btn-borrow {
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

        .btn-borrow:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-borrow:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-details {
            background: #f5f5f5;
            color: #666;
            border: none;
            padding: 0.8rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-details:hover {
            background: #e0e0e0;
        }

        /* Recent Activity */
        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
        }

        .activity-icon.borrowed { background: #4caf50; }
        .activity-icon.returned { background: #2196f3; }
        .activity-icon.overdue { background: #f44336; }

        .activity-content h4 {
            font-size: 1rem;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .activity-content p {
            color: #666;
            font-size: 0.9rem;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
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
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
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

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }

            .welcome-banner h1 {
                font-size: 2rem;
            }

            .content-grid {
                grid-template-columns: 1fr;
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
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <p>กำลังดำเนินการ...</p>
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
                    <i class="fas fa-history"></i>
                    <div class="user-stat-number"><?php echo $user_total_borrowed; ?></div>
                    <div class="user-stat-label">ยืมทั้งหมด</div>
                </div>
                <div class="user-stat-card">
                    <i class="fas fa-calendar-check"></i>
                    <div class="user-stat-number"><?php echo $recent_activity_count; ?></div>
                    <div class="user-stat-label">กิจกรรม 30 วัน</div>
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
            
            <form class="search-form" method="GET" action="">
                <input type="text" name="search" class="search-input" 
                       placeholder="ค้นหาหนังสือ ผู้เขียน หรือ ISBN..." 
                       value="<?php echo htmlspecialchars($search); ?>">
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

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Books Section -->
            <div class="section">
                <div class="section-header">
                    <i class="fas fa-book"></i>
                    <h3>
                        <?php 
                        if (!empty($search) || $category_id > 0) {
                            echo "ผลการค้นหา (" . number_format($total_books) . " เล่ม)";
                        } else {
                            echo "หนังสือแนะนำ";
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
                        ?>
                            <div class="book-card" data-book-id="<?php echo $book['book_id']; ?>">
                                <div class="book-image">
                                    <div class="book-availability">
                                        <?php echo $book['available_copies']; ?>/<?php echo $book['total_copies']; ?> เล่ม
                                    </div>
                                    <?php if (!empty($book['cover_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($book['cover_image']); ?>" 
                                             alt="<?php echo htmlspecialchars($book['title']); ?>"
                                             style="width: 100%; height: 100%; object-fit: cover;"
                                             onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'fas fa-book\'></i><div class=\'book-availability\'><?php echo $book['available_copies']; ?>/<?php echo $book['total_copies']; ?> เล่ม</div>';">
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
                                    
                                    <div class="book-category">
                                        <?php echo htmlspecialchars($book['category_name'] ?: 'ไม่ระบุหมวดหมู่'); ?>
                                    </div>
                                    
                                    <div class="book-actions">
                                        <?php if ($already_borrowed): ?>
                                            <button class="btn-borrow" disabled style="background: #28a745;">
                                                <i class="fas fa-check"></i>
                                                ยืมแล้ว
                                            </button>
                                        <?php elseif ($user_borrowed_count >= $max_borrow_books): ?>
                                            <button class="btn-borrow" disabled title="ยืมครบจำนวนสูงสุดแล้ว">
                                                <i class="fas fa-ban"></i>
                                                ยืมครบแล้ว
                                            </button>
                                        <?php elseif ($book['available_copies'] <= 0): ?>
                                            <button class="btn-borrow" disabled>
                                                <i class="fas fa-times"></i>
                                                ไม่ว่าง
                                            </button>
                                        <?php else: ?>
                                            <button class="btn-borrow" onclick="borrowBook(<?php echo $book['book_id']; ?>, '<?php echo addslashes($book['title']); ?>')">
                                                <i class="fas fa-hand-holding"></i>
                                                ยืมหนังสือ
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button class="btn-details" onclick="showBookDetails(<?php echo $book['book_id']; ?>)" title="ดูรายละเอียด">
                                            <i class="fas fa-info-circle"></i>
                                        </button>
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

            <!-- Recent Activity -->
            <div class="section">
                <div class="section-header">
                    <i class="fas fa-clock"></i>
                    <h3>กิจกรรมล่าสุด</h3>
                </div>

                <?php if (empty($recent_activity)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h4>ยังไม่มีกิจกรรม</h4>
                        <p>เริ่มยืมหนังสือเพื่อดูกิจกรรมของคุณ</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_activity as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?php echo $activity['status']; ?>">
                                <i class="fas fa-<?php 
                                    echo $activity['status'] == 'borrowed' ? 'book-reader' : 
                                         ($activity['status'] == 'returned' ? 'check-circle' : 'exclamation-triangle'); 
                                ?>"></i>
                            </div>
                            <div class="activity-content">
                                <h4><?php echo htmlspecialchars($activity['title']); ?></h4>
                                <p>
                                    <?php 
                                    switch($activity['status']) {
                                        case 'borrowed':
                                            echo 'ยืมเมื่อ ' . date('d/m/Y', strtotime($activity['borrow_date']));
                                            break;
                                        case 'returned':
                                            echo 'คืนแล้วเมื่อ ' . date('d/m/Y', strtotime($activity['borrow_date']));
                                            break;
                                        case 'overdue':
                                            echo 'เกินกำหนดตั้งแต่ ' . date('d/m/Y', strtotime($activity['borrow_date']));
                                            break;
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div style="text-align: center; margin-top: 1rem;">
                        <a href="history.php" style="color: #667eea; text-decoration: none; font-size: 0.9rem;">
                            <i class="fas fa-arrow-right"></i> ดูกิจกรรมทั้งหมด
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let isProcessing = false;

        // Show notification function
        function showNotification(message, type = 'success') {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.notification');
            existingNotifications.forEach(notification => notification.remove());

            // Create new notification
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            const icon = type === 'success' ? 'check-circle' : 
                        type === 'error' ? 'exclamation-triangle' : 
                        type === 'warning' ? 'exclamation-circle' : 'info-circle';
            
            notification.innerHTML = `
                <i class="fas fa-${icon}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                hideNotification(notification);
            }, 5000);
            
            return notification;
        }

        // Hide notification function
        function hideNotification(notification) {
            notification.classList.remove('show');
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }

        // Show loading overlay
        function showLoading(message = 'กำลังดำเนินการ...') {
            const loadingOverlay = document.getElementById('loadingOverlay');
            const loadingText = loadingOverlay.querySelector('p');
            loadingText.textContent = message;
            loadingOverlay.classList.add('show');
        }

        // Hide loading overlay
        function hideLoading() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.classList.remove('show');
        }

        // Borrow book function - ปรับปรุงแล้ว
        function borrowBook(bookId, bookTitle) {
            if (isProcessing) {
                showNotification('กรุณารอการดำเนินการเสร็จสิ้น', 'warning');
                return;
            }

            // Show confirmation dialog
            if (!confirm(`คุณต้องการยืมหนังสือ "${bookTitle}" หรือไม่?\n\nระยะเวลายืม: <?php echo $max_borrow_days; ?> วัน\nกำหนดคืน: ${getEstimatedDueDate()}`)) {
                return;
            }

            isProcessing = true;
            showLoading('กำลังดำเนินการยืมหนังสือ...');

            // ปรับ path ให้ถูกต้อง - ใช้ borrow_book.php ที่อยู่ในโฟลเดอร์เดียวกัน
            fetch('borrow_book.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    book_id: bookId
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                hideLoading();
                isProcessing = false;
                
                if (data.success) {
                    showNotification(`ยืมหนังสือ "${bookTitle}" สำเร็จ!\nกรุณาคืนภายในวันที่ ${data.data.due_date}`, 'success');
                    
                    // Update UI
                    updateBookCardAfterBorrow(bookId);
                    updateUserStats();
                    
                    // Auto refresh after 3 seconds
                    setTimeout(() => {
                        window.location.reload();
                    }, 3000);
                } else {
                    showNotification(`ไม่สามารถยืมหนังสือได้: ${data.message}`, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                isProcessing = false;
                console.error('Borrow book error:', error);
                showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่อีกครั้ง', 'error');
            });
        }

        // Update book card after successful borrow
        function updateBookCardAfterBorrow(bookId) {
            const bookCard = document.querySelector(`[data-book-id="${bookId}"]`);
            if (bookCard) {
                const borrowBtn = bookCard.querySelector('.btn-borrow');
                if (borrowBtn) {
                    borrowBtn.disabled = true;
                    borrowBtn.style.background = '#28a745';
                    borrowBtn.innerHTML = '<i class="fas fa-check"></i> ยืมแล้ว';
                }
                
                // Update availability counter
                const availability = bookCard.querySelector('.book-availability');
                if (availability) {
                    const text = availability.textContent;
                    const matches = text.match(/(\d+)\/(\d+)/);
                    if (matches) {
                        const available = parseInt(matches[1]) - 1;
                        const total = matches[2];
                        availability.textContent = `${available}/${total} เล่ม`;
                    }
                }
            }
        }

        // Update user stats
        function updateUserStats() {
            const borrowedStat = document.querySelector('.user-stat-card .user-stat-number');
            if (borrowedStat) {
                const text = borrowedStat.textContent;
                const matches = text.match(/(\d+)\/(\d+)/);
                if (matches) {
                    const current = parseInt(matches[1]) + 1;
                    const max = matches[2];
                    borrowedStat.textContent = `${current}/${max}`;
                }
            }
        }

        // Show book details
        function showBookDetails(bookId) {
            window.location.href = `../book_details.php?id=${bookId}`;
        }

        // Get estimated due date
        function getEstimatedDueDate() {
            const now = new Date();
            now.setDate(now.getDate() + <?php echo $max_borrow_days; ?>);
            return now.toLocaleDateString('th-TH');
        }

        // Search form enhancement
        document.querySelector('.search-form').addEventListener('submit', function(e) {
            const searchInput = document.querySelector('.search-input');
            if (searchInput.value.trim() === '') {
                e.preventDefault();
                searchInput.focus();
                showNotification('กรุณาใส่คำค้นหา', 'warning');
                return;
            }
            
            // Show loading state
            const searchBtn = document.querySelector('.search-btn');
            const originalHTML = searchBtn.innerHTML;
            searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            searchBtn.disabled = true;
            
            // Save search query
            sessionStorage.setItem('lastSearch', searchInput.value);
        });

        // Add scroll effect to header
        let lastScrollY = window.scrollY;
        window.addEventListener('scroll', function() {
            const header = document.querySelector('.header');
            const currentScrollY = window.scrollY;
            
            if (currentScrollY > 100) {
                header.style.background = 'rgba(102, 126, 234, 0.95)';
                header.style.backdropFilter = 'blur(10px)';
            } else {
                header.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
                header.style.backdropFilter = 'none';
            }
            
            // Hide/show header on scroll
            if (currentScrollY > lastScrollY && currentScrollY > 200) {
                header.style.transform = 'translateY(-100%)';
            } else {
                header.style.transform = 'translateY(0)';
            }
            lastScrollY = currentScrollY;
        });

        // Enhanced book card interactions
        document.querySelectorAll('.book-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                if (!this.classList.contains('borrowing')) {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                    this.style.boxShadow = '0 20px 40px rgba(0,0,0,0.15)';
                }
            });
            
            card.addEventListener('mouseleave', function() {
                if (!this.classList.contains('borrowing')) {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = '0 5px 15px rgba(0,0,0,0.1)';
                }
            });
        });

        // Quick action hover effects
        document.querySelectorAll('.quick-action').forEach(action => {
            action.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px) scale(1.02)';
            });
            
            action.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                document.querySelector('.search-input').focus();
                showNotification('ใช้ Ctrl+K เพื่อค้นหาอย่างรวดเร็ว', 'info');
            }
            
            // Alt + D for dashboard
            if (e.altKey && e.key === 'd') {
                e.preventDefault();
                window.location.href = 'dashboard.php';
            }
            
            // Alt + P for profile
            if (e.altKey && e.key === 'p') {
                e.preventDefault();
                window.location.href = 'profile.php';
            }
            
            // Alt + H for history
            if (e.altKey && e.key === 'h') {
                e.preventDefault();
                window.location.href = 'history.php';
            }
            
            // Escape to clear search
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('.search-input');
                if (document.activeElement === searchInput) {
                    searchInput.value = '';
                    searchInput.blur();
                }
            }
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Show welcome message based on time
            const hour = new Date().getHours();
            const greeting = hour < 12 ? 'สวัสดีตอนเช้า' : hour < 17 ? 'สวัสดีตอนบ่าย' : 'สวัสดีตอนเย็น';
            
            // Restore last search if available
            const lastSearch = sessionStorage.getItem('lastSearch');
            if (lastSearch && !document.querySelector('.search-input').value) {
                document.querySelector('.search-input').placeholder += ` (ล่าสุด: "${lastSearch}")`;
            }
            
            // Add tooltips to disabled buttons
            document.querySelectorAll('.btn-borrow:disabled').forEach(btn => {
                if (!btn.hasAttribute('title')) {
                    const text = btn.textContent.trim();
                    if (text.includes('ไม่ว่าง')) {
                        btn.title = 'หนังสือเล่มนี้ถูกยืมหมดแล้ว';
                    } else if (text.includes('ยืมครบ')) {
                        btn.title = `คุณยืมหนังสือครบ ${<?php echo $max_borrow_books; ?>} เล่มแล้ว`;
                    }
                }
            });
            
            // Performance monitoring
            if (performance.mark) {
                performance.mark('page-interactive');
                
                // Log load performance
                window.addEventListener('load', function() {
                    const navigation = performance.getEntriesByType('navigation')[0];
                    const loadTime = navigation.loadEventEnd - navigation.fetchStart;
                    console.log(`Page loaded in ${loadTime.toFixed(2)}ms`);
                });
            }
            
            // Add real-time clock
            updateClock();
            setInterval(updateClock, 1000);
        });

        // Real-time clock function
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('th-TH');
            const dateString = now.toLocaleDateString('th-TH', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            // Update page title with time
            document.title = `${timeString} - ห้องสมุดดิจิทัล`;
        }

        // Add pull-to-refresh for mobile
        let startY = 0;
        let pullDistance = 0;
        const threshold = 100;

        document.addEventListener('touchstart', function(e) {
            if (window.scrollY === 0) {
                startY = e.touches[0].pageY;
            }
        });

        document.addEventListener('touchmove', function(e) {
            if (window.scrollY === 0 && startY > 0) {
                pullDistance = e.touches[0].pageY - startY;
                if (pullDistance > 0) {
                    e.preventDefault();
                    const opacity = Math.min(pullDistance / threshold, 1);
                    document.body.style.transform = `translateY(${Math.min(pullDistance * 0.5, 50)}px)`;
                    document.body.style.opacity = 1 - (opacity * 0.3);
                }
            }
        });

        document.addEventListener('touchend', function() {
            if (pullDistance > threshold) {
                showNotification('กำลังรีเฟรชหน้า...', 'info');
                setTimeout(() => {
                    window.location.reload();
                }, 500);
            }
            
            // Reset
            document.body.style.transform = '';
            document.body.style.opacity = '';
            startY = 0;
            pullDistance = 0;
        });

        // Console branding
        console.log('%cห้องสมุดดิจิทัล - วิทยาลัยเทคนิคหาดใหญ่', 'color: #667eea; font-size: 20px; font-weight: bold;');
        console.log('%cUser Dashboard - Enhanced with ❤️', 'color: #764ba2; font-size: 14px;');
        console.log('พัฒนาโดย นายปิยพัชร์ ทองวงศ์');
        console.log('Keyboard shortcuts: Ctrl+K (search), Alt+D (dashboard), Alt+P (profile), Alt+H (history)');

        // Add contextual help
        function showHelp() {
            const helpContent = `
                🔍 เคล็ดลับการใช้งาน:
                • กด Ctrl+K เพื่อค้นหาอย่างรวดเร็ว
                • กด Alt+D เพื่อไปยังแดชบอร์ด
                • ดับเบิลคลิกที่หนังสือเพื่อดูรายละเอียด
                • ลากลงเพื่อรีเฟรช (มือถือ)
                
                📚 ข้อมูลการยืม:
                • ยืมได้สูงสุด ${<?php echo $max_borrow_books; ?>} เล่ม
                • ระยะเวลา ${<?php echo $max_borrow_days; ?>} วัน
                • ไม่มีค่าปรับ (ยกเว้นให้)
            `;
            
            alert(helpContent);
        }

        // Add help button (floating)
        const helpButton = document.createElement('button');
        helpButton.innerHTML = '<i class="fas fa-question-circle"></i>';
        helpButton.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            z-index: 1000;
        `;
        helpButton.addEventListener('click', showHelp);
        helpButton.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.1)';
        });
        helpButton.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
        document.body.appendChild(helpButton);

        // Error handling for images
        document.querySelectorAll('img').forEach(img => {
            img.addEventListener('error', function() {
                this.style.display = 'none';
                const bookIcon = document.createElement('i');
                bookIcon.className = 'fas fa-book';
                bookIcon.style.fontSize = '3rem';
                this.parentElement.appendChild(bookIcon);
            });
        });
    </script>
</body>
</html>