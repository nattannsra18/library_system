-- สร้างตาราง system_settings สำหรับเก็บข้อมูลการติดต่อ
CREATE TABLE IF NOT EXISTS system_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(100) NOT NULL UNIQUE,
    contact_phone VARCHAR(20),
    contact_email VARCHAR(100),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- เพิ่มข้อมูลการติดต่อเริ่มต้น
INSERT INTO system_settings (setting_name, contact_phone, contact_email, description) 
VALUES (
    'library_contact', 
    '074-123456', 
    'library@techhathai.ac.th',
    'ข้อมูลการติดต่อห้องสมุดสำหรับการรีเซ็ตรหัสผ่าน'
) ON DUPLICATE KEY UPDATE 
    contact_phone = VALUES(contact_phone),
    contact_email = VALUES(contact_email),
    updated_at = CURRENT_TIMESTAMP;

-- ALTER TABLE admins ADD COLUMN phone VARCHAR(20) AFTER email;