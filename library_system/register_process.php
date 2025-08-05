<?php
require_once 'db.php';

// เริ่มต้น session
start_session();

// ตรวจสอบว่าเป็นการส่งข้อมูลแบบ POST หรือไม่
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: register.php");
    exit();
}

// รับข้อมูลจากฟอร์มและทำความสะอาด
$student_id = clean_input($_POST['student_id'] ?? '');
$email = clean_input($_POST['email'] ?? '');
$first_name = clean_input($_POST['first_name'] ?? '');
$last_name = clean_input($_POST['last_name'] ?? '');
$phone = clean_input($_POST['phone'] ?? '');
$user_type = clean_input($_POST['user_type'] ?? '');
$department = clean_input($_POST['department'] ?? '');
$class_level = clean_input($_POST['class_level'] ?? '');
$address = clean_input($_POST['address'] ?? '');
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

// ตัวแปรสำหรับเก็บข้อผิดพลาด
$errors = [];

// ตรวจสอบข้อมูลที่จำเป็น
if (empty($student_id)) {
    $errors[] = 'กรุณากรอกรหัสนักเรียน';
}

if (empty($email)) {
    $errors[] = 'กรุณากรอกอีเมล';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
}

if (empty($first_name)) {
    $errors[] = 'กรุณากรอกชื่อ';
}

if (empty($last_name)) {
    $errors[] = 'กรุณากรอกนามสกุล';
}

if (empty($user_type) || !in_array($user_type, ['student', 'teacher', 'staff'])) {
    $errors[] = 'กรุณาเลือกประเภทผู้ใช้ที่ถูกต้อง';
}

if (empty($password)) {
    $errors[] = 'กรุณากรอกรหัสผ่าน';
} elseif (strlen($password) < 6) {
    $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
}

if (empty($confirm_password)) {
    $errors[] = 'กรุณายืนยันรหัสผ่าน';
} elseif ($password !== $confirm_password) {
    $errors[] = 'รหัสผ่านไม่ตรงกัน';
}

// ตรวจสอบรูปแบบรหัสนักเรียน
if (!empty($student_id) && !preg_match('/^[a-zA-Z0-9]+$/', $student_id)) {
    $errors[] = 'รหัสนักเรียนต้องเป็นตัวอักษรและตัวเลขเท่านั้น';
}

// ตรวจสอบรูปแบบเบอร์โทรศัพท์ (ถ้ามี)
if (!empty($phone) && !preg_match('/^[0-9\-\+\(\)\.\s]+$/', $phone)) {
    $errors[] = 'รูปแบบเบอร์โทรศัพท์ไม่ถูกต้อง';
}

// ตรวจสอบว่ารหัสนักเรียนและอีเมลซ้ำหรือไม่
if (empty($errors)) {
    try {
        // ตรวจสอบรหัสนักเรียนซ้ำ
        $stmt = $db->prepare("SELECT user_id FROM users WHERE student_id = ?");
        $stmt->execute([$student_id]);
        if ($stmt->fetch()) {
            $errors[] = 'รหัสนักเรียนนี้มีอยู่ในระบบแล้ว';
        }

        // ตรวจสอบอีเมลซ้ำ
        $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'อีเมลนี้มีอยู่ในระบบแล้ว';
        }
    } catch (PDOException $e) {
        $errors[] = 'เกิดข้อผิดพลาดในการตรวจสอบข้อมูル';
        error_log("Database error in registration check: " . $e->getMessage());
    }
}

// จัดการการอัปโหลดรูปโปรไฟล์
$profile_image = null;
if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    $file = $_FILES['profile_image'];
    $file_type = $file['type'];
    $file_size = $file['size'];
    $file_tmp = $file['tmp_name'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // ตรวจสอบประเภทไฟล์
    if (!in_array($file_type, $allowed_types)) {
        $errors[] = 'รองรับเฉพาะไฟล์รูปภาพ (JPG, PNG, GIF)';
    }
    
    // ตรวจสอบขนาดไฟล์
    if ($file_size > $max_size) {
        $errors[] = 'ขนาดไฟล์รูปภาพต้องไม่เกิน 5MB';
    }
    
    // ตรวจสอบว่าเป็นไฟล์รูปภาพจริงหรือไม่
    $image_info = getimagesize($file_tmp);
    if ($image_info === false) {
        $errors[] = 'ไฟล์ที่อัปโหลดไม่ใช่รูปภาพ';
    }
    
    // ถ้าไม่มีข้อผิดพลาด ให้อัปโหลดไฟล์
    if (empty($errors)) {
        $upload_dir = 'uploads/profiles/';
        
        // สร้างโฟลเดอร์ถ้ายังไม่มี
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // สร้างชื่อไฟล์ใหม่เพื่อป้องกันการซ้ำ
        $new_filename = uniqid() . '_' . time() . '.' . $file_ext;
        $upload_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($file_tmp, $upload_path)) {
            $profile_image = $upload_path;
        } else {
            $errors[] = 'เกิดข้อผิดพลาดในการอัปโหลดรูปภาพ';
        }
    }
}

