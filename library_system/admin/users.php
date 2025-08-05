<?php
require_once '../db.php';

// ตรวจสอบการล็อกอิน
require_admin();
if (!is_admin()) {
    header("Location: ../login.php");
    exit();
}

// ดึงรายการผู้ใช้
$stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();

// ดึงข้อมูลการยืมของผู้ใช้เมื่อเลือก
$user_borrows = [];
if (isset($_GET['view_borrows'])) {
    $user_id = $_GET['view_borrows'];
    $stmt = $db->prepare("
        SELECT b.*, bo.title, bo.cover_image 
        FROM borrowing b 
        JOIN books bo ON b.book_id = bo.book_id 
        WHERE b.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $user_borrows = $stmt->fetchAll();
}

// จัดการการอัพเดทข้อมูลผู้ใช้
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'update_status') {
            $user_id = $_POST['user_id'];
            $status = $_POST['status'];
            
            $stmt = $db->prepare("UPDATE users SET status = ? WHERE user_id = ?");
            $stmt->execute([$status, $user_id]);
            
            // บันทึก activity log
            $log_stmt = $db->prepare("
                INSERT INTO activity_logs (admin_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
                VALUES (?, 'update_user_status', 'users', ?, ?, ?, ?, NOW())
            ");
            $log_stmt->execute([
                $_SESSION['admin_id'],
                $user_id,
                json_encode(['status' => $status]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            header("Location: users.php?success=updated");
            exit();
        } elseif ($_POST['action'] === 'edit_user') {
            $user_id = $_POST['user_id'];
            $first_name = clean_input($_POST['first_name']);
            $last_name = clean_input($_POST['last_name']);
            $email = clean_input($_POST['email']);
            $phone = clean_input($_POST['phone'] ?? '');
            $user_type = $_POST['user_type'];
            $department = clean_input($_POST['department'] ?? '');
            $class_level = clean_input($_POST['class_level'] ?? '');
            
            $stmt = $db->prepare("
                UPDATE users 
                SET first_name = ?, last_name = ?, email = ?, phone = ?, user_type = ?, department = ?, class_level = ?
                WHERE user_id = ?
            ");
            $stmt->execute([$first_name, $last_name, $email, $phone, $user_type, $department, $class_level, $user_id]);
            
            // บันทึก activity log
            $log_stmt = $db->prepare("
                INSERT INTO activity_logs (admin_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
                VALUES (?, 'edit_user', 'users', ?, ?, ?, ?, NOW())
            ");
            $log_stmt->execute([
                $_SESSION['admin_id'],
                $user_id,
                json_encode(['first_name' => $first_name, 'last_name' => $last_name, 'email' => $email]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            header("Location: users.php?success=edited");
            exit();
        }
    } catch (PDOException $e) {
        error_log("User management error: " . $e->getMessage());
        header("Location: users.php?error=database");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการผู้ใช้ - ห้องสมุดดิจิทัล</title>
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

        .user-management {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .user-management h2 {
            font-size: 1.8rem;
            color: #333;
            margin-bottom: 1.5rem;
        }

        .user-list {
            margin-top: 2rem;
        }

        .user-item {
            border-bottom: 1px solid #e1e5e9;
            padding: 1rem 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .user-details h4 {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .user-details p {
            color: #666;
            font-size: 0.9rem;
        }

        .user-actions {
            display: flex;
            gap: 1rem;
        }

        .user-actions select {
            padding: 8px;
            border-radius: 8px;
            border: 2px solid #e1e5e9;
        }

        .user-actions a, .user-actions button {
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-view {
            background: #3498db;
            color: white;
        }

        .btn-edit {
            background: #f1c40f;
            color: white;
        }

        .btn-update {
            background: #27ae60;
            color: white;
        }

        .borrow-list {
            margin-top: 2rem;
        }

        .borrow-item {
            border-bottom: 1px solid #e1e5e9;
            padding: 1rem 0;
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .borrow-item img {
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

        .edit-form {
            margin-top: 2rem;
            padding: 1rem;
            border: 1px solid #e1e5e9;
            border-radius: 8px;
        }

        .edit-form .form-group {
            margin-bottom: 1rem;
        }

        .edit-form .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .edit-form .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 1rem;
        }

        .edit-form .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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

            .user-item, .borrow-item {
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

    <div class="container">
        <section class="user-management">
            <h2>จัดการผู้ใช้</h2>
            
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <?php
                    switch ($_GET['success']) {
                        case 'updated':
                            echo 'อัพเดทสถานะผู้ใช้สำเร็จ';
                            break;
                        case 'edited':
                            echo 'แก้ไขข้อมูลผู้ใช้สำเร็จ';
                            break;
                    }
                    ?>
                </div>
            <?php elseif (isset($_GET['error'])): ?>
                <div class="alert alert-error">เกิดข้อผิดพลาดในการดำเนินการ</div>
            <?php endif; ?>

            <div class="user-list">
                <h3>รายการผู้ใช้</h3>
                <?php if (count($users) > 0): ?>
                    <?php foreach ($users as $user): ?>
                        <div class="user-item">
                            <div class="user-details">
                                <h4><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                                <p>รหัสนักเรียน: <?php echo htmlspecialchars($user['student_id']); ?></p>
                                <p>อีเมล: <?php echo htmlspecialchars($user['email']); ?></p>
                                <p>ประเภท: <?php echo $user['user_type'] == 'student' ? 'นักเรียน' : ($user['user_type'] == 'teacher' ? 'ครู' : 'เจ้าหน้าที่'); ?></p>
                                <p>แผนก: <?php echo htmlspecialchars($user['department'] ?: 'ไม่ระบุ'); ?></p>
                                <p>ชั้นเรียน: <?php echo htmlspecialchars($user['class_level'] ?: 'ไม่ระบุ'); ?></p>
                                <p>สถานะ: <?php echo $user['status'] == 'active' ? 'ใช้งาน' : ($user['status'] == 'inactive' ? 'ไม่ใช้งาน' : 'ระงับ'); ?></p>
                            </div>
                            <div class="user-actions">
                                <form method="POST" action="users.php">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                    <select name="status">
                                        <option value="active" <?php echo $user['status'] == 'active' ? 'selected' : ''; ?>>ใช้งาน</option>
                                        <option value="inactive" <?php echo $user['status'] == 'inactive' ? 'selected' : ''; ?>>ไม่ใช้งาน</option>
                                        <option value="suspended" <?php echo $user['status'] == 'suspended' ? 'selected' : ''; ?>>ระงับ</option>
                                    </select>
                                    <button type="submit" class="btn-update">อัพเดท</button>
                                </form>
                                <a href="#" class="btn-edit" onclick='editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)'>แก้ไข</a>
                                <a href="users.php?view_borrows=<?php echo $user['user_id']; ?>" class="btn-view">ดูการยืม</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>ไม่มีผู้ใช้ในระบบ</p>
                <?php endif; ?>
            </div>

            <div class="edit-form" id="edit-user-form" style="display: none;">
                <h3>แก้ไขข้อมูลผู้ใช้</h3>
                <form method="POST" action="users.php">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="form-group">
                        <label for="edit_first_name">ชื่อ</label>
                        <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_last_name">นามสกุล</label>
                        <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_email">อีเมล</label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_phone">เบอร์โทร</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="edit_user_type">ประเภทผู้ใช้</label>
                        <select name="user_type" id="edit_user_type" class="form-control">
                            <option value="student">นักเรียน</option>
                            <option value="teacher">ครู</option>
                            <option value="staff">เจ้าหน้าที่</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_department">แผนก</label>
                        <input type="text" name="department" id="edit_department" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="edit_class_level">ชั้นเรียน</label>
                        <input type="text" name="class_level" id="edit_class_level" class="form-control">
                    </div>
                    <button type="submit" class="btn-submit">บันทึกการแก้ไข</button>
                </form>
            </div>

            <?php if (!empty($user_borrows)): ?>
                <div class="borrow-list">
                    <h3>ประวัติการยืม</h3>
                    <?php foreach ($user_borrows as $borrow): ?>
                        <div class="borrow-item">
                            <img src="<?php echo htmlspecialchars($borrow['cover_image'] ?: '../assets/default_book.jpg'); ?>" alt="Book cover">
                            <div class="borrow-details">
                                <h4><?php echo htmlspecialchars($borrow['title']); ?></h4>
                                <p>วันที่ยืม: <?php echo date('d/m/Y', strtotime($borrow['borrow_date'])); ?></p>
                                <p>กำหนดคืน: <?php echo date('d/m/Y', strtotime($borrow['due_date'])); ?></p>
                                <p>สถานะ: <?php echo $borrow['status'] == 'borrowed' ? 'ยืมอยู่' : ($borrow['status'] == 'overdue' ? 'เกินกำหนด' : ($borrow['status'] == 'returned' ? 'คืนแล้ว' : 'ปฏิเสธ')); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script>
        function editUser(user) {
            document.getElementById('edit-user-form').style.display = 'block';
            document.getElementById('edit_user_id').value = user.user_id;
            document.getElementById('edit_first_name').value = user.first_name;
            document.getElementById('edit_last_name').value = user.last_name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_phone').value = user.phone || '';
            document.getElementById('edit_user_type').value = user.user_type;
            document.getElementById('edit_department').value = user.department || '';
            document.getElementById('edit_class_level').value = user.class_level || '';
            window.scrollTo({ top: document.getElementById('edit-user-form').offsetTop, behavior: 'smooth' });
        }
    </script>
</body>
</html>