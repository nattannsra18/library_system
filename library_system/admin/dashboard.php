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

// จัดการการอนุมัติ/ปฏิเสธ
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];
    
    if ($action == 'approve_return') {
        $stmt = $db->prepare("UPDATE borrowing SET status = 'returned', return_date = CURDATE() WHERE borrow_id = ?");
        $stmt->execute([$id]);
        
        // อัพเดต available_copies และตั้งสถานะหนังสือให้พร้อมใช้งาน
        $stmt = $db->prepare("
            UPDATE books b 
            JOIN borrowing br ON b.book_id = br.book_id 
            SET b.available_copies = b.available_copies + 1 
            WHERE br.borrow_id = ?
        ");
        $stmt->execute([$id]);
        
        // ตรวจสอบและอัพเดตสถานะหนังสือให้เป็น available หากมีสำเนาว่าง
        $stmt = $db->prepare("
            UPDATE books b
            JOIN borrowing br ON b.book_id = br.book_id 
            SET b.status = 'available'
            WHERE br.borrow_id = ? AND b.available_copies > 0
        ");
        $stmt->execute([$id]);
        
        $_SESSION['success'] = "อนุมัติการคืนหนังสือเรียบร้อยแล้ว";
    } elseif ($action == 'reject_return') {
        $stmt = $db->prepare("UPDATE borrowing SET status = 'borrowed' WHERE borrow_id = ?");
        $stmt->execute([$id]);
        
        $_SESSION['error'] = "ปฏิเสธการคืนหนังสือ";
    } elseif ($action == 'approve_reservation') {
        // ตรวจสอบว่ามีหนังสือว่างหรือไม่
        $stmt = $db->prepare("
            SELECT b.available_copies, b.book_id, r.user_id
            FROM reservations r 
            JOIN books b ON r.book_id = b.book_id 
            WHERE r.reservation_id = ? AND r.status = 'active'
        ");
        $stmt->execute([$id]);
        $reservation = $stmt->fetch();
        
        if ($reservation && $reservation['available_copies'] > 0) {
            // สร้างการยืม
            $due_date = date('Y-m-d', strtotime('+14 days'));
            $stmt = $db->prepare("
                INSERT INTO borrowing (user_id, book_id, admin_id, borrow_date, due_date, status) 
                VALUES (?, ?, ?, CURDATE(), ?, 'borrowed')
            ");
            $stmt->execute([$reservation['user_id'], $reservation['book_id'], $admin_id, $due_date]);
            
            // อัพเดต reservation status
            $stmt = $db->prepare("UPDATE reservations SET status = 'fulfilled' WHERE reservation_id = ?");
            $stmt->execute([$id]);
            
            // ลดจำนวนหนังสือที่ว่าง
            $stmt = $db->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE book_id = ?");
            $stmt->execute([$reservation['book_id']]);
            
            // อัพเดตสถานะหนังสือ - หากไม่มีสำเนาว่างแล้วให้เป็น unavailable แต่ยังคง searchable
            $stmt = $db->prepare("
                UPDATE books 
                SET status = CASE 
                    WHEN available_copies <= 0 THEN 'unavailable'
                    ELSE 'available'
                END
                WHERE book_id = ?
            ");
            $stmt->execute([$reservation['book_id']]);
            
            $_SESSION['success'] = "อนุมัติการจองเรียบร้อยแล้ว สร้างรายการยืมให้ผู้ใช้แล้ว";
        } else {
            $_SESSION['error'] = "ไม่สามารถอนุมัติการจองได้ เนื่องจากหนังสือไม่ว่าง";
        }
    } elseif ($action == 'reject_reservation') {
        $stmt = $db->prepare("UPDATE reservations SET status = 'cancelled' WHERE reservation_id = ?");
        $stmt->execute([$id]);
        
        $_SESSION['error'] = "ปฏิเสธการจองแล้ว";
    }
    
    header("Location: dashboard.php");
    exit();
}

// ดึงสถิติพื้นฐาน
$total_books = $db->query("SELECT COUNT(*) FROM books")->fetchColumn();
$available_books = $db->query("SELECT COUNT(*) FROM books WHERE available_copies > 0")->fetchColumn();
$total_users = $db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$total_borrows = $db->query("SELECT COUNT(*) FROM borrowing WHERE status IN ('borrowed', 'overdue')")->fetchColumn();
$total_overdue = $db->query("SELECT COUNT(*) FROM borrowing WHERE status = 'overdue'")->fetchColumn();
$total_reservations = $db->query("SELECT COUNT(*) FROM reservations WHERE status = 'active'")->fetchColumn();
$pending_returns = $db->query("SELECT COUNT(*) FROM borrowing WHERE status = 'pending_return'")->fetchColumn();

// ดึงการจองที่รออนุมัติ
$stmt = $db->prepare("
    SELECT r.*, u.student_id, u.first_name, u.last_name, bo.title, bo.isbn, bo.available_copies,
           DATEDIFF(NOW(), r.reservation_date) as days_waiting
    FROM reservations r 
    JOIN users u ON r.user_id = u.user_id 
    JOIN books bo ON r.book_id = bo.book_id 
    WHERE r.status = 'active'
    ORDER BY r.priority_number ASC, r.reservation_date ASC
    LIMIT 5
");
$stmt->execute();
$pending_reservations = $stmt->fetchAll();

// ดึงการยืมที่รอการอนุมัติการคืน
$stmt = $db->prepare("
    SELECT b.*, u.student_id, u.first_name, u.last_name, bo.title, bo.isbn,
           DATEDIFF(NOW(), b.due_date) as days_overdue
    FROM borrowing b 
    JOIN users u ON b.user_id = u.user_id 
    JOIN books bo ON b.book_id = bo.book_id 
    WHERE b.status = 'pending_return'
    ORDER BY b.updated_at DESC 
    LIMIT 5
");
$stmt->execute();
$pending_returns_list = $stmt->fetchAll();

// ดึงการยืมที่เกินกำหนด
$stmt = $db->prepare("
    SELECT b.*, u.student_id, u.first_name, u.last_name, bo.title,
           DATEDIFF(NOW(), b.due_date) as days_overdue
    FROM borrowing b 
    JOIN users u ON b.user_id = u.user_id 
    JOIN books bo ON b.book_id = bo.book_id 
    WHERE b.status = 'overdue' 
    ORDER BY b.due_date ASC 
    LIMIT 5
");
$stmt->execute();
$overdue_borrows = $stmt->fetchAll();

// ดึงหนังสือยอดนิยม - แก้ไขแล้ว
$stmt = $db->query("
    SELECT 
        b.title, 
        b.isbn, 
        COUNT(*) as borrow_count,
        c.category_name, 
        p.publisher_name,
        GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as author
    FROM borrowing br 
    JOIN books b ON br.book_id = b.book_id 
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN publishers p ON b.publisher_id = p.publisher_id
    LEFT JOIN book_authors ba ON b.book_id = ba.book_id
    LEFT JOIN authors a ON ba.author_id = a.author_id
    GROUP BY b.book_id 
    ORDER BY borrow_count DESC 
    LIMIT 5
");
$popular_books = $stmt->fetchAll();

// ดึงผู้ใช้ใหม่ล่าสุด
$stmt = $db->query("
    SELECT first_name, last_name, student_id, created_at
    FROM users 
    WHERE status = 'active'
    ORDER BY created_at DESC 
    LIMIT 5
");
$new_users = $stmt->fetchAll();

// ดึงกิจกรรมล่าสุด (ลดจำนวนลง)
$stmt = $db->prepare("
    SELECT al.*, a.first_name as admin_fname, a.last_name as admin_lname,
           u.first_name as user_fname, u.last_name as user_lname
    FROM activity_logs al 
    LEFT JOIN admins a ON al.admin_id = a.admin_id
    LEFT JOIN users u ON al.user_id = u.user_id
    ORDER BY al.created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_activities = $stmt->fetchAll();

// ดึงข้อมูลจริงจากฐานข้อมูล
// สถิติรายเดือนนี้
$current_month = date('Y-m');
$monthly_borrows = $db->query("SELECT COUNT(*) FROM borrowing WHERE DATE_FORMAT(borrow_date, '%Y-%m') = '$current_month'")->fetchColumn();
$monthly_returns = $db->query("SELECT COUNT(*) FROM borrowing WHERE DATE_FORMAT(return_date, '%Y-%m') = '$current_month' AND status = 'returned'")->fetchColumn();
$monthly_new_users = $db->query("SELECT COUNT(*) FROM users WHERE DATE_FORMAT(created_at, '%Y-%m') = '$current_month'")->fetchColumn();
$monthly_reservations = $db->query("SELECT COUNT(*) FROM reservations WHERE DATE_FORMAT(reservation_date, '%Y-%m') = '$current_month'")->fetchColumn();

// สถิติวันนี้
$today = date('Y-m-d');
$today_borrows = $db->query("SELECT COUNT(*) FROM borrowing WHERE DATE(borrow_date) = '$today'")->fetchColumn();
$today_returns = $db->query("SELECT COUNT(*) FROM borrowing WHERE DATE(return_date) = '$today'")->fetchColumn();
$today_reservations = $db->query("SELECT COUNT(*) FROM reservations WHERE DATE(reservation_date) = '$today'")->fetchColumn();

// นับจำนวนแอดมินที่ล็อกอินในวันนี้
$today_active_admins = $db->query("SELECT COUNT(DISTINCT admin_id) FROM activity_logs WHERE DATE(created_at) = '$today' AND action IN ('login', 'create', 'update', 'delete')")->fetchColumn();

// นับจำนวนผู้ใช้ที่มีกิจกรรมในวันนี้
$today_active_users = $db->query("SELECT COUNT(DISTINCT user_id) FROM activity_logs WHERE DATE(created_at) = '$today' AND user_id IS NOT NULL")->fetchColumn();

// ข้อมูลฐานข้อมูล
$db_version = $db->query("SELECT VERSION()")->fetchColumn();
$total_records = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM books) + 
        (SELECT COUNT(*) FROM users) + 
        (SELECT COUNT(*) FROM borrowing) + 
        (SELECT COUNT(*) FROM reservations) + 
        (SELECT COUNT(*) FROM activity_logs) as total
")->fetchColumn();

// ขนาดฐานข้อมูล (ถ้าเป็น MySQL)
try {
    $db_size_result = $db->query("
        SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS db_size_mb
        FROM information_schema.tables 
        WHERE table_schema = DATABASE()
    ");
    $db_size = $db_size_result->fetchColumn();
} catch(Exception $e) {
    $db_size = 'ไม่ทราบ';
}

// อัพเดตล่าสุดของข้อมูล
$last_activity = $db->query("SELECT created_at FROM activity_logs ORDER BY created_at DESC LIMIT 1")->fetchColumn();
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

        .welcome-section {
            background: white;
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 2.5rem;
            position: relative;
            overflow: hidden;
        }

        .welcome-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .welcome-section h1 {
            font-size: 2.2rem;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .welcome-section p {
            color: #666;
            font-size: 1.1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: white;
            padding: 2.5rem;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .stat-card i {
            font-size: 3.5rem;
            color: #667eea;
            margin-bottom: 1rem;
        }

        .stat-number {
            font-size: 3rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 0.5rem;
            background: linear-gradient(45deg, #667eea, #764ba2); 
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-label {
            color: #666;
            font-size: 1.1rem;
            font-weight: 500;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2.5rem;
            margin-bottom: 2.5rem;
        }

        .main-content {
            display: flex;
            flex-direction: column;
            gap: 2.5rem;
        }

        .sidebar-content {
            display: flex;
            flex-direction: column;
            gap: 2.5rem;
        }

        .section-card {
            background: white;
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .section-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .section-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }

        .section-card h3 {
            font-size: 1.6rem;
            color: #333;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }

        .section-card h3 i {
            color: #667eea !important;
            font-size: 1.4rem;
        }

        .item-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            border-left: 5px solid #667eea;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .item-card::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 0;
            height: 100%;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            transition: width 0.3s ease;
        }

        .item-card:hover::after {
            width: 100%;
        }

        .item-card:hover {
            transform: translateX(10px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.2);
            border-left-color: #764ba2;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }

        .item-details {
            flex: 1;
            position: relative;
            z-index: 1;
        }

        .item-details h4 {
            font-size: 1.2rem;
            color: #333;
            margin-bottom: 0.8rem;
            font-weight: 600;
        }

        .item-details p {
            color: #666;
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .item-details p i {
            color: #667eea !important;
            width: 16px;
        }

        .item-actions {
            display: flex;
            gap: 0.8rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }

        .btn-action {
            padding: 10px 18px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-approve {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
        }

        .btn-reject {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
        }

        .btn-view {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            gap: 5px;
        }

        .status-overdue {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
        }

        .status-pending {
            background: linear-gradient(135deg, #f39c12 0%, #d35400 100%);
            color: white;
        }

        .status-active {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
        }

        .status-available {
            background: linear-gradient(135deg, #1abc9c 0%, #16a085 100%);
            color: white;
        }

        .no-data {
            text-align: center;
            color: #999;
            padding: 3rem 2rem;
            font-style: italic;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 1.2rem;
            padding: 1.2rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 12px;
            margin-bottom: 0.8rem;
            transition: all 0.3s ease;
            border-left: 3px solid #667eea;
        }

        .activity-item:hover {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            transform: translateX(5px);
        }

        .activity-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-size: 1.2rem;
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3);
        }

        .activity-details {
            flex: 1;
        }

        .activity-details h5 {
            font-size: 1rem;
            color: #333;
            margin-bottom: 0.3rem;
            font-weight: 600;
        }

        .activity-details p {
            font-size: 0.85rem;
            color: #666;
        }

        .alert {
            padding: 1.2rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(46, 204, 113, 0.1) 0%, rgba(39, 174, 96, 0.1) 100%);
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }

        .alert-error {
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.1) 0%, rgba(192, 57, 43, 0.1) 100%);
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 0;
            border: none;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease-out;
            overflow: hidden;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
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
            text-align: center;
            position: relative;
        }

        .modal-header h4 {
            font-size: 1.4rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .modal-header i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        .close {
            color: white;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            right: 20px;
            top: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .close:hover,
        .close:focus {
            color: #ffcccc;
            transform: scale(1.1);
        }

        .modal-body {
            padding: 2rem;
            text-align: center;
        }

        .modal-body p {
            font-size: 1.1rem;
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }

        .modal-details {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            text-align: left;
        }

        .modal-details h5 {
            color: #333;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .modal-details p {
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
            color: #666;
        }

        .modal-footer {
            padding: 1.5rem 2rem 2rem;
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        .modal-btn {
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 120px;
        }

        .modal-btn-confirm {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
        }

        .modal-btn-reject {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
        }

        .modal-btn-cancel {
            background: #6c757d;
            color: white;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }

        .modal-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
        }

        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .sidebar-content {
                order: -1;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 0 1rem;
                margin-top: 100px;
            }

            .nav-container {
                padding: 0 1rem;
                flex-direction: column;
                gap: 1rem;
            }

            .nav-links {
                gap: 1rem;
                flex-wrap: wrap;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 1.5rem;
            }

            .item-header {
                flex-direction: column;
                gap: 1rem;
            }

            .item-actions {
                flex-direction: column;
                gap: 0.5rem;
            }

            .btn-action {
                justify-content: center;
            }

            .modal-content {
                margin: 10% auto;
                width: 95%;
            }

            .modal-footer {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
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
                <li><a href="dashboard.php" class="active">แดชบอร์ด</a></li>
                <li><a href="books.php">จัดการหนังสือ</a></li>
                <li><a href="users.php">จัดการผู้ใช้</a></li>
                <li><a href="borrows.php">จัดการการยืม</a></li>
                <li><a href="reservations.php">การจอง</a></li>
            </ul>
            <a href="../logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i>
                ออกจากระบบ
            </a>
        </nav>
    </header>

    <div class="dashboard-container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success animate__animated animate__fadeIn">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error animate__animated animate__fadeIn">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <section class="welcome-section">
            <h1>ยินดีต้อนรับ, <?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?></h1>
            <p>บทบาท: <?php echo $admin['role'] == 'super_admin' ? 'ผู้ดูแลระบบหลัก' : ($admin['role'] == 'librarian' ? 'บรรณารักษ์' : 'ผู้ช่วยบรรณารักษ์'); ?> | อีเมล: <?php echo htmlspecialchars($admin['email']); ?></p>
        </section>

        <section class="stats-grid">
            <div class="stat-card books">
                <i class="fas fa-book"></i>
                <div class="stat-number"><?php echo number_format($total_books); ?></div>
                <div class="stat-label">หนังสือทั้งหมด</div>
            </div>
            <div class="stat-card available animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
                <i class="fas fa-check-circle"></i>
                <div class="stat-number"><?php echo number_format($available_books); ?></div>
                <div class="stat-label">หนังสือที่มีให้ยืม</div>
            </div>
            <div class="stat-card users animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
                <i class="fas fa-users"></i>
                <div class="stat-number"><?php echo number_format($total_users); ?></div>
                <div class="stat-label">ผู้ใช้งานทั้งหมด</div>
            </div>
            <div class="stat-card borrows animate__animated animate__fadeInUp" style="animation-delay: 0.4s;">
                <i class="fas fa-exchange-alt"></i>
                <div class="stat-number"><?php echo number_format($total_borrows); ?></div>
                <div class="stat-label">การยืมที่ดำเนินการอยู่</div>
            </div>
            <div class="stat-card overdue animate__animated animate__fadeInUp" style="animation-delay: 0.5s;">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="stat-number"><?php echo number_format($total_overdue); ?></div>
                <div class="stat-label">การยืมเกินกำหนด</div>
            </div>
            <div class="stat-card reservations animate__animated animate__fadeInUp" style="animation-delay: 0.6s;">
                <i class="fas fa-calendar-check"></i>
                <div class="stat-number"><?php echo number_format($total_reservations); ?></div>
                <div class="stat-label">การจองหนังสือ</div>
            </div>
            <div class="stat-card pending animate__animated animate__fadeInUp" style="animation-delay: 0.7s;">
                <i class="fas fa-clock"></i>
                <div class="stat-number"><?php echo number_format($pending_returns); ?></div>
                <div class="stat-label">รอการอนุมัติคืน</div>
            </div>
        </section>

        <div class="dashboard-grid">
            <div class="main-content">
                <!-- การขออนุมัติการจองหนังสือ -->
                <div class="section-card">
                    <h3><i class="fas fa-bookmark"></i>รออนุมัติการจองหนังสือ</h3>
                    <?php if (count($pending_reservations) > 0): ?>
                        <?php foreach ($pending_reservations as $reservation): ?>
                            <div class="item-card">
                                <div class="item-header">
                                    <div class="item-details">
                                        <h4><?php echo htmlspecialchars($reservation['title']); ?></h4>
                                        <p><i class="fas fa-user"></i> ผู้จอง: <?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name'] . ' (' . $reservation['student_id'] . ')'); ?></p>
                                        <p><i class="fas fa-calendar"></i> วันที่จอง: <?php echo date('d/m/Y H:i', strtotime($reservation['reservation_date'])); ?></p>
                                        <p><i class="fas fa-clock"></i> รอมาแล้ว: <?php echo $reservation['days_waiting']; ?> วัน</p>
                                        <?php if ($reservation['isbn']): ?>
                                            <p><i class="fas fa-barcode"></i> ISBN: <?php echo htmlspecialchars($reservation['isbn']); ?></p>
                                        <?php endif; ?>
                                        <p><i class="fas fa-copy"></i> หนังสือว่าง: 
                                            <?php if ($reservation['available_copies'] > 0): ?>
                                                <span class="status-badge status-available"><?php echo $reservation['available_copies']; ?> เล่ม</span>
                                            <?php else: ?>
                                                <span class="status-badge status-overdue">ไม่ว่าง</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="item-actions">
                                    <?php if ($reservation['available_copies'] > 0): ?>
                                        <button class="btn-action btn-approve" 
                                                onclick="showReservationModal('approve', <?php echo $reservation['reservation_id']; ?>, '<?php echo htmlspecialchars($reservation['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-check"></i> อนุมัติการจอง
                                        </button>
                                    <?php else: ?>
                                        <span class="btn-action" style="background: #95a5a6; cursor: not-allowed;">
                                            <i class="fas fa-ban"></i> หนังสือไม่ว่าง
                                        </span>
                                    <?php endif; ?>
                                    <button class="btn-action btn-reject"
                                            onclick="showReservationModal('reject', <?php echo $reservation['reservation_id']; ?>, '<?php echo htmlspecialchars($reservation['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-times"></i> ปฏิเสธ
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-inbox"></i>
                            <p>ไม่มีรายการจองที่รออนุมัติ</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- การขออนุมัติการคืนหนังสือ -->
                <div class="section-card animate__animated animate__fadeInLeft" style="animation-delay: 0.1s;">
                    <h3><i class="fas fa-undo-alt"></i>รออนุมัติการคืนหนังสือ</h3>
                    <?php if (count($pending_returns_list) > 0): ?>
                        <?php foreach ($pending_returns_list as $return): ?>
                            <div class="item-card">
                                <div class="item-header">
                                    <div class="item-details">
                                        <h4><?php echo htmlspecialchars($return['title']); ?></h4>
                                        <p><i class="fas fa-user"></i> ผู้ยืม: <?php echo htmlspecialchars($return['first_name'] . ' ' . $return['last_name'] . ' (' . $return['student_id'] . ')'); ?></p>
                                        <p><i class="fas fa-calendar"></i> วันที่ยืม: <?php echo date('d/m/Y', strtotime($return['borrow_date'])); ?></p>
                                        <p><i class="fas fa-calendar-times"></i> กำหนดคืน: <?php echo date('d/m/Y', strtotime($return['due_date'])); ?></p>
                                        <?php if ($return['days_overdue'] > 0): ?>
                                            <p><i class="fas fa-exclamation-triangle"></i> เกินกำหนด: <span class="status-badge status-overdue"><?php echo $return['days_overdue']; ?> วัน</span></p>
                                        <?php else: ?>
                                            <p><i class="fas fa-check-circle"></i> สถานะ: <span class="status-badge status-pending">รอการอนุมัติ</span></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="item-actions">
                                    <button class="btn-action btn-approve"
                                            onclick="showReturnModal('approve', <?php echo $return['borrow_id']; ?>, '<?php echo htmlspecialchars($return['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($return['first_name'] . ' ' . $return['last_name'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-check"></i> อนุมัติการคืน
                                    </button>
                                    <button class="btn-action btn-reject"
                                            onclick="showReturnModal('reject', <?php echo $return['borrow_id']; ?>, '<?php echo htmlspecialchars($return['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($return['first_name'] . ' ' . $return['last_name'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-times"></i> ปฏิเสธ
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-inbox"></i>
                            <p>ไม่มีรายการรอการอนุมัติการคืน</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- การยืมที่เกินกำหนด -->
                <div class="section-card animate__animated animate__fadeInLeft" style="animation-delay: 0.2s;">
                    <h3><i class="fas fa-exclamation-circle"></i>การยืมเกินกำหนด</h3>
                    <?php if (count($overdue_borrows) > 0): ?>
                        <?php foreach ($overdue_borrows as $overdue): ?>
                            <div class="item-card">
                                <div class="item-details">
                                    <h4><?php echo htmlspecialchars($overdue['title']); ?></h4>
                                    <p><i class="fas fa-user"></i> ผู้ยืม: <?php echo htmlspecialchars($overdue['first_name'] . ' ' . $overdue['last_name'] . ' (' . $overdue['student_id'] . ')'); ?></p>
                                    <p><i class="fas fa-calendar"></i> วันที่ยืม: <?php echo date('d/m/Y', strtotime($overdue['borrow_date'])); ?></p>
                                    <p><i class="fas fa-calendar-times"></i> กำหนดคืน: <?php echo date('d/m/Y', strtotime($overdue['due_date'])); ?></p>
                                    <p><i class="fas fa-exclamation-triangle"></i> เกินกำหนด: 
                                        <span class="status-badge status-overdue"><?php echo $overdue['days_overdue']; ?> วัน</span>
                                    </p>
                                </div>
                                <div class="item-actions">
                                    <a href="borrows.php?contact=<?php echo $overdue['user_id']; ?>" class="btn-action" style="background: linear-gradient(135deg, #f39c12 0%, #d35400 100%); color: white;">
                                        <i class="fas fa-phone"></i> ติดต่อผู้ยืม
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-check-circle"></i>
                            <p>ไม่มีการยืมที่เกินกำหนด</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- หนังสือยอดนิยม -->
                <div class="section-card animate__animated animate__fadeInLeft" style="animation-delay: 0.3s;">
                    <h3><i class="fas fa-star"></i>หนังสือยอดนิยม</h3>
                    <?php if (count($popular_books) > 0): ?>
                        <?php foreach ($popular_books as $index => $book): ?>
                            <div class="item-card">
                                <div class="item-details">
                                    <h4>
                                        <span style="background: linear-gradient(135deg, #667eea, #764ba2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: bold; font-size: 1.1em;">
                                            #<?php echo $index + 1; ?>
                                        </span>
                                        <?php echo htmlspecialchars($book['title']); ?>
                                    </h4>
                                    <?php if ($book['author']): ?>
                                        <p><i class="fas fa-user-edit"></i> ผู้แต่ง: <?php echo htmlspecialchars($book['author']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($book['isbn']): ?>
                                        <p><i class="fas fa-barcode"></i> ISBN: <?php echo htmlspecialchars($book['isbn']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($book['category_name']): ?>
                                        <p><i class="fas fa-tag"></i> หมวดหมู่: <?php echo htmlspecialchars($book['category_name']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($book['publisher_name']): ?>
                                        <p><i class="fas fa-building"></i> สำนักพิมพ์: <?php echo htmlspecialchars($book['publisher_name']); ?></p>
                                    <?php endif; ?>
                                    <p><i class="fas fa-chart-bar"></i> ยืมทั้งหมด: 
                                        <span class="status-badge status-active"><?php echo $book['borrow_count']; ?> ครั้ง</span>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-chart-line"></i>
                            <p>ไม่มีข้อมูลหนังสือยอดนิยม</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sidebar-content">
                <!-- ผู้ใช้ที่เพิ่งสมัครใหม่ -->
                <div class="section-card animate__animated animate__fadeInRight">
                    <h3><i class="fas fa-user-plus"></i>ผู้ใช้ใหม่ล่าสุด</h3>
                    <?php if (count($new_users) > 0): ?>
                        <?php foreach ($new_users as $user): ?>
                            <div class="item-card">
                                <div class="item-details">
                                    <h4><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                                    <p><i class="fas fa-id-card"></i> รหัสนิสิต: <?php echo htmlspecialchars($user['student_id']); ?></p>
                                    <p><i class="fas fa-calendar"></i> สมัครเมื่อ: <?php echo date('d/m/Y H:i', strtotime($user['created_at'])); ?></p>
                                    <p><i class="fas fa-clock"></i> 
                                        <?php 
                                            $days_ago = floor((time() - strtotime($user['created_at'])) / 86400);
                                            if ($days_ago == 0) {
                                                echo '<span class="status-badge status-active">วันนี้</span>';
                                            } elseif ($days_ago == 1) {
                                                echo '<span class="status-badge status-pending">เมื่อวาน</span>';
                                            } else {
                                                echo '<span class="status-badge">' . $days_ago . ' วันที่แล้ว</span>';
                                            }
                                        ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-user-plus"></i>
                            <p>ไม่มีผู้ใช้ใหม่</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- กิจกรรมล่าสุด -->
                <div class="section-card animate__animated animate__fadeInRight" style="animation-delay: 0.2s;">
                    <h3><i class="fas fa-history"></i>กิจกรรมล่าสุด</h3>
                    <?php if (count($recent_activities) > 0): ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <?php
                                        $icons = [
                                            'login' => 'fas fa-sign-in-alt',
                                            'logout' => 'fas fa-sign-out-alt',
                                            'borrow' => 'fas fa-book',
                                            'return' => 'fas fa-undo-alt',
                                            'create' => 'fas fa-plus',
                                            'update' => 'fas fa-edit',
                                            'delete' => 'fas fa-trash',
                                            'reserve' => 'fas fa-calendar-check'
                                        ];
                                        $icon = $icons[$activity['action']] ?? 'fas fa-info';
                                    ?>
                                    <i class="<?php echo $icon; ?>"></i>
                                </div>
                                <div class="activity-details">
                                    <h5>
                                        <?php
                                            $action_names = [
                                                'login' => 'เข้าสู่ระบบ',
                                                'logout' => 'ออกจากระบบ',
                                                'borrow' => 'ยืมหนังสือ',
                                                'return' => 'คืนหนังสือ',
                                                'create' => 'เพิ่มข้อมูล',
                                                'update' => 'แก้ไขข้อมูล',
                                                'delete' => 'ลบข้อมูล',
                                                'reserve' => 'จองหนังสือ'
                                            ];
                                            echo $action_names[$activity['action']] ?? $activity['action'];
                                        ?>
                                        <?php if ($activity['table_name']): ?>
                                            - <?php echo $activity['table_name']; ?>
                                        <?php endif; ?>
                                    </h5>
                                    <p>
                                        <?php if ($activity['admin_fname']): ?>
                                            โดย: <?php echo htmlspecialchars($activity['admin_fname'] . ' ' . $activity['admin_lname']); ?>
                                        <?php elseif ($activity['user_fname']): ?>
                                            ผู้ใช้: <?php echo htmlspecialchars($activity['user_fname'] . ' ' . $activity['user_lname']); ?>
                                        <?php endif; ?>
                                        | <?php echo date('d/m/Y H:i', strtotime($activity['created_at'])); ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-history"></i>
                            <p>ไม่มีกิจกรรมล่าสุด</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- สถิติด่วน -->
                <div class="section-card animate__animated animate__fadeInRight" style="animation-delay: 0.4s;">
                    <h3><i class="fas fa-tachometer-alt"></i>สถิติด่วน</h3>
                    <div style="display: grid; gap: 1rem;">
                        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.5rem; border-radius: 15px; text-align: center;">
                            <div style="font-size: 1.8rem; font-weight: bold; margin-bottom: 0.5rem;">
                                <?php echo round(($total_books - $available_books) / max($total_books, 1) * 100, 1); ?>%
                            </div>
                            <div style="opacity: 0.9;">อัตราการใช้งานหนังสือ</div>
                        </div>
                        <div style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; padding: 1.5rem; border-radius: 15px; text-align: center;">
                            <div style="font-size: 1.8rem; font-weight: bold; margin-bottom: 0.5rem;">
                                <?php echo round($total_overdue / max($total_borrows + $total_overdue, 1) * 100, 1); ?>%
                            </div>
                            <div style="opacity: 0.9;">อัตราการยืมเกินกำหนด</div>
                        </div>
                        <div style="background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%); color: white; padding: 1.5rem; border-radius: 15px; text-align: center;">
                            <div style="font-size: 1.8rem; font-weight: bold; margin-bottom: 0.5rem;">
                                <?php echo $total_users > 0 ? round(($total_borrows / $total_users), 1) : 0; ?>
                            </div>
                            <div style="opacity: 0.9;">หนังสือต่อผู้ใช้โดยเฉลี่ย</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- สรุปสถิติรายเดือน -->
        <div class="section-card animate__animated animate__fadeInUp">
            <h3><i class="fas fa-chart-pie"></i>สรุปสถิติเดือนนี้</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-book-reader"></i>
                    <div class="stat-number"><?php echo number_format($monthly_borrows); ?></div>
                    <div class="stat-label">การยืมเดือนนี้</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-undo"></i>
                    <div class="stat-number"><?php echo number_format($monthly_returns); ?></div>
                    <div class="stat-label">การคืนเดือนนี้</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-user-plus"></i>
                    <div class="stat-number"><?php echo number_format($monthly_new_users); ?></div>
                    <div class="stat-label">สมาชิกใหม่เดือนนี้</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-bookmark"></i>
                    <div class="stat-number"><?php echo number_format($monthly_reservations); ?></div>
                    <div class="stat-label">การจองเดือนนี้</div>
                </div>
            </div>
        </div>

        <!-- ข้อมูลระบบ -->
        <div class="section-card animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
            <h3><i class="fas fa-info-circle"></i>ข้อมูลระบบ</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                <div>
                    <h4 style="color: #667eea; margin-bottom: 1rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-chart-line"></i> สถิติการใช้งานวันนี้
                    </h4>
                    <p style="margin-bottom: 0.5rem;"><i class="fas fa-arrow-right" style="color: #27ae60; width: 20px;"></i> การยืมวันนี้: <strong><?php echo $today_borrows; ?></strong> รายการ</p>
                    <p style="margin-bottom: 0.5rem;"><i class="fas fa-arrow-left" style="color: #3498db; width: 20px;"></i> การคืนวันนี้: <strong><?php echo $today_returns; ?></strong> รายการ</p>
                    <p style="margin-bottom: 0.5rem;"><i class="fas fa-bookmark" style="color: #9b59b6; width: 20px;"></i> การจองวันนี้: <strong><?php echo $today_reservations; ?></strong> รายการ</p>
                    <p style="margin-bottom: 0.5rem;"><i class="fas fa-users-cog" style="color: #e74c3c; width: 20px;"></i> แอดมินที่ใช้งาน: <strong><?php echo $today_active_admins; ?></strong> คน</p>
                    <p><i class="fas fa-users" style="color: #f39c12; width: 20px;"></i> ผู้ใช้ที่มีกิจกรรม: <strong><?php echo $today_active_users; ?></strong> คน</p>
                </div>
                <div>
                    <h4 style="color: #667eea; margin-bottom: 1rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-server"></i> สถานะระบบ
                    </h4>
                    <p style="margin-bottom: 0.5rem;"><i class="fas fa-database" style="color: #3498db; width: 20px;"></i> ฐานข้อมูล: <strong><?php echo htmlspecialchars($db_version); ?></strong></p>
                    <p style="margin-bottom: 0.5rem;"><i class="fas fa-hdd" style="color: #27ae60; width: 20px;"></i> ขนาด DB: <strong><?php echo $db_size; ?><?php echo is_numeric($db_size) ? ' MB' : ''; ?></strong></p>
                    <p style="margin-bottom: 0.5rem;"><i class="fas fa-table" style="color: #f39c12; width: 20px;"></i> รายการทั้งหมด: <strong><?php echo number_format($total_records); ?></strong> รายการ</p>
                    <p style="margin-bottom: 0.5rem;"><i class="fas fa-clock" style="color: #e74c3c; width: 20px;"></i> กิจกรรมล่าสุด: <strong><?php echo $last_activity ? date('d/m/Y H:i:s', strtotime($last_activity)) : 'ไม่มีข้อมูล'; ?></strong></p>
                    <p><i class="fas fa-shield-alt" style="color: #27ae60; width: 20px;"></i> สถานะระบบ: <span class="status-badge status-active">ปกติ</span></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Reservation Modal -->
    <div id="reservationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="close" onclick="closeModal('reservationModal')">&times;</span>
                <i id="reservationIcon" class="fas fa-bookmark"></i>
                <h4 id="reservationTitle">ยืนยันการดำเนินการ</h4>
                <p id="reservationSubtitle">กรุณาตรวจสอบข้อมูลก่อนดำเนินการ</p>
            </div>
            <div class="modal-body">
                <p id="reservationMessage">คุณต้องการดำเนินการหรือไม่?</p>
                <div class="modal-details">
                    <h5><i class="fas fa-book"></i> รายละเอียดการจอง</h5>
                    <p><i class="fas fa-bookmark"></i> หนังสือ: <span id="reservationBookTitle"></span></p>
                    <p><i class="fas fa-user"></i> ผู้จอง: <span id="reservationUserName"></span></p>
                    <p><i class="fas fa-calendar"></i> วันเวลาที่ดำเนินการ: <span id="reservationDateTime"></span></p>
                </div>
            </div>
            <div class="modal-footer">
                <button id="reservationConfirmBtn" class="modal-btn modal-btn-confirm" onclick="confirmReservationAction()">
                    <i class="fas fa-check"></i> ยืนยัน
                </button>
                <button class="modal-btn modal-btn-cancel" onclick="closeModal('reservationModal')">
                    <i class="fas fa-times"></i> ยกเลิก
                </button>
            </div>
        </div>
    </div>

    <!-- Return Modal -->
    <div id="returnModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="close" onclick="closeModal('returnModal')">&times;</span>
                <i id="returnIcon" class="fas fa-undo-alt"></i>
                <h4 id="returnTitle">ยืนยันการดำเนินการ</h4>
                <p id="returnSubtitle">กรุณาตรวจสอบข้อมูลก่อนดำเนินการ</p>
            </div>
            <div class="modal-body">
                <p id="returnMessage">คุณต้องการดำเนินการหรือไม่?</p>
                <div class="modal-details">
                    <h5><i class="fas fa-book"></i> รายละเอียดการคืน</h5>
                    <p><i class="fas fa-book-open"></i> หนังสือ: <span id="returnBookTitle"></span></p>
                    <p><i class="fas fa-user"></i> ผู้ยืม: <span id="returnUserName"></span></p>
                    <p><i class="fas fa-calendar"></i> วันเวลาที่ดำเนินการ: <span id="returnDateTime"></span></p>
                </div>
            </div>
            <div class="modal-footer">
                <button id="returnConfirmBtn" class="modal-btn modal-btn-confirm" onclick="confirmReturnAction()">
                    <i class="fas fa-check"></i> ยืนยัน
                </button>
                <button class="modal-btn modal-btn-cancel" onclick="closeModal('returnModal')">
                    <i class="fas fa-times"></i> ยกเลิก
                </button>
            </div>
        </div>
    </div>

    <script>
        // Modal Management
        let currentAction = null;
        let currentId = null;

        function showReservationModal(action, id, bookTitle, userName) {
            currentAction = action;
            currentId = id;
            
            const modal = document.getElementById('reservationModal');
            const icon = document.getElementById('reservationIcon');
            const title = document.getElementById('reservationTitle');
            const subtitle = document.getElementById('reservationSubtitle');
            const message = document.getElementById('reservationMessage');
            const confirmBtn = document.getElementById('reservationConfirmBtn');
            
            // Update modal content
            document.getElementById('reservationBookTitle').textContent = bookTitle;
            document.getElementById('reservationUserName').textContent = userName;
            document.getElementById('reservationDateTime').textContent = new Date().toLocaleString('th-TH');
            
            if (action === 'approve') {
                icon.className = 'fas fa-check-circle';
                title.textContent = 'อนุมัติการจองหนังสือ';
                subtitle.textContent = 'ระบบจะสร้างรายการยืมให้ผู้ใช้โดยอัตโนมัติ';
                message.textContent = 'คุณแน่ใจหรือไม่ว่าต้องการอนุมัติการจองนี้? ระบบจะสร้างรายการยืมให้ผู้ใช้ทันที';
                confirmBtn.className = 'modal-btn modal-btn-confirm';
                confirmBtn.innerHTML = '<i class="fas fa-check"></i> อนุมัติการจอง';
            } else {
                icon.className = 'fas fa-times-circle';
                title.textContent = 'ปฏิเสธการจองหนังสือ';
                subtitle.textContent = 'การจองจะถูกยกเลิก';
                message.textContent = 'คุณแน่ใจหรือไม่ว่าต้องการปฏิเสธการจองนี้?';
                confirmBtn.className = 'modal-btn modal-btn-reject';
                confirmBtn.innerHTML = '<i class="fas fa-times"></i> ปฏิเสธการจอง';
            }
            
            modal.style.display = 'block';
        }

        function showReturnModal(action, id, bookTitle, userName) {
            currentAction = action;
            currentId = id;
            
            const modal = document.getElementById('returnModal');
            const icon = document.getElementById('returnIcon');
            const title = document.getElementById('returnTitle');
            const subtitle = document.getElementById('returnSubtitle');
            const message = document.getElementById('returnMessage');
            const confirmBtn = document.getElementById('returnConfirmBtn');
            
            // Update modal content
            document.getElementById('returnBookTitle').textContent = bookTitle;
            document.getElementById('returnUserName').textContent = userName;
            document.getElementById('returnDateTime').textContent = new Date().toLocaleString('th-TH');
            
            if (action === 'approve') {
                icon.className = 'fas fa-check-circle';
                title.textContent = 'อนุมัติการคืนหนังสือ';
                subtitle.textContent = 'หนังสือจะถูกส่งคืนเข้าระบบ';
                message.textContent = 'คุณแน่ใจหรือไม่ว่าต้องการอนุมัติการคืนนี้? หนังสือจะพร้อมให้ผู้อื่นยืมได้ทันที';
                confirmBtn.className = 'modal-btn modal-btn-confirm';
                confirmBtn.innerHTML = '<i class="fas fa-check"></i> อนุมัติการคืน';
            } else {
                icon.className = 'fas fa-times-circle';
                title.textContent = 'ปฏิเสธการคืนหนังสือ';
                subtitle.textContent = 'การคืนจะถูกปฏิเสธ';
                message.textContent = 'คุณแน่ใจหรือไม่ว่าต้องการปฏิเสธการคืนนี้?';
                confirmBtn.className = 'modal-btn modal-btn-reject';
                confirmBtn.innerHTML = '<i class="fas fa-times"></i> ปฏิเสธการคืน';
            }
            
            modal.style.display = 'block';
        }

        function confirmReservationAction() {
            if (currentAction === 'approve') {
                window.location.href = `?action=approve_reservation&id=${currentId}`;
            } else if (currentAction === 'reject') {
                window.location.href = `?action=reject_reservation&id=${currentId}`;
            }
        }

        function confirmReturnAction() {
            if (currentAction === 'approve') {
                window.location.href = `?action=approve_return&id=${currentId}`;
            } else if (currentAction === 'reject') {
                window.location.href = `?action=reject_return&id=${currentId}`;
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            currentAction = null;
            currentId = null;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = ['reservationModal', 'returnModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    closeModal(modalId);
                }
            });
        }

        // ESC key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('reservationModal');
                closeModal('returnModal');
            }
        });

        // Auto refresh every 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000);

        // Show notification count in title if there are pending items
        const pendingCount = <?php echo $pending_returns + $total_overdue + count($pending_reservations); ?>;
        if (pendingCount > 0) {
            document.title = `(${pendingCount}) แดชบอร์ดและรายงาน - ห้องสมุดดิจิทัล`;
        }

        // Real-time clock update
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleString('th-TH', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            
            // Update clock elements in modals
            document.querySelectorAll('#reservationDateTime, #returnDateTime').forEach(clock => {
                if (clock.textContent && !clock.textContent.includes('กำลัง')) {
                    clock.textContent = timeString;
                }
            });
        }

        // Update clock every second
        setInterval(updateClock, 1000);
        updateClock();

        // Add smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Show/hide alerts with fade effect
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 500);
            }, 5000);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt + R for refresh
            if (e.altKey && e.key === 'r') {
                e.preventDefault();
                location.reload();
            }
            
            // Alt + B for books page
            if (e.altKey && e.key === 'b') {
                e.preventDefault();
                window.location.href = 'books.php';
            }
            
            // Alt + U for users page
            if (e.altKey && e.key === 'u') {
                e.preventDefault();
                window.location.href = 'users.php';
            }
        });

        // Add tooltips to status badges
        document.querySelectorAll('.status-badge').forEach(badge => {
            const text = badge.textContent.trim();
            let tooltip = '';
            
            if (text.includes('เกินกำหนด')) {
                tooltip = 'ต้องติดตามการคืนหนังสือ';
            } else if (text.includes('รออนุมัติ')) {
                tooltip = 'รอการดำเนินการจากแอดมิน';
            } else if (text.includes('เล่ม')) {
                tooltip = 'จำนวนหนังสือที่มีให้ยืม';
            }
            
            if (tooltip) {
                badge.title = tooltip;
            }
        });

        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            console.log(`📊 Dashboard loaded in ${Math.round(loadTime)}ms`);
            
            // Log page statistics
            console.log('📈 Page Statistics:');
            console.log(`- Total books: ${document.querySelector('.books .stat-number')?.textContent || 'N/A'}`);
            console.log(`- Active users: ${document.querySelector('.users .stat-number')?.textContent || 'N/A'}`);
            console.log(`- Active borrows: ${document.querySelector('.borrows .stat-number')?.textContent || 'N/A'}`);
            console.log(`- Overdue items: ${document.querySelector('.overdue .stat-number')?.textContent || 'N/A'}`);
        });

        // Console shortcuts help
        console.log('⌨️ Keyboard Shortcuts:');
        console.log('Alt + R: Refresh page');
        console.log('Alt + B: Go to Books page');
        console.log('Alt + U: Go to Users page');
        console.log('ESC: Close modals');
    </script>
</body>
</html> 