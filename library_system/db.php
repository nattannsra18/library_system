<?php
// db.php - ไฟล์เชื่อมต่อฐานข้อมูล
session_start(); // เริ่มต้น session ด้านบนสุด

class Database {
    private $host = "localhost";
    private $db_name = "library_system";
    private $username = "root";  // เปลี่ยนตามการตั้งค่าของคุณ
    private $password = "";      // เปลี่ยนตามการตั้งค่าของคุณ
    public $conn;

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4", 
                $this->username, 
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        
        return $this->conn;
    }
}

// สร้าง instance ของ Database
$database = new Database();
$db = $database->getConnection();

// ฟังก์ชันสำหรับป้องกัน SQL Injection และ XSS
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// ฟังก์ชันสำหรับสร้าง hash password
function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// ฟังก์ชันสำหรับตรวจสอบ password
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// ฟังก์ชันสำหรับเริ่มต้น session (ไม่จำเป็นต้องใช้แล้ว)
function start_session() {
    // ฟังก์ชันนี้สามารถลบได้ หรือเก็บไว้สำหรับกรณีพิเศษ
}

// ฟังก์ชันตรวจสอบการล็อกอิน
function is_logged_in() {
    return isset($_SESSION['user_id']) || isset($_SESSION['admin_id']);
}

// ฟังก์ชันตรวจสอบสิทธิ์ผู้ใช้
function is_user() {
    return isset($_SESSION['user_id']);
}

// ฟังก์ชันตรวจสอบสิทธิ์แอดมิน
function is_admin() {
    return isset($_SESSION['admin_id']);
}

// ฟังก์ชันเปลี่ยนเส้นทางหากไม่ได้ล็อกอิน
function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit();
    }
}

// ฟังก์ชันเปลี่ยนเส้นทางหากไม่ใช่แอดมิน
function require_admin() {
    if (!is_admin()) {
        header("Location: login.php");
        exit();
    }
}