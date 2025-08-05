<?php
require_once '../db.php';

if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

// ดึงการตั้งค่าระบบ (ถ้าต้องการใช้ในอนาคต)
$max_borrow_days = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_borrow_days'")->fetchColumn() ?: 14;

// จัดการการค้นหาและกรอง
$search = clean_input($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$where = ["1=1"];
$params = [];

if ($search) {
    $where[] = "(u.student_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR bo.title LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if ($status_filter) {
    $where[] = "b.status = ?";
    $params[] = $status_filter;
}

$where_clause = implode(" AND ", $where);

// ดึงรายการการยืม
$stmt = $db->prepare("
    SELECT b.*, u.student_id, u.first_name, u.last_name, bo.title, bo.cover_image, c.category_name,
           GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name, ' (', ba.role, ')') SEPARATOR ', ') as authors
    FROM borrowing b 
    JOIN users u ON b.user_id = u.user_id 
    JOIN books bo ON b.book_id = bo.book_id 
    LEFT JOIN categories c ON bo.category_id = c.category_id
    LEFT JOIN book_authors ba ON bo.book_id = ba.book_id
    LEFT JOIN authors a ON ba.author_id = a.author_id
    WHERE $where_clause
    GROUP BY b.borrow_id
    ORDER BY b.borrow_date DESC
");
$stmt->execute($params);
$borrows = $stmt->fetchAll();

