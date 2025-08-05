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

// จัดการการส่งข้อมูล
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_book':
                // เพิ่มหนังสือใหม่
                $stmt = $db->prepare("
                    INSERT INTO books (isbn, title, category_id, publisher_id, publication_year, total_copies, available_copies, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'available')
                ");
                $total_copies = (int)$_POST['total_copies'];
                $stmt->execute([
                    $_POST['isbn'] ?: null,
                    $_POST['title'],
                    $_POST['category_id'] ?: null,
                    $_POST['publisher_id'] ?: null,
                    $_POST['publication_year'] ?: null,
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
                // แก้ไขหนังสือ
                $book_id = $_POST['book_id'];
                $stmt = $db->prepare("
                    UPDATE books 
                    SET isbn = ?, title = ?, category_id = ?, publisher_id = ?, publication_year = ?, total_copies = ?
                    WHERE book_id = ?
                ");
                $stmt->execute([
                    $_POST['isbn'] ?: null,
                    $_POST['title'],
                    $_POST['category_id'] ?: null,
                    $_POST['publisher_id'] ?: null,
                    $_POST['publication_year'] ?: null,
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
                // ลบหนังสือ
                $book_id = $_POST['book_id'];
                $stmt = $db->prepare("DELETE FROM books WHERE book_id = ?");
                $stmt->execute([$book_id]);
                
                $message = 'ลบหนังสือสำเร็จ';
                $message_type = 'success';
                break;
                
            case 'add_category':
                // เพิ่มหมวดหมู่ใหม่
                $stmt = $db->prepare("INSERT INTO categories (category_name, category_code) VALUES (?, ?)");
                $category_name = $_POST['category_name'];
                $category_code = strtoupper(substr($category_name, 0, 4)); // สร้างรหัสอัตโนมัติ
                $stmt->execute([$category_name, $category_code]);
                
                $message = 'เพิ่มหมวดหมู่สำเร็จ';
                $message_type = 'success';
                break;
                
            case 'add_publisher':
                // เพิ่มสำนักพิมพ์ใหม่
                $stmt = $db->prepare("INSERT INTO publishers (publisher_name) VALUES (?)");
                $stmt->execute([$_POST['publisher_name']]);
                
                $message = 'เพิ่มสำนักพิมพ์สำเร็จ';
                $message_type = 'success';
                break;
                
            case 'add_author':
                // เพิ่มผู้เขียนใหม่
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
            background: #f8f9fa;
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
            max-width: 1400px;
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
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: #f8f9fa;
            color: #495057;
            border: 2px solid #e9ecef;
        }

        .btn-secondary:hover {
            background: #e9ecef;
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

        .main-content {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
        }

        .form-panel {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            height: fit-content;
            position: sticky;
            top: 120px;
        }

        .books-panel {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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

        .input-group {
            display: flex;
            gap: 10px;
            align-items: end;
        }

        .input-group .form-control {
            flex: 1;
        }

        .btn-icon {
            padding: 8px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }

        .search-bar {
            position: relative;
            margin-bottom: 25px;
        }

        .search-input {
            padding-left: 50px;
            background: #f8f9fa;
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
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #f0f0f0;
            transition: all 0.3s ease;
        }

        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }

        .book-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .book-title {
            font-size: 1.3rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
            line-height: 1.4;
        }

        .book-isbn {
            font-size: 0.9rem;
            color: #6c757d;
            font-family: monospace;
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
        }

        .meta-value {
            color: #333;
        }

        .book-actions {
            display: flex;
            gap: 10px;
            padding-top: 20px;
            border-top: 1px solid #f0f0f0;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-available {
            background: #d4edda;
            color: #155724;
        }

        .status-unavailable {
            background: #f8d7da;
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
            z-index: 1000;
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
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
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
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
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
            background: white;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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
                <li><a href="borrows.php">จัดการการยืม</a></li>
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
                
                <form id="bookForm" method="POST">
                    <input type="hidden" id="bookId" name="book_id">
                    <input type="hidden" id="formAction" name="action" value="add_book">
                    
                    <div class="form-group">
                        <label class="form-label" for="isbn">
                            <i class="fas fa-barcode"></i> ISBN
                        </label>
                        <input type="text" id="isbn" name="isbn" class="form-control" placeholder="เช่น 978-0-7475-3269-9">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="title">
                            <i class="fas fa-book"></i> ชื่อหนังสือ *
                        </label>
                        <input type="text" id="title" name="title" class="form-control" required placeholder="ระบุชื่อหนังสือ">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="category">
                            <i class="fas fa-tags"></i> หมวดหมู่
                        </label>
                        <div class="input-group">
                            <select id="category" name="category_id" class="form-control">
                                <option value="">เลือกหมวดหมู่</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-secondary btn-icon" onclick="openModal('categoryModal')">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="publisher">
                            <i class="fas fa-building"></i> สำนักพิมพ์
                        </label>
                        <div class="input-group">
                            <select id="publisher" name="publisher_id" class="form-control">
                                <option value="">เลือกสำนักพิมพ์</option>
                                <?php foreach ($publishers as $pub): ?>
                                    <option value="<?= $pub['publisher_id'] ?>"><?= htmlspecialchars($pub['publisher_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-secondary btn-icon" onclick="openModal('publisherModal')">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="publicationYear">
                            <i class="fas fa-calendar"></i> ปีที่พิมพ์
                        </label>
                        <input type="number" id="publicationYear" name="publication_year" class="form-control" min="1900" max="2024" placeholder="เช่น 2023">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="totalCopies">
                            <i class="fas fa-copy"></i> จำนวนเล่ม
                        </label>
                        <input type="number" id="totalCopies" name="total_copies" class="form-control" min="1" value="1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-user-edit"></i> ผู้เขียน
                            <button type="button" class="btn btn-secondary btn-icon" onclick="openModal('authorModal')" style="margin-left: 10px;">
                                <i class="fas fa-plus"></i>
                            </button>
                        </label>
                        <div id="authorsContainer">
                            <div class="input-group" style="margin-bottom: 10px;">
                                <select name="author_ids[]" class="form-control">
                                    <option value="">เลือกผู้เขียน</option>
                                    <?php foreach ($authors as $author): ?>
                                        <option value="<?= $author['author_id'] ?>"><?= htmlspecialchars($author['first_name'] . ' ' . $author['last_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="author_roles[]" class="form-control" style="max-width: 120px;">
                                    <option value="author">ผู้เขียน</option>
                                    <option value="co-author">ผู้เขียนร่วม</option>
                                    <option value="editor">บรรณาธิการ</option>
                                    <option value="translator">ผู้แปล</option>
                                </select>
                                <button type="button" class="btn btn-danger btn-icon" onclick="removeAuthor(this)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="addAuthor()" style="margin-top: 10px;">
                            <i class="fas fa-plus"></i> เพิ่มผู้เขียน
                        </button>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i>
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
                                    <div>
                                        <div class="book-title"><?= htmlspecialchars($book['title']) ?></div>
                                        <div class="book-isbn">ISBN: <?= htmlspecialchars($book['isbn'] ?: 'ไม่ระบุ') ?></div>
                                    </div>
                                    <div class="status-badge <?= $book['status'] === 'available' ? 'status-available' : 'status-unavailable' ?>">
                                        <?= $book['status'] === 'available' ? 'พร้อมใช้งาน' : 'ไม่พร้อมใช้งาน' ?>
                                    </div>
                                </div>
                                
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
                                    <button class="btn btn-danger" onclick="deleteBook(<?= $book['book_id'] ?>)">
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
                    <label class="form-label" for="categoryName">ชื่อหมวดหมู่ *</label>
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
                    <label class="form-label" for="publisherName">ชื่อสำนักพิมพ์ *</label>
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
                    <label class="form-label" for="authorName">ชื่อผู้เขียน *</label>
                    <input type="text" id="authorName" name="author_name" class="form-control" required placeholder="เช่น นายสมชาย ใจดี">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> บันทึกผู้เขียน
                </button>
            </form>
        </div>
    </div>

    <script>
        // JavaScript functions
        function addAuthor() {
            const container = document.getElementById('authorsContainer');
            const authorDiv = document.createElement('div');
            authorDiv.className = 'input-group';
            authorDiv.style.marginBottom = '10px';
            authorDiv.innerHTML = `
                <select name="author_ids[]" class="form-control">
                    <option value="">เลือกผู้เขียน</option>
                    <?php foreach ($authors as $author): ?>
                        <option value="<?= $author['author_id'] ?>"><?= htmlspecialchars($author['first_name'] . ' ' . $author['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="author_roles[]" class="form-control" style="max-width: 120px;">
                    <option value="author">ผู้เขียน</option>
                    <option value="co-author">ผู้เขียนร่วม</option>
                    <option value="editor">บรรณาธิการ</option>
                    <option value="translator">ผู้แปล</option>
                </select>
                <button type="button" class="btn btn-danger btn-icon" onclick="removeAuthor(this)">
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

        function editBook(bookId) {
            <?php if (isset($_GET['edit'])): 
                $edit_book_id = $_GET['edit'];
                $stmt = $db->prepare("
                    SELECT b.*, ba.author_id, ba.role 
                    FROM books b 
                    LEFT JOIN book_authors ba ON b.book_id = ba.book_id 
                    WHERE b.book_id = ?
                ");
                $stmt->execute([$edit_book_id]);
                $edit_book = $stmt->fetch();
                $book_authors = $stmt->fetchAll();
            ?>
                if (bookId === <?= $edit_book_id ?>) {
                    document.getElementById('bookId').value = '<?= $edit_book['book_id'] ?>';
                    document.getElementById('formAction').value = 'edit_book';
                    document.getElementById('isbn').value = '<?= $edit_book['isbn'] ?>';
                    document.getElementById('title').value = '<?= htmlspecialchars($edit_book['title']) ?>';
                    document.getElementById('category').value = '<?= $edit_book['category_id'] ?>';
                    document.getElementById('publisher').value = '<?= $edit_book['publisher_id'] ?>';
                    document.getElementById('publicationYear').value = '<?= $edit_book['publication_year'] ?>';
                    document.getElementById('totalCopies').value = '<?= $edit_book['total_copies'] ?>';
                    document.getElementById('formTitle').textContent = 'แก้ไขหนังสือ';
                    document.getElementById('submitText').textContent = 'บันทึกการแก้ไข';
                    
                    // รีเซ็ตและเติมผู้เขียน
                    const container = document.getElementById('authorsContainer');
                    container.innerHTML = '';
                    <?php foreach ($book_authors as $index => $ba): ?>
                        container.innerHTML += `
                            <div class="input-group" style="margin-bottom: 10px;">
                                <select name="author_ids[]" class="form-control">
                                    <option value="">เลือกผู้เขียน</option>
                                    <?php foreach ($authors as $author): ?>
                                        <option value="<?= $author['author_id'] ?>" <?= $author['author_id'] == $ba['author_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($author['first_name'] . ' ' . $author['last_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="author_roles[]" class="form-control" style="max-width: 120px;">
                                    <option value="author" <?= $ba['role'] == 'author' ? 'selected' : '' ?>>ผู้เขียน</option>
                                    <option value="co-author" <?= $ba['role'] == 'co-author' ? 'selected' : '' ?>>ผู้เขียนร่วม</option>
                                    <option value="editor" <?= $ba['role'] == 'editor' ? 'selected' : '' ?>>บรรณาธิการ</option>
                                    <option value="translator" <?= $ba['role'] == 'translator' ? 'selected' : '' ?>>ผู้แปล</option>
                                </select>
                                <button type="button" class="btn btn-danger btn-icon" onclick="removeAuthor(this)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        `;
                    <?php endforeach; ?>
                    if (container.children.length === 0) addAuthor();
                    
                    // เลื่อนขึ้นด้านบน
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            <?php endif; ?>
            
            if (!<?= isset($_GET['edit']) ? 'true' : 'false' ?>) {
                window.location.href = 'books.php?edit=' + bookId;
            }
        }

        function deleteBook(bookId) {
            if (confirm('คุณแน่ใจหรือไม่ที่จะลบหนังสือนี้?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_book">
                    <input type="hidden" name="book_id" value="${bookId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function resetForm() {
            document.getElementById('bookForm').reset();
            document.getElementById('bookId').value = '';
            document.getElementById('formAction').value = 'add_book';
            document.getElementById('formTitle').textContent = 'เพิ่มหนังสือใหม่';
            document.getElementById('submitText').textContent = 'บันทึกหนังสือ';
            
            // รีเซ็ตผู้เขียนให้เหลือแค่ 1 รายการ
            const container = document.getElementById('authorsContainer');
            container.innerHTML = `
                <div class="input-group" style="margin-bottom: 10px;">
                    <select name="author_ids[]" class="form-control">
                        <option value="">เลือกผู้เขียน</option>
                        <?php foreach ($authors as $author): ?>
                            <option value="<?= $author['author_id'] ?>"><?= htmlspecialchars($author['first_name'] . ' ' . $author['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="author_roles[]" class="form-control" style="max-width: 120px;">
                        <option value="author">ผู้เขียน</option>
                        <option value="co-author">ผู้เขียนร่วม</option>
                        <option value="editor">บรรณาธิการ</option>
                        <option value="translator">ผู้แปล</option>
                    </select>
                    <button type="button" class="btn btn-danger btn-icon" onclick="removeAuthor(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // ปิด modal เมื่อคลิกข้างนอก
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.style.display = 'none';
            }
        });

        // Auto-submit search form
        document.querySelector('input[name="search"]').addEventListener('input', function() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });
    </script>
</body>
</html>