// หากมีข้อผิดพลาด ให้ redirect กลับไปหน้าสมัครสมาชิก
if (!empty($errors)) {
    $_SESSION['register_errors'] = $errors;
    $_SESSION['register_data'] = $_POST; // เก็บข้อมูลเดิมไว้
    
    // กำหนด error code สำหรับ URL parameter
    $error_code = 'invalid_data';
    if (in_array('รหัสนักเรียนนี้มีอยู่ในระบบแล้ว', $errors)) {
        $error_code = 'student_exists';
    } elseif (in_array('อีเมลนี้มีอยู่ในระบบแล้ว', $errors)) {
        $error_code = 'email_exists';
    } elseif (strpos(implode(' ', $errors), 'อัปโหลด') !== false) {
        $error_code = 'upload_error';
    }
    
    header("Location: register.php?error=" . $error_code);
    exit();
}

// บันทึกข้อมูลลงฐานข้อมูล
try {
    $db->beginTransaction();
    
    // เข้ารหัสรหัสผ่าน
    $hashed_password = hash_password($password);
    
    // เตรียม SQL statement
    $sql = "INSERT INTO users (
        student_id, first_name, last_name, email, phone, address, 
        password, user_type, department, class_level, profile_image,
        status, registration_date, created_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW()
    )";
    
    $stmt = $db->prepare($sql);
    $result = $stmt->execute([
        $student_id,
        $first_name,
        $last_name,
        $email,
        $phone ?: null,
        $address ?: null,
        $hashed_password,
        $user_type,
        $department ?: null,
        $class_level ?: null,
        $profile_image
    ]);
    
    if ($result) {
        $user_id = $db->lastInsertId();
        
        // บันทึก activity log
        $log_sql = "INSERT INTO activity_logs (
            user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at
        ) VALUES (?, 'register', 'users', ?, ?, ?, ?, NOW())";
        
        $new_values = json_encode([
            'student_id' => $student_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'user_type' => $user_type,
            'department' => $department,
            'class_level' => $class_level
        ]);
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $log_stmt = $db->prepare($log_sql);
        $log_stmt->execute([
            $user_id,
            $user_id,
            $new_values,
            $ip_address,
            $user_agent
        ]);
        
        // สร้างการแจ้งเตือนต้นรับสำหรับผู้ใช้ใหม่
        $notification_sql = "INSERT INTO notifications (
            user_id, type, title, message, sent_date
        ) VALUES (?, 'general', ?, ?, NOW())";
        
        $welcome_title = 'ยินดีต้อนรับเข้าสู่ห้องสมุดดิจิทัล';
        $welcome_message = "สวัสดี คุณ{$first_name} {$last_name} ยินดีต้อนรับเข้าสู่ระบบห้องสมุดวิทยาลัยเทคนิคหาดใหญ่ คุณสามารถเริ่มค้นหาและยืมหนังสือได้แล้ว";
        
        $notification_stmt = $db->prepare($notification_sql);
        $notification_stmt->execute([
            $user_id,
            $welcome_title,
            $welcome_message
        ]);
        
        $db->commit();
        
        // ล้างข้อมูลใน session
        unset($_SESSION['register_errors']);
        unset($_SESSION['register_data']);
        
        // redirect ไปหน้า login พร้อมข้อความสำเร็จ
        header("Location: login.php?success=registered");
        exit();
        
    } else {
        throw new Exception("Failed to insert user data");
    }
    
} catch (Exception $e) {
    $db->rollBack();
    
    // ลบไฟล์รูปภาพที่อัปโหลดแล้ว (ถ้ามี)
    if ($profile_image && file_exists($profile_image)) {
        unlink($profile_image);
    }
    
    error_log("Registration error: " . $e->getMessage());
    
    $_SESSION['register_errors'] = ['เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง'];
    $_SESSION['register_data'] = $_POST;
    
    header("Location: register.php?error=database_error");
    exit();
}
?>