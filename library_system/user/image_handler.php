<?php
// image_handler.php - ไฟล์จัดการรูปภาพ
function getBookCoverUrl($coverImage, $bookId = null) {
    if (empty($coverImage)) {
        return null;
    }
    
    // กำหนด path ของโฟลเดอร์รูปภาพ
    $uploadPath = '/uploads/covers/';
    $fullPath = $_SERVER['DOCUMENT_ROOT'] . $uploadPath . $coverImage;
    
    // ตรวจสอบว่าไฟล์มีอยู่จริง
    if (file_exists($fullPath)) {
        return $uploadPath . $coverImage;
    }
    
    // หากไฟล์ไม่พบ ลองหาไฟล์ที่มี book_id
    if ($bookId) {
        $possibleFiles = [
            "book_{$bookId}.jpg",
            "book_{$bookId}.png",
            "book_{$bookId}.jpeg",
            "{$bookId}.jpg",
            "{$bookId}.png"
        ];
        
        foreach ($possibleFiles as $filename) {
            $testPath = $_SERVER['DOCUMENT_ROOT'] . $uploadPath . $filename;
            if (file_exists($testPath)) {
                return $uploadPath . $filename;
            }
        }
    }
    
    return null;
}

function generateBookCoverPlaceholder($title, $width = 200, $height = 300) {
    // สร้าง placeholder image URL
    $bgColor = '667eea';
    $textColor = 'ffffff';
    $text = urlencode(substr($title, 0, 20));
    
    return "https://via.placeholder.com/{$width}x{$height}/{$bgColor}/{$textColor}?text=" . $text;
}
?>

<?php
