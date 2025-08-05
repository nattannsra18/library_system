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

// Get current borrowed books
$stmt = $pdo->prepare("
    SELECT b.*, bo.title, bo.cover_image, c.category_name,
           CONCAT(a.first_name, ' ', a.last_name) as author_name,
           DATEDIFF(b.due_date, NOW()) as days_remaining
    FROM borrowing b 
    JOIN books bo ON b.book_id = bo.book_id 
    LEFT JOIN categories c ON bo.category_id = c.category_id
    LEFT JOIN book_authors ba ON bo.book_id = ba.book_id
    LEFT JOIN authors a ON ba.author_id = a.author_id
    WHERE b.user_id = ? AND b.status IN ('borrowed', 'overdue')
    ORDER BY b.due_date ASC
");
$stmt->execute([$user_id]);
$current_borrows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get notifications
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = FALSE 
    ORDER BY sent_date DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get borrowing statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_borrowed,
        COUNT(CASE WHEN status = 'returned' THEN 1 END) as total_returned,
        COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count
    FROM borrowing 
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent returned books
$stmt = $pdo->prepare("
    SELECT b.*, bo.title, bo.cover_image
    FROM borrowing b 
    JOIN books bo ON b.book_id = bo.book_id 
    WHERE b.user_id = ? AND b.status = 'returned'
    ORDER BY b.return_date DESC
    LIMIT 3
");
$stmt->execute([$user_id]);
$recent_returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get favorite books (if you have a favorites table)
$stmt = $pdo->prepare("
    SELECT bo.*, c.category_name
    FROM books bo
    LEFT JOIN categories c ON bo.category_id = c.category_id
    WHERE bo.status = 'available'
    ORDER BY RAND()
    LIMIT 4
");
$stmt->execute();
$recommended_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แดชบอร์ดผู้ใช้ - ห้องสมุดดิจิทัล</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #333;
            line-height: 1.6;
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
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
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
        }

        .btn-logout:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
        }

        /* Main Content */
        .dashboard-container {
            max-width: 1200px;
            margin: 100px auto 20px;
            padding: 0 20px;
        }

        /* Welcome Section */
        .welcome-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .profile-img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
        }

        .welcome-text h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .welcome-text p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .stat-card.borrowed i { color: #667eea; }
        .stat-card.returned i { color: #4caf50; }
        .stat-card.overdue i { color: #f44336; }
        .stat-card.available i { color: #ff9800; }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .card-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
        }

        .card-header i {
            font-size: 1.5rem;
        }

        .card-content {
            padding: 1.5rem;
            max-height: 400px;
            overflow-y: auto;
        }

        /* Borrowed Books */
        .book-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #e1e5e9;
        }

        .book-item:last-child {
            border-bottom: none;
        }

        .book-cover {
            width: 60px;
            height: 80px;
            border-radius: 8px;
            object-fit: cover;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .book-details h4 {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .book-details p {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .due-date {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .due-soon {
            background: #fff3cd;
            color: #856404;
        }

        .overdue {
            background: #f8d7da;
            color: #721c24;
        }

        .normal {
            background: #d1ecf1;
            color: #0c5460;
        }

        /* Notifications */
        .notification-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #e1e5e9;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
        }

        .notification-icon.warning { background: #ff9800; }
        .notification-icon.info { background: #2196f3; }
        .notification-icon.success { background: #4caf50; }

        .notification-content h4 {
            font-size: 1rem;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .notification-content p {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .notification-time {
            color: #999;
            font-size: 0.8rem;
        }

        /* Recommended Books */
        .recommended-section {
            margin-top: 2rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .section-header h2 {
            font-size: 1.8rem;
            color: #333;
        }

        .section-header i {
            color: #667eea;
            font-size: 2rem;
        }

        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .book-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
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
        }

        .book-info {
            padding: 1rem;
        }

        .book-title {
            font-size: 1.1rem;
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

        .book-category {
            display: inline-block;
            background: #e3f2fd;
            color: #1976d2;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
        }

        .btn-view {
            width: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.8rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .btn-view:hover {
            opacity: 0.9;
            transform: translateY(-2px);
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

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .quick-action-btn {
            background: white;
            border: 2px solid #667eea;
            color: #667eea;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .quick-action-btn:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 0 1rem;
                margin-top: 80px;
            }

            .nav-container {
                padding: 0 1rem;
            }

            .nav-links {
                display: none;
            }

            .welcome-section {
                flex-direction: column;
                text-align: center;
            }

            .welcome-text h1 {
                font-size: 2rem;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                flex-direction: column;
            }

            .books-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
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
                <li><a href="dashboard.php" class="active">แดชบอร์ด</a></li>
                <li><a href="index_user.php">หน้าแรก</a></li>
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

    <!-- Main Content -->
    <div class="dashboard-container">
        <!-- Welcome Section -->
        <section class="welcome-section">
            <div class="profile-img">
                <?php if (!empty($user['profile_image']) && file_exists($user['profile_image'])): ?>
                    <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <div class="welcome-text">
                <h1>ยินดีต้อนรับ, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h1>
                <p>รหัสนักเรียน: <?php echo htmlspecialchars($user['student_id']); ?> | แผนก: <?php echo htmlspecialchars($user['department'] ?: 'ไม่ระบุ'); ?></p>
                <p>สมาชิกตั้งแต่: <?php echo date('d/m/Y', strtotime($user['created_at'])); ?></p>
            </div>
        </section>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="index_user.php" class="quick-action-btn">
                <i class="fas fa-search"></i>
                ค้นหาหนังสือ
            </a>
            <a href="history.php" class="quick-action-btn">
                <i class="fas fa-history"></i>
                ประวัติการยืม
            </a>
            <a href="profile.php" class="quick-action-btn">
                <i class="fas fa-user-edit"></i>
                แก้ไขโปรไฟล์
            </a>
        </div>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card borrowed">
                <i class="fas fa-book-reader"></i>
                <div class="stat-number"><?php echo count($current_borrows); ?></div>
                <div class="stat-label">กำลังยืมอยู่</div>
            </div>
            <div class="stat-card returned">
                <i class="fas fa-check-circle"></i>
                <div class="stat-number"><?php echo $stats['total_returned']; ?></div>
                <div class="stat-label">คืนแล้ว</div>
            </div>
            <div class="stat-card overdue">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="stat-number"><?php echo $stats['overdue_count']; ?></div>
                <div class="stat-label">เกินกำหนด</div>
            </div>
            <div class="stat-card available">
                <i class="fas fa-books"></i>
                <div class="stat-number"><?php echo $stats['total_borrowed']; ?></div>
                <div class="stat-label">ยืมทั้งหมด</div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Current Borrowed Books -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-book-reader"></i>
                    <h3>หนังสือที่กำลังยืมอยู่</h3>
                </div>
                <div class="card-content">
                    <?php if (count($current_borrows) > 0): ?>
                        <?php foreach ($current_borrows as $borrow): ?>
                            <div class="book-item">
                                <div class="book-cover">
                                    <?php if (!empty($borrow['cover_image']) && file_exists($borrow['cover_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($borrow['cover_image']); ?>" alt="Book cover" style="width: 100%; height: 100%; border-radius: 8px; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fas fa-book"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="book-details">
                                    <h4><?php echo htmlspecialchars($borrow['title']); ?></h4>
                                    <p><i class="fas fa-user"></i> <?php echo htmlspecialchars($borrow['author_name'] ?: 'ไม่ระบุผู้เขียน'); ?></p>
                                    <p><i class="fas fa-tag"></i> <?php echo htmlspecialchars($borrow['category_name'] ?: 'ไม่ระบุหมวดหมู่'); ?></p>
                                    <p><i class="fas fa-calendar"></i> กำหนดคืน: <?php echo date('d/m/Y', strtotime($borrow['due_date'])); ?></p>
                                    <span class="due-date <?php 
                                        echo $borrow['days_remaining'] < 0 ? 'overdue' : 
                                             ($borrow['days_remaining'] <= 3 ? 'due-soon' : 'normal'); 
                                    ?>">
                                        <?php 
                                        if ($borrow['days_remaining'] < 0) {
                                            echo 'เกินกำหนด ' . abs($borrow['days_remaining']) . ' วัน';
                                        } elseif ($borrow['days_remaining'] == 0) {
                                            echo 'ครบกำหนดวันนี้';
                                        } else {
                                            echo 'เหลือ ' . $borrow['days_remaining'] . ' วัน';
                                        }
                                        ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-book-open"></i>
                            <h4>ไม่มีหนังสือที่กำลังยืมอยู่</h4>
                            <p>ไปค้นหาและยืมหนังสือที่สนใจได้เลย!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Notifications -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-bell"></i>
                    <h3>การแจ้งเตือน</h3>
                </div>
                <div class="card-content">
                    <?php if (count($notifications) > 0): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item">
                                <div class="notification-icon <?php echo $notification['type'] == 'overdue' ? 'warning' : 'info'; ?>">
                                    <i class="fas fa-<?php echo $notification['type'] == 'overdue' ? 'exclamation-triangle' : 'bell'; ?>"></i>
                                </div>
                                <div class="notification-content">
                                    <h4><?php echo htmlspecialchars($notification['title']); ?></h4>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <div class="notification-time"><?php echo date('d/m/Y H:i', strtotime($notification['sent_date'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <h4>ไม่มีการแจ้งเตือนใหม่</h4>
                            <p>คุณไม่มีการแจ้งเตือนที่ยังไม่ได้อ่าน</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function viewBook(bookId) {
            window.location.href = '../book_details.php?id=' + bookId;
        }

        // Auto-refresh notifications every 5 minutes
        setInterval(function() {
            location.reload();
        }, 300000);

        // Add smooth scrolling
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

        // Add animation to cards
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

        // Observe cards for animation
        document.querySelectorAll('.stat-card, .book-card, .card').forEach(card => {
            observer.observe(card);
        });

        // Real-time clock
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('th-TH');
            const dateString = now.toLocaleDateString('th-TH', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            // Update clock if element exists
            const clockElement = document.getElementById('current-time');
            if (clockElement) {
                clockElement.innerHTML = `<i class="fas fa-clock"></i> ${timeString} - ${dateString}`;
            }
        }

        // Update clock every second
        setInterval(updateClock, 1000);
        updateClock(); // Initial call

        // Notification management
        function markNotificationAsRead(notificationId) {
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    notification_id: notificationId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove notification from UI or mark as read
                    const notificationElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
                    if (notificationElement) {
                        notificationElement.style.opacity = '0.5';
                    }
                }
            })
            .catch(error => {
                console.error('Error marking notification as read:', error);
            });
        }

        // Add click handlers to notification items
        document.querySelectorAll('.notification-item').forEach((item, index) => {
            item.addEventListener('click', function() {
                // Mark as read when clicked
                this.style.opacity = '0.7';
                // Here you could add actual notification ID and call markNotificationAsRead
            });
        });

        // Book card hover effects enhancement
        document.querySelectorAll('.book-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.02)';
                this.style.boxShadow = '0 20px 40px rgba(0,0,0,0.2)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
                this.style.boxShadow = '0 5px 15px rgba(0,0,0,0.1)';
            });
        });

        // Enhanced stat card animations
        document.querySelectorAll('.stat-card').forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
            
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) rotate(2deg)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) rotate(0deg)';
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt + S for search
            if (e.altKey && e.key === 's') {
                e.preventDefault();
                window.location.href = 'index_user.php';
            }
            
            // Alt + H for history
            if (e.altKey && e.key === 'h') {
                e.preventDefault();
                window.location.href = 'history.php';
            }
            
            // Alt + P for profile
            if (e.altKey && e.key === 'p') {
                e.preventDefault();
                window.location.href = 'profile.php';
            }
        });

        // Progressive Web App features
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js')
                    .then(function(registration) {
                        console.log('ServiceWorker registration successful');
                    })
                    .catch(function(err) {
                        console.log('ServiceWorker registration failed');
                    });
            });
        }

        // Dark mode toggle (future feature)
        function toggleDarkMode() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
        }

        // Load dark mode preference
        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark-mode');
        }

        // Responsive image loading
        function loadImage(img) {
            return new Promise((resolve, reject) => {
                const image = new Image();
                image.onload = () => resolve(image);
                image.onerror = reject;
                image.src = img.dataset.src || img.src;
            });
        }

        // Lazy loading for images
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    loadImage(img)
                        .then(() => {
                            img.classList.add('loaded');
                        })
                        .catch(() => {
                            img.classList.add('error');
                        });
                    observer.unobserve(img);
                }
            });
        });

        document.querySelectorAll('img[data-src]').forEach(img => {
            imageObserver.observe(img);
        });

        // Add loading states
        function showLoading(element) {
            element.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังโหลด...';
            element.disabled = true;
        }

        function hideLoading(element, originalText) {
            element.innerHTML = originalText;
            element.disabled = false;
        }

        // Enhanced button interactions
        document.querySelectorAll('.btn-view').forEach(btn => {
            const originalText = btn.innerHTML;
            
            btn.addEventListener('click', function() {
                showLoading(this);
                
                setTimeout(() => {
                    hideLoading(this, originalText);
                }, 1000);
            });
        });

        // Print functionality
        function printDashboard() {
            window.print();
        }

        // Export data functionality (future feature)
        function exportUserData() {
            const userData = {
                user: <?php echo json_encode($user); ?>,
                currentBorrows: <?php echo json_encode($current_borrows); ?>,
                stats: <?php echo json_encode($stats); ?>
            };
            
            const dataStr = JSON.stringify(userData, null, 2);
            const dataBlob = new Blob([dataStr], {type: 'application/json'});
            const url = URL.createObjectURL(dataBlob);
            
            const link = document.createElement('a');
            link.href = url;
            link.download = 'user_data.json';
            link.click();
            
            URL.revokeObjectURL(url);
        }

        // Add refresh button functionality
        function refreshDashboard() {
            location.reload();
        }

        // Tooltips for stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                tooltip.textContent = this.querySelector('.stat-label').textContent;
                tooltip.style.cssText = `
                    position: absolute;
                    background: #333;
                    color: white;
                    padding: 0.5rem;
                    border-radius: 4px;
                    font-size: 0.8rem;
                    z-index: 1000;
                    pointer-events: none;
                    transform: translate(-50%, -100%);
                    margin-top: -10px;
                `;
                
                this.style.position = 'relative';
                this.appendChild(tooltip);
            });
            
            card.addEventListener('mouseleave', function() {
                const tooltip = this.querySelector('.tooltip');
                if (tooltip) {
                    tooltip.remove();
                }
            });
        });

        // Console branding
        console.log('%cห้องสมุดดิจิทัล - วิทยาลัยเทคนิคหาดใหญ่', 'color: #667eea; font-size: 20px; font-weight: bold;');
        console.log('%cUser Dashboard - Developed with ❤️', 'color: #764ba2; font-size: 14px;');
        console.log('พัฒนาโดย นายปิยพัชร์ ทองวงศ์');

        // Performance monitoring
        if (performance.mark) {
            performance.mark('dashboard-load-start');
            
            window.addEventListener('load', function() {
                performance.mark('dashboard-load-end');
                performance.measure('dashboard-load-time', 'dashboard-load-start', 'dashboard-load-end');
                
                const loadTime = performance.getEntriesByName('dashboard-load-time')[0];
                console.log(`Dashboard loaded in ${loadTime.duration.toFixed(2)}ms`);
            });
        }
    </script>
</body>
</html>