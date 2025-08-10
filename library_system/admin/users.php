<?php
require_once '../db.php';

// ตรวจสอบการล็อกอิน
require_admin();
if (!is_admin()) {
    header("Location: ../login.php");
    exit();
}

// จัดการ AJAX requests สำหรับดึงข้อมูลการยืม
if (isset($_GET['ajax']) && $_GET['ajax'] === 'borrowing' && isset($_GET['user_id'])) {
    $user_id = $_GET['user_id'];
    
    $stmt = $db->prepare("
        SELECT b.*, bo.title, bo.cover_image, bo.isbn,
               CASE 
                   WHEN b.status = 'overdue' THEN DATEDIFF(NOW(), b.due_date)
                   WHEN b.status = 'borrowed' AND b.due_date < NOW() THEN DATEDIFF(NOW(), b.due_date)
                   ELSE 0
               END as days_overdue
        FROM borrowing b 
        JOIN books bo ON b.book_id = bo.book_id 
        WHERE b.user_id = ?
        ORDER BY b.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$user_id]);
    $user_borrows = $stmt->fetchAll();
    
    // ดึงข้อมูลผู้ใช้
    $user_stmt = $db->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $user_stmt->execute([$user_id]);
    $user_info = $user_stmt->fetch();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'user_info' => $user_info,
        'borrows' => $user_borrows
    ]);
    exit();
}

// ดึงรายการผู้ใช้พร้อมข้อมูลเพิ่มเติม
$search = $_GET['search'] ?? '';
$filter_type = $_GET['filter_type'] ?? 'all';
$filter_status = $_GET['filter_status'] ?? 'all';
$page = max(1, $_GET['page'] ?? 1);
$limit = 10;
$offset = ($page - 1) * $limit;

// สร้าง query สำหรับค้นหาและกรอง
$where_clauses = [];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "(first_name LIKE ? OR last_name LIKE ? OR student_id LIKE ? OR email LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

if ($filter_type !== 'all') {
    $where_clauses[] = "user_type = ?";
    $params[] = $filter_type;
}

