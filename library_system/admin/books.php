<?php
require_once '../db.php';

if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

// ดึงข้อมูลแอดมิน
$admin_id = $_SESSION['admin_id'];
$stmt = $db->prepare("SELECT * FROM admins WHERE admin_id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

// สร้างโฟลเดอร์สำหรับเก็บรูปปก
$upload_dir = '../uploads/covers/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// ฟังก์ชันสำหรับอัพโหลดรูป
function uploadCoverImage($file, $upload_dir) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('รูปปกต้องเป็นไฟล์ JPG, PNG, GIF หรือ WebP เท่านั้น');
    }
    
    if ($file['size'] > 5 * 1024 * 1024) { // 5MB
        throw new Exception('รูปปกต้องมีขนาดไม่เกิน 5MB');
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'book_' . uniqid() . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return 'uploads/covers/' . $filename;
    }
    
    return false;
}

// จัดการการส่งข้อมูล
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_book':
                // จัดการอัพโหลดรูปปก
                $cover_image_path = null;
                if (!empty($_FILES['cover_image']['name'])) {
                    $cover_image_path = uploadCoverImage($_FILES['cover_image'], $upload_dir);
                    if (!$cover_image_path) {
                        throw new Exception('ไม่สามารถอัพโหลดรูปปกได้');
                    }
                } elseif (!empty($_POST['cover_image_url'])) {
                    $cover_image_path = $_POST['cover_image_url'];
                }
                
                // เพิ่มหนังสือใหม่
                $stmt = $db->prepare("
                    INSERT INTO books (isbn, title, subtitle, description, category_id, publisher_id, 
                                     publication_year, edition, pages, language, location, cover_image, 
                                     price, acquisition_date, total_copies, available_copies, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')
                ");
                $total_copies = (int)$_POST['total_copies'];
                $stmt->execute([
                    $_POST['isbn'] ?: null,
                    $_POST['title'],
                    $_POST['subtitle'] ?: null,
                    $_POST['description'] ?: null,
                    $_POST['category_id'] ?: null,
                    $_POST['publisher_id'] ?: null,
                    $_POST['publication_year'] ?: null,
                    $_POST['edition'] ?: null,
                    $_POST['pages'] ? (int)$_POST['pages'] : null,
                    $_POST['language'] ?: 'Thai',
                    $_POST['location'] ?: null,
                    $cover_image_path,
                    $_POST['price'] ? (float)$_POST['price'] : null,
                    $_POST['acquisition_date'] ?: date('Y-m-d'),
                    $total_copies,
                    $total_copies
                ]);
                
                $book_id = $db->lastInsertId();
                
                // เพิ่มผู้เขียน
                if (!empty($_POST['author_ids'])) {
                    $author_ids = array_filter($_POST['author_ids']);
                    $author_roles = $_POST['author_roles'] ?? [];
                    
                    foreach ($author_ids as $index => $author_id) {
                        if ($author_id) {
                            $role = $author_roles[$index] ?? 'author';
                            $stmt = $db->prepare("INSERT INTO book_authors (book_id, author_id, role) VALUES (?, ?, ?)");
                            $stmt->execute([$book_id, $author_id, $role]);
                        }
                    }
                }
                
                $message = 'เพิ่มหนังสือสำเร็จ';
                $message_type = 'success';
                break;
                
            case 'edit_book':
                // จัดการอัพโหลดรูปปกใหม่
                $cover_image_path = $_POST['existing_cover_image'];
                
                if (!empty($_FILES['cover_image']['name'])) {
                    $new_cover = uploadCoverImage($_FILES['cover_image'], $upload_dir);
                    if ($new_cover) {
                        // ลบรูปเดิม (ถ้าเป็นไฟล์ที่อัพโหลด)
                        if ($cover_image_path && strpos($cover_image_path, 'uploads/covers/') === 0) {
                            $old_file = '../' . $cover_image_path;
                            if (file_exists($old_file)) {
                                unlink($old_file);
                            }
                        }
                        $cover_image_path = $new_cover;
                    }
                } elseif (!empty($_POST['cover_image_url'])) {
                    $cover_image_path = $_POST['cover_image_url'];
                }
                
                // แก้ไขหนังสือ
                $book_id = $_POST['book_id'];
                $stmt = $db->prepare("
                    UPDATE books 
                    SET isbn = ?, title = ?, subtitle = ?, description = ?, category_id = ?, publisher_id = ?, 
                        publication_year = ?, edition = ?, pages = ?, language = ?, location = ?, 
                        cover_image = ?, price = ?, acquisition_date = ?, total_copies = ?
                    WHERE book_id = ?
                ");
                $stmt->execute([
                    $_POST['isbn'] ?: null,
                    $_POST['title'],
                    $_POST['subtitle'] ?: null,
                    $_POST['description'] ?: null,
                    $_POST['category_id'] ?: null,
                    $_POST['publisher_id'] ?: null,
                    $_POST['publication_year'] ?: null,
                    $_POST['edition'] ?: null,
                    $_POST['pages'] ? (int)$_POST['pages'] : null,
                    $_POST['language'] ?: 'Thai',
                    $_POST['location'] ?: null,
                    $cover_image_path,
                    $_POST['price'] ? (float)$_POST['price'] : null,
                    $_POST['acquisition_date'] ?: null,
                    (int)$_POST['total_copies'],
                    $book_id
                ]);
                
                // ลบผู้เขียนเดิม
                $stmt = $db->prepare("DELETE FROM book_authors WHERE book_id = ?");
                $stmt->execute([$book_id]);
                
                // เพิ่มผู้เขียนใหม่
                if (!empty($_POST['author_ids'])) {
                    $author_ids = array_filter($_POST['author_ids']);
                    $author_roles = $_POST['author_roles'] ?? [];
                    
                    foreach ($author_ids as $index => $author_id) {
                        if ($author_id) {
                            $role = $author_roles[$index] ?? 'author';
                            $stmt = $db->prepare("INSERT INTO book_authors (book_id, author_id, role) VALUES (?, ?, ?)");
                            $stmt->execute([$book_id, $author_id, $role]);
                        }
                    }
                }
                
                $message = 'แก้ไขหนังสือสำเร็จ';
                $message_type = 'success';
                break;
                
            case 'delete_book':
                // ดึงข้อมูลรูปปกก่อนลบ
                $stmt = $db->prepare("SELECT cover_image FROM books WHERE book_id = ?");
                $stmt->execute([$_POST['book_id']]);
                $book = $stmt->fetch();
                
                // ลบหนังสือ
                $book_id = $_POST['book_id'];
                $stmt = $db->prepare("DELETE FROM books WHERE book_id = ?");
                $stmt->execute([$book_id]);
                
                // ลบรูปปก (ถ้าเป็นไฟล์ที่อัพโหลด)
                if ($book && $book['cover_image'] && strpos($book['cover_image'], 'uploads/covers/') === 0) {
                    $cover_file = '../' . $book['cover_image'];
                    if (file_exists($cover_file)) {
                        unlink($cover_file);
                    }
                }
                
                $message = 'ลบหนังสือสำเร็จ';
                $message_type = 'success';
                break;
                
            case 'add_category':
                $stmt = $db->prepare("INSERT INTO categories (category_name, category_code) VALUES (?, ?)");
                $category_name = $_POST['category_name'];
                $category_code = strtoupper(substr($category_name, 0, 4));
                $stmt->execute([$category_name, $category_code]);
                
                $message = 'เพิ่มหมวดหมู่สำเร็จ';
                $message_type = 'success';
                break;
                
            case 'add_publisher':
                $stmt = $db->prepare("INSERT INTO publishers (publisher_name) VALUES (?)");
                $stmt->execute([$_POST['publisher_name']]);
                
                $message = 'เพิ่มสำนักพิมพ์สำเร็จ';
                $message_type = 'success';
                break;
                
            case 'add_author':
                $stmt = $db->prepare("INSERT INTO authors (first_name, last_name) VALUES (?, ?)");
                $names = explode(' ', $_POST['author_name'], 2);
                $first_name = $names[0];
                $last_name = $names[1] ?? '';
                $stmt->execute([$first_name, $last_name]);
                
                $message = 'เพิ่มผู้เขียนสำเร็จ';
                $message_type = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// ดึงข้อมูลหนังสือ
$search = $_GET['search'] ?? '';
$sql = "
    SELECT b.*, c.category_name, p.publisher_name,
           GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name, ' (', ba.role, ')') SEPARATOR ', ') as authors
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN publishers p ON b.publisher_id = p.publisher_id
    LEFT JOIN book_authors ba ON b.book_id = ba.book_id
    LEFT JOIN authors a ON ba.author_id = a.author_id
";

if ($search) {
    $sql .= " WHERE b.title LIKE ? OR b.isbn LIKE ? OR c.category_name LIKE ? OR p.publisher_name LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
} else {
    $params = [];
}

$sql .= " GROUP BY b.book_id ORDER BY b.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();

// ดึงข้อมูลหมวดหมู่
$categories = $db->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();

// ดึงข้อมูลสำนักพิมพ์
$publishers = $db->query("SELECT * FROM publishers ORDER BY publisher_name")->fetchAll();

// ดึงข้อมูลผู้เขียน
$authors = $db->query("SELECT * FROM authors ORDER BY first_name, last_name")->fetchAll();

// ดึงสถิติ
$total_books = $db->query("SELECT COUNT(*) FROM books")->fetchColumn();
$available_books = $db->query("SELECT COUNT(*) FROM books WHERE status = 'available'")->fetchColumn();
$total_categories = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$total_authors = $db->query("SELECT COUNT(*) FROM authors")->fetchColumn();

// API สำหรับดึงข้อมูลหนังสือเฉพาะเล่ม (AJAX)
if (isset($_GET['get_book']) && $_GET['get_book']) {
    $book_id = $_GET['get_book'];
    $stmt = $db->prepare("
        SELECT b.*, 
               GROUP_CONCAT(ba.author_id) as author_ids,
               GROUP_CONCAT(ba.role) as author_roles
        FROM books b
        LEFT JOIN book_authors ba ON b.book_id = ba.book_id
        WHERE b.book_id = ?
        GROUP BY b.book_id
    ");
    $stmt->execute([$book_id]);
    $book_data = $stmt->fetch();
    
    if ($book_data) {
        $book_data['author_ids'] = $book_data['author_ids'] ? explode(',', $book_data['author_ids']) : [];
        $book_data['author_roles'] = $book_data['author_roles'] ? explode(',', $book_data['author_roles']) : [];
    }
    
    header('Content-Type: application/json');
    echo json_encode($book_data);
    exit;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการหนังสือ - ห้องสมุดดิจิทัล</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: #333;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
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
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.8rem 1.5rem;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 25px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-logout:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
        }

        .container {
            max-width: 1600px;
            margin: 100px auto 20px;
            padding: 0 20px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #495057;
            border: 1px solid #dee2e6;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            transform: translateY(-1px);
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #e83e8c 100%);
            color: white;
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 0.85rem;
            border-radius: 8px;
        }

        .btn-icon {
            padding: 10px;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }

        .main-content {
            display: grid;
            grid-template-columns: 600px 1fr;
            gap: 30px;
        }

        .form-panel {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            height: fit-content;
            position: sticky;
            top: 120px;
            border: 1px solid rgba(255,255,255,0.2);
            max-width: 650px;
        }

        .books-panel {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .panel-title {
            font-size: 1.6rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-section {
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 4px solid #667eea;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-row.full {
            grid-template-columns: 1fr;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
            font-size: 0.95rem;
        }

        .form-label.required::after {
            content: " *";
            color: #dc3545;
        }

        .form-control {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            transform: translateY(-1px);
        }

        .form-control.textarea {
            resize: vertical;
            min-height: 100px;
        }

        .input-group {
            display: flex;
            gap: 10px;
            align-items: end;
        }

        .input-group .form-control {
            flex: 1;
        }

        .input-add-btn {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .input-add-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .authors-section {
            background: #e8f5e8;
            border-left-color: #28a745;
        }

        .author-item {
            display: flex;
            gap: 10px;
            align-items: end;
            margin-bottom: 10px;
            padding: 15px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .author-item .form-control {
            margin-bottom: 0;
        }

        .remove-author {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
        }

        .remove-author:hover {
            background: #c82333;
        }

        /* Cover Upload Styles */
        .cover-upload-section {
            display: flex;
            gap: 20px;
            align-items: flex-start;
        }

        .cover-preview {
            flex-shrink: 0;
        }

        .cover-placeholder {
            width: 100px;
            height: 150px;
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .upload-options {
            flex: 1;
        }

        .upload-tabs {
            display: flex;
            margin-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .upload-tab {
            background: none;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 500;
            color: #6c757d;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .upload-tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }

        .upload-tab:hover {
            color: #667eea;
        }

        .upload-mode {
            margin-top: 10px;
        }

        input[type="file"] {
            border: 2px dashed #dee2e6;
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        input[type="file"]:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .search-bar {
            position: relative;
            margin-bottom: 25px;
        }

        .search-input {
            padding-left: 50px;
            background: rgba(248, 249, 250, 0.9);
            border-color: #e9ecef;
        }

        .search-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .books-grid {
            display: grid;
            gap: 20px;
        }

        .book-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .book-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .book-info {
            flex: 1;
        }

        .book-title {
            font-size: 1.3rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
            line-height: 1.4;
        }

        .book-subtitle {
            font-size: 1rem;
            color: #6c757d;
            font-style: italic;
            margin-bottom: 5px;
        }

        .book-isbn {
            font-size: 0.9rem;
            color: #6c757d;
            font-family: monospace;
        }

        .book-cover {
            width: 80px;
            height: 120px;
            background: #f8f9fa;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 2rem;
            margin-left: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .book-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }

        .book-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
        }

        .meta-icon {
            width: 20px;
            text-align: center;
            color: #667eea;
        }

        .meta-label {
            color: #6c757d;
            font-weight: 500;
            min-width: 80px;
        }

        .meta-value {
            color: #333;
        }

        .book-description {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            font-size: 0.95rem;
            line-height: 1.6;
            color: #495057;
        }

        .book-actions {
            display: flex;
            gap: 10px;
            padding-top: 20px;
            border-top: 1px solid #f0f0f0;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-available {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }

        .status-unavailable {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            animation: modalSlideIn 0.3s ease;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .modal-content.large {
            max-width: 800px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .modal-title {
            font-size: 1.4rem;
            font-weight: bold;
            color: #333;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.8rem;
            color: #dc3545;
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-btn:hover {
            background: #f8f9fa;
            transform: rotate(90deg);
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.9);
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }

        .stat-label {
            color: #6c757d;
            font-weight: 500;
        }

        /* Delete Confirmation Modal Styles */
        .delete-modal .modal-content {
            max-width: 400px;
            text-align: center;
        }

        .delete-icon {
            font-size: 4rem;
            color: #dc3545;
            margin-bottom: 20px;
        }

        .delete-title {
            font-size: 1.5rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 15px;
        }

        .delete-text {
            color: #6c757d;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .delete-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 1200px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            
            .form-panel {
                position: relative;
                top: 0;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .nav-container {
                flex-direction: column;
                gap: 20px;
                padding: 1rem;
            }
            
            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .book-meta {
                grid-template-columns: 1fr;
            }
            
            .book-actions {
                flex-direction: column;
            }
            
            .modal-content {
                margin: 20px;
                width: calc(100% - 40px);
            }

            .book-header {
                flex-direction: column;
                gap: 15px;
            }

            .book-cover {
                margin: 0;
                align-self: center;
            }

            .cover-upload-section {
                flex-direction: column;
                align-items: center;
            }
            
            .cover-preview {
                margin-bottom: 15px;
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
                <li><a href="books.php" class="active">จัดการหนังสือ</a></li>
                <li><a href="users.php">จัดการผู้ใช้</a></li>
            </ul>
            <a href="../logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i>
                ออกจากระบบ
            </a>
        </nav>
    </header>

    <div class="container">
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= number_format($total_books) ?></div>
                <div class="stat-label">หนังสือทั้งหมด</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($available_books) ?></div>
                <div class="stat-label">หนังสือที่พร้อมใช้</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($total_categories) ?></div>
                <div class="stat-label">หมวดหมู่</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($total_authors) ?></div>
                <div class="stat-label">ผู้เขียน</div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>">
                <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Form Panel -->
            <div class="form-panel">
                <h2 class="panel-title">
                    <i class="fas fa-plus-circle"></i>
                    <span id="formTitle">เพิ่มหนังสือใหม่</span>
                </h2>
                
                <form id="bookForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" id="bookId" name="book_id">
                    <input type="hidden" id="formAction" name="action" value="add_book">
                    
                    <!-- ข้อมูลพื้นฐาน -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-info-circle"></i>
                            ข้อมูลพื้นฐาน
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required" for="title">ชื่อหนังสือ</label>
                            <input type="text" id="title" name="title" class="form-control" required placeholder="ระบุชื่อหนังสือ">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="subtitle">ชื่อรอง</label>
                            <input type="text" id="subtitle" name="subtitle" class="form-control" placeholder="ชื่อรอง (หากมี)">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="description">รายละเอียด/เนื้อหาย่อ</label>
                            <textarea id="description" name="description" class="form-control textarea" placeholder="อธิบายเนื้อหาของหนังสือโดยย่อ..."></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="isbn">ISBN</label>
                                <input type="text" id="isbn" name="isbn" class="form-control" placeholder="978-0-7475-3269-9">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="language">ภาษา</label>
                                <select id="language" name="language" class="form-control">
                                    <option value="Thai">ไทย</option>
                                    <option value="English">อังกฤษ</option>
                                    <option value="Chinese">จีน</option>
                                    <option value="Japanese">ญี่ปุ่น</option>
                                    <option value="Korean">เกาหลี</option>
                                    <option value="Other">อื่นๆ</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- การจัดหมู่ -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-tags"></i>
                            การจัดหมู่และสำนักพิมพ์
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="category">หมวดหมู่</label>
                            <div class="input-group">
                                <select id="category" name="category_id" class="form-control">
                                    <option value="">เลือกหมวดหมู่</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="input-add-btn" onclick="openModal('categoryModal')">
                                    <i class="fas fa-plus"></i> เพิ่ม
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="publisher">สำนักพิมพ์</label>
                            <div class="input-group">
                                <select id="publisher" name="publisher_id" class="form-control">
                                    <option value="">เลือกสำนักพิมพ์</option>
                                    <?php foreach ($publishers as $pub): ?>
                                        <option value="<?= $pub['publisher_id'] ?>"><?= htmlspecialchars($pub['publisher_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="input-add-btn" onclick="openModal('publisherModal')">
                                    <i class="fas fa-plus"></i> เพิ่ม
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="publicationYear">ปีที่พิมพ์</label>
                                <input type="number" id="publicationYear" name="publication_year" class="form-control" min="1900" max="2024" placeholder="2023">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="edition">ครั้งที่พิมพ์</label>
                                <input type="text" id="edition" name="edition" class="form-control" placeholder="ครั้งที่ 1">
                            </div>
                        </div>
                    </div>

                    <!-- รายละเอียดเพิ่มเติม -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-cog"></i>
                            รายละเอียดเพิ่มเติม
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="pages">จำนวนหน้า</label>
                                <input type="number" id="pages" name="pages" class="form-control" min="1" placeholder="350">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="price">ราคา (บาท)</label>
                                <input type="number" id="price" name="price" class="form-control" min="0" step="0.01" placeholder="299.00">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="location">ตำแหน่งในห้องสมุด</label>
                                <input type="text" id="location" name="location" class="form-control" placeholder="ชั้น A ตู้ 1 ช่อง 5">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="acquisitionDate">วันที่ได้หนังสือ</label>
                                <input type="date" id="acquisitionDate" name="acquisition_date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="totalCopies">จำนวนเล่ม</label>
                                <input type="number" id="totalCopies" name="total_copies" class="form-control" min="1" value="1">
                            </div>
                            <div class="form-group">
                                <!-- รูปปก -->
                            </div>
                        </div>
                        
                        <!-- รูปปกหนังสือ -->
                        <div class="form-group">
                            <label class="form-label" for="coverImageFile">รูปปกหนังสือ</label>
                            <div class="cover-upload-section">
                                <!-- Preview รูปปก -->
                                <div class="cover-preview" id="coverPreview">
                                    <img id="previewImg" style="display: none; width: 100px; height: 150px; object-fit: cover; border-radius: 8px; margin-bottom: 10px;">
                                    <div id="previewPlaceholder" class="cover-placeholder">
                                        <i class="fas fa-image" style="font-size: 2rem; color: #ccc;"></i>
                                        <p style="margin: 10px 0 0 0; color: #999; font-size: 0.9rem;">ไม่มีรูปปก</p>
                                    </div>
                                </div>
                                
                                <!-- ตัวเลือกการอัพโหลด -->
                                <div class="upload-options">
                                    <div class="upload-tabs">
                                        <button type="button" class="upload-tab active" onclick="switchUploadMode('file')">อัพโหลดไฟล์</button>
                                        <button type="button" class="upload-tab" onclick="switchUploadMode('url')">ใส่ลิงก์</button>
                                    </div>
                                    
                                    <div id="fileUploadMode" class="upload-mode">
                                        <input type="file" id="coverImageFile" name="cover_image" class="form-control" 
                                               accept="image/*" onchange="previewCoverImage(this)">
                                        <small style="color: #6c757d; font-size: 0.85rem; margin-top: 5px; display: block;">
                                            รองรับไฟล์ JPG, PNG, GIF, WebP ขนาดไม่เกิน 5MB
                                        </small>
                                    </div>
                                    
                                    <div id="urlUploadMode" class="upload-mode" style="display: none;">
                                        <input type="url" id="coverImageUrl" name="cover_image_url" class="form-control" 
                                               placeholder="https://example.com/cover.jpg" onchange="previewCoverUrl(this)">
                                        <small style="color: #6c757d; font-size: 0.85rem; margin-top: 5px; display: block;">
                                            ใส่ลิงก์รูปภาพจากอินเทอร์เน็ต
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <!-- สำหรับเก็บรูปเดิมตอนแก้ไข -->
                            <input type="hidden" id="existingCoverImage" name="existing_cover_image">
                        </div>
                    </div>

                    <!-- ผู้เขียน -->
                    <div class="form-section authors-section">
                        <div class="section-title">
                            <i class="fas fa-user-edit"></i>
                            ผู้เขียน
                            <button type="button" class="input-add-btn" onclick="openModal('authorModal')">
                                <i class="fas fa-user-plus"></i> เพิ่มผู้เขียนใหม่
                            </button>
                        </div>
                        
                        <div id="authorsContainer">
                            <div class="author-item">
                                <select name="author_ids[]" class="form-control">
                                    <option value="">เลือกผู้เขียน</option>
                                    <?php foreach ($authors as $author): ?>
                                        <option value="<?= $author['author_id'] ?>"><?= htmlspecialchars($author['first_name'] . ' ' . $author['last_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="author_roles[]" class="form-control" style="max-width: 130px;">
                                    <option value="author">ผู้เขียน</option>
                                    <option value="co-author">ผู้เขียนร่วม</option>
                                    <option value="editor">บรรณาธิการ</option>
                                    <option value="translator">ผู้แปล</option>
                                </select>
                                <button type="button" class="remove-author" onclick="removeAuthor(this)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary btn-small" onclick="addAuthor()" style="margin-top: 10px;">
                            <i class="fas fa-plus"></i> เพิ่มผู้เขียน
                        </button>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <div class="loading-spinner" id="submitSpinner"></div>
                            <i class="fas fa-save" id="submitIcon"></i>
                            <span id="submitText">บันทึกหนังสือ</span>
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetForm()">
                            <i class="fas fa-undo"></i>
                            ยกเลิก
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Books Panel -->
            <div class="books-panel">
                <h2 class="panel-title">
                    <i class="fas fa-books"></i>
                    รายการหนังสือ
                </h2>
                
                <div class="search-bar">
                    <form method="GET">
                        <input type="text" name="search" class="form-control search-input" 
                               value="<?= htmlspecialchars($search) ?>" 
                               placeholder="ค้นหาหนังสือ ผู้เขียน หรือ ISBN...">
                        <i class="fas fa-search search-icon"></i>
                    </form>
                </div>
                
                <div class="books-grid">
                    <?php if (empty($books)): ?>
                        <div style="text-align: center; padding: 60px 20px; color: #6c757d;">
                            <i class="fas fa-book-open" style="font-size: 4rem; margin-bottom: 20px; opacity: 0.5;"></i>
                            <h3>ไม่มีหนังสือในระบบ</h3>
                            <p>เริ่มต้นโดยการเพิ่มหนังสือเล่มแรกของคุณ</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($books as $book): ?>
                            <div class="book-card">
                                <div class="book-header">
                                    <div class="book-info">
                                        <div class="book-title"><?= htmlspecialchars($book['title']) ?></div>
                                        <?php if ($book['subtitle']): ?>
                                            <div class="book-subtitle"><?= htmlspecialchars($book['subtitle']) ?></div>
                                        <?php endif; ?>
                                        <div class="book-isbn">ISBN: <?= htmlspecialchars($book['isbn'] ?: 'ไม่ระบุ') ?></div>
                                        <div class="status-badge <?= $book['status'] === 'available' ? 'status-available' : 'status-unavailable' ?>">
                                            <?= $book['status'] === 'available' ? 'พร้อมใช้งาน' : 'ไม่พร้อมใช้งาน' ?>
                                        </div>
                                    </div>
                                    <div class="book-cover">
                                        <?php if ($book['cover_image']): ?>
                                            <img src="<?= htmlspecialchars($book['cover_image']) ?>" alt="Book Cover" onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'fas fa-book\'></i>';">
                                        <?php else: ?>
                                            <i class="fas fa-book"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($book['description']): ?>
                                    <div class="book-description">
                                        <?= htmlspecialchars(mb_substr($book['description'], 0, 200)) ?><?= mb_strlen($book['description']) > 200 ? '...' : '' ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="book-meta">
                                    <div class="meta-item">
                                        <i class="fas fa-tags meta-icon"></i>
                                        <span class="meta-label">หมวดหมู่:</span>
                                        <span class="meta-value"><?= htmlspecialchars($book['category_name'] ?: 'ไม่ระบุ') ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-building meta-icon"></i>
                                        <span class="meta-label">สำนักพิมพ์:</span>
                                        <span class="meta-value"><?= htmlspecialchars($book['publisher_name'] ?: 'ไม่ระบุ') ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-calendar meta-icon"></i>
                                        <span class="meta-label">ปีที่พิมพ์:</span>
                                        <span class="meta-value"><?= htmlspecialchars($book['publication_year'] ?: 'ไม่ระบุ') ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-copy meta-icon"></i>
                                        <span class="meta-label">จำนวน:</span>
                                        <span class="meta-value"><?= $book['available_copies'] ?>/<?= $book['total_copies'] ?> เล่ม</span>
                                    </div>
                                    <?php if ($book['pages']): ?>
                                        <div class="meta-item">
                                            <i class="fas fa-file-alt meta-icon"></i>
                                            <span class="meta-label">หน้า:</span>
                                            <span class="meta-value"><?= number_format($book['pages']) ?> หน้า</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($book['language'] && $book['language'] !== 'Thai'): ?>
                                        <div class="meta-item">
                                            <i class="fas fa-language meta-icon"></i>
                                            <span class="meta-label">ภาษา:</span>
                                            <span class="meta-value"><?= htmlspecialchars($book['language']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($book['location']): ?>
                                        <div class="meta-item">
                                            <i class="fas fa-map-marker-alt meta-icon"></i>
                                            <span class="meta-label">ตำแหน่ง:</span>
                                            <span class="meta-value"><?= htmlspecialchars($book['location']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($book['price']): ?>
                                        <div class="meta-item">
                                            <i class="fas fa-tag meta-icon"></i>
                                            <span class="meta-label">ราคา:</span>
                                            <span class="meta-value"><?= number_format($book['price'], 2) ?> บาท</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="meta-item" style="margin-bottom: 20px;">
                                    <i class="fas fa-user-edit meta-icon"></i>
                                    <span class="meta-label">ผู้เขียน:</span>
                                    <span class="meta-value"><?= htmlspecialchars($book['authors'] ?: 'ไม่ระบุ') ?></span>
                                </div>
                                
                                <div class="book-actions">
                                    <button class="btn btn-warning" onclick="editBook(<?= $book['book_id'] ?>)">
                                        <i class="fas fa-edit"></i> แก้ไข
                                    </button>
                                    <button class="btn btn-danger" onclick="showDeleteModal(<?= $book['book_id'] ?>, '<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-trash"></i> ลบ
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Modal -->
    <div id="categoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">เพิ่มหมวดหมู่ใหม่</h3>
                <button class="close-btn" onclick="closeModal('categoryModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_category">
                <div class="form-group">
                    <label class="form-label required" for="categoryName">ชื่อหมวดหมู่</label>
                    <input type="text" id="categoryName" name="category_name" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> บันทึกหมวดหมู่
                </button>
            </form>
        </div>
    </div>

    <!-- Publisher Modal -->
    <div id="publisherModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">เพิ่มสำนักพิมพ์ใหม่</h3>
                <button class="close-btn" onclick="closeModal('publisherModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_publisher">
                <div class="form-group">
                    <label class="form-label required" for="publisherName">ชื่อสำนักพิมพ์</label>
                    <input type="text" id="publisherName" name="publisher_name" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> บันทึกสำนักพิมพ์
                </button>
            </form>
        </div>
    </div>

    <!-- Author Modal -->
    <div id="authorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">เพิ่มผู้เขียนใหม่</h3>
                <button class="close-btn" onclick="closeModal('authorModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_author">
                <div class="form-group">
                    <label class="form-label required" for="authorName">ชื่อผู้เขียน</label>
                    <input type="text" id="authorName" name="author_name" class="form-control" required placeholder="เช่น นายสมชาย ใจดี">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> บันทึกผู้เขียน
                </button>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal delete-modal">
        <div class="modal-content">
            <div class="modal-header" style="border: none; text-align: center;">
                <div class="delete-icon">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <button class="close-btn" onclick="closeModal('deleteModal')" style="position: absolute; top: 15px; right: 15px;">&times;</button>
            </div>
            <div class="delete-title">ยืนยันการลบหนังสือ</div>
            <div class="delete-text">
                คุณแน่ใจหรือไม่ที่จะลบหนังสือ "<span id="deleteBookTitle"></span>"<br>
                การดำเนินการนี้ไม่สามารถยกเลิกได้
            </div>
            <div class="delete-actions">
                <button class="btn btn-secondary" onclick="closeModal('deleteModal')">
                    <i class="fas fa-times"></i> ยกเลิก
                </button>
                <button class="btn btn-danger" id="confirmDeleteBtn">
                    <div class="loading-spinner" id="deleteSpinner"></div>
                    <i class="fas fa-trash" id="deleteIcon"></i>
                    ลบหนังสือ
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentDeleteBookId = null;

        // JavaScript functions
        function addAuthor() {
            const container = document.getElementById('authorsContainer');
            const authorDiv = document.createElement('div');
            authorDiv.className = 'author-item';
            authorDiv.innerHTML = `
                <select name="author_ids[]" class="form-control">
                    <option value="">เลือกผู้เขียน</option>
                    <?php foreach ($authors as $author): ?>
                        <option value="<?= $author['author_id'] ?>"><?= htmlspecialchars($author['first_name'] . ' ' . $author['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="author_roles[]" class="form-control" style="max-width: 130px;">
                    <option value="author">ผู้เขียน</option>
                    <option value="co-author">ผู้เขียนร่วม</option>
                    <option value="editor">บรรณาธิการ</option>
                    <option value="translator">ผู้แปล</option>
                </select>
                <button type="button" class="remove-author" onclick="removeAuthor(this)">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            container.appendChild(authorDiv);
        }

        function removeAuthor(button) {
            const container = document.getElementById('authorsContainer');
            if (container.children.length > 1) {
                button.parentElement.remove();
            } else {
                alert('ต้องมีผู้เขียนอย่างน้อย 1 คน');
            }
        }

        async function editBook(bookId) {
            try {
                showLoading();
                
                // ดึงข้อมูลหนังสือ
                const response = await fetch(`?get_book=${bookId}`);
                const bookData = await response.json();
                
                if (!bookData) {
                    alert('ไม่พบข้อมูลหนังสือ');
                    return;
                }

                // เปลี่ยนหัวข้อและปุ่ม
                document.getElementById('formTitle').textContent = 'แก้ไขหนังสือ';
                document.getElementById('submitText').textContent = 'บันทึกการแก้ไข';
                document.getElementById('formAction').value = 'edit_book';
                document.getElementById('bookId').value = bookData.book_id;

                // เซ็ตข้อมูลลงในฟอร์ม
                document.getElementById('title').value = bookData.title || '';
                document.getElementById('subtitle').value = bookData.subtitle || '';
                document.getElementById('description').value = bookData.description || '';
                document.getElementById('isbn').value = bookData.isbn || '';
                document.getElementById('language').value = bookData.language || 'Thai';
                document.getElementById('category').value = bookData.category_id || '';
                document.getElementById('publisher').value = bookData.publisher_id || '';
                document.getElementById('publicationYear').value = bookData.publication_year || '';
                document.getElementById('edition').value = bookData.edition || '';
                document.getElementById('pages').value = bookData.pages || '';
                document.getElementById('price').value = bookData.price || '';
                document.getElementById('location').value = bookData.location || '';
                document.getElementById('acquisitionDate').value = bookData.acquisition_date || '';
                document.getElementById('totalCopies').value = bookData.total_copies || '';
                document.getElementById('existingCoverImage').value = bookData.cover_image || '';

                // จัดการรูปปก
                if (bookData.cover_image) {
                    document.getElementById('previewImg').src = bookData.cover_image;
                    document.getElementById('previewImg').style.display = 'block';
                    document.getElementById('previewPlaceholder').style.display = 'none';
                    
                    // ถ้าเป็น URL ให้เซ็ตใน URL input
                    if (!bookData.cover_image.startsWith('uploads/')) {
                        switchUploadMode('url');
                        document.getElementById('coverImageUrl').value = bookData.cover_image;
                    }
                }

                // จัดการผู้เขียน
                const container = document.getElementById('authorsContainer');
                container.innerHTML = '';
                
                if (bookData.author_ids && bookData.author_ids.length > 0) {
                    bookData.author_ids.forEach((authorId, index) => {
                        const authorDiv = document.createElement('div');
                        authorDiv.className = 'author-item';
                        authorDiv.innerHTML = `
                            <select name="author_ids[]" class="form-control">
                                <option value="">เลือกผู้เขียน</option>
                                <?php foreach ($authors as $author): ?>
                                    <option value="<?= $author['author_id'] ?>" ${authorId == '<?= $author['author_id'] ?>' ? 'selected' : ''}><?= htmlspecialchars($author['first_name'] . ' ' . $author['last_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="author_roles[]" class="form-control" style="max-width: 130px;">
                                <option value="author" ${bookData.author_roles[index] === 'author' ? 'selected' : ''}>ผู้เขียน</option>
                                <option value="co-author" ${bookData.author_roles[index] === 'co-author' ? 'selected' : ''}>ผู้เขียนร่วม</option>
                                <option value="editor" ${bookData.author_roles[index] === 'editor' ? 'selected' : ''}>บรรณาธิการ</option>
                                <option value="translator" ${bookData.author_roles[index] === 'translator' ? 'selected' : ''}>ผู้แปล</option>
                            </select>
                            <button type="button" class="remove-author" onclick="removeAuthor(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        `;
                        
                        // เซ็ตค่า author ที่ถูกต้อง
                        const authorSelect = authorDiv.querySelector('select[name="author_ids[]"]');
                        authorSelect.value = authorId;
                        
                        container.appendChild(authorDiv);
                    });
                } else {
                    // เพิ่มช่องผู้เขียนเปล่า
                    addAuthor();
                }

                // เลื่อนไปที่ฟอร์ม
                document.querySelector('.form-panel').scrollIntoView({ 
                    behavior: 'smooth',
                    block: 'start'
                });
                
                hideLoading();
                
            } catch (error) {
                console.error('Error loading book data:', error);
                alert('เกิดข้อผิดพลาดในการโหลดข้อมูลหนังสือ');
                hideLoading();
            }
        }

        function showDeleteModal(bookId, bookTitle) {
            currentDeleteBookId = bookId;
            document.getElementById('deleteBookTitle').textContent = bookTitle;
            openModal('deleteModal');
        }

        function resetForm() {
            document.getElementById('bookForm').reset();
            document.getElementById('bookId').value = '';
            document.getElementById('formAction').value = 'add_book';
            document.getElementById('formTitle').textContent = 'เพิ่มหนังสือใหม่';
            document.getElementById('submitText').textContent = 'บันทึกหนังสือ';
            document.getElementById('existingCoverImage').value = '';
            
            // รีเซ็ต upload mode และ preview
            switchUploadMode('file');
            clearPreview();
            
            // รีเซ็ตผู้เขียนให้เหลือแค่ 1 รายการ
            const container = document.getElementById('authorsContainer');
            container.innerHTML = `
                <div class="author-item">
                    <select name="author_ids[]" class="form-control">
                        <option value="">เลือกผู้เขียน</option>
                        <?php foreach ($authors as $author): ?>
                            <option value="<?= $author['author_id'] ?>"><?= htmlspecialchars($author['first_name'] . ' ' . $author['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="author_roles[]" class="form-control" style="max-width: 130px;">
                        <option value="author">ผู้เขียน</option>
                        <option value="co-author">ผู้เขียนร่วม</option>
                        <option value="editor">บรรณาธิการ</option>
                        <option value="translator">ผู้แปล</option>
                    </select>
                    <button type="button" class="remove-author" onclick="removeAuthor(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Cover upload functions
        function switchUploadMode(mode) {
            const fileMode = document.getElementById('fileUploadMode');
            const urlMode = document.getElementById('urlUploadMode');
            const tabs = document.querySelectorAll('.upload-tab');
            
            tabs.forEach(tab => tab.classList.remove('active'));
            
            if (mode === 'file') {
                fileMode.style.display = 'block';
                urlMode.style.display = 'none';
                tabs[0].classList.add('active');
                document.getElementById('coverImageUrl').value = '';
            } else {
                fileMode.style.display = 'none';
                urlMode.style.display = 'block';
                tabs[1].classList.add('active');
                document.getElementById('coverImageFile').value = '';
            }
            
            clearPreview();
        }

        function previewCoverImage(input) {
            const preview = document.getElementById('previewImg');
            const placeholder = document.getElementById('previewPlaceholder');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                    placeholder.style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                clearPreview();
            }
        }

        function previewCoverUrl(input) {
            const preview = document.getElementById('previewImg');
            const placeholder = document.getElementById('previewPlaceholder');
            
            if (input.value) {
                preview.src = input.value;
                preview.style.display = 'block';
                placeholder.style.display = 'none';
                
                preview.onerror = function() {
                    clearPreview();
                    alert('ไม่สามารถโหลดรูปภาพจากลิงก์ที่ระบุได้');
                };
            } else {
                clearPreview();
            }
        }

        function clearPreview() {
            const preview = document.getElementById('previewImg');
            const placeholder = document.getElementById('previewPlaceholder');
            
            preview.style.display = 'none';
            preview.src = '';
            placeholder.style.display = 'flex';
        }

        function showLoading() {
            document.getElementById('submitSpinner').style.display = 'block';
            document.getElementById('submitIcon').style.display = 'none';
        }

        function hideLoading() {
            document.getElementById('submitSpinner').style.display = 'none';
            document.getElementById('submitIcon').style.display = 'block';
        }

        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // ปิด modal เมื่อคลิกข้างนอก
            window.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal')) {
                    closeModal(e.target.id);
                }
            });

            // Auto-submit search form
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(this.searchTimeout);
                    this.searchTimeout = setTimeout(() => {
                        this.form.submit();
                    }, 500);
                });
            }

            // Handle delete confirmation
            document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
                if (currentDeleteBookId) {
                    const deleteSpinner = document.getElementById('deleteSpinner');
                    const deleteIcon = document.getElementById('deleteIcon');
                    
                    deleteSpinner.style.display = 'block';
                    deleteIcon.style.display = 'none';
                    this.disabled = true;
                    
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete_book">
                        <input type="hidden" name="book_id" value="${currentDeleteBookId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });

            // Handle form submission
            document.getElementById('bookForm').addEventListener('submit', function() {
                showLoading();
                document.querySelector('.btn-primary').disabled = true;
            });

            // Auto-refresh selects after adding new items
            document.querySelectorAll('.modal form').forEach(form => {
                form.addEventListener('submit', function() {
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                });
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // ESC to close modals
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    if (modal.style.display === 'flex') {
                        closeModal(modal.id);
                    }
                });
            }
        });
    </script>
</body>
</html>