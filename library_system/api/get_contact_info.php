<?php
// api/get_contact_info.php - ดึงข้อมูลการติดต่อจาก admin
require_once '../db.php';

header('Content-Type: application/json');

try {
    // ดึงข้อมูลจาก admin ที่เป็น super_admin หรือ librarian
    $stmt = $db->prepare("
        SELECT phone, email
        FROM admins 
        WHERE role IN ('super_admin', 'librarian') 
        AND status = 'active' 
        AND (phone IS NOT NULL OR email IS NOT NULL)
        ORDER BY 
            CASE role 
                WHEN 'super_admin' THEN 1 
                WHEN 'librarian' THEN 2 
                ELSE 3 
            END,
            created_at ASC 
        LIMIT 1
    ");
    $stmt->execute();
    $contact = $stmt->fetch();
    
    // ถ้าไม่มีข้อมูลในฐานข้อมูล ใช้ข้อมูล default
    if (!$contact) {
        $contact = [
            'phone' => '074-123456',
            'email' => 'library@techhathai.ac.th'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $contact
    ]);
    
} catch (PDOException $e) {
    error_log("Get contact info error: " . $e->getMessage());
    
    // ส่งข้อมูล default กรณีเกิดข้อผิดพลาด
    echo json_encode([
        'success' => true,
        'data' => [
            'phone' => '074-123456',
            'email' => 'library@techhathai.ac.th'
        ]
    ]);
}
?>