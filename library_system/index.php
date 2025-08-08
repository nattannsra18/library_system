<?php
session_start();

// Database connection
$host = 'localhost';
$dbname = 'library_system';
$username = 'root'; // แก้ไขตามการตั้งค่าของคุณ
$password = '';     // แก้ไขตามการตั้งค่าของคุณ

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get search parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_id = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 12;
$offset = ($page - 1) * $per_page;

// Get all categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY category_name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build query for books
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(b.title LIKE :search OR b.subtitle LIKE :search OR b.isbn LIKE :search OR 
                          a.first_name LIKE :search OR a.last_name LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($category_id > 0) {
    $where_conditions[] = "b.category_id = :category_id";
    $params[':category_id'] = $category_id;
}

$where_conditions[] = "b.status = 'available'";

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get books with pagination
$sql = "SELECT DISTINCT b.*, c.category_name, p.publisher_name,
               GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.category_id
        LEFT JOIN publishers p ON b.publisher_id = p.publisher_id
        LEFT JOIN book_authors ba ON b.book_id = ba.book_id
        LEFT JOIN authors a ON ba.author_id = a.author_id
        $where_sql
        GROUP BY b.book_id
        ORDER BY b.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_sql = "SELECT COUNT(DISTINCT b.book_id) as total
              FROM books b
              LEFT JOIN book_authors ba ON b.book_id = ba.book_id
              LEFT JOIN authors a ON ba.author_id = a.author_id
              $where_sql";

$stmt = $pdo->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_books = $stmt->fetchColumn();
$total_pages = ceil($total_books / $per_page);

// Get library stats
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM books WHERE status = 'available') as total_books,
    (SELECT COUNT(*) FROM users WHERE status = 'active') as total_users,
    (SELECT COUNT(*) FROM borrowing) as total_borrows";
