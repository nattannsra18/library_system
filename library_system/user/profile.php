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

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_profile':
                try {
                    $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, department = ?, class_level = ? WHERE user_id = ?");
                    $stmt->execute([
                        $_POST['first_name'],
                        $_POST['last_name'],
                        $_POST['email'],
                        $_POST['phone'],
                        $_POST['address'],
                        $_POST['department'],
                        $_POST['class_level'],
                        $user_id
                    ]);
                    
                    $message = 'อัปเดตข้อมูลส่วนตัวสำเร็จ';
                    $message_type = 'success';
                    
                    // Log activity
                    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, table_name, record_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $user_id,
                        'update_profile',
                        'users',
                        $user_id,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                } catch(Exception $e) {
                    $message = 'เกิดข้อผิดพลาดในการอัปเดตข้อมูล';
                    $message_type = 'error';
                }
                break;

            case 'change_password':
                try {
                    // Verify current password
                    $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    $current_hash = $stmt->fetchColumn();
                    
                    if (!password_verify($_POST['current_password'], $current_hash)) {
                        $message = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
                        $message_type = 'error';
                    } else if ($_POST['new_password'] !== $_POST['confirm_password']) {
                        $message = 'รหัสผ่านใหม่และยืนยันรหัสผ่านไม่ตรงกัน';
                        $message_type = 'error';
                    } else if (strlen($_POST['new_password']) < 6) {
                        $message = 'รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษร';
                        $message_type = 'error';
                    } else {
                        // Update password
                        $new_hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                        $stmt->execute([$new_hash, $user_id]);
                        
                        $message = 'เปลี่ยนรหัสผ่านสำเร็จ';
                        $message_type = 'success';
                        
                        // Log activity
                        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, table_name, record_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $user_id,
                            'change_password',
                            'users',
                            $user_id,
                            $_SERVER['REMOTE_ADDR'],
                            $_SERVER['HTTP_USER_AGENT']
                        ]);
                    }
                } catch(Exception $e) {
                    $message = 'เกิดข้อผิดพลาดในการเปลี่ยนรหัสผ่าน';
                    $message_type = 'error';
                }
                break;
        }
    }
}

// Handle AJAX requests for image upload/delete
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('กรุณาเลือกไฟล์รูปภาพ');
            }
            
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $file_type = $_FILES['profile_image']['type'];
            
            if (!in_array($file_type, $allowed_types)) {
                throw new Exception('รูปแบบไฟล์ไม่ถูกต้อง (รองรับเฉพาะ JPG, PNG, GIF)');
            }
            
            if ($_FILES['profile_image']['size'] > 5 * 1024 * 1024) { // 5MB
                throw new Exception('ไฟล์รูปภาพต้องมีขนาดไม่เกิน 5MB');
            }
            
            // Create upload directory if not exists
            $upload_dir = '../uploads/profile_images/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            $db_path = 'uploads/profile_images/' . $new_filename;
            
            // Delete old profile image if exists
            $stmt = $pdo->prepare("SELECT profile_image FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $old_image = $stmt->fetchColumn();
            if ($old_image && file_exists('../' . $old_image)) {
                unlink('../' . $old_image);
            }
            
            // Move uploaded file
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                // Update database
                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE user_id = ?");
                $stmt->execute([$db_path, $user_id]);
                
                // Log activity
                $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, table_name, record_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $user_id,
                    'upload_profile_image',
                    'users',
                    $user_id,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                echo json_encode(['success' => true, 'image_path' => '../' . $db_path, 'message' => 'อัปโหลดรูปโปรไฟล์สำเร็จ']);
            } else {
                throw new Exception('ไม่สามารถอัปโหลดไฟล์ได้');
            }
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_GET['ajax'] === 'delete_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $stmt = $pdo->prepare("SELECT profile_image FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $old_image = $stmt->fetchColumn();
            
            if ($old_image && file_exists('../' . $old_image)) {
                unlink('../' . $old_image);
            }
            
            $stmt = $pdo->prepare("UPDATE users SET profile_image = NULL WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            // Log activity
            $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, table_name, record_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id,
                'delete_profile_image',
                'users',
                $user_id,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            echo json_encode(['success' => true, 'message' => 'ลบรูปโปรไฟล์สำเร็จ']);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการลบรูปภาพ']);
        }
        exit;
    }
}

// Get user information
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user statistics
$stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ? AND status IN ('borrowed', 'overdue')");
$stmt->execute([$user_id]);
$current_borrowed = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_borrowed = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'active'");
$stmt->execute([$user_id]);
$active_reservations = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM fines WHERE user_id = ? AND status = 'unpaid'");
$stmt->execute([$user_id]);
$unpaid_fines = $stmt->fetchColumn();