if ($filter_status !== 'all') {
    $where_clauses[] = "status = ?";
    $params[] = $filter_status;
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// นับจำนวนผู้ใช้ทั้งหมด
$count_stmt = $db->prepare("SELECT COUNT(*) FROM users $where_sql");
$count_stmt->execute($params);
$total_users = $count_stmt->fetchColumn();
$total_pages = ceil($total_users / $limit);

// ดึงข้อมูลผู้ใช้
$stmt = $db->prepare("
    SELECT u.*,
           (SELECT COUNT(*) FROM borrowing WHERE user_id = u.user_id AND status = 'borrowed') as current_borrows,
           (SELECT COUNT(*) FROM borrowing WHERE user_id = u.user_id) as total_borrows,
           (SELECT COUNT(*) FROM fines WHERE user_id = u.user_id AND status = 'unpaid') as unpaid_fines,
           (SELECT SUM(amount) FROM fines WHERE user_id = u.user_id AND status = 'unpaid') as fine_amount
    FROM users u 
    $where_sql 
    ORDER BY created_at DESC 
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();

// ดึงสถิติภาพรวม
$stats_stmt = $db->query("
    SELECT 
        COUNT(*) as total_users,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_users,
        COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended_users,
        COUNT(CASE WHEN user_type = 'student' THEN 1 END) as students,
        COUNT(CASE WHEN user_type = 'teacher' THEN 1 END) as teachers
    FROM users
");
$stats = $stats_stmt->fetch();

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
            
            // สร้าง notification
            if ($status === 'suspended') {
                $notif_stmt = $db->prepare("
                    INSERT INTO notifications (user_id, type, title, message, sent_date)
                    VALUES (?, 'general', 'บัญชีถูกระงับ', 'บัญชีของคุณถูกระงับการใช้งาน กรุณาติดต่อเจ้าหน้าที่ห้องสมุด', NOW())
                ");
                $notif_stmt->execute([$user_id]);
            }
            
            header("Location: users.php?success=updated");
            exit();
        } 
        elseif ($_POST['action'] === 'edit_user') {
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
        elseif ($_POST['action'] === 'reset_password') {
            $user_id = $_POST['user_id'];
            $new_password = 'password123'; // Default password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            
            // บันทึก activity log
            $log_stmt = $db->prepare("
                INSERT INTO activity_logs (admin_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
                VALUES (?, 'reset_password', 'users', ?, ?, ?, ?, NOW())
            ");
            $log_stmt->execute([
                $_SESSION['admin_id'],
                $user_id,
                json_encode(['password' => 'reset']),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            // สร้าง notification
            $notif_stmt = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, sent_date)
                VALUES (?, 'general', 'รีเซ็ตรหัสผ่าน', 'รหัสผ่านของคุณถูกรีเซ็ตเป็น: password123 กรุณาเปลี่ยนรหัสผ่านใหม่หลังจากเข้าสู่ระบบ', NOW())
            ");
            $notif_stmt->execute([$user_id]);
            
            header("Location: users.php?success=password_reset");
            exit();
        }
        elseif ($_POST['action'] === 'delete_user') {
            $user_id = $_POST['user_id'];
            
            // ตรวจสอบว่ามีการยืมหนังสืออยู่หรือไม่
            $check_stmt = $db->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND status IN ('borrowed', 'overdue')");
            $check_stmt->execute([$user_id]);
            $active_borrows = $check_stmt->fetchColumn();
            
            if ($active_borrows > 0) {
                header("Location: users.php?error=cannot_delete");
                exit();
            }
        
            
            // บันทึก activity log
            $log_stmt = $db->prepare("
                INSERT INTO activity_logs (admin_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
                VALUES (?, 'delete_user', 'users', ?, ?, ?, ?, NOW())
            ");
            $log_stmt->execute([
                $_SESSION['admin_id'],
                $user_id,
                json_encode(['deleted' => true]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            header("Location: users.php?success=deleted");
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

        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            --success-gradient: linear-gradient(135deg, #28a745, #20c997);
            --warning-gradient: linear-gradient(135deg, #ffc107, #ffca2c);
            --danger-gradient: linear-gradient(135deg, #dc3545, #e55353);
            --info-gradient: linear-gradient(135deg, #17a2b8, #20c997);
            --bg-body: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            --bg-card: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            --bg-glass: rgba(255, 255, 255, 0.95);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            min-height: 100vh;
            color: #333;
            line-height: 1.6;
        }

        .dashboard-container {
            max-width: 1400px;
            margin: 120px auto 20px;
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
            max-width: 1400px;
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
            font-weight: 500;
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
            font-weight: 500;
        }

        .btn-logout:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
        }

        /* Dashboard Container */
        .dashboard-container {
            max-width: 1200px;
            margin: 100px auto 20px;
            padding: 0 20px;
        }

        /* Welcome Section */
        .welcome-section {
            background: var(--bg-card);
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .profile-img {
            width: 80px;
            height: 80px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .welcome-text h1 {
            font-size: 2rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 2rem;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            border-radius: 20px 20px 0 0;
        }

        .stat-card.total::before { background: var(--info-gradient); }
        .stat-card.active::before { background: var(--success-gradient); }
        .stat-card.suspended::before { background: var(--danger-gradient); }
        .stat-card.students::before { background: var(--primary-gradient); }
        .stat-card.teachers::before { background: var(--warning-gradient); }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .stat-card i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.8;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Card */
        .card {
            background: var(--bg-card);
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 2rem;
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
            font-size: 1.5rem;
            font-weight: 600;
        }

        .card-content {
            padding: 2rem;
        }

        /* Search and Filters */
        .search-filters {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 120px;
            gap: 1rem;
            margin-bottom: 2rem;
            align-items: end;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #555;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn-search {
            background: var(--primary-gradient);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-search:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        /* User List */
        .user-item {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 1.5rem;
            align-items: center;
        }

        .user-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.12);
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            background: var(--secondary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #667eea;
            font-weight: bold;
        }

        .user-details h4 {
            font-size: 1.2rem;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .user-details p {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }

        .user-stats {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .user-stat {
            background: #f8f9fa;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            color: #555;
        }

        .user-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .btn-view { 
            background: var(--info-gradient); 
            color: white; 
        }
        .btn-edit { 
            background: var(--warning-gradient); 
            color: white; 
        }
        .btn-reset { 
            background: var(--secondary-gradient); 
            color: #666; 
        }
        .btn-delete { 
            background: var(--danger-gradient); 
            color: white; 
        }

        .btn-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.2);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.inactive { background: #f8d7da; color: #721c24; }
        .status-badge.suspended { background: #fff3cd; color: #856404; }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 20px;
            width: 90%;
            max-width: 800px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-content.small {
            max-width: 500px;
        }

        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            background: var(--primary-gradient);
            color: white;
            padding: 1.5rem 2rem;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.3rem;
        }

        .close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close:hover {
            background: rgba(255,255,255,0.2);
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn-modal {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-modal-primary { 
            background: var(--success-gradient); 
            color: white; 
        }
        .btn-modal-danger { 
            background: var(--danger-gradient); 
            color: white; 
        }
        .btn-modal-secondary { 
            background: #6c757d; 
            color: white; 
        }

        .btn-modal:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da, #f1aeb5);
            color: #721c24;
            border: 1px solid #f1aeb5;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin: 2rem 0;
        }

        .page-link {
            padding: 8px 12px;
            background: white;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            color: #667eea;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .page-link:hover, .page-link.active {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }

        /* Loading Overlay */
        .loading {
            display: none;
            position: fixed;
            z-index: 3000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(5px);
        }

        .loading-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: white;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255,255,255,0.3);
            border-top: 5px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Status Dropdown */
        .status-dropdown {
            position: relative;
            display: inline-block;
        }

        .status-select {
            padding: 6px 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            background: white;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 120px;
        }

        .status-select:hover {
            border-color: #667eea;
        }

        /* Borrow History Styles */
        .borrow-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 1rem;
            align-items: center;
            transition: all 0.3s ease;
        }

        .borrow-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .borrow-cover {
            width: 50px;
            height: 70px;
            background: var(--secondary-gradient);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #667eea;
            overflow: hidden;
        }

        .borrow-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .borrow-details h4 {
            font-size: 1rem;
            color: #333;
            margin-bottom: 0.3rem;
        }

        .borrow-details p {
            color: #666;
            font-size: 0.8rem;
            margin-bottom: 0.1rem;
        }

        .due-date {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .due-date.normal { background: #d4edda; color: #155724; }
        .due-date.due-soon { background: #fff3cd; color: #856404; }
        .due-date.overdue { background: #f8d7da; color: #721c24; }
        .due-date.returned { background: #e2e3e5; color: #383d41; }

        /* Form Styles */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .info-box {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border: 1px solid #90caf9;
            color: #0d47a1;
            padding: 1rem;
            border-radius: 12px;
            margin: 1rem 0;
        }

        .info-box h4 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .warning-box {
            background: linear-gradient(135deg, #fff3e0, #ffcc02);
            border: 1px solid #ffb74d;
            color: #e65100;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 0 1rem;
                margin-top: 80px;
            }
            
            .search-filters {
                grid-template-columns: 1fr;
            }
            
            .user-item {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .user-actions {
                justify-content: center;
            }
            
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 2% auto;
            }

            .borrow-item {
                grid-template-columns: 1fr;
                text-align: center;
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
                <li><a href="users.php" class="active">จัดการผู้ใช้</a></li>
            </ul>
            <a href="../logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i>
                ออกจากระบบ
            </a>
        </nav>
    </header>

    <div class="dashboard-container">
        <section class="welcome-section">
            <div class="profile-img">
                <i class="fas fa-users-cog"></i>
            </div>
            <div class="welcome-text">
                <h1>จัดการผู้ใช้ระบบ</h1>
                <p>ดูแลและจัดการข้อมูลผู้ใช้ทั้งหมดในระบบห้องสมุดดิจิทัล</p>
            </div>
        </section>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card total">
                <i class="fas fa-users"></i>
                <div class="stat-number"><?php echo number_format($stats['total_users']); ?></div>
                <div class="stat-label">ผู้ใช้ทั้งหมด</div>
            </div>
            <div class="stat-card active">
                <i class="fas fa-user-check"></i>
                <div class="stat-number"><?php echo number_format($stats['active_users']); ?></div>
                <div class="stat-label">ใช้งานอยู่</div>
            </div>
            <div class="stat-card suspended">
                <i class="fas fa-user-times"></i>
                <div class="stat-number"><?php echo number_format($stats['suspended_users']); ?></div>
                <div class="stat-label">ถูกระงับ</div>
            </div>
            <div class="stat-card students">
                <i class="fas fa-user-graduate"></i>
                <div class="stat-number"><?php echo number_format($stats['students']); ?></div>
                <div class="stat-label">นักเรียน</div>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php
                switch ($_GET['success']) {
                    case 'updated':
                        echo 'อัพเดทสถานะผู้ใช้สำเร็จ';
                        break;
                    case 'edited':
                        echo 'แก้ไขข้อมูลผู้ใช้สำเร็จ';
                        break;
                    case 'password_reset':
                        echo 'รีเซ็ตรหัสผ่านสำเร็จ';
                        break;
                    case 'deleted':
                        echo 'ลบผู้ใช้สำเร็จ';
                        break;
                }
                ?>
            </div>
        <?php elseif (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php
                switch ($_GET['error']) {
                    case 'cannot_delete':
                        echo 'ไม่สามารถลบผู้ใช้ได้ เนื่องจากมีการยืมหนังสืออยู่';
                        break;
                    default:
                        echo 'เกิดข้อผิดพลาดในการดำเนินการ';
                }
                ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <i class="fas fa-search"></i>
                <h3>ค้นหาและกรองข้อมูล</h3>
            </div>
            <div class="card-content">
                <form method="GET" action="users.php">
                    <div class="search-filters">
                        <div class="form-group">
                            <label for="search">ค้นหา</label>
                            <input type="text" 
                                   name="search" 
                                   id="search"
                                   class="form-control" 
                                   placeholder="ชื่อ นามสกุล รหัสนักเรียน หรืออีเมล"
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="form-group">
                            <label for="filter_type">ประเภทผู้ใช้</label>
                            <select name="filter_type" id="filter_type" class="form-control">
                                <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>ทั้งหมด</option>
                                <option value="student" <?php echo $filter_type === 'student' ? 'selected' : ''; ?>>นักเรียน</option>
                                <option value="teacher" <?php echo $filter_type === 'teacher' ? 'selected' : ''; ?>>ครู</option>
                                <option value="staff" <?php echo $filter_type === 'staff' ? 'selected' : ''; ?>>เจ้าหน้าที่</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="filter_status">สถานะ</label>
                            <select name="filter_status" id="filter_status" class="form-control">
                                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>ทั้งหมด</option>
                                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>ใช้งาน</option>
                                <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>ไม่ใช้งาน</option>
                                <option value="suspended" <?php echo $filter_status === 'suspended' ? 'selected' : ''; ?>>ระงับ</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn-search">
                                <i class="fas fa-search"></i> ค้นหา
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i>
                <h3>รายการผู้ใช้ (<?php echo number_format($total_users); ?> คน)</h3>
            </div>
            <div class="card-content">
                <?php if (count($users) > 0): ?>
                    <?php foreach ($users as $user): ?>
                        <div class="user-item">
                            <div class="user-avatar">
                                <?php echo strtoupper(mb_substr($user['first_name'], 0, 1, 'UTF-8')); ?>
                            </div>
                            <div class="user-details">
                                <h4><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                    <span class="status-badge <?php echo $user['status']; ?>">
                                        <?php echo $user['status'] == 'active' ? 'ใช้งาน' : ($user['status'] == 'inactive' ? 'ไม่ใช้งาน' : 'ระงับ'); ?>
                                    </span>
                                </h4>
                                <p><i class="fas fa-id-card"></i> รหัส: <?php echo htmlspecialchars($user['student_id']); ?></p>
                                <p><i class="fas fa-envelope"></i> อีเมล: <?php echo htmlspecialchars($user['email']); ?></p>
                                <p><i class="fas fa-user-tag"></i> ประเภท: <?php echo $user['user_type'] == 'student' ? 'นักเรียน' : ($user['user_type'] == 'teacher' ? 'ครู' : 'เจ้าหน้าที่'); ?></p>
                                <?php if ($user['department']): ?>
                                    <p><i class="fas fa-building"></i> แผนก: <?php echo htmlspecialchars($user['department']); ?></p>
                                <?php endif; ?>
                                <?php if ($user['class_level']): ?>
                                    <p><i class="fas fa-graduation-cap"></i> ชั้นเรียน: <?php echo htmlspecialchars($user['class_level']); ?></p>
                                <?php endif; ?>
                                <div class="user-stats">
                                    <div class="user-stat">
                                        <i class="fas fa-book"></i> ยืมอยู่: <?php echo $user['current_borrows']; ?> เล่ม
                                    </div>
                                    <div class="user-stat">
                                        <i class="fas fa-history"></i> ยืมทั้งหมด: <?php echo $user['total_borrows']; ?> ครั้ง
                                    </div>
                                    <?php if ($user['unpaid_fines'] > 0): ?>
                                        <div class="user-stat" style="background: #f8d7da; color: #721c24;">
                                            <i class="fas fa-exclamation-triangle"></i> ค่าปรับ: <?php echo number_format($user['fine_amount'], 2); ?> บาท
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <p style="font-size: 0.8rem; color: #999;">
                                    <i class="fas fa-calendar-plus"></i> สมัครสมาชิก: <?php echo date('d/m/Y', strtotime($user['created_at'])); ?>
                                </p>
                            </div>
                            <div class="user-actions">
                                <button class="btn-action btn-view" onclick="showBorrowHistoryModal(<?php echo $user['user_id']; ?>)">
                                    <i class="fas fa-eye"></i> ดูการยืม
                                </button>
                                <button class="btn-action btn-edit" 
                                        onclick='showEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)'>
                                    <i class="fas fa-edit"></i> แก้ไข
                                </button>
                                <div class="status-dropdown">
                                    <select class="status-select" 
                                            onchange="showStatusModal(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>', this.value, '<?php echo $user['status']; ?>')">
                                        <option value="<?php echo $user['status']; ?>" selected disabled>
                                            <?php echo $user['status'] == 'active' ? 'ใช้งาน' : ($user['status'] == 'inactive' ? 'ไม่ใช้งาน' : 'ระงับ'); ?>
                                        </option>
                                        <?php if ($user['status'] !== 'active'): ?>
                                            <option value="active">ใช้งาน</option>
                                        <?php endif; ?>
                                        <?php if ($user['status'] !== 'inactive'): ?>
                                            <option value="inactive">ไม่ใช้งาน</option>
                                        <?php endif; ?>
                                        <?php if ($user['status'] !== 'suspended'): ?>
                                            <option value="suspended">ระงับ</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <button class="btn-action btn-reset" 
                                        onclick='showResetPasswordModal(<?php echo $user['user_id']; ?>, "<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>")'>
                                    <i class="fas fa-key"></i> รีเซ็ต
                                </button>
                                <?php if ($user['current_borrows'] == 0): ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&filter_type=<?php echo $filter_type; ?>&filter_status=<?php echo $filter_status; ?>" class="page-link">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&filter_type=<?php echo $filter_type; ?>&filter_status=<?php echo $filter_status; ?>" 
                                   class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&filter_type=<?php echo $filter_type; ?>&filter_status=<?php echo $filter_status; ?>" class="page-link">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h4>ไม่พบผู้ใช้</h4>
                        <p>ไม่มีผู้ใช้ที่ตรงกับเงื่อนไขการค้นหา</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Borrow History Modal -->
    <div id="borrowHistoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-history"></i> ประวัติการยืมหนังสือ</h3>
                <button class="close" onclick="closeModal('borrowHistoryModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="borrowHistoryContent">
                    <div class="loading-content">
                        <div class="spinner"></div>
                        <p>กำลังโหลดข้อมูล...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Change Confirmation Modal -->
    <div id="statusChangeModal" class="modal">
        <div class="modal-content small">
            <div class="modal-header">
                <h3><i class="fas fa-user-cog"></i> ยืนยันการเปลี่ยนสถานะ</h3>
                <button class="close" onclick="closeModal('statusChangeModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="info-box" id="statusWarning" style="display: none;">
                    <h4><i class="fas fa-exclamation-triangle"></i> คำเตือน</h4>
                    <p id="statusWarningText"></p>
                </div>
                
                <p><strong>ผู้ใช้:</strong> <span id="statusUserName"></span></p>
                <p><strong>สถานะปัจจุบัน:</strong> <span id="currentStatus"></span></p>
                <p><strong>สถานะใหม่:</strong> <span id="newStatus"></span></p>
                <p>คุณต้องการเปลี่ยนสถานะของผู้ใช้นี้หรือไม่?</p>
                
                <div class="modal-actions">
                    <button type="button" class="btn-modal btn-modal-secondary" onclick="closeModal('statusChangeModal')">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <form method="POST" action="users.php" style="display: inline;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="user_id" id="status_user_id">
                        <input type="hidden" name="status" id="status_value">
                        <button type="submit" class="btn-modal btn-modal-primary">
                            <i class="fas fa-check"></i> ยืนยันการเปลี่ยน
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> แก้ไขข้อมูลผู้ใช้</h3>
                <button class="close" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="users.php" id="editUserForm">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_first_name">ชื่อ</label>
                            <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_last_name">นามสกุล</label>
                            <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email">อีเมล</label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    
                    <div class="form-row">
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
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_department">แผนก</label>
                            <input type="text" name="department" id="edit_department" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_class_level">ชั้นเรียน</label>
                            <input type="text" name="class_level" id="edit_class_level" class="form-control">
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn-modal btn-modal-secondary" onclick="closeModal('editUserModal')">
                            <i class="fas fa-times"></i> ยกเลิก
                        </button>
                        <button type="submit" class="btn-modal btn-modal-primary">
                            <i class="fas fa-save"></i> บันทึกการแก้ไข
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetPasswordModal" class="modal">
        <div class="modal-content small">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> รีเซ็ตรหัสผ่าน</h3>
                <button class="close" onclick="closeModal('resetPasswordModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="info-box warning-box">
                    <h4><i class="fas fa-exclamation-triangle"></i> คำเตือน</h4>
                    <p>การรีเซ็ตรหัสผ่านจะเปลี่ยนรหัสผ่านเป็น "password123" และจะส่งการแจ้งเตือนไปยังผู้ใช้</p>
                </div>
                
                <p><strong>ผู้ใช้:</strong> <span id="resetUserName"></span></p>
                <p><strong>รหัสผ่านใหม่:</strong> password123</p>
                
                <div class="modal-actions">
                    <button type="button" class="btn-modal btn-modal-secondary" onclick="closeModal('resetPasswordModal')">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <form method="POST" action="users.php" style="display: inline;">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" id="reset_user_id">
                        <button type="submit" class="btn-modal btn-modal-primary">
                            <i class="fas fa-key"></i> ยืนยันรีเซ็ต
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <p>กำลังดำเนินการ...</p>
        </div>
    </div>

    <script>
        // Modal Functions
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
            
            // รีเซ็ตการเลือกใน dropdown หลังจากปิด modal
            if (modalId === 'statusChangeModal') {
                const selects = document.querySelectorAll('.status-select');
                selects.forEach(select => {
                    // คืนค่าเดิม
                    const currentStatus = select.querySelector('option[selected]');
                    if (currentStatus) {
                        select.value = currentStatus.value;
                    }
                });
            }
        }

        // Show Borrow History Modal
        function showBorrowHistoryModal(userId) {
            showModal('borrowHistoryModal');
            
            // แสดง loading
            document.getElementById('borrowHistoryContent').innerHTML = `
                <div class="loading-content">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูลการยืม...</p>
                </div>
            `;
            
            // ดึงข้อมูลการยืมผ่าน AJAX
            fetch(`users.php?ajax=borrowing&user_id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayBorrowHistory(data.user_info, data.borrows);
                    } else {
                        document.getElementById('borrowHistoryContent').innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-exclamation-triangle"></i>
                                <h4>ไม่สามารถโหลดข้อมูลได้</h4>
                                <p>กรุณาลองใหม่อีกครั้ง</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error fetching borrow history:', error);
                    document.getElementById('borrowHistoryContent').innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h4>เกิดข้อผิดพลาด</h4>
                            <p>ไม่สามารถโหลดข้อมูลการยืมได้</p>
                        </div>
                    `;
                });
        }

        // Display Borrow History
        function displayBorrowHistory(userInfo, borrows) {
            let content = `
                <div class="info-box">
                    <h4><i class="fas fa-user"></i> ข้อมูลผู้ใช้</h4>
                    <p><strong>ชื่อ:</strong> ${userInfo.first_name} ${userInfo.last_name}</p>
                </div>
            `;
            
            if (borrows.length === 0) {
                content += `
                    <div class="empty-state">
                        <i class="fas fa-book-open"></i>
                        <h4>ไม่มีประวัติการยืม</h4>
                        <p>ผู้ใช้นี้ยังไม่เคยยืมหนังสือ</p>
                    </div>
                `;
            } else {
                content += '<div class="borrow-list">';
                
                borrows.forEach(borrow => {
                    const statusClass = getStatusClass(borrow.status, borrow.days_overdue);
                    const statusText = getStatusText(borrow.status, borrow.days_overdue);
                    
                    content += `
                        <div class="borrow-item">
                            <div class="borrow-cover">
                                ${borrow.cover_image ? 
                                    `<img src="../${borrow.cover_image}" alt="Book cover" style="width: 100%; height: 100%; object-fit: cover;">` : 
                                    '<i class="fas fa-book"></i>'
                                }
                            </div>
                            <div class="borrow-details">
                                <h4>${borrow.title}</h4>
                                <p><i class="fas fa-barcode"></i> ISBN: ${borrow.isbn || 'ไม่ระบุ'}</p>
                                <p><i class="fas fa-calendar-alt"></i> วันที่ยืม: ${formatDate(borrow.borrow_date)}</p>
                                <p><i class="fas fa-calendar-check"></i> กำหนดคืน: ${formatDate(borrow.due_date)}</p>
                                ${borrow.return_date ? `<p><i class="fas fa-calendar-times"></i> วันที่คืน: ${formatDate(borrow.return_date)}</p>` : ''}
                                ${borrow.renewal_count > 0 ? `<p><i class="fas fa-redo"></i> ต่ออายุ: ${borrow.renewal_count} ครั้ง</p>` : ''}
                            </div>
                            <div>
                                <span class="due-date ${statusClass}">
                                    <i class="fas fa-${getStatusIcon(borrow.status)}"></i>
                                    ${statusText}
                                </span>
                            </div>
                        </div>
                    `;
                });
                
                content += '</div>';
            }
            
            document.getElementById('borrowHistoryContent').innerHTML = content;
        }

        // Helper functions for borrow history
        function getStatusClass(status, daysOverdue) {
            if (status === 'returned') return 'returned';
            if (status === 'overdue' || daysOverdue > 0) return 'overdue';
            if (status === 'borrowed') return 'normal';
            return 'normal';
        }

        function getStatusText(status, daysOverdue) {
            switch(status) {
                case 'borrowed': 
                    return daysOverdue > 0 ? `เกินกำหนด ${daysOverdue} วัน` : 'ยืมอยู่';
                case 'returned': return 'คืนแล้ว';
                case 'overdue': return `เกินกำหนด ${daysOverdue} วัน`;
                case 'lost': return 'หายไป';
                case 'pending_return': return 'รอยืนยันการคืน';
                default: return 'ไม่ทราบสถานะ';
            }
        }

        function getStatusIcon(status) {
            switch(status) {
                case 'returned': return 'check';
                case 'overdue': return 'exclamation-triangle';
                case 'lost': return 'exclamation-circle';
                case 'pending_return': return 'clock';
                default: return 'book';
            }
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('th-TH');
        }

        // Status Change Modal
        function showStatusModal(userId, userName, newStatus, currentStatus) {
            // ป้องกันการเปลี่ยนเป็นสถานะเดียวกัน
            if (newStatus === currentStatus) {
                return;
            }
            
            document.getElementById('status_user_id').value = userId;
            document.getElementById('status_value').value = newStatus;
            document.getElementById('statusUserName').textContent = userName;
            
            // แปลสถานะเป็นภาษาไทย
            const statusMapping = {
                'active': 'ใช้งาน',
                'inactive': 'ไม่ใช้งาน', 
                'suspended': 'ระงับ'
            };
            
            document.getElementById('currentStatus').textContent = statusMapping[currentStatus];
            document.getElementById('newStatus').textContent = statusMapping[newStatus];
            
            // แสดงคำเตือนสำหรับการระงับ
            const warningDiv = document.getElementById('statusWarning');
            const warningText = document.getElementById('statusWarningText');
            
            if (newStatus === 'suspended') {
                warningDiv.style.display = 'block';
                warningText.textContent = 'การระงับผู้ใช้จะทำให้ผู้ใช้ไม่สามารถเข้าสู่ระบบได้ และจะได้รับการแจ้งเตือน';
            } else if (newStatus === 'inactive') {
                warningDiv.style.display = 'block';
                warningText.textContent = 'การทำให้ผู้ใช้ไม่ใช้งานจะปิดการใช้งานชั่วคราว';
            } else {
                warningDiv.style.display = 'none';
            }
            
            showModal('statusChangeModal');
        }

        // Edit User Modal
        function showEditModal(user) {
            document.getElementById('edit_user_id').value = user.user_id;
            document.getElementById('edit_first_name').value = user.first_name;
            document.getElementById('edit_last_name').value = user.last_name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_phone').value = user.phone || '';
            document.getElementById('edit_user_type').value = user.user_type;
            document.getElementById('edit_department').value = user.department || '';
            document.getElementById('edit_class_level').value = user.class_level || '';
            showModal('editUserModal');
        }

        // Reset Password Modal
        function showResetPasswordModal(userId, userName) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('resetUserName').textContent = userName;
            showModal('resetPasswordModal');
        }

        // Delete User Modal
        function showDeleteModal(userId, userName) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('deleteUserName').textContent = userName;
            showModal('deleteUserModal');
        }

        // Loading Functions
        function showLoading(message = 'กำลังดำเนินการ...') {
            document.getElementById('loadingOverlay').style.display = 'block';
            document.querySelector('.loading-content p').textContent = message;
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // Document Ready
        document.addEventListener('DOMContentLoaded', function() {
            // Add loading to all forms
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    showLoading();
                });
            });

            // Close modal when clicking outside
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeModal(modal.id);
                    }
                });
            });

            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 5000);
            });

            // Enhanced search functionality
            const searchInput = document.getElementById('search');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    if (e.target.value.length > 2 || e.target.value.length === 0) {
                        clearTimeout(window.searchTimeout);
                        window.searchTimeout = setTimeout(() => {
                            e.target.form.submit();
                        }, 1000);
                    }
                });
            }

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Escape key closes modals
                if (e.key === 'Escape') {
                    const openModals = document.querySelectorAll('.modal[style*="block"]');
                    openModals.forEach(modal => {
                        closeModal(modal.id);
                    });
                }
                
                // Ctrl+F focuses search
                if (e.ctrlKey && e.key === 'f') {
                    e.preventDefault();
                    const searchInput = document.getElementById('search');
                    if (searchInput) {
                        searchInput.focus();
                    }
                }
            });

            // Animation on scroll
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, { threshold: 0.1 });

            // Apply animation to cards
            const cards = document.querySelectorAll('.user-item, .stat-card');
            cards.forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(card);
            });
        });

        // Notification System
        function showNotification(message, type = 'success', duration = 6000) {
            const notification = document.createElement('div');
            notification.className = `notification-toast ${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#28a745' : '#dc3545'};
                color: white;
                padding: 15px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                z-index: 9999;
                transform: translateX(400px);
                transition: all 0.3s ease;
                max-width: 350px;
                display: flex;
                align-items: center;
                gap: 10px;
            `;
            
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                <span style="flex: 1;">${message}</span>
                <button onclick="this.parentElement.remove()" style="background: none; border: none; color: white; cursor: pointer; padding: 0; margin-left: 10px; font-size: 18px;">&times;</button>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.remove();
                    }
                }, 300);
            }, duration);
        }

        // Export functions
        function exportToCSV() {
            const users = <?php echo json_encode($users ?? []); ?>;
            if (!users || users.length === 0) {
                showNotification('ไม่มีข้อมูลผู้ใช้ให้ส่งออก', 'error');
                return;
            }

            let csvContent = "data:text/csv;charset=utf-8,\uFEFF";
            csvContent += "รหัสนักเรียน,ชื่อ,นามสกุล,อีเมล,ประเภท,แผนก,ชั้นเรียน,สถานะ,วันที่สมัคร\n";
            
            users.forEach(user => {
                const row = [
                    user.student_id,
                    user.first_name,
                    user.last_name,
                    user.email,
                    user.user_type === 'student' ? 'นักเรียน' : (user.user_type === 'teacher' ? 'ครู' : 'เจ้าหน้าที่'),
                    user.department || '',
                    user.class_level || '',
                    user.status === 'active' ? 'ใช้งาน' : (user.status === 'inactive' ? 'ไม่ใช้งาน' : 'ระงับ'),
                    new Date(user.created_at).toLocaleDateString('th-TH')
                ].map(field => `"${field}"`).join(",");
                csvContent += row + "\n";
            });
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `users_${new Date().toISOString().split('T')[0]}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showNotification('ส่งออกข้อมูลผู้ใช้สำเร็จ');
        }

        function resetFilters() {
            document.getElementById('search').value = '';
            document.getElementById('filter_type').value = 'all';
            document.getElementById('filter_status').value = 'all';
            document.querySelector('form').submit();
        }

        // Auto-refresh stats every 30 seconds
        setInterval(() => {
            fetch('users.php?ajax=stats')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.querySelector('.stat-card.total .stat-number').textContent = data.stats.total_users.toLocaleString();
                        document.querySelector('.stat-card.active .stat-number').textContent = data.stats.active_users.toLocaleString();
                        document.querySelector('.stat-card.suspended .stat-number').textContent = data.stats.suspended_users.toLocaleString();
                        document.querySelector('.stat-card.students .stat-number').textContent = data.stats.students.toLocaleString();
                    }
                })
                .catch(error => console.log('Stats update failed:', error));
        }, 30000);
    </script>

    <!-- Print Styles -->
    <style media="print">
        .header, .btn-action, .modal, .loading, .pagination, .user-actions {
            display: none !important;
        }
        
        .dashboard-container {
            margin-top: 0 !important;
            padding: 0 !important;
        }
        
        .card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
        }
        
        .user-item {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        
        body {
            background: white !important;
        }
        
        .welcome-section {
            background: white !important;
            border: 1px solid #ddd !important;
        }
        
        .quick-stats {
            display: grid !important;
            grid-template-columns: repeat(4, 1fr) !important;
            gap: 10px !important;
        }
        
        .stat-card {
            background: white !important;
            border: 1px solid #ddd !important;
            font-size: 0.8rem !important;
        }
        
        @page {
            margin: 1cm;
        }
    </style>

    <!-- Additional CSS for notifications -->
    <style>
        .notification-toast.show {
            transform: translateX(0) !important;
        }

        /* Enhanced modal styles */
        .modal-content.small {
            max-width: 500px;
        }

        .warning-box {
            background: linear-gradient(135deg, #fff3e0, #ffe0b3);
            border: 1px solid #ffb74d;
            color: #e65100;
            padding: 1rem;
            border-radius: 12px;
            margin: 1rem 0;
        }

        .warning-box h4 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        /* Enhanced borrow history styles */
        .borrow-list {
            max-height: 400px;
            overflow-y: auto;
            margin-top: 1rem;
        }

        .borrow-list::-webkit-scrollbar {
            width: 6px;
        }

        .borrow-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .borrow-list::-webkit-scrollbar-thumb {
            background: #667eea;
            border-radius: 3px;
        }

        .borrow-list::-webkit-scrollbar-thumb:hover {
            background: #5a6fd8;
        }

        /* Responsive enhancements */
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 10% auto;
                max-height: 80vh;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .modal-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .btn-modal {
                width: 100%;
                justify-content: center;
            }
            
            .search-filters {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .user-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .btn-action {
                width: 100%;
                justify-content: center;
            }
            
            .status-dropdown {
                width: 100%;
            }
            
            .status-select {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .user-item {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 1rem;
            }
            
            .user-avatar {
                margin: 0 auto;
            }
        }
    </style>
</body>
</html>