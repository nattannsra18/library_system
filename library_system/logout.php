<?php
// logout.php - ประมวลผลการออกจากระบบ
require_once 'db.php';

start_session();

// ตรวจสอบว่าผู้ใช้หรือแอดมินล็อกอินอยู่หรือไม่
if (!is_logged_in() && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

try {
    // บันทึก activity log ก่อนทำการ logout
    $user_id = $_SESSION['user_id'] ?? null;
    $admin_id = $_SESSION['admin_id'] ?? null;
    $log_stmt = null;

    if ($user_id) {
        $log_stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, ip_address, user_agent, created_at)
            VALUES (?, 'logout', ?, ?, NOW())
        ");
        $log_stmt->execute([$user_id, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
    } elseif ($admin_id) {
        $log_stmt = $db->prepare("
            INSERT INTO activity_logs (admin_id, action, ip_address, user_agent, created_at)
            VALUES (?, 'logout', ?, ?, NOW())
        ");
        $log_stmt->execute([$admin_id, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
    }
} catch (PDOException $e) {
    // บันทึก error ลง log เฉพาะในกรณีล้มเหลว โดยไม่รบกวนการ logout
    error_log("Logout logging error: " . $e->getMessage());
}

// ลบข้อมูลทั้งหมดใน session
$_SESSION = array();

// ลบ session cookie โดยใช้การตั้งค่า secure และ httponly ตามสภาพแวดล้อม
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // ใช้ secure ถ้าเป็น HTTPS
        true // httponly
    );
}

// ทำลาย session
session_destroy();

// เปลี่ยนเส้นทางกลับไปหน้า index.php พร้อมข้อความแจ้ง
header("Location: index.php?success=logout");
exit();
?>