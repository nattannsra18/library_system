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
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($book_id <= 0) {
    header("Location: users/index_user.php");
    exit();
}

// Get book details with authors and publisher
$stmt = $pdo->prepare("
    SELECT b.*, c.category_name, p.publisher_name,
           GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors,
           GROUP_CONCAT(a.author_id) as author_ids
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN publishers p ON b.publisher_id = p.publisher_id
    LEFT JOIN book_authors ba ON b.book_id = ba.book_id
    LEFT JOIN authors a ON ba.author_id = a.author_id
    WHERE b.book_id = ?
    GROUP BY b.book_id
");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header("Location: users/index_user.php");
    exit();
}

// Get user information
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if user already borrowed this book
$stmt = $pdo->prepare("
    SELECT * FROM borrowing 
    WHERE user_id = ? AND book_id = ? AND status IN ('borrowed', 'overdue')
");
$stmt->execute([$user_id, $book_id]);
$current_borrow = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user's current borrowed books count
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM borrowing 
    WHERE user_id = ? AND status IN ('borrowed', 'overdue')
");
$stmt->execute([$user_id]);
$user_borrowed_count = $stmt->fetchColumn();

// Get system settings
$stmt = $pdo->prepare("
    SELECT setting_key, setting_value 
    FROM system_settings 
    WHERE setting_key IN ('max_borrow_books', 'max_borrow_days', 'fine_per_day')
");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$max_borrow_books = (int)($settings['max_borrow_books'] ?? 5);
$max_borrow_days = (int)($settings['max_borrow_days'] ?? 14);
$fine_per_day = (float)($settings['fine_per_day'] ?? 5.00);

// Check for unpaid fines
$stmt = $pdo->prepare("SELECT COUNT(*) FROM fines WHERE user_id = ? AND status = 'unpaid'");
$stmt->execute([$user_id]);
$unpaid_fines = $stmt->fetchColumn();

// Get borrowing history for this book
$stmt = $pdo->prepare("
    SELECT br.*, u.first_name, u.last_name
    FROM borrowing br
    JOIN users u ON br.user_id = u.user_id
    WHERE br.book_id = ? AND br.status = 'returned'
    ORDER BY br.return_date DESC
    LIMIT 5
");
$stmt->execute([$book_id]);
$borrowing_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get related books (same category)
$stmt = $pdo->prepare("
    SELECT b.*, c.category_name,
           GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN book_authors ba ON b.book_id = ba.book_id
    LEFT JOIN authors a ON ba.author_id = a.author_id
    WHERE b.category_id = ? AND b.book_id != ? AND b.status = 'available'
    GROUP BY b.book_id
    ORDER BY RAND()
    LIMIT 4
");
$stmt->execute([$book['category_id'], $book_id]);
$related_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate estimated due date if borrowed today
$estimated_due_date = date('Y-m-d', strtotime("+{$max_borrow_days} days"));
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($book['title']); ?> - ห้องสมุดดิจิทัล</title>
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

        .nav-links a:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.8rem 1.5rem;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 25px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .back-btn:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
        }

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 100px auto 20px;
            padding: 0 2rem;
        }

        .book-detail-container {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .book-header {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
            padding: 2rem;
        }

        .book-cover-container {
            position: relative;
        }

        .book-cover {
            width: 100%;
            height: 400px;
            border-radius: 15px;
            object-fit: cover;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 4rem;
        }

        .availability-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
        }

        .available {
            background: #4caf50;
            color: white;
        }

        .unavailable {
            background: #f44336;
            color: white;
        }

        .limited {
            background: #ff9800;
            color: white;
        }

        .book-info {
            padding: 1rem 0;
        }

        .book-title {
            font-size: 2.5rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 0.5rem;
            line-height: 1.2;
        }

        .book-subtitle {
            font-size: 1.3rem;
            color: #666;
            margin-bottom: 1rem;
            font-style: italic;
        }

        .book-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .meta-item i {
            color: #667eea;
            font-size: 1.2rem;
            width: 20px;
        }

        .meta-label {
            font-weight: 600;
            color: #333;
        }

        .meta-value {
            color: #666;
        }

        .book-description {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 15px;
            margin: 2rem 0;
        }

        .book-description h3 {
            color: #333;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .book-description p {
            line-height: 1.8;
            color: #555;
        }

        /* Borrowing Section */
        .borrowing-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .borrow-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .borrow-stat {
            background: rgba(255,255,255,0.15);
            padding: 1rem;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }

        .borrow-stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
        }

        .borrow-stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .borrow-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 25px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: white;
            color: #667eea;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.3);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        .warning-message {
            background: rgba(255,193,7,0.2);
            border: 2px solid rgba(255,193,7,0.3);
            color: #fff3cd;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }

        .error-message {
            background: rgba(220,53,69,0.2);
            border: 2px solid rgba(220,53,69,0.3);
            color: #f8d7da;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }

        /* Additional Info Sections */
        .info-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .info-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .info-header h3 {
            color: #333;
            font-size: 1.3rem;
        }

        .info-header i {
            color: #667eea;
            font-size: 1.3rem;
        }

        /* History List */
        .history-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .history-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .history-content h4 {
            color: #333;
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .history-content p {
            color: #666;
            font-size: 0.9rem;
        }

        /* Related Books */
        .related-books {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .related-books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .related-book-card {
            background: #f8f9fa;
            border-radius: 10px;
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .related-book-card:hover {
            transform: translateY(-3px);
        }

        .related-book-image {
            height: 150px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
        }

        .related-book-info {
            padding: 1rem;
        }

        .related-book-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 0.5rem;
            line-height: 1.3;
            height: 2.6rem;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .related-book-author {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .btn-related {
            width: 100%;
            background: #667eea;
            color: white;
            border: none;
            padding: 0.5rem;
            border-radius: 5px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .btn-related:hover {
            background: #5a6fd8;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }

            .main-content {
                padding: 0 1rem;
                margin-top: 80px;
            }

            .book-header {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .book-cover {
                height: 300px;
                margin: 0 auto;
                max-width: 250px;
            }

            .book-title {
                font-size: 2rem;
            }

            .book-meta {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .borrow-actions {
                flex-direction: column;
            }

            .related-books-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Loading Animation */
        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            color: white;
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

        /* Notification */
        .notification {
            position: fixed;
            top: 100px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            z-index: 9998;
            transform: translateX(100%);
            transition: transform 0.3s ease;
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
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <nav class="nav-container">
            <div class="logo">
                <i class="fas fa-book-open"></i>
                <span>ห้องสมุดดิจิทัล</span>
            </div>
            <ul class="nav-links">
                <li><a href="users/dashboard.php">แดชบอร์ด</a></li>
                <li><a href="users/index_user.php">หน้าแรก</a></li>
                <li><a href="users/profile.php">โปรไฟล์</a></li>
                <li><a href="users/history.php">ประวัติการยืม</a></li>
            </ul>
            <div>
                <a href="users/index_user.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    กลับ
                </a>
            </div>
        </nav>
    </header>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Book Detail Container -->
        <div class="book-detail-container">
            <!-- Book Header -->
            <div class="book-header">
                <div class="book-cover-container">
                    <div class="book-cover">
                        <?php if (!empty($book['cover_image'])): ?>
                            <img src="<?php echo htmlspecialchars($book['cover_image']); ?>" 
                                 alt="<?php echo htmlspecialchars($book['title']); ?>"
                                 style="width: 100%; height: 100%; object-fit: cover; border-radius: 15px;">
                        <?php else: ?>
                            <i class="fas fa-book"></i>
                        <?php endif; ?>
                    </div>
                    <div class="availability-badge <?php 
                        echo $book['available_copies'] > 5 ? 'available' : 
                             ($book['available_copies'] > 0 ? 'limited' : 'unavailable'); 
                    ?>">
                        <?php 
                        if ($book['available_copies'] > 5) {
                            echo 'พร้อมให้บริการ';
                        } elseif ($book['available_copies'] > 0) {
                            echo 'เหลือน้อย (' . $book['available_copies'] . ' เล่ม)';
                        } else {
                            echo 'ไม่ว่าง';
                        }
                        ?>
                    </div>
                </div>

                <div class="book-info">
                    <h1 class="book-title"><?php echo htmlspecialchars($book['title']); ?></h1>
                    <?php if (!empty($book['subtitle'])): ?>
                        <p class="book-subtitle"><?php echo htmlspecialchars($book['subtitle']); ?></p>
                    <?php endif; ?>

                    <div class="book-meta">
                        <div class="meta-item">
                            <i class="fas fa-user"></i>
                            <span class="meta-label">ผู้เขียน:</span>
                            <span class="meta-value"><?php echo htmlspecialchars($book['authors'] ?: 'ไม่ระบุ'); ?></span>
                        </div>

                        <div class="meta-item">
                            <i class="fas fa-building"></i>
                            <span class="meta-label">สำนักพิมพ์:</span>
                            <span class="meta-value"><?php echo htmlspecialchars($book['publisher_name'] ?: 'ไม่ระบุ'); ?></span>
                        </div>

                        <div class="meta-item">
                            <i class="fas fa-tag"></i>
                            <span class="meta-label">หมวดหมู่:</span>
                            <span class="meta-value"><?php echo htmlspecialchars($book['category_name'] ?: 'ไม่ระบุ'); ?></span>
                        </div>

                        <div class="meta-item">
                            <i class="fas fa-barcode"></i>
                            <span class="meta-label">ISBN:</span>
                            <span class="meta-value"><?php echo htmlspecialchars($book['isbn'] ?: 'ไม่ระบุ'); ?></span>
                        </div>

                        <div class="meta-item">
                            <i class="fas fa-calendar"></i>
                            <span class="meta-label">ปีที่พิมพ์:</span>
                            <span class="meta-value"><?php echo htmlspecialchars($book['publication_year'] ?: 'ไม่ระบุ'); ?></span>
                        </div>

                        <div class="meta-item">
                            <i class="fas fa-file-alt"></i>
                            <span class="meta-label">จำนวนหน้า:</span>
                            <span class="meta-value"><?php echo $book['pages'] ? number_format($book['pages']) . ' หน้า' : 'ไม่ระบุ'; ?></span>
                        </div>
                    </div>

                    <?php if (!empty($book['description'])): ?>
                        <div class="book-description">
                            <h3><i class="fas fa-info-circle"></i> รายละเอียด</h3>
                            <p><?php echo nl2br(htmlspecialchars($book['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Borrowing Section -->
            <div class="borrowing-section">
                <div class="borrow-info">
                    <div class="borrow-stat">
                        <div class="borrow-stat-number"><?php echo $book['available_copies']; ?>/<?php echo $book['total_copies']; ?></div>
                        <div class="borrow-stat-label">เล่มที่พร้อมให้บริการ</div>
                    </div>
                    <div class="borrow-stat">
                        <div class="borrow-stat-number"><?php echo $user_borrowed_count; ?>/<?php echo $max_borrow_books; ?></div>
                        <div class="borrow-stat-label">หนังสือที่คุณยืมอยู่</div>
                    </div>
                    <div class="borrow-stat">
                        <div class="borrow-stat-number"><?php echo $max_borrow_days; ?></div>
                        <div class="borrow-stat-label">วันที่อนุญาตให้ยืม</div>
                    </div>
                    <div class="borrow-stat">
                        <div class="borrow-stat-number"><?php echo number_format($fine_per_day, 2); ?></div>
                        <div class="borrow-stat-label">ค่าปรับต่อวัน (บาท)</div>
                    </div>
                </div>

                <?php if ($current_borrow): ?>
                    <div class="warning-message">
                        <i class="fas fa-info-circle"></i>
                        คุณได้ยืมหนังสือเล่มนี้แล้วในวันที่ <?php echo date('d/m/Y', strtotime($current_borrow['borrow_date'])); ?>
                        กำหนดคืนวันที่ <?php echo date('d/m/Y', strtotime($current_borrow['due_date'])); ?>
                    </div>
                    <div class="borrow-actions">
                        <button class="btn btn-secondary" onclick="returnBook(<?php echo $current_borrow['borrow_id']; ?>)">
                            <i class="fas fa-undo"></i>
                            คืนหนังสือ
                        </button>
                        <a href="users/history.php" class="btn btn-secondary">
                            <i class="fas fa-history"></i>
                            ดูประวัติการยืม
                        </a>
                    </div>
                <?php elseif ($unpaid_fines > 0): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        คุณมีค่าปรับที่ยังไม่ได้ชำระ กรุณาชำระค่าปรับก่อนยืมหนังสือใหม่
                    </div>
                    <div class="borrow-actions">
                        <button class="btn btn-primary" disabled>
                            <i class="fas fa-ban"></i>
                            ไม่สามารถยืมได้
                        </button>
                        <a href="users/profile.php" class="btn btn-secondary">
                            <i class="fas fa-credit-card"></i>
                            ดูค่าปรับ
                        </a>
                    </div>
                <?php elseif ($user_borrowed_count >= $max_borrow_books): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        คุณยืมหนังสือครบจำนวนสูงสุดแล้ว (<?php echo $max_borrow_books; ?> เล่ม) กรุณาคืนหนังสือบางเล่มก่อน
                    </div>
                    <div class="borrow-actions">
                        <button class="btn btn-primary" disabled>
                            <i class="fas fa-ban"></i>
                            ไม่สามารถยืมได้
                        </button>
                        <a href="users/dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-book-reader"></i>
                            ดูหนังสือที่ยืมอยู่
                        </a>
                    </div>
                <?php elseif ($book['available_copies'] <= 0): ?>
                    <div class="error-message">
                        <i class="fas fa-times-circle"></i>
                        หนังสือเล่มนี้ถูกยืมหมดแล้ว กรุณาลองใหม่ภายหลัง
                    </div>
                    <div class="borrow-actions">
                        <button class="btn btn-primary" disabled>
                            <i class="fas fa-ban"></i>
                            ไม่สามารถยืมได้
                        </button>
                        <button class="btn btn-secondary" onclick="notifyWhenAvailable(<?php echo $book_id; ?>)">
                            <i class="fas fa-bell"></i>
                            แจ้งเตือนเมื่อว่าง
                        </button>
                    </div>
                <?php elseif ($book['status'] !== 'available'): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        หนังสือเล่มนี้ไม่พร้อมให้บริการในขณะนี้
                    </div>
                    <div class="borrow-actions">
                        <button class="btn btn-primary" disabled>
                            <i class="fas fa-ban"></i>
                            ไม่สามารถยืมได้
                        </button>
                    </div>
                <?php else: ?>
                    <div class="warning-message">
                        <i class="fas fa-info-circle"></i>
                        หากยืมวันนี้ กำหนดคืนวันที่ <?php echo date('d/m/Y', strtotime($estimated_due_date)); ?>
                    </div>
                    <div class="borrow-actions">
                        <button class="btn btn-primary" onclick="borrowBook(<?php echo $book_id; ?>)">
                            <i class="fas fa-hand-holding"></i>
                            ยืมหนังสือเล่มนี้
                        </button>
                        <button class="btn btn-secondary" onclick="addToWishlist(<?php echo $book_id; ?>)">
                            <i class="fas fa-heart"></i>
                            เพิ่มในรายการโปรด
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Additional Info Grid -->
        <div class="info-grid">
            <!-- Borrowing History -->
            <div class="info-card">
                <div class="info-header">
                    <i class="fas fa-history"></i>
                    <h3>ประวัติการยืม</h3>
                </div>
                
                <?php if (empty($borrowing_history)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h4>ยังไม่มีประวัติการยืม</h4>
                        <p>หนังสือเล่มนี้ยังไม่เคยถูกยืม</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($borrowing_history as $history): ?>
                        <div class="history-item">
                            <div class="history-avatar">
                                <?php echo strtoupper(substr($history['first_name'], 0, 1)); ?>
                            </div>
                            <div class="history-content">
                                <h4><?php echo htmlspecialchars($history['first_name'] . ' ' . $history['last_name']); ?></h4>
                                <p>ยืมเมื่อ <?php echo date('d/m/Y', strtotime($history['borrow_date'])); ?> 
                                   - คืนเมื่อ <?php echo date('d/m/Y', strtotime($history['return_date'])); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Book Statistics -->
            <div class="info-card">
                <div class="info-header">
                    <i class="fas fa-chart-bar"></i>
                    <h3>สถิติหนังสือ</h3>
                </div>
                
                <div class="book-stats">
                    <div class="stat-item">
                        <div class="stat-icon">
                            <i class="fas fa-eye"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo count($borrowing_history); ?></div>
                            <div class="stat-label">ครั้งที่ถูกยืม</div>
                        </div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo count(array_unique(array_column($borrowing_history, 'user_id'))); ?></div>
                            <div class="stat-label">ผู้ยืมที่แตกต่าง</div>
                        </div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo date('d/m/Y', strtotime($book['created_at'])); ?></div>
                            <div class="stat-label">วันที่เพิ่มในระบบ</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Related Books -->
        <?php if (!empty($related_books)): ?>
            <div class="related-books">
                <div class="info-header">
                    <i class="fas fa-books"></i>
                    <h3>หนังสือที่เกี่ยวข้อง</h3>
                </div>
                
                <div class="related-books-grid">
                    <?php foreach ($related_books as $related): ?>
                        <div class="related-book-card">
                            <div class="related-book-image">
                                <?php if (!empty($related['cover_image'])): ?>
                                    <img src="<?php echo htmlspecialchars($related['cover_image']); ?>" 
                                         alt="<?php echo htmlspecialchars($related['title']); ?>"
                                         style="width: 100%; height: 100%; object-fit: cover;">
                                <?php else: ?>
                                    <i class="fas fa-book"></i>
                                <?php endif; ?>
                            </div>
                            <div class="related-book-info">
                                <h4 class="related-book-title"><?php echo htmlspecialchars($related['title']); ?></h4>
                                <p class="related-book-author"><?php echo htmlspecialchars($related['authors'] ?: 'ไม่ระบุผู้เขียน'); ?></p>
                                <button class="btn-related" onclick="viewBook(<?php echo $related['book_id']; ?>)">
                                    ดูรายละเอียด
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Loading Overlay -->
    <div class="loading" id="loadingOverlay">
        <div>
            <div class="spinner"></div>
            <p>กำลังดำเนินการ...</p>
        </div>
    </div>

    <style>
        .book-stats {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .stat-item {
                flex-direction: column;
                text-align: center;
            }

            .stat-icon {
                margin-bottom: 0.5rem;
            }
        }
    </style>

    <script>
        // Borrow book function
        function borrowBook(bookId) {
            if (confirm('คุณต้องการยืมหนังสือ "<?php echo addslashes($book['title']); ?>" หรือไม่?\n\nกำหนดคืน: <?php echo date('d/m/Y', strtotime($estimated_due_date)); ?>')) {
                showLoading();
                
                fetch('users/borrow_book.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        book_id: bookId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showNotification('ยืมหนังสือสำเร็จ! กรุณาคืนภายในกำหนด', 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    } else {
                        showNotification('เกิดข้อผิดพลาด: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    hideLoading();
                    showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
                    console.error('Error:', error);
                });
            }
        }

        // Return book function
        function returnBook(borrowId) {
            if (confirm('คุณต้องการคืนหนังสือ "<?php echo addslashes($book['title']); ?>" หรือไม่?')) {
                showLoading();
                
                fetch('users/return_book.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        borrow_id: borrowId,
                        condition: 'good',
                        notes: 'คืนผ่านระบบออนไลน์'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showNotification('คืนหนังสือสำเร็จ!', 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    } else {
                        showNotification('เกิดข้อผิดพลาด: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    hideLoading();
                    showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
                    console.error('Error:', error);
                });
            }
        }

        // View other book
        function viewBook(bookId) {
            window.location.href = 'book_details.php?id=' + bookId;
        }

        // Add to wishlist (placeholder function)
        function addToWishlist(bookId) {
            showNotification('เพิ่มในรายการโปรดแล้ว', 'success');
        }

        // Notify when available (placeholder function)
        function notifyWhenAvailable(bookId) {
            showNotification('ระบบจะแจ้งเตือนเมื่อหนังสือว่าง', 'success');
        }

        // Loading functions
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // Notification function
        function showNotification(message, type = 'success') {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.notification');
            existingNotifications.forEach(notification => notification.remove());

            // Create new notification
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                ${message}
            `;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            // Hide notification after 5 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 5000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // B for borrow (if available)
            if (e.key.toLowerCase() === 'b' && !e.ctrlKey && !e.altKey) {
                const borrowBtn = document.querySelector('.btn-primary:not([disabled])');
                if (borrowBtn && borrowBtn.textContent.includes('ยืม')) {
                    e.preventDefault();
                    borrowBtn.click();
                }
            }
            
            // R for return (if borrowed)
            if (e.key.toLowerCase() === 'r' && !e.ctrlKey && !e.altKey) {
                const returnBtn = document.querySelector('button[onclick*="returnBook"]');
                if (returnBtn) {
                    e.preventDefault();
                    returnBtn.click();
                }
            }
            
            // Escape to go back
            if (e.key === 'Escape') {
                window.location.href = 'users/index_user.php';
            }
        });

        // Smooth scrolling for internal links
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

        // Book cover fallback
        document.querySelectorAll('img').forEach(img => {
            img.addEventListener('error', function() {
                this.style.display = 'none';
                this.parentElement.innerHTML = '<i class="fas fa-book"></i>';
            });
        });

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '0';
                    entry.target.style.transform = 'translateY(20px)';
                    entry.target.style.transition = 'all 0.6s ease';
                    
                    setTimeout(() => {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }, 100);
                    
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        // Observe elements for animation
        document.querySelectorAll('.info-card, .related-book-card, .book-detail-container').forEach(element => {
            observer.observe(element);
        });

        // Print book details
        function printBookDetails() {
            window.print();
        }

        // Share book (placeholder)
        function shareBook() {
            if (navigator.share) {
                navigator.share({
                    title: '<?php echo addslashes($book['title']); ?>',
                    text: 'ดูหนังสือเล่มนี้ที่ห้องสมุดดิจิทัล',
                    url: window.location.href
                });
            } else {
                // Fallback: copy URL to clipboard
                navigator.clipboard.writeText(window.location.href).then(() => {
                    showNotification('คัดลอกลิงก์แล้ว', 'success');
                });
            }
        }

        // Enhanced book card interactions
        document.querySelectorAll('.related-book-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px) scale(1.02)';
                this.style.boxShadow = '0 10px 25px rgba(0,0,0,0.15)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
                this.style.boxShadow = 'none';
            });
        });

        // Console branding
        console.log('%cห้องสมุดดิจิทัล - วิทยาลัยเทคนิคหาดใหญ่', 'color: #667eea; font-size: 20px; font-weight: bold;');
        console.log('%cBook Details Page - Developed with ❤️', 'color: #764ba2; font-size: 14px;');
        console.log('พัฒนาโดย นายปิยพัชร์ ทองวงศ์');

        // Performance monitoring
        if (performance.mark) {
            performance.mark('book-details-load-start');
            
            window.addEventListener('load', function() {
                performance.mark('book-details-load-end');
                performance.measure('book-details-load-time', 'book-details-load-start', 'book-details-load-end');
                
                const loadTime = performance.getEntriesByName('book-details-load-time')[0];
                console.log(`Book details loaded in ${loadTime.duration.toFixed(2)}ms`);
            });
        }

        // Add context menu for additional actions
        document.addEventListener('contextmenu', function(e) {
            if (e.target.closest('.book-cover') || e.target.closest('.book-title')) {
                e.preventDefault();
                
                // Could show custom context menu here
                console.log('Book context menu');
            }
        });

        // Breadcrumb navigation
        function updateBreadcrumb() {
            const breadcrumb = document.createElement('nav');
            breadcrumb.className = 'breadcrumb';
            breadcrumb.innerHTML = `
                <a href="users/index_user.php">หน้าแรก</a>
                <span>/</span>
                <span><?php echo htmlspecialchars($book['category_name'] ?: 'หนังสือ'); ?></span>
                <span>/</span>
                <span><?php echo htmlspecialchars($book['title']); ?></span>
            `;
            
            const mainContent = document.querySelector('.main-content');
            mainContent.insertBefore(breadcrumb, mainContent.firstChild);
        }

        // Call breadcrumb function
        updateBreadcrumb();

        // Add breadcrumb styles
        const breadcrumbStyles = document.createElement('style');
        breadcrumbStyles.textContent = `
            .breadcrumb {
                background: white;
                padding: 1rem;
                border-radius: 10px;
                margin-bottom: 1rem;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                font-size: 0.9rem;
            }
            
            .breadcrumb a {
                color: #667eea;
                text-decoration: none;
            }
            
            .breadcrumb a:hover {
                text-decoration: underline;
            }
            
            .breadcrumb span {
                color: #666;
                margin: 0 0.5rem;
            }
            
            .breadcrumb span:last-child {
                color: #333;
                font-weight: 500;
            }
        `;
        document.head.appendChild(breadcrumbStyles);
    </script>
</body>
</html>