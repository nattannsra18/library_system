<?php
// login_process.php - ประมวลผลการเข้าสู่ระบบ
require_once 'db.php';

start_session();

// ตรวจสอบว่ามีการส่งข้อมูลแบบ POST หรือไม่
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: login.php");
    exit();
}

// รับข้อมูลจากฟอร์ม
$username = clean_input($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$user_type = $_POST['user_type'] ?? 'user';

// ตรวจสอบว่ากรอกข้อมูลครบหรือไม่
if (empty($username) || empty($password)) {
    header("Location: login.php?error=required");
    exit();
}

try {
    if ($user_type === 'admin') {
        // ตรวจสอบการเข้าสู่ระบบของแอดมิน
        $stmt = $db->prepare("
            SELECT admin_id, username, first_name, last_name, email, password, role, status 
            FROM admins 
            WHERE username = ? OR email = ?
        ");
        $stmt->execute([$username, $username]);
        $admin = $stmt->fetch();
        
        if ($admin && verify_password($password, $admin['password'])) {
            if ($admin['status'] === 'active') {
                // บันทึกข้อมูลลง session
                $_SESSION['admin_id'] = $admin['admin_id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_name'] = $admin['first_name'] . ' ' . $admin['last_name'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_role'] = $admin['role'];
                $_SESSION['user_type'] = 'admin';
                
                // อัพเดทเวลาเข้าสู่ระบบล่าสุด
                $update_stmt = $db->prepare("UPDATE admins SET last_login = NOW() WHERE admin_id = ?");
                $update_stmt->execute([$admin['admin_id']]);
                
                // บันทึก activity log
                $log_stmt = $db->prepare("
                    INSERT INTO activity_logs (admin_id, action, ip_address, user_agent) 
                    VALUES (?, 'login', ?, ?)
                ");
                $log_stmt->execute([
                    $admin['admin_id'], 
                    $_SERVER['REMOTE_ADDR'] ?? '', 
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                // เปลี่ยนเส้นทางไปหน้าแอดมิน
                header("Location: admin/dashboard.php");
                exit();
            } else {
                header("Location: login.php?error=inactive");
                exit();
            }
        } else {
            header("Location: login.php?error=invalid");
            exit();
        }
        
    } else {
        // ตรวจสอบการเข้าสู่ระบบของผู้ใช้ทั่วไป
        $stmt = $db->prepare("
            SELECT user_id, student_id, first_name, last_name, email, password, user_type, status, department, class_level
            FROM users 
            WHERE student_id = ? OR email = ?
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && verify_password($password, $user['password'])) {
            if ($user['status'] === 'active') {
                // บันทึกข้อมูลลง session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['student_id'] = $user['student_id'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_type_detail'] = $user['user_type'];
                $_SESSION['user_department'] = $user['department'];
                $_SESSION['user_class'] = $user['class_level'];
                $_SESSION['user_type'] = 'user';
                
                // อัพเดทเวลาเข้าสู่ระบบล่าสุด
                $update_stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                $update_stmt->execute([$user['user_id']]);
                
                // บันทึก activity log
                $log_stmt = $db->prepare("
                    INSERT INTO activity_logs (user_id, action, ip_address, user_agent) 
                    VALUES (?, 'login', ?, ?)
                ");
                $log_stmt->execute([
                    $user['user_id'], 
                    $_SERVER['REMOTE_ADDR'] ?? '', 
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                // เปลี่ยนเส้นทางไปหน้าผู้ใช้
                header("Location: user/index_user.php");
                exit();
            } else {
                header("Location: login.php?error=inactive");
                exit();
            }
        } else {
            header("Location: login.php?error=invalid");
            exit();
        }
    }
    
} catch (PDOException $e) {
    // บันทึก error log
    error_log("Login error: " . $e->getMessage());
    header("Location: login.php?error=system");
    exit();
}
?>