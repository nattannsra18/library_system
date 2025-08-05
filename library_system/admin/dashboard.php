<?php
require_once '../db.php';

// ตรวจสอบการล็อกอิน
require_admin();
if (!is_admin()) {
    header("Location: ../login.php");
    exit();
}

// ดึงข้อมูลแอดมิน
$admin_id = $_SESSION['admin_id'];
$stmt = $db->prepare("SELECT * FROM admins WHERE admin_id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

// ดึงสถิติ
$total_books = $db->query("SELECT COUNT(*) FROM books")->fetchColumn();
$total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_borrows = $db->query("SELECT COUNT(*) FROM borrowing WHERE status = 'borrowed'")->fetchColumn();
$total_overdue = $db->query("SELECT COUNT(*) FROM borrowing WHERE status = 'overdue'")->fetchColumn();

// ดึงการยืมที่รออนุมัติหรือเกินกำหนด
$stmt = $db->prepare("
    SELECT b.*, u.student_id, u.first_name, u.last_name, bo.title 
    FROM borrowing b 
    JOIN users u ON b.user_id = u.user_id 
    JOIN books bo ON b.book_id = bo.book_id 
    WHERE b.status IN ('borrowed', 'overdue') 
    ORDER BY b.borrow_date DESC 
    LIMIT 5
");
$stmt->execute();
$pending_borrows = $stmt->fetchAll();

// ดึงข้อมูลการยืมยอดนิยม
$stmt = $db->query("
    SELECT b.title, COUNT(*) as borrow_count 
    FROM borrowing br 
    JOIN books b ON br.book_id = b.book_id 
    GROUP BY b.book_id 
    ORDER BY borrow_count DESC 
    LIMIT 5
");
$popular_books = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แดชบอร์ดและรายงาน - ห้องสมุดดิจิทัล</title>
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

        .dashboard-container {
            max-width: 1200px;
            margin: 100px auto 20px;
            padding: 0 20px;
        }

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

        .nav-links a:hover {
            background: rgba(255,255,255,0.2);
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

        .welcome-section {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .welcome-section h1 {
            font-size: 2rem;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .welcome-section p {
            color: #666;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card i {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 1rem;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #666;
            font-size: 1.1rem;
        }

        .pending-borrows, .popular-books {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .pending-borrows h3, .popular-books h3 {
            font-size: 1.5rem;
            color: #333;
            margin-bottom: 1rem;
        }

        .borrow-item, .book-item {
            border-bottom: 1px solid #e1e5e9;
            padding: 1rem 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .borrow-details h4, .book-details h4 {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .borrow-details p, .book-details p {
            color: #666;
            font-size: 0.9rem;
        }

        .btn-action {
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-approve {
            background: #27ae60;
            color: white;
        }

        .btn-reject {
            background: #e74c3c;
            color: white;
        }

        .btn-action:hover {
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 0 1rem;
                margin-top: 80px;
            }

            .nav-container {
                padding: 0 1rem;
            }

            .borrow-item, .book-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <nav class="nav-container">
            <div class="logo">
                <i class="fas fa-book-open"></i>
                <span>ห้องสมุดดิจิทัล</span>
            </div>
            <ul class="nav-links">
                <li><a href="dashboard.php">แดชบอร์ด</a></li>
                <li><a href="books.php">จัดการหนังสือ</a></li>
                <li><a href="users.php">จัดการผู้ใช้</a></li>
                <li><a href="borrows.php">จัดการการยืม</a></li>
            </ul>
            <a href="../logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i>
                ออกจากระบบ
            </a>
        </nav>
    </header>

    <div class="dashboard-container">
        <section class="welcome-section">
            <h1>ยินดีต้อนรับ, <?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?></h1>
            <p>บทบาท: <?php echo $admin['role'] == 'super_admin' ? 'ผู้ดูแลระบบหลัก' : 'บรรณารักษ์'; ?> | อีเมล: <?php echo htmlspecialchars($admin['email']); ?></p>
        </section>

        <section class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-book"></i>
                <div class="stat-number"><?php echo number_format($total_books); ?></div>
                <div class="stat-label">หนังสือทั้งหมด</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <div class="stat-number"><?php echo number_format($total_users); ?></div>
                <div class="stat-label">ผู้ใช้ทั้งหมด</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-exchange-alt"></i>
                <div class="stat-number"><?php echo number_format($total_borrows); ?></div>
                <div class="stat-label">การยืมที่ดำเนินการอยู่</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-exclamation-circle"></i>
                <div class="stat-number"><?php echo number_format($total_overdue); ?></div>
                <div class="stat-label">การยืมที่เกินกำหนด</div>
            </div>
        </section>

        <section class="pending-borrows">
            <h3>การยืมที่ดำเนินการอยู่/เกินกำหนด</h3>
            <?php if (count($pending_borrows) > 0): ?>
                <?php foreach ($pending_borrows as $borrow): ?>
                    <div class="borrow-item">
                        <div class="borrow-details">
                            <h4><?php echo htmlspecialchars($borrow['title']); ?></h4>
                            <p>ผู้ยืม: <?php echo htmlspecialchars($borrow['first_name'] . ' ' . $borrow['last_name'] . ' (' . $borrow['student_id'] . ')'); ?></p>
                            <p>วันที่ยืม: <?php echo date('d/m/Y', strtotime($borrow['borrow_date'])); ?></p>
                            <p>กำหนดคืน: <?php echo date('d/m/Y', strtotime($borrow['due_date'])); ?></p>
                            <p>สถานะ: <?php echo $borrow['status'] == 'overdue' ? 'เกินกำหนด' : 'ยืมอยู่'; ?></p>
                        </div>
                        <div class="borrow-actions">
                            <a href="borrows.php?approve=<?php echo $borrow['borrow_id']; ?>" class="btn-action btn-approve">อนุมัติการคืน</a>
                            <a href="borrows.php?reject=<?php echo $borrow['borrow_id']; ?>" class="btn-action btn-reject">ปฏิเสธ</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>ไม่มีรายการยืมที่รออนุมัติ</p>
            <?php endif; ?>
        </section>

        <section class="popular-books">
            <h3>หนังสือยอดนิยม</h3>
            <?php if (count($popular_books) > 0): ?>
                <?php foreach ($popular_books as $book): ?>
                    <div class="book-item">
                        <div class="book-details">
                            <h4><?php echo htmlspecialchars($book['title']); ?></h4>
                            <p>ยืมทั้งหมด: <?php echo $book['borrow_count']; ?> ครั้ง</p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>ไม่มีข้อมูลหนังสือยอดนิยม</p>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>