$stmt = $pdo->query($stats_query);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ห้องสมุดดิจิทัล - วิทยาลัยเทคนิคหาดใหญ่</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
        }

        /* Header */
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
            transition: all 0.3s ease;
            padding: 0.5rem 1rem;
            border-radius: 8px;
        }

        .nav-links a:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .btn-login {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.8rem 1.5rem;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 25px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-login:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Search Section */
        .search-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 120px 0 60px;
            text-align: center;
        }

        .search-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .search-section h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .search-section p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }

        .search-form {
            background: white;
            border-radius: 50px;
            padding: 10px;
            display: flex;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 2rem;
        }

        .search-input {
            flex: 1;
            border: none;
            padding: 1rem 1.5rem;
            font-size: 1.1rem;
            border-radius: 40px;
            outline: none;
        }

        .search-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 40px;
            cursor: pointer;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }

        .search-btn:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }

        .category-filters {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .category-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 0.5rem 1rem;
            border-radius: 25px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .category-btn:hover, .category-btn.active {
            background: white;
            color: #667eea;
        }

        /* Stats Section */
        .stats {
            background: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }

        .stats-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
        }

        .stat-card {
            text-align: center;
            padding: 1.5rem;
            border-radius: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 1rem;
            opacity: 0.9;
        }

        /* Books Section */
        .books-section {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem 4rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 2rem;
            color: #333;
        }

        .results-info {
            color: #666;
            font-size: 1.1rem;
        }

        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .book-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
        }

        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }

        .book-image {
            height: 250px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 4rem;
        }

        .book-info {
            padding: 1.5rem;
        }

        .book-title {
            font-size: 1.2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #333;
            line-height: 1.3;
            height: 2.6rem;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .book-author {
            color: #666;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .book-category {
            display: inline-block;
            background: #e3f2fd;
            color: #1976d2;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            margin-bottom: 1rem;
        }

        .book-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #666;
        }

        .availability {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .available {
            color: #4caf50;
        }

        .unavailable {
            color: #f44336;
        }

        .book-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-borrow {
            flex: 1;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.8rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .btn-borrow:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        .btn-borrow:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .btn-details {
            background: #f5f5f5;
            color: #666;
            border: none;
            padding: 0.8rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-details:hover {
            background: #e0e0e0;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination a, .pagination span {
            padding: 0.8rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .pagination a {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .pagination a:hover {
            background: #667eea;
            color: white;
        }

        .pagination .current {
            background: #667eea;
            color: white;
            border: 2px solid #667eea;
        }

        /* No results */
        .no-results {
            text-align: center;
            padding: 4rem 2rem;
            color: #666;
        }

        .no-results i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 1rem;
        }

        .no-results h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        /* Footer */
        .footer {
            background: #2c3e50;
            color: white;
            text-align: center;
            padding: 2rem 0;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }

            .search-section h1 {
                font-size: 2rem;
            }

            .search-form {
                flex-direction: column;
                border-radius: 15px;
            }

            .search-input, .search-btn {
                border-radius: 10px;
            }

            .category-filters {
                justify-content: flex-start;
                overflow-x: auto;
                padding-bottom: 1rem;
            }

            .books-grid {
                grid-template-columns: 1fr;
            }

            .section-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .nav-container, .search-container, .stats-container, .books-section {
                padding: 0 1rem;
            }
        }

        /* Loading Animation */
        .loading {
            display: none;
            text-align: center;
            padding: 2rem;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Login Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 2rem;
            border-radius: 15px;
            width: 90%;
            max-width: 400px;
            text-align: center;
        }

        .modal-content h3 {
            margin-bottom: 1rem;
            color: #333;
        }

        .modal-content p {
            margin-bottom: 2rem;
            color: #666;
        }

        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        .btn-modal {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .btn-primary-modal {
            background: #667eea;
            color: white;
        }

        .btn-secondary-modal {
            background: #f5f5f5;
            color: #666;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <nav class="nav-container">
            <div class="logo">
                <i class="fas fa-book-open"></i>
                <span>ห้องสมุดดิจิทัล</span>
            </div>
            <ul class="nav-links">
                <li><a href="#home">หน้าแรก</a></li>
                <li><a href="#books">หนังสือ</a></li>
            </ul>
            <div class="user-menu">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <span>สวัสดี, <?php echo htmlspecialchars($_SESSION['first_name']); ?></span>
                    <a href="logout.php" class="btn-login">ออกจากระบบ</a>
                <?php else: ?>
                    <a href="login.php" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i>
                        เข้าสู่ระบบ
                    </a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <!-- Search Section -->
    <section class="search-section" id="home">
        <div class="search-container">
            <h1>ห้องสมุดดิจิทัล</h1>
            <p>ค้นหาและยืมหนังสือออนไลน์ วิทยาลัยเทคนิคหาดใหญ่</p>
            
            <form class="search-form" method="GET" action="">
                <input type="text" name="search" class="search-input" 
                       placeholder="ค้นหาหนังสือ ผู้เขียน หรือ ISBN..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="search-btn">
                    <i class="fas fa-search"></i>
                </button>
            </form>

            <div class="category-filters">
                <a href="?" class="category-btn <?php echo $category_id == 0 ? 'active' : ''; ?>">
                    ทั้งหมด
                </a>
                <?php foreach ($categories as $category): ?>
                    <a href="?category=<?php echo $category['category_id']; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
                       class="category-btn <?php echo $category_id == $category['category_id'] ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($category['category_name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats">
        <div class="stats-container">
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-book"></i>
                    <div class="stat-number"><?php echo number_format($stats['total_books']); ?></div>
                    <div class="stat-label">หนังสือทั้งหมด</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-users"></i>
                    <div class="stat-number"><?php echo number_format($stats['total_users']); ?></div>
                    <div class="stat-label">สมาชิกทั้งหมด</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-exchange-alt"></i>
                    <div class="stat-number"><?php echo number_format($stats['total_borrows']); ?></div>
                    <div class="stat-label">การยืมทั้งหมด</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-clock"></i>
                    <div class="stat-number">24/7</div>
                    <div class="stat-label">เปิดให้บริการ</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Books Section -->
    <section class="books-section" id="books">
        <div class="section-header">
            <h2 class="section-title">
                <?php 
                if (!empty($search) || $category_id > 0) {
                    echo "ผลการค้นหา";
                } else {
                    echo "หนังสือทั้งหมด";
                }
                ?>
            </h2>
            <div class="results-info">
                พบ <?php echo number_format($total_books); ?> เล่ม
                <?php if ($total_pages > 1): ?>
                    (หน้า <?php echo $page; ?> จาก <?php echo $total_pages; ?>)
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($books)): ?>
            <div class="no-results">
                <i class="fas fa-search"></i>
                <h3>ไม่พบหนังสือที่ค้นหา</h3>
                <p>ลองใช้คำค้นหาอื่น หรือเลือกหมวดหมู่ที่แตกต่างกัน</p>
            </div>
        <?php else: ?>
            <div class="books-grid">
                <?php foreach ($books as $book): ?>
                    <div class="book-card">
                        <div class="book-image">
                            <?php if (!empty($book['cover_image'])): ?>
                                <img src="<?php echo htmlspecialchars($book['cover_image']); ?>" 
                                     alt="<?php echo htmlspecialchars($book['title']); ?>"
                                     style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <i class="fas fa-book"></i>
                            <?php endif; ?>
                        </div>
                        
                        <div class="book-info">
                            <h3 class="book-title" title="<?php echo htmlspecialchars($book['title']); ?>">
                                <?php echo htmlspecialchars($book['title']); ?>
                            </h3>
                            
                            <div class="book-author">
                                <i class="fas fa-user"></i>
                                <?php echo htmlspecialchars($book['authors'] ?: 'ไม่ระบุผู้เขียน'); ?>
                            </div>
                            
                            <div class="book-category">
                                <?php echo htmlspecialchars($book['category_name'] ?: 'ไม่ระบุหมวดหมู่'); ?>
                            </div>
                            
                            <div class="book-meta">
                                <span><i class="fas fa-calendar"></i> <?php echo $book['publication_year']; ?></span>
                                <div class="availability <?php echo $book['available_copies'] > 0 ? 'available' : 'unavailable'; ?>">
                                    <i class="fas fa-<?php echo $book['available_copies'] > 0 ? 'check-circle' : 'times-circle'; ?>"></i>
                                    <?php echo $book['available_copies']; ?>/<?php echo $book['total_copies']; ?> เล่ม
                                </div>
                            </div>
                            
                            <div class="book-actions">
                                <button class="btn-borrow" 
                                        onclick="borrowBook(<?php echo $book['book_id']; ?>)"
                                        <?php echo $book['available_copies'] <= 0 ? 'disabled' : ''; ?>>
                                    <i class="fas fa-hand-holding"></i>
                                    <?php echo $book['available_copies'] > 0 ? 'ยืมหนังสือ' : 'ไม่ว่าง'; ?>
                                </button>
                                <button class="btn-details" onclick="showBookDetails(<?php echo $book['book_id']; ?>)">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php
                    $query_params = [];
                    if (!empty($search)) $query_params['search'] = $search;
                    if ($category_id > 0) $query_params['category'] = $category_id;
                    
                    for ($i = 1; $i <= $total_pages; $i++):
                        $query_params['page'] = $i;
                        $url = '?' . http_build_query($query_params);
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo $url; ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Login Modal -->
    <div id="loginModal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-lock"></i> จำเป็นต้องเข้าสู่ระบบ</h3>
            <p>กรุณาเข้าสู่ระบบก่อนยืมหนังสือ</p>
            <div class="modal-buttons">
                <button class="btn-modal btn-primary-modal" onclick="location.href='login.php'">
                    เข้าสู่ระบบ
                </button>
                <button class="btn-modal btn-secondary-modal" onclick="closeModal()">
                    ยกเลิก
                </button>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-container">
            <p>&copy; 2025 ห้องสมุดดิจิทัล - วิทยาลัยเทคนิคหาดใหญ่</p>
        </div>
    </footer>

    <script>
        function borrowBook(bookId) {
            <?php if (isset($_SESSION['user_id'])): ?>
                // User is logged in, proceed with borrowing
                if (confirm('คุณต้องการยืมหนังสือเล่มนี้หรือไม่?')) {
                    // Show loading
                    const loadingDiv = document.createElement('div');
                    loadingDiv.className = 'loading';
                    loadingDiv.innerHTML = '<div class="spinner"></div><p>กำลังดำเนินการ...</p>';
                    loadingDiv.style.display = 'block';
                    document.body.appendChild(loadingDiv);

                    // Send AJAX request to borrow book
                    fetch('borrow_book.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            book_id: bookId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        document.body.removeChild(loadingDiv);
                        if (data.success) {
                            alert('ยืมหนังสือสำเร็จ! กรุณาคืนภายในกำหนด');
                            location.reload(); // Refresh to update availability
                        } else {
                            alert('เกิดข้อผิดพลาด: ' + data.message);
                        }
                    })
                    .catch(error => {
                        document.body.removeChild(loadingDiv);
                        alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
                        console.error('Error:', error);
                    });
                }
            <?php else: ?>
                // User not logged in, show login modal
                document.getElementById('loginModal').style.display = 'block';
            <?php endif; ?>
        }

        function showBookDetails(bookId) {
            // Redirect to book details page
            window.location.href = 'book_details.php?id=' + bookId;
        }

        function closeModal() {
            document.getElementById('loginModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('loginModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Search form enhancement
        document.querySelector('.search-form').addEventListener('submit', function(e) {
            const searchInput = document.querySelector('.search-input');
            if (searchInput.value.trim() === '') {
                e.preventDefault();
                searchInput.focus();
                return;
            }
            
            // Show loading state
            const searchBtn = document.querySelector('.search-btn');
            const originalText = searchBtn.innerHTML;
            searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            searchBtn.disabled = true;
            
            // Re-enable button after a short delay if form doesn't submit
            setTimeout(() => {
                searchBtn.innerHTML = originalText;
                searchBtn.disabled = false;
            }, 3000);
        });

        // Add scroll effect to header
        window.addEventListener('scroll', function() {
            const header = document.querySelector('.header');
            if (window.scrollY > 100) {
                header.style.background = 'rgba(102, 126, 234, 0.95)';
                header.style.backdropFilter = 'blur(10px)';
            } else {
                header.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
                header.style.backdropFilter = 'none';
            }
        });

        // Auto-hide alerts/messages
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });

        // Book card hover effects
        document.querySelectorAll('.book-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                document.querySelector('.search-input').focus();
            }
            
            // Escape to close modal
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Add loading states to buttons
        document.querySelectorAll('.btn-borrow').forEach(btn => {
            btn.addEventListener('click', function() {
                if (!this.disabled) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังดำเนินการ...';
                    this.disabled = true;
                    
                    // Reset if no AJAX call is made
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.disabled = false;
                    }, 3000);
                }
            });
        });

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '0';
                    entry.target.style.transform = 'translateY(20px)';
                    entry.target.style.transition = 'all 0.6s ease';
                    
                    setTimeout(() => {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }, 100);
                    
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        // Observe book cards for animation
        document.querySelectorAll('.book-card').forEach(card => {
            observer.observe(card);
        });

        // Mobile menu toggle (placeholder for future implementation)
        const mobileMenu = document.querySelector('.mobile-menu');
        if (mobileMenu) {
            mobileMenu.addEventListener('click', function() {
                // Toggle mobile navigation
                const navLinks = document.querySelector('.nav-links');
                navLinks.style.display = navLinks.style.display === 'flex' ? 'none' : 'flex';
            });
        }

        // Add to favorites functionality (placeholder)
        function addToFavorites(bookId) {
            <?php if (isset($_SESSION['user_id'])): ?>
                fetch('add_favorite.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        book_id: bookId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('เพิ่มในรายการโปรดแล้ว');
                    } else {
                        alert('เกิดข้อผิดพลาด: ' + data.message);
                    }
                });
            <?php else: ?>
                document.getElementById('loginModal').style.display = 'block';
            <?php endif; ?>
        }

        // Enhanced search with suggestions (future feature)
        let searchTimeout;
        const searchInput = document.querySelector('.search-input');
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length >= 2) {
                searchTimeout = setTimeout(() => {
                    // Future: Show search suggestions
                    console.log('Search suggestions for:', query);
                }, 300);
            }
        });

        // Print functionality
        function printBookList() {
            window.print();
        }

        // Export functionality (placeholder)
        function exportBookList() {
            alert('ฟีเจอร์นี้จะมาในเร็วๆ นี้');
        }

        console.log('%cห้องสมุดดิจิทัล - วิทยาลัยเทคนิคหาดใหญ่', 'color: #667eea; font-size: 20px; font-weight: bold;');
        console.log('พัฒนาโดย นายปิยพัชร์ ทองวงศ์');
    </script>
</body>
</html>