// จัดการการอนุมัติการคืน
if (isset($_GET['approve'])) {
    $borrow_id = $_GET['approve'];
    try {
        $db->beginTransaction();
        
        // ตรวจสอบสถานะปัจจุบัน
        $stmt = $db->prepare("SELECT status, book_id, user_id FROM borrowing WHERE borrow_id = ?");
        $stmt->execute([$borrow_id]);
        $borrow = $stmt->fetch();
        
        if (!$borrow) {
            throw new Exception("ไม่พบรายการยืม");
        }
        
        if ($borrow['status'] === 'returned' || $borrow['status'] === 'rejected') {
            throw new Exception("รายการนี้ได้รับการอนุมัติหรือปฏิเสธแล้ว");
        }
        
        // อัพเดทสถานะการยืมเป็น returned
        $stmt = $db->prepare("UPDATE borrowing SET status = 'returned', return_date = NOW() WHERE borrow_id = ?");
        $stmt->execute([$borrow_id]);
        
        // อัพเดทจำนวนหนังสือที่ว่าง
        $stmt = $db->prepare("UPDATE books SET available_copies = available_copies + 1 WHERE book_id = ? AND available_copies < total_copies");
        $stmt->execute([$borrow['book_id']]);
        
        // ตรวจสอบว่าอัพเดทสำเร็จหรือไม่
        if ($stmt->rowCount() === 0) {
            throw new Exception("ไม่สามารถเพิ่มจำนวนเล่มที่ว่างได้ อาจถึงขีดจำกัดแล้ว");
        }
        
        // บันทึก activity log
        $log_stmt = $db->prepare("
            INSERT INTO activity_logs (admin_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
            VALUES (?, 'approve_return', 'borrowing', ?, ?, ?, ?, NOW())
        ");
        $log_stmt->execute([
            $_SESSION['admin_id'],
            $borrow_id,
            json_encode(['status' => 'returned', 'book_id' => $borrow['book_id'], 'user_id' => $borrow['user_id']]),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        $db->commit();
        header("Location: borrows.php?success=approved&borrow_id=$borrow_id");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Borrow approval error: " . $e->getMessage());
        header("Location: borrows.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// จัดการการปฏิเสธการคืน
if (isset($_GET['reject'])) {
    $borrow_id = $_GET['reject'];
    try {
        $db->beginTransaction();
        
        // ตรวจสอบสถานะปัจจุบัน
        $stmt = $db->prepare("SELECT status, book_id, user_id FROM borrowing WHERE borrow_id = ?");
        $stmt->execute([$borrow_id]);
        $borrow = $stmt->fetch();
        
        if (!$borrow) {
            throw new Exception("ไม่พบรายการยืม");
        }
        
        if ($borrow['status'] === 'returned' || $borrow['status'] === 'rejected') {
            throw new Exception("รายการนี้ได้รับการอนุมัติหรือปฏิเสธแล้ว");
        }
        
        // อัพเดทสถานะการยืมเป็น rejected
        $stmt = $db->prepare("UPDATE borrowing SET status = 'rejected' WHERE borrow_id = ?");
        $stmt->execute([$borrow_id]);
        
        // บันทึก activity log
        $log_stmt = $db->prepare("
            INSERT INTO activity_logs (admin_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
            VALUES (?, 'reject_return', 'borrowing', ?, ?, ?, ?, NOW())
        ");
        $log_stmt->execute([
            $_SESSION['admin_id'],
            $borrow_id,
            json_encode(['status' => 'rejected', 'book_id' => $borrow['book_id'], 'user_id' => $borrow['user_id']]),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        $db->commit();
        header("Location: borrows.php?success=rejected&borrow_id=$borrow_id");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Borrow rejection error: " . $e->getMessage());
        header("Location: borrows.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// จัดการสถานะ lost
if (isset($_GET['mark_lost'])) {
    $borrow_id = $_GET['mark_lost'];
    try {
        $db->beginTransaction();
        
        // ตรวจสอบสถานะปัจจุบัน
        $stmt = $db->prepare("SELECT status, book_id, user_id FROM borrowing WHERE borrow_id = ?");
        $stmt->execute([$borrow_id]);
        $borrow = $stmt->fetch();
        
        if (!$borrow) {
            throw new Exception("ไม่พบรายการยืม");
        }
        
        if ($borrow['status'] === 'lost') {
            throw new Exception("รายการนี้ถูกตั้งเป็นสูญหายแล้ว");
        }
        
        // อัพเดทสถานะการยืมเป็น lost
        $stmt = $db->prepare("UPDATE borrowing SET status = 'lost' WHERE borrow_id = ?");
        $stmt->execute([$borrow_id]);
        
        // อัพเดทสถานะหนังสือเป็น lost
        $stmt = $db->prepare("UPDATE books SET status = 'lost' WHERE book_id = ?");
        $stmt->execute([$borrow['book_id']]);

        // บันทึก activity log
        $log_stmt = $db->prepare("
            INSERT INTO activity_logs (admin_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
            VALUES (?, 'mark_lost', 'borrowing', ?, ?, ?, ?, NOW())
        ");
        $log_stmt->execute([
            $_SESSION['admin_id'],
            $borrow_id,
            json_encode(['status' => 'lost', 'book_id' => $borrow['book_id'], 'user_id' => $borrow['user_id']]),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        $db->commit();
        header("Location: borrows.php?success=lost&borrow_id=$borrow_id");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Mark lost error: " . $e->getMessage());
        header("Location: borrows.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการการยืม-คืน - ห้องสมุดดิจิทัล</title>
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

        .container {
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

        .borrow-management {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .borrow-management h2 {
            font-size: 1.8rem;
            color: #333;
            margin-bottom: 1.5rem;
        }

        .search-filter {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .search-filter input, .search-filter select {
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 1rem;
        }

        .search-filter input:focus, .search-filter select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .search-filter button {
            background: #3498db;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .search-filter button:hover {
            background: #2980b9;
        }

        .btn-reset {
            background: #e74c3c;
        }

        .btn-reset:hover {
            background: #c0392b;
        }

        .borrow-list {
            margin-top: 2rem;
        }

        .borrow-item {
            border-bottom: 1px solid #e1e5e9;
            padding: 1rem 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .borrow-details {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .borrow-details img {
            width: 50px;
            height: 75px;
            object-fit: cover;
            border-radius: 8px;
        }

        .borrow-details h4 {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .borrow-details p {
            color: #666;
            font-size: 0.9rem;
        }

        .borrow-actions a {
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            margin-left: 1rem;
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

        .btn-lost {
            background: #f1c40f;
            color: white;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .alert-success {
            background: #efe;
            color: #363;
            border: 1px solid #cfc;
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
                margin-top: 80px;
            }

            .borrow-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .search-filter {
                flex-direction: column;
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
                <li><a href="borrows.php" class="active">จัดการการยืม</a></li>
            </ul>
            <a href="../logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i>
                ออกจากระบบ
            </a>
        </nav>
    </header>

    <div class="container">
        <section class="borrow-management">
            <h2>จัดการการยืม-คืน</h2>
            
            <?php if (isset($_GET['success']) && isset($_GET['borrow_id'])): ?>
                <?php
                $borrow_id = $_GET['borrow_id'];
                $stmt = $db->prepare("SELECT bo.title, u.first_name, u.last_name FROM borrowing b JOIN books bo ON b.book_id = bo.book_id JOIN users u ON b.user_id = u.user_id WHERE b.borrow_id = ?");
                $stmt->execute([$borrow_id]);
                $borrow_info = $stmt->fetch();
                ?>
                <div class="alert alert-success">
                    <?php
                    switch ($_GET['success']) {
                        case 'approved':
                            echo "อนุมัติการคืนหนังสือ '{$borrow_info['title']}' โดย {$borrow_info['first_name']} {$borrow_info['last_name']} สำเร็จ";
                            break;
                        case 'rejected':
                            echo "ปฏิเสธการคืนหนังสือ '{$borrow_info['title']}' โดย {$borrow_info['first_name']} {$borrow_info['last_name']} สำเร็จ";
                            break;
                        case 'lost':
                            echo "ตั้งสถานะหนังสือ '{$borrow_info['title']}' โดย {$borrow_info['first_name']} {$borrow_info['last_name']} เป็นสูญหายสำเร็จ";
                            break;
                    }
                    ?>
                </div>
            <?php elseif (isset($_GET['error'])): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars(urldecode($_GET['error'])); ?></div>
            <?php endif; ?>

            <form method="GET" action="borrows.php" class="search-filter">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="ค้นหาด้วยรหัสนักเรียน, ชื่อ, หรือชื่อหนังสือ">
                <select name="status">
                    <option value="">ทุกสถานะ</option>
                    <option value="borrowed" <?php echo $status_filter == 'borrowed' ? 'selected' : ''; ?>>ยืมอยู่</option>
                    <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>เกินกำหนด</option>
                    <option value="returned" <?php echo $status_filter == 'returned' ? 'selected' : ''; ?>>คืนแล้ว</option>
                    <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>ปฏิเสธ</option>
                    <option value="lost" <?php echo $status_filter == 'lost' ? 'selected' : ''; ?>>สูญหาย</option>
                </select>
                <button type="submit">ค้นหา</button>
                <button type="button" class="btn-reset" onclick="window.location.href='borrows.php'">รีเซ็ต</button>
            </form>

            <div class="borrow-list">
                <h3>รายการการยืม</h3>
                <?php if (count($borrows) > 0): ?>
                    <?php foreach ($borrows as $borrow): ?>
                        <div class="borrow-item">
                            <div class="borrow-details">
                                <img src="<?php echo htmlspecialchars($borrow['cover_image'] ?: '../assets/default_book.jpg'); ?>" alt="Book cover">
                                <div>
                                    <h4><?php echo htmlspecialchars($borrow['title']); ?></h4>
                                    <p>ผู้ยืม: <?php echo htmlspecialchars($borrow['first_name'] . ' ' . $borrow['last_name'] . ' (' . $borrow['student_id'] . ')'); ?></p>
                                    <p>หมวดหมู่: <?php echo htmlspecialchars($borrow['category_name'] ?: 'ไม่ระบุ'); ?></p>
                                    <p>ผู้เขียน: <?php echo htmlspecialchars($borrow['authors'] ?: 'ไม่ระบุ'); ?></p>
                                    <p>วันที่ยืม: <?php echo date('d/m/Y', strtotime($borrow['borrow_date'])); ?></p>
                                    <p>กำหนดคืน: <?php echo date('d/m/Y', strtotime($borrow['due_date'])); ?></p>
                                    <p>สถานะ: <?php
                                        switch ($borrow['status']) {
                                            case 'borrowed':
                                                echo 'ยืมอยู่';
                                                break;
                                            case 'overdue':
                                                echo 'เกินกำหนด';
                                                break;
                                            case 'returned':
                                                echo 'คืนแล้ว';
                                                break;
                                            case 'rejected':
                                                echo 'ปฏิเสธการคืน';
                                                break;
                                            case 'lost':
                                                echo 'สูญหาย';
                                                break;
                                        }
                                    ?></p>
                                </div>
                            </div>
                            <?php if (in_array($borrow['status'], ['borrowed', 'overdue'])): ?>
                                <div class="borrow-actions">
                                    <a href="borrows.php?approve=<?php echo $borrow['borrow_id']; ?>" class="btn-approve">อนุมัติการคืน</a>
                                    <a href="borrows.php?reject=<?php echo $borrow['borrow_id']; ?>" class="btn-reject" onclick="return confirm('ยืนยันการปฏิเสธการคืน?')">ปฏิเสธ</a>
                                    <a href="borrows.php?mark_lost=<?php echo $borrow['borrow_id']; ?>" class="btn-lost" onclick="return confirm('ยืนยันการตั้งสถานะเป็นสูญหาย?')">ตั้งเป็นสูญหาย</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>ไม่มีรายการยืมในระบบ</p>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>
</html>