// Get recent activity logs
$stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$user_id]);
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get borrowing statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_borrowed,
        COUNT(CASE WHEN status = 'returned' THEN 1 END) as total_returned,
        COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count,
        COUNT(CASE WHEN status = 'pending_return' THEN 1 END) as pending_return_count
    FROM borrowing 
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Function to format activity action
function formatActivityAction($action) {
    $actions = [
        'login' => 'เข้าสู่ระบบ',
        'logout' => 'ออกจากระบบ',
        'update_profile' => 'อัปเดตโปรไฟล์',
        'change_password' => 'เปลี่ยนรหัสผ่าน',
        'upload_profile_image' => 'อัปโหลดรูปโปรไฟล์',
        'delete_profile_image' => 'ลบรูปโปรไฟล์',
        'reserve_book' => 'จองหนังสือ',
        'return_request' => 'แจ้งคืนหนังสือ'
    ];
    return $actions[$action] ?? $action;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ - ห้องสมุดดิจิทัล</title>
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
            background: var(--primary-gradient);
            color: white;
            padding: 1rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow-medium);
            backdrop-filter: blur(20px);
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
            border-radius: 12px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .nav-links a:hover, 
        .nav-links a.active {
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
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        /* Profile Header */
        .profile-header {
            background: var(--primary-gradient);
            color: white;
            padding: 3rem 2.5rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 2rem;
            box-shadow: var(--shadow-strong);
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
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

        .profile-avatar {
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            flex-shrink: 0;
            position: relative;
            z-index: 2;
            border: 4px solid rgba(255,255,255,0.3);
            cursor: pointer;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .profile-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }

        .profile-img {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            overflow: hidden;
        }

        .profile-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .profile-avatar .edit-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 3;
        }

        .profile-avatar:hover .edit-overlay {
            opacity: 1;
        }

        .edit-overlay i {
            font-size: 1.5rem;
            color: white;
        }

        .profile-info {
            position: relative;
            z-index: 2;
        }

        .profile-info h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .profile-info p {
            opacity: 0.9;
            font-size: 1.1rem;
            margin-bottom: 0.3rem;
        }

        .profile-badges {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .profile-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid rgba(255,255,255,0.3);
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

        .stat-card.borrowed { box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2); }
        .stat-card.returned { box-shadow: 0 8px 25px rgba(40, 167, 69, 0.2); }
        .stat-card.reserved { box-shadow: 0 8px 25px rgba(118, 75, 162, 0.2); }
        .stat-card.fines { box-shadow: 0 8px 25px rgba(220, 53, 69, 0.2); }

        .stat-card i {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
        }

        .stat-card.borrowed i { color: #667eea; }
        .stat-card.returned i { color: #28a745; }
        .stat-card.reserved i { color: #764ba2; }
        .stat-card.fines i { color: #dc3545; }

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

        /* Tab System */
        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            background: #f8f9fa;
            padding: 0.5rem;
            border-radius: 16px;
            overflow-x: auto;
        }

        .tab {
            flex: 1;
            padding: 1rem 2rem;
            background: transparent;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-secondary);
            transition: all 0.3s ease;
            white-space: nowrap;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
        }

        .tab.active {
            background: var(--primary-gradient);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .tab:hover:not(.active) {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Form Styling */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .form-label i {
            color: #667eea;
            width: 16px;
        }

        .form-label.required::after {
            content: '*';
            color: #dc3545;
            margin-left: 0.3rem;
        }

        .form-input, .form-select, .form-textarea {
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

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        .form-textarea {
            resize: vertical;
            min-height: 120px;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            padding: 2rem 1.5rem;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(102, 126, 234, 0.1);
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

        .stat-card.borrowed::before { background: linear-gradient(90deg, #667eea, #764ba2); }
        .stat-card.returned::before { background: linear-gradient(90deg, #4caf50, #45a049); }
        .stat-card.overdue::before { background: linear-gradient(90deg, #f44336, #e53935); }
        .stat-card.pending::before { background: linear-gradient(90deg, #ff9800, #f57c00); }
        .stat-card.reserved::before { background: linear-gradient(90deg, #9c27b0, #8e24aa); }

        .stat-card:hover { 
            transform: translateY(-8px) scale(1.02); 
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }

        .stat-card i { 
            font-size: 3rem; 
            margin-bottom: 1.2rem; 
            display: block;
        }
        .stat-card.borrowed i { color: #667eea; }
        .stat-card.returned i { color: #4caf50; }
        .stat-card.overdue i { color: #f44336; }
        .stat-card.pending i { color: #ff9800; }
        .stat-card.reserved i { color: #9c27b0; }

        .stat-number { 
            font-size: 2.2rem; 
            font-weight: 700; 
            margin-bottom: 0.5rem; 
            color: #333; 
        }
        .stat-label { 
            color: #666; 
            font-size: 0.95rem; 
            font-weight: 500;
        }

        /* Buttons */
        .btn {
            padding: 1.2rem 2rem;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            text-decoration: none;
            justify-content: center;
            min-width: 150px;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: var(--shadow-strong);
        }

        .btn-secondary {
            background: rgba(108, 117, 125, 0.1);
            color: var(--text-secondary);
            border: 2px solid rgba(108, 117, 125, 0.2);
        }

        .btn-secondary:hover {
            background: rgba(108, 117, 125, 0.15);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: var(--danger-gradient);
            color: white;
            box-shadow: 0 6px 18px rgba(220, 53, 69, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.4);
        }

        .btn-warning {
            background: var(--warning-gradient);
            color: white;
            box-shadow: 0 6px 18px rgba(255, 193, 7, 0.3);
        }

        .btn-warning:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 25px rgba(255, 193, 7, 0.4);
        }

        .btn-success {
            background: var(--success-gradient);
            color: white;
            box-shadow: 0 6px 18px rgba(40, 167, 69, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
        }

        /* Modal Styles - Enhanced */
        .modal {
            display: none;
            position: fixed;
            z-index: 10000; /* base z-index */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(12px);
            animation: modalFadeIn 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }
       
        /* Confirm Modal ต้องอยู่ด้านบนสุด */
        #confirmModal {
            z-index: 10001 !important;
        }


        .modal-content {
            background: var(--bg-glass);
            backdrop-filter: blur(25px);
            border-radius: 24px;
            border: 1px solid var(--border-light);
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 92%;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.8) translateY(50px);
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            position: relative;
            z-index: 1; /* relative z-index ภายใน modal */
        }

        .modal.show .modal-content {
            transform: scale(1) translateY(0);
        }

        .modal-header {
            background: var(--primary-gradient);
            color: white;
            padding: 2rem 2.5rem 1.5rem;
            border-radius: 24px 24px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-header h3 {
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .modal-header i {
            font-size: 1.8rem;
            opacity: 0.9;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
            color: white;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1.2rem;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg) scale(1.1);
            border-color: rgba(255, 255, 255, 0.5);
        }

        .modal-body {
            padding: 2.5rem;
        }

        .modal-footer {
            padding: 1.5rem 2.5rem 2.5rem;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            border-top: 1px solid var(--border-light);
            background: rgba(248, 249, 250, 0.5);
            border-radius: 0 0 24px 24px;
            flex-wrap: wrap;
        }

        /* Confirm Modal Specific Styles */
        .confirm-message {
            font-size: 1.2rem;
            line-height: 1.6;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
        }

        .warning-box {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.1), rgba(255, 202, 44, 0.05));
            border: 2px solid rgba(255, 193, 7, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            backdrop-filter: blur(10px);
        }

        .warning-box i {
            color: #ffc107;
            font-size: 1.5rem;
            margin-top: 0.2rem;
            flex-shrink: 0;
        }

        .warning-box .warning-content {
            flex: 1;
        }

        .warning-box h4 {
            color: #856404;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .warning-box p {
            color: var(--text-primary);
            margin: 0;
            font-weight: 500;
        }

        /* Image Modal Specific Styles */
        .image-preview-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 2rem;
        }

        .image-preview {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            border: 4px solid var(--border-medium);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            overflow: hidden;
            position: relative;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-medium);
        }

        .image-preview:hover {
            transform: scale(1.05);
            box-shadow: var(--shadow-strong);
        }

        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .image-preview i {
            font-size: 4rem;
            color: var(--text-muted);
        }

        .image-preview .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.9);
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .image-preview .loading-overlay.show {
            display: flex;
        }

        .upload-area {
            border: 3px dashed var(--border-medium);
            border-radius: 20px;
            padding: 2.5rem;
            text-align: center;
            margin: 1.5rem 0;
            transition: all 0.4s ease;
            cursor: pointer;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.02), rgba(118, 75, 162, 0.02));
            position: relative;
            overflow: hidden;
        }

        .upload-area::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(102, 126, 234, 0.1), transparent);
            transition: left 0.6s ease;
        }

        .upload-area:hover {
            border-color: #667eea;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.08), rgba(118, 75, 162, 0.05));
            transform: translateY(-2px);
        }

        .upload-area:hover::before {
            left: 100%;
        }

        .upload-area.dragover {
            border-color: #28a745;
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.08), rgba(32, 201, 151, 0.05));
            transform: scale(1.02);
        }

        .upload-area i {
            font-size: 3.5rem;
            color: #667eea;
            margin-bottom: 1rem;
            display: block;
        }

        .upload-area .upload-text {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .upload-area .upload-hint {
            font-size: 0.95rem;
            color: var(--text-muted);
            margin: 0;
        }

        .file-input {
            display: none;
        }

        .image-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin: 1.5rem 0;
            flex-wrap: wrap;
        }

        .image-actions .btn {
            min-width: 140px;
            padding: 1rem 1.5rem;
        }

        .upload-progress {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
            display: none;
        }

        .upload-progress.show {
            display: block;
        }

        .upload-progress-bar {
            height: 100%;
            background: var(--primary-gradient);
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 4px;
        }

        /* Activity Log */
        .activity-item {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-light);
            transition: all 0.3s ease;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }

        .activity-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .activity-icon.login { background: rgba(40, 167, 69, 0.1); color: #28a745; }
        .activity-icon.logout { background: rgba(108, 117, 125, 0.1); color: #6c757d; }
        .activity-icon.update_profile { background: rgba(23, 162, 184, 0.1); color: #17a2b8; }
        .activity-icon.change_password { background: rgba(255, 193, 7, 0.1); color: #ffc107; }
        .activity-icon.upload_profile_image { background: rgba(102, 126, 234, 0.1); color: #667eea; }
        .activity-icon.delete_profile_image { background: rgba(220, 53, 69, 0.1); color: #dc3545; }
        .activity-icon.reserve_book { background: rgba(118, 75, 162, 0.1); color: #764ba2; }
        .activity-icon.return_request { background: rgba(102, 126, 234, 0.1); color: #667eea; }

        .activity-content {
            flex: 1;
        }

        .activity-content h4 {
            color: var(--text-primary);
            margin-bottom: 0.3rem;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .activity-content p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin: 0;
        }

        .activity-time {
            font-size: 0.85rem;
            color: var(--text-muted);
            flex-shrink: 0;
        }

        /* Info Box */
        .info-box {
            background: rgba(23, 162, 184, 0.1);
            border: 2px solid rgba(23, 162, 184, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .info-box h4 {
            color: #17a2b8;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .info-box p {
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .info-box p:last-child {
            margin-bottom: 0;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
            display: block;
        }

        .empty-state h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: 1.4rem;
        }

        .empty-state p {
            color: var(--text-secondary);
            font-size: 1rem;
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
            z-index: 99998; /* ต่ำกว่า loading แต่สูงกว่า modal */
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
            z-index: 99999 !important; /* สูงสุด */
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
            margin: 0 auto 1.5rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes modalFadeIn {
            from { 
                opacity: 0;
                backdrop-filter: blur(0px);
            }
            to { 
                opacity: 1;
                backdrop-filter: blur(12px);
            }
        }

        .loading p {
            font-size: 1.1rem;
            font-weight: 500;
        }

        /* Password Strength Indicator */
        .password-strength {
            margin-top: 0.5rem;
            display: none;
        }

        .strength-bar {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .strength-fill.weak { background: #dc3545; width: 25%; }
        .strength-fill.fair { background: #ffc107; width: 50%; }
        .strength-fill.good { background: #fd7e14; width: 75%; }
        .strength-fill.strong { background: #28a745; width: 100%; }

        .strength-text {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .nav-links { display: none; }
            .dashboard-container { 
                padding: 0 1rem; 
                margin-top: 80px; 
            }
            .profile-header {
                flex-direction: column;
                text-align: center;
                padding: 2rem;
            }
            .profile-info h1 { font-size: 2rem; }
            .form-grid { grid-template-columns: 1fr; }
            .quick-stats { grid-template-columns: repeat(2, 1fr); }
            .form-actions { 
                flex-direction: column; 
                align-items: stretch; 
            }
            .tabs { 
                flex-direction: column;
                gap: 0.5rem;
            }
            .tab { 
                padding: 1rem;
                justify-content: flex-start;
            }
            .activity-item {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            .modal-content {
                background: var(--bg-glass);
                backdrop-filter: blur(25px);
                border-radius: 24px;
                border: 1px solid var(--border-light);
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                max-width: 600px;
                width: 92%;
                max-height: 90vh;
                overflow-y: auto;
                transform: scale(0.8) translateY(50px);
                transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
                position: relative;
                z-index: 1; /* relative z-index ภายใน modal */
            }

            .modal-body {
                padding: 1.5rem;
            }
            .modal-footer {
                padding: 1rem 1.5rem 1.5rem;
                flex-direction: column;
            }
            .modal-footer .btn {
                width: 100%;
            }
            .image-actions {
                flex-direction: column;
            }
            .image-actions .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .profile-header { padding: 1.5rem; }
            .profile-info h1 { font-size: 1.8rem; }
            .stat-card { padding: 1.5rem; }
            .card-content { padding: 1.5rem; }
            .quick-stats { grid-template-columns: 1fr; }
            .modal-header {
                padding: 1.5rem;
            }
            .modal-body {
                padding: 1rem;
            }
            .image-preview {
                width: 140px;
                height: 140px;
            }
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

    <!-- Enhanced Confirm Modal -->
    <div class="modal" id="confirmModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="confirmTitle">
                    <i class="fas fa-question-circle"></i>
                    ยืนยันการดำเนินการ
                </h3>
                <button class="modal-close" onclick="closeModal('confirmModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p class="confirm-message" id="confirmMessage">
                    คุณแน่ใจหรือไม่ที่จะดำเนินการนี้?
                </p>
                <div class="warning-box" id="confirmWarning" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div class="warning-content">
                        <h4>โปรดระวัง!</h4>
                        <p id="warningMessage">การดำเนินการนี้ไม่สามารถยกเลิกได้</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('confirmModal')">
                    <i class="fas fa-times"></i>
                    ยกเลิก
                </button>
                <button class="btn btn-primary" id="confirmBtn" onclick="executeConfirmedAction()">
                    <i class="fas fa-check"></i>
                    ยืนยัน
                </button>
            </div>
        </div>
    </div>

    <!-- Enhanced Image Modal -->
    <div class="modal" id="imageModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-camera"></i>
                    จัดการรูปโปรไฟล์
                </h3>
                <button class="modal-close" onclick="closeModal('imageModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="image-preview-container">
                    <div class="image-preview" id="imagePreview">
                        <?php if (!empty($user['profile_image']) && file_exists('../' . $user['profile_image'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile" id="previewImg">
                        <?php else: ?>
                            <i class="fas fa-user" id="previewIcon"></i>
                        <?php endif; ?>
                        <div class="loading-overlay">
                            <div class="spinner" style="width: 40px; height: 40px; border-width: 3px;"></div>
                        </div>
                    </div>
                </div>

                <div class="upload-area" onclick="document.getElementById('profileImageInput').click()" id="uploadArea">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p class="upload-text">คลิกหรือลากไฟล์รูปภาพมาที่นี่</p>
                    <p class="upload-hint">รองรับไฟล์ JPG, PNG, GIF (ไม่เกิน 5MB)</p>
                </div>

                <div class="upload-progress" id="uploadProgress">
                    <div class="upload-progress-bar" id="uploadProgressBar"></div>
                </div>

                <input type="file" id="profileImageInput" class="file-input" accept="image/*">

                <div class="image-actions">
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('profileImageInput').click()">
                        <i class="fas fa-upload"></i>
                        เลือกไฟล์
                    </button>
                    <?php if (!empty($user['profile_image'])): ?>
                    <button type="button" class="btn btn-danger" onclick="confirmDeleteImage()">
                        <i class="fas fa-trash"></i>
                        ลบรูปภาพ
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('imageModal')">
                    <i class="fas fa-times"></i>
                    ปิด
                </button>
                <button class="btn btn-success" id="applyImageBtn" onclick="applyImageChanges()" style="display: none;">
                    <i class="fas fa-check"></i>
                    ตกลง
                </button>
            </div>
        </div>
    </div>

    <!-- Header -->
    <header class="header">
        <nav class="nav-container">
            <div class="logo">
                <i class="fas fa-book-open"></i>
                <span>ห้องสมุดดิจิทัล</span>
            </div>
            <ul class="nav-links">
                <li><a href="index_user.php">หน้าแรก</a></li>
                <li><a href="dashboard.php">แดชบอร์ด</a></li>
                <li><a href="#" class="active">โปรไฟล์</a></li>
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
        <!-- Profile Header -->
        <section class="profile-header">
            <div class="profile-avatar" onclick="openModal('imageModal')">
                <div class="profile-img">
                    <?php if (!empty($user['profile_image']) && file_exists('../' . $user['profile_image'])): ?>
                        <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                </div>
                <div class="edit-overlay">
                    <i class="fas fa-camera"></i>
                </div>
            </div>
            <div class="profile-info">
                <h1><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h1>
                <p><i class="fas fa-id-card"></i> รหัสนักศึกษา: <?php echo htmlspecialchars($user['student_id']); ?></p>
                <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></p>
                <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($user['phone'] ?: 'ไม่ได้ระบุ'); ?></p>
                <div class="profile-badges">
                    <div class="profile-badge">
                        <i class="fas fa-graduation-cap"></i>
                        <?php 
                        $user_types = [
                            'student' => 'นักศึกษา',
                            'teacher' => 'อาจารย์',
                            'staff' => 'บุคลากร'
                        ];
                        echo $user_types[$user['user_type']] ?? 'ไม่ระบุ';
                        ?>
                    </div>
                    <?php if ($user['department']): ?>
                    <div class="profile-badge">
                        <i class="fas fa-building"></i>
                        <?php echo htmlspecialchars($user['department']); ?>
                    </div>
                    <?php endif; ?>
                    <div class="profile-badge">
                        <i class="fas fa-calendar-alt"></i>
                        สมัครเมื่อ <?php echo date('d/m/Y', strtotime($user['registration_date'])); ?>
                    </div>
                </div>
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
                <h4>หน้าหลัก</h4>
                <p>ค้นหาและเรียกดูหนังสือ</p>
            </a>
        </div>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card borrowed">
                <i class="fas fa-book-reader"></i>
                <div class="stat-number"><?php echo $current_borrowed; ?></div>
                <div class="stat-label">กำลังยืมอยู่</div>
            </div>
            <div class="stat-card returned">
                <i class="fas fa-history"></i>
                <div class="stat-number"><?php echo $total_borrowed; ?></div>
                <div class="stat-label">ยืมทั้งหมด</div>
            </div>
            <div class="stat-card reserved">
                <i class="fas fa-bookmark"></i>
                <div class="stat-number"><?php echo $active_reservations; ?></div>
                <div class="stat-label">กำลังจองอยู่</div>
            </div>
            <div class="stat-card overdue">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="stat-number"><?php echo $stats['overdue_count']; ?></div>
                <div class="stat-label">เกินกำหนด</div>
            </div>
        </div>

        <!-- Profile Management Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-user-cog"></i>
                <h3>จัดการโปรไฟล์</h3>
            </div>
            <div class="card-content">
                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab active" onclick="showTab('profile-tab')">
                        <i class="fas fa-user"></i>
                        <span>ข้อมูลส่วนตัว</span>
                    </button>
                    <button class="tab" onclick="showTab('password-tab')">
                        <i class="fas fa-lock"></i>
                        <span>เปลี่ยนรหัสผ่าน</span>
                    </button>
                    <button class="tab" onclick="showTab('activity-tab')">
                        <i class="fas fa-history"></i>
                        <span>ประวัติการใช้งาน</span>
                    </button>
                </div>

                <!-- Profile Tab -->
                <div id="profile-tab" class="tab-content active">
                    <form method="POST" id="profileForm">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label required">
                                    <i class="fas fa-user"></i>
                                    ชื่อ
                                </label>
                                <input type="text" name="first_name" class="form-input" 
                                       value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">
                                    <i class="fas fa-user"></i>
                                    นามสกุล
                                </label>
                                <input type="text" name="last_name" class="form-input" 
                                       value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">
                                    <i class="fas fa-envelope"></i>
                                    อีเมล
                                </label>
                                <input type="email" name="email" class="form-input" 
                                       value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-phone"></i>
                                    เบอร์โทรศัพท์
                                </label>
                                <input type="tel" name="phone" class="form-input" 
                                       value="<?php echo htmlspecialchars($user['phone']); ?>" 
                                       pattern="[0-9]{10}" placeholder="0812345678">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-building"></i>
                                    แผนก/สาขา
                                </label>
                                <input type="text" name="department" class="form-input" 
                                       value="<?php echo htmlspecialchars($user['department']); ?>" 
                                       placeholder="เช่น วิศวกรรมคอมพิวเตอร์">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-graduation-cap"></i>
                                    ระดับชั้น/ปีการศึกษา
                                </label>
                                <input type="text" name="class_level" class="form-input" 
                                       value="<?php echo htmlspecialchars($user['class_level']); ?>" 
                                       placeholder="เช่น ปวช.3, ปวส.2">
                            </div>
                            
                            <div class="form-group full-width">
                                <label class="form-label">
                                    <i class="fas fa-map-marker-alt"></i>
                                    ที่อยู่
                                </label>
                                <textarea name="address" class="form-textarea" rows="3" 
                                          placeholder="ที่อยู่ปัจจุบัน"><?php echo htmlspecialchars($user['address']); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetProfileForm()">
                                <i class="fas fa-undo"></i>
                                รีเซ็ต
                            </button>
                            <button type="button" class="btn btn-primary" onclick="confirmUpdateProfile()">
                                <i class="fas fa-save"></i>
                                บันทึกการเปลี่ยนแปลง
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Password Tab -->
                <div id="password-tab" class="tab-content">
                    <form method="POST" id="passwordForm">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="form-label required">
                                    <i class="fas fa-lock"></i>
                                    รหัสผ่านปัจจุบัน
                                </label>
                                <input type="password" name="current_password" class="form-input" 
                                       id="current-password" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">
                                    <i class="fas fa-key"></i>
                                    รหัสผ่านใหม่
                                </label>
                                <input type="password" name="new_password" class="form-input" 
                                       id="new-password" minlength="6" required 
                                       onkeyup="checkPasswordStrength(this.value)">
                                <div class="password-strength" id="password-strength">
                                    <div class="strength-bar">
                                        <div class="strength-fill" id="strength-fill"></div>
                                    </div>
                                    <div class="strength-text" id="strength-text">กรุณากรอกรหัสผ่านใหม่</div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">
                                    <i class="fas fa-check"></i>
                                    ยืนยันรหัสผ่านใหม่
                                </label>
                                <input type="password" name="confirm_password" class="form-input" 
                                       id="confirm-password" minlength="6" required>
                            </div>
                        </div>
                        
                        <div class="info-box">
                            <h4><i class="fas fa-info-circle"></i> ข้อกำหนดรหัสผ่าน</h4>
                            <p>รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร</p>
                            <p>ควรผสมตัวอักษรใหญ่ เล็ก ตัวเลข และอักขระพิเศษ</p>
                            <p>หลีกเลี่ยงการใช้ข้อมูลส่วนตัว เช่น ชื่อ วันเกิด</p>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetPasswordForm()">
                                <i class="fas fa-times"></i>
                                ยกเลิก
                            </button>
                            <button type="button" class="btn btn-danger" onclick="confirmChangePassword()">
                                <i class="fas fa-key"></i>
                                เปลี่ยนรหัสผ่าน
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Activity Tab -->
                <div id="activity-tab" class="tab-content">
                    <?php if (empty($recent_activities)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h4>ไม่มีประวัติการใช้งาน</h4>
                            <p>เมื่อคุณใช้งานระบบ ประวัติการใช้งานจะปรากฏที่นี่</p>
                        </div>
                    <?php else: ?>
                        <div class="activity-log">
                            <?php foreach ($recent_activities as $activity): 
                                $action_class = str_replace(['_', ' '], ['_', '_'], $activity['action']);
                            ?>
                                <div class="activity-item">
                                    <div class="activity-icon <?php echo $action_class; ?>">
                                        <i class="fas <?php 
                                            switch($activity['action']) {
                                                case 'login': echo 'fa-sign-in-alt'; break;
                                                case 'logout': echo 'fa-sign-out-alt'; break;
                                                case 'update_profile': echo 'fa-user-edit'; break;
                                                case 'change_password': echo 'fa-key'; break;
                                                case 'upload_profile_image': echo 'fa-camera'; break;
                                                case 'delete_profile_image': echo 'fa-trash'; break;
                                                case 'reserve_book': echo 'fa-bookmark'; break;
                                                case 'return_request': echo 'fa-undo'; break;
                                                default: echo 'fa-cog';
                                            }
                                        ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <h4><?php echo formatActivityAction($activity['action']); ?></h4>
                                        <p>IP: <?php echo htmlspecialchars($activity['ip_address']); ?></p>
                                    </div>
                                    <div class="activity-time">
                                        <?php echo date('d/m/Y H:i', strtotime($activity['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let pendingAction = null;
        let hasImageChanges = false;
        let currentImageUrl = null;

        // Tab System
        function showTab(tabId) {
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));
            
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            document.getElementById(tabId).classList.add('active');
            event.target.closest('.tab').classList.add('active');
        }

        // Enhanced Modal System
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                // ปิด modal อื่นๆ ก่อน (ยกเว้น loading)
                const otherModals = document.querySelectorAll('.modal:not(#loadingOverlay)');
                otherModals.forEach(otherModal => {
                    if (otherModal.id !== modalId && otherModal.classList.contains('show')) {
                        otherModal.classList.remove('show');
                        setTimeout(() => {
                            otherModal.style.display = 'none';
                        }, 300);
                    }
                });
                
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                
                // กำหนด z-index ให้เหมาะสม
                if (modalId === 'confirmModal') {
                    modal.style.zIndex = '10001'; // สูงกว่า modal อื่น
                } else {
                    modal.style.zIndex = '10000';
                }
                
                // Add show class for animation
                setTimeout(() => modal.classList.add('show'), 10);
                
                // Reset image modal state
                if (modalId === 'imageModal') {
                    hasImageChanges = false;
                    updateApplyButton();
                }
            }
        }


        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('show');
                setTimeout(() => {
                    modal.style.display = 'none';
                    
                    // คืนค่า z-index
                    modal.style.zIndex = '';
                    
                    // ตรวจสอบว่ายังมี modal เปิดอยู่ไหม
                    const openModals = document.querySelectorAll('.modal.show');
                    if (openModals.length === 0) {
                        document.body.style.overflow = '';
                    }
                }, 300);
            }
        }


        // Enhanced Confirmation System
        function showConfirmationModal(title, message, onConfirm, options = {}) {
            const {
                type = 'primary',
                showWarning = false,
                warningMessage = 'การดำเนินการนี้ไม่สามารถยกเลิกได้'
            } = options;

            document.getElementById('confirmTitle').innerHTML = `<i class="fas fa-question-circle"></i> ${title}`;
            document.getElementById('confirmMessage').textContent = message;
            
            const confirmBtn = document.getElementById('confirmBtn');
            confirmBtn.className = `btn btn-${type}`;
            
            const warningBox = document.getElementById('confirmWarning');
            const warningMsg = document.getElementById('warningMessage');
            
            if (showWarning) {
                warningMsg.textContent = warningMessage;
                warningBox.style.display = 'flex';
            } else {
                warningBox.style.display = 'none';
            }
            
            pendingAction = onConfirm;
            openModal('confirmModal');
        }


        function executeConfirmedAction() {
            closeModal('confirmModal');
            
            // รอให้ modal ปิดเสร็จก่อนทำงาน
            setTimeout(() => {
                if (pendingAction) {
                    pendingAction();
                    pendingAction = null;
                }
            }, 300);
        }

        // Profile Management Functions
        function confirmUpdateProfile() {
            const form = document.getElementById('profileForm');
            if (!validateProfileForm(form)) return;

            showConfirmationModal(
                'ยืนยันการอัปเดตข้อมูล',
                'คุณต้องการบันทึกการเปลี่ยนแปลงข้อมูลส่วนตัวหรือไม่?',
                () => {
                    showLoading('กำลังอัปเดตข้อมูล...');
                    form.submit();
                }
            );
        }

        function confirmChangePassword() {
            const form = document.getElementById('passwordForm');
            if (!validatePasswordForm()) return;

            showConfirmationModal(
                'ยืนยันการเปลี่ยนรหัสผ่าน',
                'คุณต้องการเปลี่ยนรหัสผ่านหรือไม่?',
                () => {
                    showLoading('กำลังเปลี่ยนรหัสผ่าน...');
                    form.submit();
                },
                {
                    type: 'danger',
                    showWarning: true,
                    warningMessage: 'คุณจะต้องเข้าสู่ระบบใหม่หลังจากเปลี่ยนรหัสผ่าน'
                }
            );
        }

        function confirmDeleteImage() {
            showConfirmationModal(
                'ยืนยันการลบรูปโปรไฟล์',
                'คุณต้องการลบรูปโปรไฟล์ปัจจุบันหรือไม่?',
                () => {
                    deleteProfileImage();
                },
                {
                    type: 'danger',
                    showWarning: true,
                    warningMessage: 'รูปโปรไฟล์จะถูกลบถาวรและไม่สามารถกู้คืนได้'
                }
            );
        }



        // Enhanced Image Upload System
        function setupImageUpload() {
            const input = document.getElementById('profileImageInput');
            const uploadArea = document.getElementById('uploadArea');
            const preview = document.getElementById('imagePreview');

            // File input change event
            input.addEventListener('change', function(e) {
                if (e.target.files && e.target.files[0]) {
                    const file = e.target.files[0];
                    
                    if (!validateImageFile(file)) return;
                    
                    // Preview image
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        updateImagePreview(e.target.result);
                        hasImageChanges = true;
                        updateApplyButton();
                    };
                    reader.readAsDataURL(file);
                    
                    // Upload via AJAX
                    uploadImageAjax(file);
                }
            });

            // Enhanced Drag and Drop
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                uploadArea.addEventListener(eventName, preventDefaults, false);
                document.body.addEventListener(eventName, preventDefaults, false);
            });

            ['dragenter', 'dragover'].forEach(eventName => {
                uploadArea.addEventListener(eventName, () => uploadArea.classList.add('dragover'), false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                uploadArea.addEventListener(eventName, () => uploadArea.classList.remove('dragover'), false);
            });

            uploadArea.addEventListener('drop', function(e) {
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    input.files = files;
                    input.dispatchEvent(new Event('change'));
                }
            });
        }

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        function validateImageFile(file) {
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            
            if (!allowedTypes.includes(file.type)) {
                showNotification('รูปแบบไฟล์ไม่ถูกต้อง (รองรับเฉพาะ JPG, PNG, GIF)', 'error');
                return false;
            }
            
            if (file.size > 5 * 1024 * 1024) { // 5MB
                showNotification('ไฟล์รูปภาพต้องมีขนาดไม่เกิน 5MB', 'error');
                return false;
            }
            
            return true;
        }

        // แก้ไขฟังก์ชัน uploadImageAjax ให้ไม่แจ้งเตือนทันที
        function uploadImageAjax(file) {
            const formData = new FormData();
            formData.append('profile_image', file);
            
            const loadingOverlay = document.querySelector('.loading-overlay');
            const progressBar = document.getElementById('uploadProgress');
            const progressFill = document.getElementById('uploadProgressBar');
            
            // Show loading states
            loadingOverlay.classList.add('show');
            progressBar.classList.add('show');
            
            const xhr = new XMLHttpRequest();
            
            // Progress tracking
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    progressFill.style.width = percentComplete + '%';
                }
            });
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === XMLHttpRequest.DONE) {
                    loadingOverlay.classList.remove('show');
                    progressBar.classList.remove('show');
                    progressFill.style.width = '0%';
                    
                    try {
                        const response = JSON.parse(xhr.responseText);
                        
                        if (response.success) {
                            currentImageUrl = response.image_path;
                            hasImageChanges = true;
                            updateApplyButton();
                            
                            // แสดงปุ่มลบรูปภาพเมื่ออัปโหลดสำเร็จ
                            const deleteBtn = document.querySelector('button[onclick="confirmDeleteImage()"]');
                            if (deleteBtn) {
                                deleteBtn.style.display = 'flex';
                            }
                            
                        } else {
                            showNotification(response.message, 'error');
                            resetImagePreview();
                        }
                    } catch (e) {
                        showNotification('เกิดข้อผิดพลาดในการอัปโหลด', 'error');
                        resetImagePreview();
                    }
                }
            };
            
            xhr.open('POST', '?ajax=upload_image', true);
            xhr.send(formData);
        }





        // แก้ไขฟังก์ชัน deleteProfileImage ให้ไม่แจ้งเตือนทันที
        function deleteProfileImage() {
            showLoading('กำลังลบรูปภาพ...');
            
            fetch('?ajax=delete_image', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    // แจ้งเตือนทันทีเมื่อลบสำเร็จ
                    showNotification(data.message || 'ลบรูปโปรไฟล์สำเร็จ', 'success');
                    
                    // Update preview to show default icon
                    const preview = document.getElementById('imagePreview');
                    const existingImg = preview.querySelector('img');
                    const existingIcon = preview.querySelector('i');
                    
                    if (existingImg) existingImg.remove();
                    if (!existingIcon) {
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-user';
                        icon.id = 'previewIcon';
                        preview.appendChild(icon);
                    }
                    
                    // อัปเดต profile avatar ในหน้าหลักด้วย
                    const profileImg = document.querySelector('.profile-avatar .profile-img');
                    if (profileImg) {
                        profileImg.innerHTML = '<i class="fas fa-user"></i>';
                    }
                    
                    hasImageChanges = true;
                    updateApplyButton();
                    
                    // ซ่อนปุ่มลบรูปภาพ
                    const deleteBtn = document.querySelector('button[onclick="confirmDeleteImage()"]');
                    if (deleteBtn) {
                        deleteBtn.style.display = 'none';
                    }
                    
                } else {
                    showNotification(data.message || 'เกิดข้อผิดพลาดในการลบรูปภาพ', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Delete image error:', error);
                showNotification('เกิดข้อผิดพลาดในการลบรูปภาพ', 'error');
            });
        }



        // แก้ไขฟังก์ชัน applyImageChanges ให้รีเฟรชและแจ้งเตือน
        function applyImageChanges() {
            if (hasImageChanges) {
                // เก็บข้อความแจ้งเตือนก่อนรีเฟรช
                sessionStorage.setItem('uploadMessage', 'บันทึกการเปลี่ยนแปลงรูปโปรไฟล์สำเร็จ');
                sessionStorage.setItem('uploadMessageType', 'success');
                
                closeModal('imageModal');
                showLoading('กำลังบันทึกการเปลี่ยนแปลง...');
                
                // รีเฟรชหน้าเว็บ
                setTimeout(() => {
                    location.reload();
                }, 800);
            }
        }


        // เพิ่มฟังก์ชันตรวจสอบข้อความแจ้งเตือนหลังรีเฟรช
        function checkStoredNotification() {
            const message = sessionStorage.getItem('uploadMessage');
            const messageType = sessionStorage.getItem('uploadMessageType');
            
            if (message && messageType) {
                // ลบข้อความออกจาก sessionStorage ทันที
                sessionStorage.removeItem('uploadMessage');
                sessionStorage.removeItem('uploadMessageType');
                
                // แจ้งเตือนหลังจากหน้าเว็บโหลดเสร็จ
                setTimeout(() => {
                    showNotification(message, messageType);
                }, 1000);
            }
        }



        function updateImagePreview(imageSrc) {
            const preview = document.getElementById('imagePreview');
            const existingImg = preview.querySelector('img');
            const existingIcon = preview.querySelector('i');
            
            if (existingImg) {
                existingImg.src = imageSrc;
            } else {
                if (existingIcon) existingIcon.remove();
                const img = document.createElement('img');
                img.src = imageSrc;
                img.id = 'previewImg';
                preview.appendChild(img);
            }
        }

        function resetImagePreview() {
            // Reset to original state
            location.reload();
        }

        function updateApplyButton() {
            const applyBtn = document.getElementById('applyImageBtn');
            const deleteBtn = document.querySelector('button[onclick="confirmDeleteImage()"]');
            
            if (hasImageChanges) {
                applyBtn.style.display = 'flex';
                
                // ตรวจสอบว่ามีรูปภาพหรือไม่เพื่อแสดง/ซ่อนปุ่มลบ
                const previewImg = document.getElementById('previewImg');
                if (previewImg && deleteBtn) {
                    deleteBtn.style.display = 'flex';
                } else if (deleteBtn) {
                    deleteBtn.style.display = 'none';
                }
            } else {
                applyBtn.style.display = 'none';
            }
        }


        function applyImageChanges() {
            if (hasImageChanges) {
                // เก็บข้อความแจ้งเตือนก่อนรีเฟรช
                sessionStorage.setItem('uploadMessage', 'บันทึกการเปลี่ยนแปลงรูปโปรไฟล์สำเร็จ');
                sessionStorage.setItem('uploadMessageType', 'success');
                
                closeModal('imageModal');
                showLoading('กำลังบันทึกการเปลี่ยนแปลง...');
                
                // รีเฟรชหน้าเว็บ
                setTimeout(() => {
                    location.reload();
                }, 800);
            } else {
                // ถ้าไม่มีการเปลี่ยนแปลง แค่ปิด modal
                closeModal('imageModal');
            }
        }

        // Form Validation
        function validateProfileForm(form) {
            const email = form.querySelector('input[name="email"]').value;
            const phone = form.querySelector('input[name="phone"]').value;
            
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showNotification('รูปแบบอีเมลไม่ถูกต้อง', 'error');
                return false;
            }
            
            if (phone && !/^[0-9]{10}$/.test(phone)) {
                showNotification('เบอร์โทรศัพท์ต้องเป็นตัวเลข 10 หลัก', 'error');
                return false;
            }
            
            return true;
        }

        function validatePasswordForm() {
            const currentPassword = document.getElementById('current-password').value;
            const newPassword = document.getElementById('new-password').value;
            const confirmPassword = document.getElementById('confirm-password').value;
            
            if (newPassword !== confirmPassword) {
                showNotification('รหัสผ่านใหม่และยืนยันรหัสผ่านไม่ตรงกัน', 'error');
                return false;
            }
            
            if (newPassword.length < 6) {
                showNotification('รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษร', 'error');
                return false;
            }
            
            return true;
        }

        // Password Strength Checker
        function checkPasswordStrength(password) {
            const strengthElement = document.getElementById('password-strength');
            const fillElement = document.getElementById('strength-fill');
            const textElement = document.getElementById('strength-text');
            
            if (password.length === 0) {
                strengthElement.style.display = 'none';
                return;
            }
            
            strengthElement.style.display = 'block';
            
            let strength = 0;
            let feedback = [];
            
            if (password.length >= 6) strength += 1;
            else feedback.push('ต้องมีอย่างน้อย 6 ตัวอักษร');
            
            if (/[a-z]/.test(password)) strength += 1;
            else feedback.push('ต้องมีตัวอักษรเล็ก');
            
            if (/[A-Z]/.test(password)) strength += 1;
            else feedback.push('ควรมีตัวอักษรใหญ่');
            
            if (/[0-9]/.test(password)) strength += 1;
            else feedback.push('ควรมีตัวเลข');
            
            if (/[^A-Za-z0-9]/.test(password)) strength += 1;
            else feedback.push('ควรมีอักขระพิเศษ');
            
            fillElement.className = 'strength-fill';
            
            if (strength <= 2) {
                fillElement.classList.add('weak');
                textElement.textContent = 'รหัสผ่านอ่อน: ' + feedback.slice(0, 2).join(', ');
            } else if (strength <= 3) {
                fillElement.classList.add('fair');
                textElement.textContent = 'รหัสผ่านปานกลาง: ' + feedback.slice(0, 1).join(', ');
            } else if (strength <= 4) {
                fillElement.classList.add('good');
                textElement.textContent = 'รหัสผ่านดี';
            } else {
                fillElement.classList.add('strong');
                textElement.textContent = 'รหัสผ่านแข็งแรง';
            }
        }

        // Form Reset Functions
        function resetProfileForm() {
            showConfirmationModal(
                'ยืนยันการรีเซ็ตข้อมูล',
                'คุณต้องการรีเซ็ตข้อมูลกลับเป็นค่าเดิมหรือไม่?',
                () => location.reload()
            );
        }

        function resetPasswordForm() {
            document.getElementById('current-password').value = '';
            document.getElementById('new-password').value = '';
            document.getElementById('confirm-password').value = '';
            document.getElementById('password-strength').style.display = 'none';
        }

        // Notification System
        function showNotification(message, type = 'success', duration = 6000) {
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
            
            notification.innerHTML = `<i class="${icon}"></i><span>${message}</span>`;
            document.body.appendChild(notification);

            setTimeout(() => notification.classList.add('show'), 100);
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 400);
            }, duration);
        }

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

        // Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            setupImageUpload();
            
            // ตรวจสอบข้อความแจ้งเตือนที่เก็บไว้
            checkStoredNotification();
            
            // Real-time password confirmation check
            const confirmPasswordField = document.getElementById('confirm-password');
            const newPasswordField = document.getElementById('new-password');
            
            if (confirmPasswordField && newPasswordField) {
                confirmPasswordField.addEventListener('input', function() {
                    const newPassword = newPasswordField.value;
                    const confirmPassword = this.value;
                    
                    if (confirmPassword && newPassword !== confirmPassword) {
                        this.style.borderColor = '#dc3545';
                        this.style.boxShadow = '0 0 0 4px rgba(220, 53, 69, 0.1)';
                    } else if (confirmPassword) {
                        this.style.borderColor = '#28a745';
                        this.style.boxShadow = '0 0 0 4px rgba(40, 167, 69, 0.1)';
                    } else {
                        this.style.borderColor = '';
                        this.style.boxShadow = '';
                    }
                });
            }

            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    if (event.target === modal) {
                        closeModal(modal.id);
                    }
                });
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    // ปิด modal ที่มี z-index สูงสุดก่อน
                    const openModals = Array.from(document.querySelectorAll('.modal.show'));
                    if (openModals.length > 0) {
                        // เรียงตาม z-index
                        openModals.sort((a, b) => {
                            const aIndex = parseInt(a.style.zIndex) || 10000;
                            const bIndex = parseInt(b.style.zIndex) || 10000;
                            return bIndex - aIndex;
                        });
                        closeModal(openModals[0].id);
                    }
                }
            });
        });

        // แสดงข้อความแจ้งเตือนจาก PHP เฉพาะที่ไม่เกี่ยวกับรูปภาพ
        // และไม่ใช่จาก AJAX request
        window.addEventListener('DOMContentLoaded', function() {
            <?php if ($message && !isset($_GET['ajax']) && !strpos($message, 'รูป')): ?>
                const message = '<?php echo addslashes($message); ?>';
                const messageType = '<?php echo $message_type; ?>';
                
                setTimeout(() => {
                    showNotification(message, messageType);
                }, 500);
            <?php endif; ?>
            
            hideLoading();
        });

        console.log('🚀 Fixed Profile Image Upload System Ready!');
    </script>
</body>
</html>