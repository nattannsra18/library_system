<?php
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

// Get statistics for landing page
try {
    // Total books count
    $stmt = $pdo->query("SELECT COUNT(*) as total_books FROM books WHERE status != 'lost'");
    $total_books = $stmt->fetchColumn();

    // Total categories
    $stmt = $pdo->query("SELECT COUNT(*) FROM categories");
    $total_categories = $stmt->fetchColumn();

    // Total active users
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'");
    $total_users = $stmt->fetchColumn();

    // Total borrowed books currently
    $stmt = $pdo->query("SELECT COUNT(*) FROM borrowing WHERE status IN ('borrowed', 'overdue')");
    $total_borrowed = $stmt->fetchColumn();

    // Available books
    $available_books = $total_books - $total_borrowed;

    // Get popular categories (categories with most books)
    $stmt = $pdo->query("
        SELECT c.category_name, c.description, COUNT(b.book_id) as book_count
        FROM categories c
        LEFT JOIN books b ON c.category_id = b.category_id
        WHERE b.status != 'lost'
        GROUP BY c.category_id
        ORDER BY book_count DESC
        LIMIT 8
    ");
    $popular_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get featured books (newest books)
    $stmt = $pdo->query("
        SELECT b.*, c.category_name, p.publisher_name,
               GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors,
               CASE 
                   WHEN b.status = 'available' THEN 
                       GREATEST(0, b.total_copies - (
                           SELECT COUNT(*) FROM borrowing br 
                           WHERE br.book_id = b.book_id AND br.status IN ('borrowed', 'overdue')
                       ))
                   ELSE 0
               END as available_copies
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.category_id
        LEFT JOIN publishers p ON b.publisher_id = p.publisher_id
        LEFT JOIN book_authors ba ON b.book_id = ba.book_id
        LEFT JOIN authors a ON ba.author_id = a.author_id
        WHERE b.status IN ('available', 'unavailable', 'reserved')
        GROUP BY b.book_id
        ORDER BY b.created_at DESC
        LIMIT 6
    ");
    $featured_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get system settings
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('max_borrow_books', 'max_borrow_days')");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $max_borrow_books = (int)($settings['max_borrow_books'] ?? 5);
    $max_borrow_days = (int)($settings['max_borrow_days'] ?? 14);

} catch (Exception $e) {
    // Set default values if query fails
    $total_books = 0;
    $total_categories = 0;
    $total_users = 0;
    $total_borrowed = 0;
    $available_books = 0;
    $popular_categories = [];
    $featured_books = [];
    $max_borrow_books = 5;
    $max_borrow_days = 14;
}

// Function to check if cover image exists
function checkCoverImageExists($cover_image) {
    return !empty($cover_image) && file_exists($cover_image);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ห้องสมุดดิจิทัล - วิทยาลัยเทคนิคหาดใหญ่</title>
    <meta name="description" content="ห้องสมุดดิจิทัลของวิทยาลัยเทคนิคหาดใหญ่ ค้นหาหนังสือ ยืม-คืน และจัดการการอ่านของคุณได้อย่างง่ายดาย">
    <meta name="keywords" content="ห้องสมุด, หนังสือ, วิทยาลัยเทคนิค, หาดใหญ่, digital library">
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
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
            overflow-x: hidden;
        }

        /* Header */
        .header {
            background: #667eea; 
            padding: 1rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
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
            font-weight: 700;
            text-decoration: none;
            color: white;
        }

        .logo i { 
            margin-right: 10px; 
            font-size: 1.5rem;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-3px); }
        }

        .nav-links {
            display: flex;
            list-style: none;
            gap: 2rem;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 0.8rem 1rem; 
            border-radius: 8px; 
            transition: background-color 0.2s ease; 
            font-weight: 500;
        }

        .nav-links a:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }

        .auth-buttons {
            display: flex;
            gap: 1rem;
        }

        .btn-login, .btn-register {
            padding: 0.8rem 2rem;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 25px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 600;
            position: relative;
            overflow: hidden;
        }

        .btn-login {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .btn-register {
            background: white;
            color: #667eea;
        }

        .btn-login:hover, .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }

        .btn-login:hover {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .btn-register:hover {
            background: #f8f9fa;
            color: #667eea;
        }

        /* Hero Section */
        .hero-section {
            background: var(--bg-body);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 120px 2rem 4rem;
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 800px;
            height: 800px;
            background: linear-gradient(45deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            border-radius: 50%;
            animation: heroFloat 20s ease-in-out infinite;
        }

        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 600px;
            height: 600px;
            background: linear-gradient(45deg, rgba(118, 75, 162, 0.1), rgba(102, 126, 234, 0.1));
            border-radius: 50%;
            animation: heroFloat 15s ease-in-out infinite reverse;
        }

        @keyframes heroFloat {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-30px) rotate(2deg); }
        }

        .hero-container {
            max-width: 1200px;
            margin: 0 auto;
            text-align: center;
            position: relative;
            z-index: 2;
        }

        .hero-content h1 {
            font-size: 4rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }

        .hero-content p {
            font-size: 1.4rem;
            color: var(--text-secondary);
            margin-bottom: 3rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.8;
        }

        .hero-cta {
            display: flex;
            gap: 2rem;
            justify-content: center;
            margin-bottom: 4rem;
        }

        .btn-hero {
            padding: 1.2rem 3rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .btn-hero-primary {
            background: var(--primary-gradient);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .btn-hero-secondary {
            background: var(--bg-glass);
            color: var(--text-primary);
            border: 2px solid var(--border-medium);
            backdrop-filter: blur(10px);
        }

        .btn-hero:hover {
            transform: translateY(-4px) scale(1.05);
        }

        .btn-hero-primary:hover {
            box-shadow: var(--shadow-strong);
        }

        .btn-hero-secondary:hover {
            background: white;
            border-color: #667eea;
            color: #667eea;
        }

        /* Stats Section */
        .stats-section {
            padding: 6rem 2rem;
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
        }

        .stats-container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
        }

        .stat-item {
            text-align: center;
            padding: 2rem;
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow-light);
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            position: relative;
            overflow: hidden;
        }

        .stat-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--primary-gradient);
        }

        .stat-item:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-strong);
        }

        .stat-icon {
            font-size: 3rem;
            margin-bottom: 1.5rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .stat-label {
            font-size: 1.1rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        /* Features Section */
        .features-section {
            padding: 6rem 2rem;
            background: var(--bg-body);
        }

        .features-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-title {
            text-align: center;
            margin-bottom: 4rem;
        }

        .section-title h2 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .section-title p {
            font-size: 1.2rem;
            color: var(--text-secondary);
            max-width: 600px;
            margin: 0 auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 3rem;
        }

        .feature-card {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 3rem 2rem;
            text-align: center;
            border: 1px solid var(--border-light);
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(102, 126, 234, 0.05) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-strong);
        }

        .feature-card:hover::before {
            opacity: 1;
        }

        .feature-icon {
            font-size: 4rem;
            margin-bottom: 2rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .feature-card h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-primary);
        }

        .feature-card p {
            color: var(--text-secondary);
            line-height: 1.8;
            font-size: 1rem;
        }

        /* Categories Section */
        .categories-section {
            padding: 6rem 2rem;
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
        }

        .category-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow-light);
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .category-card:nth-child(4n+1) { border-left-color: #667eea; }
        .category-card:nth-child(4n+2) { border-left-color: #764ba2; }
        .category-card:nth-child(4n+3) { border-left-color: #28a745; }
        .category-card:nth-child(4n+4) { border-left-color: #ffc107; }

        .category-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-medium);
        }

        .category-card h4 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.8rem;
            color: var(--text-primary);
        }

        .category-card p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .category-count {
            display: inline-block;
            background: #f8f9fa;
            color: #667eea;
            padding: 0.4rem 0.8rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Featured Books Section */
        .books-section {
            padding: 6rem 2rem;
            background: var(--bg-body);
        }

        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .book-card {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--shadow-medium);
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            border: 1px solid var(--border-light);
        }

        .book-card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: var(--shadow-strong);
        }

        .book-cover {
            height: 200px;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
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
            transition: transform 0.4s ease;
        }

        .book-card:hover .book-cover img {
            transform: scale(1.1);
        }

        .book-availability {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(0,0,0,0.85);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .book-details {
            padding: 1.5rem;
        }

        .book-details h4 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-primary);
            line-height: 1.4;
            height: 2.8rem;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .book-meta {
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .book-meta i {
            color: #667eea;
            width: 14px;
        }

        .book-category {
            display: inline-block;
            background: #f8f9fa;
            color: #667eea;
            padding: 0.4rem 1rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid var(--border-light);
        }

        /* Footer */
        .footer {
            background: var(--primary-gradient);
            color: white;
            padding: 3rem 2rem 2rem;
            text-align: center;
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .footer h3 {
            font-size: 2rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
        }

        .footer p {
            opacity: 0.9;
            margin-bottom: 2rem;
            font-size: 1.1rem;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: white;
            text-decoration: none;
            opacity: 0.9;
            transition: opacity 0.3s ease;
        }

        .footer-links a:hover {
            opacity: 1;
        }

        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,0.2);
            padding-top: 2rem;
            opacity: 0.8;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .nav-links { display: none; }
            .hero-content h1 { font-size: 2.5rem; }
            .hero-content p { font-size: 1.1rem; }
            .hero-cta { flex-direction: column; align-items: center; }
            .btn-hero { width: 100%; max-width: 300px; justify-content: center; }
            .stats-container { grid-template-columns: repeat(2, 1fr); gap: 1.5rem; }
            .features-grid { grid-template-columns: 1fr; }
            .categories-grid { grid-template-columns: 1fr; }
            .books-grid { grid-template-columns: 1fr; }
            .auth-buttons { gap: 0.5rem; }
            .btn-login, .btn-register { padding: 0.6rem 1.2rem; font-size: 0.9rem; }
        }

        @media (max-width: 480px) {
            .hero-content h1 { font-size: 2rem; }
            .section-title h2 { font-size: 2rem; }
            .stat-number { font-size: 2rem; }
            .feature-icon { font-size: 3rem; }
            .stats-container { grid-template-columns: 1fr; }
        }

        /* Loading Animation */
        .loading-animation {
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 0.8s ease forwards;
        }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Scroll Animations */
        .scroll-animate {
            opacity: 0;
            transform: translateY(50px);
            transition: all 0.8s ease;
        }

        .scroll-animate.active {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <nav class="nav-container">
            <a href="#" class="logo">
                <i class="fas fa-book-open"></i>
                <span>ห้องสมุดดิจิทัล</span>
            </a>
            <ul class="nav-links">
                <li><a href="#home">หน้าแรก</a></li>
                <li><a href="#categories">หมวดหมู่</a></li>
                <li><a href="#books">หนังสือแนะนำ</a></li>
                <li><a href="#features">คุณสมบัติ</a></li>
            </ul>
            <div class="auth-buttons">
                <a href="login.php" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> เข้าสู่ระบบ
                </a>
                <a href="register.php" class="btn-register">
                    <i class="fas fa-user-plus"></i> สมัครสมาชิก
                </a>
            </div>
        </nav>
    </header>

    <!-- Hero Section -->
    <section class="hero-section" id="home">
        <div class="hero-container">
            <div class="hero-content loading-animation">
                <h1>ห้องสมุดดิจิทัลยุคใหม่</h1>
                <p>ค้นหาหนังสือ ยืม-คืน และจัดการการอ่านของคุณได้อย่างง่ายดาย ผ่านระบบห้องสมุดออนไลน์ที่ทันสมัยของวิทยาลัยเทคนิคหาดใหญ่</p>
                
                <div class="hero-cta">
                    <a href="register.php" class="btn-hero btn-hero-primary">
                        <i class="fas fa-rocket"></i> เริ่มต้นใช้งาน
                    </a>
                    <a href="login.php" class="btn-hero btn-hero-secondary">
                        <i class="fas fa-sign-in-alt"></i> เข้าสู่ระบบ
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats-section">
        <div class="stats-container">
            <div class="stat-item scroll-animate">
                <div class="stat-icon">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-number"><?php echo number_format($total_books); ?></div>
                <div class="stat-label">หนังสือทั้งหมด</div>
            </div>
            
            <div class="stat-item scroll-animate">
                <div class="stat-icon">
                    <i class="fas fa-list"></i>
                </div>
                <div class="stat-number"><?php echo number_format($total_categories); ?></div>
                <div class="stat-label">หมวดหมู่หนังสือ</div>
            </div>
            
            <div class="stat-item scroll-animate">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?php echo number_format($total_users); ?></div>
                <div class="stat-label">สมาชิกทั้งหมด</div>
            </div>
            
            <div class="stat-item scroll-animate">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number"><?php echo number_format($available_books); ?></div>
                <div class="stat-label">หนังสือพร้อมให้ยืม</div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section" id="features">
        <div class="features-container">
            <div class="section-title scroll-animate">
                <h2>คุณสมบัติเด่น</h2>
                <p>ระบบห้องสมุดดิจิทัลที่ครบครันและใช้งานง่าย เพื่อประสบการณ์การเรียนรู้ที่ดีที่สุด</p>
            </div>
            
            <div class="features-grid">
                <div class="feature-card scroll-animate">
                    <div class="feature-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <h3>ค้นหาอัจฉริยะ</h3>
                    <p>ระบบค้นหาที่ทรงพลัง สามารถค้นหาหนังสือได้จากชื่อ ผู้เขียน หมวดหมู่ ISBN และคำสำคัญต่างๆ ได้อย่างรวดเร็วและแม่นยำ</p>
                </div>
                
                
                <div class="feature-card scroll-animate">
                    <div class="feature-icon">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <h3>ใช้งานได้ทุกอุปกรณ์</h3>
                    <p>รองรับการใช้งานบนทุกอุปกรณ์ ไม่ว่าจะเป็นคอมพิวเตอร์ แท็บเล็ต หรือสมาร์ทโฟน ใช้งานได้ทุกที่ทุกเวลา</p>
                </div>
                
                <div class="feature-card scroll-animate">
                    <div class="feature-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <h3>ติดตามประวัติการยืม</h3>
                    <p>ดูประวัติการยืม-คืนหนังสือ กำหนดวันคืน และรับแจ้งเตือนก่อนครบกำหนด เพื่อไม่ให้เกิดค่าปรับ</p>
                </div>
                
            </div>
        </div>
    </section>

    <!-- Categories Section -->
    <section class="categories-section" id="categories">
        <div class="features-container">
            <div class="section-title scroll-animate">
                <h2>หมวดหมู่หนังสือ</h2>
                <p>เลือกอ่านหนังสือจากหมวดหมู่ที่หลากหลาย เพื่อตอบสนองความต้องการในการเรียนรู้ของคุณ</p>
            </div>
            
            <div class="categories-grid">
                <?php foreach (array_slice($popular_categories, 0, 8) as $category): ?>
                <div class="category-card scroll-animate">
                    <h4><?php echo htmlspecialchars($category['category_name']); ?></h4>
                    <p><?php echo htmlspecialchars($category['description'] ?: 'หนังสือในหมวดหมู่นี้มีเนื้อหาที่หลากหลายและน่าสนใจ'); ?></p>
                    <span class="category-count"><?php echo number_format($category['book_count']); ?> เล่ม</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Featured Books Section -->
    <section class="books-section" id="books">
        <div class="features-container">
            <div class="section-title scroll-animate">
                <h2>หนังสือแนะนำ</h2>
                <p>หนังสือใหม่ล่าสุดที่น่าสนใจและได้รับความนิยม พร้อมให้คุณค้นพบและเรียนรู้</p>
            </div>
            
            <div class="books-grid">
                <?php foreach ($featured_books as $book): 
                    $has_cover_image = checkCoverImageExists($book['cover_image']);
                ?>
                <div class="book-card scroll-animate">
                    <div class="book-cover">
                        <div class="book-availability">
                            <?php echo $book['available_copies']; ?>/<?php echo $book['total_copies']; ?> เล่ม
                        </div>
                        <?php if ($has_cover_image): ?>
                            <img src="<?php echo htmlspecialchars($book['cover_image']); ?>" 
                                 alt="<?php echo htmlspecialchars($book['title']); ?>"
                                 onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'fas fa-book\'></i>';">
                        <?php else: ?>
                            <i class="fas fa-book"></i>
                        <?php endif; ?>
                    </div>
                    
                    <div class="book-details">
                        <h4 title="<?php echo htmlspecialchars($book['title']); ?>">
                            <?php echo htmlspecialchars($book['title']); ?>
                        </h4>
                        
                        <?php if (!empty($book['authors'])): ?>
                        <div class="book-meta">
                            <i class="fas fa-user"></i>
                            <?php echo htmlspecialchars($book['authors']); ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['publisher_name'])): ?>
                        <div class="book-meta">
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars($book['publisher_name']); ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="book-category">
                            <?php echo htmlspecialchars($book['category_name'] ?: 'ไม่ระบุหมวดหมู่'); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div style="text-align: center; margin-top: 3rem;">
                <a href="login.php" class="btn-hero btn-hero-primary">
                    <i class="fas fa-eye"></i> ดูหนังสือทั้งหมด
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-container">
            <h3>
                <i class="fas fa-book-open"></i>
                ห้องสมุดดิจิทัล
            </h3>
            <p>วิทยาลัยเทคนิคหาดใหญ่ - ประตูสู่การเรียนรู้ในยุคดิจิทัล</p>
            
            <div class="footer-links">
                <a href="#home">หน้าแรก</a>
                <a href="#features">คุณสมบัติ</a>
                <a href="#categories">หมวดหมู่</a>
                <a href="#books">หนังสือแนะนำ</a>
                <a href="login.php">เข้าสู่ระบบ</a>
                <a href="register.php">สมัครสมาชิก</a>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> ห้องสมุดดิจิทัล วิทยาลัยเทคนิคหาดใหญ่. สงวนลิขสิทธิ์.</p>
            </div>
        </div>
    </footer>

    <script>
        // Smooth scrolling for navigation links
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

        // Scroll animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                }
            });
        }, observerOptions);

        // Observe all scroll-animate elements
        document.querySelectorAll('.scroll-animate').forEach(el => {
            observer.observe(el);
        });

        // Add stagger animation to grid items
        document.querySelectorAll('.stats-container .stat-item, .features-grid .feature-card, .categories-grid .category-card, .books-grid .book-card').forEach((item, index) => {
            item.style.animationDelay = `${index * 0.1}s`;
        });

        // Header scroll effect
        let lastScrollTop = 0;
        const header = document.querySelector('.header');

        window.addEventListener('scroll', () => {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            
            if (scrollTop > lastScrollTop && scrollTop > 100) {
                // Scrolling down
                header.style.transform = 'translateY(-100%)';
            } else {
                // Scrolling up
                header.style.transform = 'translateY(0)';
            }
            
            lastScrollTop = scrollTop;
        });

        // Add loading animation to initial elements
        window.addEventListener('load', () => {
            document.querySelectorAll('.loading-animation').forEach((el, index) => {
                setTimeout(() => {
                    el.style.animationDelay = `${index * 0.2}s`;
                    el.classList.add('active');
                }, 100);
            });
        });

        // Mobile menu toggle (if needed)
        const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
        const navLinks = document.querySelector('.nav-links');

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', () => {
                navLinks.classList.toggle('active');
            });
        }

        // Counter animation for stats
        function animateCounter(element, target) {
            const increment = target / 100;
            let current = 0;
            
            const updateCounter = () => {
                if (current < target) {
                    current += increment;
                    element.textContent = Math.floor(current).toLocaleString('th-TH');
                    requestAnimationFrame(updateCounter);
                } else {
                    element.textContent = target.toLocaleString('th-TH');
                }
            };
            
            updateCounter();
        }

        // Animate counters when they come into view
        const statsObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const numberElement = entry.target.querySelector('.stat-number');
                    const targetValue = parseInt(numberElement.textContent.replace(/,/g, ''));
                    
                    setTimeout(() => {
                        animateCounter(numberElement, targetValue);
                    }, 500);
                    
                    statsObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        document.querySelectorAll('.stat-item').forEach(item => {
            statsObserver.observe(item);
        });

        // Performance optimization
        let ticking = false;

        function updateScrollEffects() {
            // Add any scroll-based effects here
            ticking = false;
        }

        window.addEventListener('scroll', () => {
            if (!ticking) {
                requestAnimationFrame(updateScrollEffects);
                ticking = true;
            }
        });

        // Error handling for images
        document.querySelectorAll('img').forEach(img => {
            img.addEventListener('error', function() {
                this.style.display = 'none';
                const parent = this.parentElement;
                if (parent.classList.contains('book-cover')) {
                    parent.innerHTML = '<i class="fas fa-book"></i>';
                }
            });
        });

        // Add hover effects for cards
        document.querySelectorAll('.feature-card, .category-card, .book-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        console.log('🎉 Digital Library Landing Page Loaded Successfully!');
    </script>
</body>
</html>