<?php
session_start();

// Database connection
$host = 'localhost';
$dbname = 'library_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user information
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get current borrowed books (รวมทั้งสถานะ pending_return)
$stmt = $pdo->prepare("
    SELECT b.*, bo.title, bo.cover_image, c.category_name,
           CONCAT(a.first_name, ' ', a.last_name) as author_name,
           DATEDIFF(b.due_date, NOW()) as days_remaining
    FROM borrowing b 
    JOIN books bo ON b.book_id = bo.book_id 
    LEFT JOIN categories c ON bo.category_id = c.category_id
    LEFT JOIN book_authors ba ON bo.book_id = ba.book_id
    LEFT JOIN authors a ON ba.author_id = a.author_id
    WHERE b.user_id = ? AND b.status IN ('borrowed', 'overdue', 'pending_return')
    ORDER BY b.due_date ASC
");
$stmt->execute([$user_id]);
$current_borrows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current active reservations
$stmt = $pdo->prepare("
    SELECT r.*, bo.title, bo.cover_image, c.category_name
    FROM reservations r
    JOIN books bo ON r.book_id = bo.book_id
    LEFT JOIN categories c ON bo.category_id = c.category_id
    WHERE r.user_id = ? AND r.status = 'active'
    ORDER BY r.reservation_date DESC
");
$stmt->execute([$user_id]);
$current_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get notifications
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = FALSE 
    ORDER BY sent_date DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get borrowing statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_borrowed,
        COUNT(CASE WHEN status = 'returned' THEN 1 END) as total_returned,
        COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count,
        COUNT(CASE WHEN status = 'pending_return' THEN 1 END) as pending_return_count
    FROM borrowing 
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent returned books
$stmt = $pdo->prepare("
    SELECT b.*, bo.title, bo.cover_image
    FROM borrowing b 
    JOIN books bo ON b.book_id = bo.book_id 
    WHERE b.user_id = ? AND b.status = 'returned'
    ORDER BY b.return_date DESC
    LIMIT 3
");
$stmt->execute([$user_id]);
$recent_returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recommended books
$stmt = $pdo->prepare("
    SELECT bo.*, c.category_name
    FROM books bo
    LEFT JOIN categories c ON bo.category_id = c.category_id
    WHERE bo.status = 'available'
    ORDER BY RAND()
    LIMIT 4
");
$stmt->execute();
$recommended_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แดชบอร์ดผู้ใช้ - ห้องสมุดดิจิทัล</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .nav-links a:hover, .nav-links a.active {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
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

        /* Main Content */
        .dashboard-container {
            max-width: 1200px;
            margin: 100px auto 20px;
            padding: 0 20px;
        }

        /* Welcome Section */
        .welcome-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .profile-img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
        }

        .welcome-text h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .welcome-text p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .stat-card.borrowed i { color: #667eea; }
        .stat-card.returned i { color: #4caf50; }
        .stat-card.overdue i { color: #f44336; }
        .stat-card.pending i { color: #ff9800; }
        .stat-card.reserved i { color: #9c27b0; }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        /* ======================================
           IMPROVED BOOKS SECTION CSS
           ====================================== */

        /* Books Section Container - Glass Morphism */
        .dashboard-grid .card:first-child {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border-radius: 20px;
            overflow: hidden;
            position: relative;
        }

        /* Gradient overlay สำหรับความลึก */
        .dashboard-grid .card:first-child::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, 
                rgba(102, 126, 234, 0.02) 0%, 
                rgba(118, 75, 162, 0.02) 50%,
                rgba(102, 126, 234, 0.04) 100%);
            pointer-events: none;
            z-index: -1;
        }

        /* Tabs - ลบการแยก */
        .tabs {
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            margin-bottom: 0;
            overflow: hidden;
            padding: 1rem 1rem 0 1rem;
            position: relative;
            display: flex;
        }

        /* เส้นแบ่งใต้ tabs */
        .tabs::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 1rem;
            right: 1rem;
            height: 1px;
            background: linear-gradient(90deg, 
                transparent 0%, 
                rgba(102, 126, 234, 0.2) 50%, 
                transparent 100%);
        }

        /* Tab buttons */
        .tab {
            flex: 1;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px 12px 0 0;
            margin-right: 0.5rem;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            font-weight: 500;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transform-origin: bottom;
        }

        .tab:last-child {
            margin-right: 0;
        }

        /* Tab hover effect */
        .tab::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, 
                rgba(102, 126, 234, 0.05) 0%, 
                rgba(118, 75, 162, 0.05) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: inherit;
        }

        .tab:hover::before {
            opacity: 1;
        }

        .tab:hover:not(.active) {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, 0.15);
        }

        /* Active tab */
        .tab.active {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-color: rgba(102, 126, 234, 0.3);
            transform: translateY(-3px) scale(1.02);
            box-shadow: 
                0 8px 25px rgba(102, 126, 234, 0.15),
                0 3px 10px rgba(0, 0, 0, 0.1);
            color: #667eea;
            font-weight: 600;
        }

        .tab.active::before {
            background: linear-gradient(135deg, 
                rgba(102, 126, 234, 0.08) 0%, 
                rgba(118, 75, 162, 0.08) 100%);
            opacity: 1;
        }

        /* Tab badges */
        .tab-badge {
            background: rgba(102, 126, 234, 0.8);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-left: 0.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }

        .tab.active .tab-badge {
            background: rgba(102, 126, 234, 0.9);
            color: white;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        /* Tab Content */
        .tab-content {
            display: none;
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            padding: 2rem;
            max-height: 500px;
            overflow-y: auto;
            position: relative;
        }

        .tab-content.active {
            display: block;
        }

        /* Background pattern สำหรับ tab content */
        .tab-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                radial-gradient(circle at 25% 25%, rgba(102, 126, 234, 0.02) 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(118, 75, 162, 0.02) 0%, transparent 50%);
            pointer-events: none;
            z-index: -1;
        }

        /* Book Items */
        .book-item {
            display: flex;
            gap: 1rem;
            padding: 1.5rem;
            position: relative;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            border-radius: 16px;
            margin-bottom: 1.5rem;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }

        .book-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, 
                rgba(102, 126, 234, 0.03) 0%, 
                rgba(118, 75, 162, 0.03) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .book-item:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 
                0 20px 40px rgba(0, 0, 0, 0.1),
                0 8px 25px rgba(102, 126, 234, 0.15);
            border-color: rgba(102, 126, 234, 0.3);
        }

        .book-item:hover::before {
            opacity: 1;
        }

        .book-item:last-child {
            margin-bottom: 0;
        }

        /* Book Cover */
        .book-cover {
            width: 70px;
            height: 95px;
            border-radius: 10px;
            object-fit: cover;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            overflow: hidden;
        }

        .book-cover::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, 
                rgba(255, 255, 255, 0.1) 0%, 
                rgba(255, 255, 255, 0.05) 50%,
                rgba(0, 0, 0, 0.1) 100%);
            pointer-events: none;
        }

        .book-item:hover .book-cover {
            transform: scale(1.08) rotateY(5deg);
            filter: brightness(1.1);
        }

        /* Book Details */
        .book-details {
            flex: 1;
        }

        .book-details h4 {
            font-size: 1.2rem;
            color: #333;
            margin-bottom: 0.8rem;
            font-weight: 600;
            line-height: 1.3;
            position: relative;
            overflow: hidden;
        }

        .book-item:hover .book-details h4::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, 
                #667eea 0%, 
                #764ba2 100%);
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { width: 0; }
            to { width: 100%; }
        }

        .book-details p {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .book-details p i {
            color: #667eea;
            width: 16px;
        }

        /* Book Actions */
        .book-actions {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-end;
        }

        /* Due Date Badges */
        .due-date {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .due-soon {
            background: #fff3cd;
            color: #856404;
        }

        .overdue {
            background: #f8d7da;
            color: #721c24;
        }

        .normal {
            background: #d1ecf1;
            color: #0c5460;
        }

        .pending-return {
            background: #e2e3e5;
            color: #383d41;
        }

        .reserved-status {
            background: #e1bee7;
            color: #4a148c;
        }

        /* Buttons with Glass Effect */
        .btn-return {
            background: #28a745;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }

        .btn-return::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, 
                transparent 0%, 
                rgba(255, 255, 255, 0.2) 50%, 
                transparent 100%);
            transition: left 0.5s ease;
        }

        .btn-return:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-return:hover::before {
            left: 100%;
        }

        .btn-cancel {
            background: #dc3545;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }

        .btn-cancel::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, 
                transparent 0%, 
                rgba(255, 255, 255, 0.2) 50%, 
                transparent 100%);
            transition: left 0.5s ease;
        }

        .btn-cancel:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .btn-cancel:hover::before {
            left: 100%;
        }

        .btn-pending {
            background: #6c757d;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.8rem;
            cursor: default;
            backdrop-filter: blur(10px);
        }

        /* ======================================
           IMPROVED NOTIFICATIONS SECTION CSS
           ====================================== */

        /* Notifications Card Container */
        .dashboard-grid .card:last-child {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border-radius: 20px;
            overflow: hidden;
            position: relative;
        }

        /* Gradient overlay สำหรับ notifications card */
        .dashboard-grid .card:last-child::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, 
                rgba(102, 126, 234, 0.02) 0%, 
                rgba(118, 75, 162, 0.02) 50%,
                rgba(102, 126, 234, 0.04) 100%);
            pointer-events: none;
            z-index: -1;
        }

        /* Card Header for Notifications */
        .card-header {
            background: transparent;
            color: #333;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
        }

        .card-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, 
                rgba(102, 126, 234, 0.05) 0%, 
                rgba(118, 75, 162, 0.05) 100%);
            border-radius: 20px 20px 0 0;
        }

        .card-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            position: relative;
            z-index: 1;
        }

        .card-header i {
            font-size: 1.5rem;
            color: #667eea;
            position: relative;
            z-index: 1;
            animation: bellShake 2s infinite;
        }

        @keyframes bellShake {
            0%, 50%, 100% { transform: rotate(0deg); }
            10%, 30% { transform: rotate(-10deg); }
            20%, 40% { transform: rotate(10deg); }
        }

        /* Card Content for Notifications */
        .card-content {
            padding: 0;
            max-height: 400px;
            overflow-y: auto;
            background: transparent;
        }

        /* Custom Scrollbar */
        .card-content::-webkit-scrollbar {
            width: 6px;
        }

        .card-content::-webkit-scrollbar-track {
            background: rgba(102, 126, 234, 0.1);
            border-radius: 3px;
        }

        .card-content::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 3px;
        }

        .card-content::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #5a67d8, #6b46c1);
        }

        /* Notification Items */
        .notification-item {
            display: flex;
            gap: 1rem;
            padding: 1.5rem;
            border-bottom: 1px solid rgba(102, 126, 234, 0.08);
            position: relative;
            transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .notification-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, 
                rgba(102, 126, 234, 0.02) 0%, 
                transparent 50%,
                rgba(118, 75, 162, 0.02) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .notification-item:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(5px);
        }

        .notification-item:hover::before {
            opacity: 1;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        /* Notification Icon */
        .notification-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
            position: relative;
            flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }

        .notification-icon::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            border-radius: 50%;
            background: linear-gradient(45deg, 
                rgba(255, 255, 255, 0.2) 0%, 
                transparent 50%, 
                rgba(255, 255, 255, 0.1) 100%);
            z-index: -1;
        }

        .notification-item:hover .notification-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .notification-icon.warning { 
            background: linear-gradient(135deg, #ff9800, #f57c00);
        }

        .notification-icon.success { 
            background: linear-gradient(135deg, #4caf50, #2e7d32);
        }

        .notification-icon.info { 
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .notification-icon.error { 
            background: linear-gradient(135deg, #f44336, #d32f2f);
        }

        /* Notification Content */
        .notification-content {
            flex: 1;
            min-width: 0;
        }

        .notification-content h4 {
            font-size: 1rem;
            color: #333;
            margin-bottom: 0.5rem;
            font-weight: 600;
            line-height: 1.3;
            position: relative;
        }

        .notification-content p {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            line-height: 1.4;
            word-wrap: break-word;
        }

        .notification-time {
            color: #999;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-weight: 500;
        }

        .notification-time i {
            font-size: 0.7rem;
            color: #667eea;
        }

        /* Enhanced Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #666;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 2px dashed rgba(102, 126, 234, 0.2);
            border-radius: 20px;
            margin: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .empty-state::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, 
                transparent 30%, 
                rgba(102, 126, 234, 0.03) 50%, 
                transparent 70%);
            animation: shimmer 4s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(0deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(360deg); }
        }

        .empty-state i {
            font-size: 3.5rem;
            color: rgba(102, 126, 234, 0.3);
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }

        .empty-state h4 {
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }

        .empty-state p {
            font-size: 0.95rem;
            color: #666;
            position: relative;
            z-index: 1;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .quick-action-btn {
            background: white;
            border: 2px solid #667eea;
            color: #667eea;
            padding: 1.2rem 2rem;
            border-radius: 15px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            font-weight: 600;
            font-size: 1rem;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.1);
            position: relative;
            overflow: hidden;
        }

        .quick-action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: left 0.4s ease;
            z-index: -1;
        }

        .quick-action-btn:hover {
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            border-color: transparent;
        }

        .quick-action-btn:hover::before {
            left: 0;
        }

        .quick-action-btn i {
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }

        .quick-action-btn:hover i {
            transform: scale(1.1);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease-out;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }

        .close {
            color: white;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
            background: none;
            border: none;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-book-info {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .modal-book-cover {
            width: 60px;
            height: 80px;
            border-radius: 8px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .modal-book-details h4 {
            margin: 0 0 0.5rem 0;
            color: #333;
        }

        .modal-book-details p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .btn-modal {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-modal-primary {
            background: #28a745;
            color: white;
        }

        .btn-modal-primary:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-modal-danger {
            background: #dc3545;
            color: white;
        }

        .btn-modal-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .btn-modal-secondary {
            background: #f8f9fa;
            color: #666;
            border: 2px solid #e1e5e9;
        }

        .btn-modal-secondary:hover {
            background: #e9ecef;
        }

        /* Loading Overlay */
        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            color: white;
        }

        .spinner {
            border: 4px solid rgba(255,255,255,0.3);
            border-top: 4px solid white;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin-bottom: 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Notification Toast */
        .notification-toast {
            position: fixed;
            top: 100px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            z-index: 9998;
            transform: translateX(100%);
            transition: transform 0.3s ease;
        }

        .notification-toast.show {
            transform: translateX(0);
        }

        .notification-toast.success {
            background: #28a745;
        }

        .notification-toast.error {
            background: #dc3545;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 0 1rem;
                margin-top: 80px;
            }

            .nav-container {
                padding: 0 1rem;
            }

            .nav-links {
                display: none;
            }

            .welcome-section {
                flex-direction: column;
                text-align: center;
            }

            .welcome-text h1 {
                font-size: 2rem;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                flex-direction: column;
            }

            .book-item {
                flex-direction: column;
                gap: 1rem;
            }

            .book-actions {
                align-items: stretch;
                flex-direction: row;
                justify-content: space-between;
            }

            .modal-content {
                margin: 10% auto;
                width: 95%;
            }

            .modal-actions {
                flex-direction: column;
            }

            .tab-content {
                padding: 1.5rem 1rem;
                max-height: 350px;
            }
            
            .tabs {
                padding: 0.5rem;
            }
            
            .tab {
                margin-right: 0.25rem;
                font-size: 0.9rem;
                padding: 0.8rem 0.5rem;
            }
            
            .empty-state {
                margin: 1rem;
                padding: 2rem 1rem;
            }

            .notification-item {
                padding: 1rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.8rem;
            }
            
            .notification-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
            
            .notification-content h4 {
                font-size: 0.95rem;
            }
            
            .notification-content p {
                font-size: 0.85rem;
            }

            .card-header {
                padding: 1rem;
            }
            
            .card-header h3 {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 480px) {
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .tab {
                font-size: 0.8rem;
                gap: 0.3rem;
            }
            
            .tab span:not(.tab-badge) {
                display: none;
            }
            
            .tab i {
                font-size: 1.2rem;
            }

            .notification-item {
                padding: 0.8rem;
            }
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
                <li><a href="dashboard.php" class="active">แดชบอร์ด</a></li>
                <li><a href="index_user.php">หน้าแรก</a></li>
                <li><a href="profile.php">โปรไฟล์</a></li>
                <li><a href="history.php">ประวัติการยืม</a></li>
                <li><a href="search.php">ค้นหาหนังสือ</a></li>
            </ul>
            <div class="user-menu">
                <a href="../logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    ออกจากระบบ
                </a>
            </div>
        </nav>
    </header>

    <!-- Main Content -->
    <div class="dashboard-container">
        <!-- Welcome Section -->
        <section class="welcome-section">
            <div class="profile-img">
                <?php if (!empty($user['profile_image']) && file_exists($user['profile_image'])): ?>
                    <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <div class="welcome-text">
                <h1>ยินดีต้อนรับ, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h1>
                <p>รหัสนักเรียน: <?php echo htmlspecialchars($user['student_id']); ?> | แผนก: <?php echo htmlspecialchars($user['department'] ?: 'ไม่ระบุ'); ?></p>
                <p>สมาชิกตั้งแต่: <?php echo date('d/m/Y', strtotime($user['created_at'])); ?></p>
                <div style="margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 10px; backdrop-filter: blur(10px);">
                    <p style="margin: 0; font-size: 0.95rem; opacity: 0.9;">
                        <i class="fas fa-calendar-day"></i> วันนี้: <?php echo date('l, d F Y', strtotime('today')); ?> | 
                        <i class="fas fa-clock"></i> เวลา: <span id="current-time"></span>
                    </p>
                </div>
            </div>
        </section>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="index_user.php" class="quick-action-btn">
                <i class="fas fa-search"></i>
                ค้นหาหนังสือ
            </a>
            <a href="history.php" class="quick-action-btn">
                <i class="fas fa-history"></i>
                ประวัติการยืม
            </a>
            <a href="profile.php" class="quick-action-btn">
                <i class="fas fa-user-edit"></i>
                แก้ไขโปรไฟล์
            </a>
        </div>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card borrowed">
                <i class="fas fa-book-reader"></i>
                <div class="stat-number"><?php echo count(array_filter($current_borrows, fn($b) => $b['status'] === 'borrowed')); ?></div>
                <div class="stat-label">กำลังยืมอยู่</div>
            </div>
            <div class="stat-card pending">
                <i class="fas fa-clock"></i>
                <div class="stat-number"><?php echo $stats['pending_return_count']; ?></div>
                <div class="stat-label">รอยืนยันการคืน</div>
            </div>
            <div class="stat-card reserved">
                <i class="fas fa-bookmark"></i>
                <div class="stat-number"><?php echo count($current_reservations); ?></div>
                <div class="stat-label">กำลังจองอยู่</div>
            </div>
            <div class="stat-card returned">
                <i class="fas fa-check-circle"></i>
                <div class="stat-number"><?php echo $stats['total_returned']; ?></div>
                <div class="stat-label">คืนแล้ว</div>
            </div>
            <div class="stat-card overdue">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="stat-number"><?php echo $stats['overdue_count']; ?></div>
                <div class="stat-label">เกินกำหนด</div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Books Section -->
            <div class="card">
                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab active" onclick="showTab('borrowed')">
                        <i class="fas fa-book-reader"></i> 
                        <span>หนังสือที่ยืม</span>
                        <span class="tab-badge"><?php echo count(array_filter($current_borrows, fn($b) => $b['status'] === 'borrowed')); ?></span>
                    </button>
                    <button class="tab" onclick="showTab('pending')">
                        <i class="fas fa-hourglass-half"></i> 
                        <span>รอคืน</span>
                        <span class="tab-badge"><?php echo $stats['pending_return_count']; ?></span>
                    </button>
                    <button class="tab" onclick="showTab('reservations')">
                        <i class="fas fa-bookmark"></i> 
                        <span>การจอง</span>
                        <span class="tab-badge"><?php echo count($current_reservations); ?></span>
                    </button>
                </div>

                <!-- Borrowed Books Content -->
                <div id="borrowed-content" class="tab-content active">
                    <?php 
                    $borrowed_books = array_filter($current_borrows, fn($b) => $b['status'] === 'borrowed');
                    if (count($borrowed_books) > 0): ?>
                        <?php foreach ($borrowed_books as $borrow): ?>
                            <div class="book-item">
                                <div class="book-cover">
                                    <?php if (!empty($borrow['cover_image']) && file_exists($borrow['cover_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($borrow['cover_image']); ?>" alt="Book cover" style="width: 100%; height: 100%; border-radius: 10px; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fas fa-book"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="book-details">
                                    <h4><?php echo htmlspecialchars($borrow['title']); ?></h4>
                                    <p><i class="fas fa-user"></i> <?php echo htmlspecialchars($borrow['author_name'] ?: 'ไม่ระบุผู้เขียน'); ?></p>
                                    <p><i class="fas fa-tag"></i> <?php echo htmlspecialchars($borrow['category_name'] ?: 'ไม่ระบุหมวดหมู่'); ?></p>
                                    <p><i class="fas fa-calendar"></i> ยืมเมื่อ: <?php echo date('d/m/Y', strtotime($borrow['borrow_date'])); ?></p>
                                    <p><i class="fas fa-calendar-alt"></i> กำหนดคืน: <?php echo date('d/m/Y', strtotime($borrow['due_date'])); ?></p>
                                </div>
                                <div class="book-actions">
                                    <span class="due-date <?php 
                                        echo $borrow['days_remaining'] < 0 ? 'overdue' : 
                                             ($borrow['days_remaining'] <= 3 ? 'due-soon' : 'normal'); 
                                    ?>">
                                        <?php 
                                        if ($borrow['days_remaining'] < 0) {
                                            echo '<i class="fas fa-exclamation-triangle"></i> เกินกำหนด ' . abs($borrow['days_remaining']) . ' วัน';
                                        } elseif ($borrow['days_remaining'] == 0) {
                                            echo '<i class="fas fa-calendar-day"></i> ครบกำหนดวันนี้';
                                        } else {
                                            echo '<i class="fas fa-calendar-check"></i> เหลือ ' . $borrow['days_remaining'] . ' วัน';
                                        }
                                        ?>
                                    </span>
                                    <button class="btn-return" onclick="showReturnModal(<?php echo $borrow['borrow_id']; ?>, '<?php echo htmlspecialchars($borrow['title']); ?>', '<?php echo htmlspecialchars($borrow['author_name'] ?: 'ไม่ระบุผู้เขียน'); ?>', '<?php echo date('d/m/Y', strtotime($borrow['borrow_date'])); ?>', '<?php echo date('d/m/Y', strtotime($borrow['due_date'])); ?>', '<?php echo htmlspecialchars($borrow['cover_image']); ?>')">
                                        <i class="fas fa-undo"></i> แจ้งคืน
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-book-open"></i>
                            <h4>ไม่มีหนังสือที่กำลังยืมอยู่</h4>
                            <p>ไปค้นหาและยืมหนังสือที่สนใจได้เลย!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pending Return Books Content -->
                <div id="pending-content" class="tab-content">
                    <?php 
                    $pending_books = array_filter($current_borrows, fn($b) => $b['status'] === 'pending_return');
                    if (count($pending_books) > 0): ?>
                        <?php foreach ($pending_books as $borrow): ?>
                            <div class="book-item">
                                <div class="book-cover">
                                    <?php if (!empty($borrow['cover_image']) && file_exists($borrow['cover_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($borrow['cover_image']); ?>" alt="Book cover" style="width: 100%; height: 100%; border-radius: 10px; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fas fa-book"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="book-details">
                                    <h4><?php echo htmlspecialchars($borrow['title']); ?></h4>
                                    <p><i class="fas fa-user"></i> <?php echo htmlspecialchars($borrow['author_name'] ?: 'ไม่ระบุผู้เขียน'); ?></p>
                                    <p><i class="fas fa-tag"></i> <?php echo htmlspecialchars($borrow['category_name'] ?: 'ไม่ระบุหมวดหมู่'); ?></p>
                                    <p><i class="fas fa-calendar"></i> ยืมเมื่อ: <?php echo date('d/m/Y', strtotime($borrow['borrow_date'])); ?></p>
                                    <p><i class="fas fa-calendar-alt"></i> กำหนดคืน: <?php echo date('d/m/Y', strtotime($borrow['due_date'])); ?></p>
                                </div>
                                <div class="book-actions">
                                    <span class="due-date pending-return">
                                        <i class="fas fa-clock"></i> รอแอดมินยืนยัน
                                    </span>
                                    <button class="btn-pending" disabled>
                                        <i class="fas fa-hourglass-half"></i> รอยืนยัน
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-hourglass-half"></i>
                            <h4>ไม่มีหนังสือที่รอยืนยันการคืน</h4>
                            <p>หนังสือที่คุณแจ้งคืนจะแสดงในหน้านี้</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Reservations Content -->
                <div id="reservations-content" class="tab-content">
                    <?php if (count($current_reservations) > 0): ?>
                        <?php foreach ($current_reservations as $reservation): ?>
                            <div class="book-item">
                                <div class="book-cover">
                                    <?php if (!empty($reservation['cover_image']) && file_exists($reservation['cover_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($reservation['cover_image']); ?>" alt="Book cover" style="width: 100%; height: 100%; border-radius: 10px; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fas fa-book"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="book-details">
                                    <h4><?php echo htmlspecialchars($reservation['title']); ?></h4>
                                    <p><i class="fas fa-tag"></i> <?php echo htmlspecialchars($reservation['category_name'] ?: 'ไม่ระบุหมวดหมู่'); ?></p>
                                    <p><i class="fas fa-calendar-plus"></i> จองเมื่อ: <?php echo date('d/m/Y H:i', strtotime($reservation['reservation_date'])); ?></p>
                                    <p><i class="fas fa-calendar-times"></i> หมดอายุ: <?php echo date('d/m/Y H:i', strtotime($reservation['expiry_date'])); ?></p>
                                    <?php
                                    $now = new DateTime();
                                    $expiry = new DateTime($reservation['expiry_date']);
                                    $diff = $now->diff($expiry);
                                    $hours_remaining = $expiry > $now ? 
                                        ($diff->days * 24 + $diff->h) : 0;
                                    ?>
                                    <p><i class="fas fa-hourglass-half"></i> 
                                        <?php if ($hours_remaining > 0): ?>
                                            เหลือเวลา: <?php echo $hours_remaining; ?> ชั่วโมง
                                        <?php else: ?>
                                            <span style="color: #dc3545;">หมดอายุแล้ว</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="book-actions">
                                    <span class="due-date reserved-status">
                                        <i class="fas fa-bookmark"></i> รอแอดมินอนุมัติ
                                    </span>
                                    <?php if ($hours_remaining > 0): ?>
                                        <button class="btn-cancel" onclick="showCancelReservationModal(<?php echo $reservation['reservation_id']; ?>, '<?php echo htmlspecialchars($reservation['title']); ?>', '<?php echo htmlspecialchars($reservation['cover_image']); ?>')">
                                            <i class="fas fa-times"></i> ยกเลิกการจอง
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-pending" disabled>
                                            <i class="fas fa-clock"></i> หมดอายุแล้ว
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bookmark"></i>
                            <h4>ไม่มีการจองหนังสือ</h4>
                            <p>คุณสามารถจองหนังสือที่ต้องการได้จากหน้าค้นหา</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Notifications -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-bell"></i>
                    <h3>การแจ้งเตือน</h3>
                </div>
                <div class="card-content">
                    <?php if (count($notifications) > 0): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item">
                                <div class="notification-icon <?php 
                                    echo match($notification['type'] ?? 'info') {
                                        'overdue' => 'warning',
                                        'returned' => 'success',
                                        'reserved' => 'info',
                                        default => 'info'
                                    };
                                ?>">
                                    <i class="fas fa-<?php 
                                        echo match($notification['type'] ?? 'info') {
                                            'overdue' => 'exclamation-triangle',
                                            'returned' => 'check-circle',
                                            'reserved' => 'bookmark',
                                            default => 'info-circle'
                                        };
                                    ?>"></i>
                                </div>
                                <div class="notification-content">
                                    <h4><?php echo htmlspecialchars($notification['title']); ?></h4>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <div class="notification-time"><?php echo date('d/m/Y H:i', strtotime($notification['sent_date'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <h4>ไม่มีการแจ้งเตือนใหม่</h4>
                            <p>คุณไม่มีการแจ้งเตือนที่ยังไม่ได้อ่าน</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Return Modal -->
    <div id="returnModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-undo"></i> แจ้งคืนหนังสือ</h3>
                <button class="close" onclick="closeModal('returnModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-book-info">
                    <div class="modal-book-cover" id="modal-book-cover">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="modal-book-details" id="modal-book-details">
                        <h4 id="modal-book-title">หนังสือ</h4>
                        <p id="modal-book-author"><i class="fas fa-user"></i> ผู้เขียน</p>
                        <p id="modal-borrow-date"><i class="fas fa-calendar"></i> ยืมเมื่อ: </p>
                        <p id="modal-due-date"><i class="fas fa-calendar-alt"></i> กำหนดคืน: </p>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                    <h4 style="color: #333; margin-bottom: 0.5rem;"><i class="fas fa-info-circle"></i> การแจ้งคืน</h4>
                    <p style="margin: 0.25rem 0; color: #666;">เมื่อคุณกดยืนยัน ระบบจะส่งคำขอคืนหนังสือไปยังแอดมิน</p>
                    <p style="margin: 0.25rem 0; color: #666;">แอดมินจะตรวจสอบสภาพหนังสือและยืนยันการคืน</p>
                    <p style="margin: 0.25rem 0; color: #666;">สถานะจะเปลี่ยนเป็น "รอแอดมินยืนยัน" จนกว่าจะได้รับการอนุมัติ</p>
                </div>

                <div style="background: #d1ecf1; border: 1px solid #bee5eb; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                    <p style="margin: 0; color: #0c5460;"><i class="fas fa-lightbulb"></i> 
                    <strong>คำแนะนำ:</strong> กรุณานำหนังสือไปคืนที่เคาน์เตอร์ห้องสมุด</p>
                </div>

                <div class="modal-actions">
                    <button class="btn-modal btn-modal-secondary" onclick="closeModal('returnModal')">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button class="btn-modal btn-modal-primary" id="confirm-return-btn" onclick="confirmReturn()">
                        <i class="fas fa-undo"></i> ยืนยันแจ้งคืน
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cancel Reservation Modal -->
    <div id="cancelReservationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> ยกเลิกการจองหนังสือ</h3>
                <button class="close" onclick="closeModal('cancelReservationModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-book-info">
                    <div class="modal-book-cover" id="cancel-modal-book-cover">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="modal-book-details">
                        <h4 id="cancel-modal-book-title">หนังสือ</h4>
                        <p><i class="fas fa-bookmark"></i> การจองนี้จะถูกยกเลิก</p>
                    </div>
                </div>
                
                <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                    <p style="margin: 0; color: #856404;"><i class="fas fa-exclamation-triangle"></i> 
                    <strong>คำเตือน:</strong> การยกเลิกการจองไม่สามารถยกเลิกได้ หากต้องการหนังสือเล่มนี้ คุณจะต้องจองใหม่อีกครั้ง</p>
                </div>

                <div class="modal-actions">
                    <button class="btn-modal btn-modal-secondary" onclick="closeModal('cancelReservationModal')">
                        <i class="fas fa-arrow-left"></i> กลับ
                    </button>
                    <button class="btn-modal btn-modal-danger" id="confirm-cancel-btn" onclick="confirmCancelReservation()">
                        <i class="fas fa-times"></i> ยืนยันยกเลิก
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading" id="loadingOverlay">
        <div>
            <div class="spinner"></div>
            <p>กำลังดำเนินการ...</p>
        </div>
    </div>

    <script>
        // Global variables for modal data
        let currentBorrowId = null;
        let currentReservationId = null;

        // Tab switching function
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-content').classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Modal functions
        function showReturnModal(borrowId, title, author, borrowDate, dueDate, coverImage) {
            currentBorrowId = borrowId;
            
            // Update modal content
            document.getElementById('modal-book-title').textContent = title;
            document.getElementById('modal-book-author').innerHTML = '<i class="fas fa-user"></i> ' + author;
            document.getElementById('modal-borrow-date').innerHTML = '<i class="fas fa-calendar"></i> ยืมเมื่อ: ' + borrowDate;
            document.getElementById('modal-due-date').innerHTML = '<i class="fas fa-calendar-alt"></i> กำหนดคืน: ' + dueDate;
            
            // Update book cover
            const coverElement = document.getElementById('modal-book-cover');
            if (coverImage && coverImage !== '') {
                coverElement.innerHTML = `<img src="${coverImage}" alt="Book cover" style="width: 100%; height: 100%; border-radius: 8px; object-fit: cover;">`;
            } else {
                coverElement.innerHTML = '<i class="fas fa-book"></i>';
            }
            
            // Show modal
            document.getElementById('returnModal').style.display = 'block';
        }

        function showCancelReservationModal(reservationId, title, coverImage) {
            currentReservationId = reservationId;
            
            // Update modal content
            document.getElementById('cancel-modal-book-title').textContent = title;
            
            // Update book cover
            const coverElement = document.getElementById('cancel-modal-book-cover');
            if (coverImage && coverImage !== '') {
                coverElement.innerHTML = `<img src="${coverImage}" alt="Book cover" style="width: 100%; height: 100%; border-radius: 8px; object-fit: cover;">`;
            } else {
                coverElement.innerHTML = '<i class="fas fa-book"></i>';
            }
            
            // Show modal
            document.getElementById('cancelReservationModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            currentBorrowId = null;
            currentReservationId = null;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const returnModal = document.getElementById('returnModal');
            const cancelModal = document.getElementById('cancelReservationModal');
            
            if (event.target == returnModal) {
                closeModal('returnModal');
            }
            if (event.target == cancelModal) {
                closeModal('cancelReservationModal');
            }
        }

        // Confirm return function
        function confirmReturn() {
            console.log('confirmReturn called, currentBorrowId:', currentBorrowId);
            
            if (!currentBorrowId || currentBorrowId <= 0) {
                console.error('Invalid currentBorrowId:', currentBorrowId);
                showNotification('เกิดข้อผิดพลาด: ไม่พบรหัสการยืม', 'error');
                return;
            }
            
            console.log('Sending return request for borrow_id:', currentBorrowId);
            
            // เก็บค่า borrowId ไว้ก่อนที่จะรีเซ็ต
            const borrowIdToSend = currentBorrowId;
            
            showLoading();
            closeModal('returnModal'); // ปิด modal หลังจากเก็บค่าแล้ว
            
            const requestData = {
                borrow_id: borrowIdToSend // ใช้ค่าที่เก็บไว้
            };
            
            console.log('Request data:', requestData);
            console.log('Request JSON:', JSON.stringify(requestData));
            
            fetch('request_return.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                return response.text();
            })
            .then(text => {
                console.log('Raw response text:', text);
                try {
                    const data = JSON.parse(text);
                    console.log('Parsed response data:', data);
                    
                    hideLoading();
                    
                    if (data.success) {
                        showNotification('แจ้งคืนหนังสือสำเร็จ! รอแอดมินยืนยัน', 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    } else {
                        showNotification('เกิดข้อผิดพลาด: ' + data.message, 'error');
                    }
                } catch (e) {
                    console.error('JSON parse error:', e);
                    console.error('Raw text that failed to parse:', text);
                    hideLoading();
                    showNotification('เกิดข้อผิดพลาดในการประมวลผลข้อมูล', 'error');
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                hideLoading();
                showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + error.message, 'error');
            });
        }

        // Confirm cancel reservation function
        function confirmCancelReservation() {
            if (!currentReservationId) return;
            
            showLoading();
            closeModal('cancelReservationModal');
            
            fetch('cancel_reservation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    reservation_id: currentReservationId
                })
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showNotification('ยกเลิกการจองเรียบร้อยแล้ว', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showNotification('เกิดข้อผิดพลาด: ' + data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
                console.error('Error:', error);
            });
        }

        // Loading functions
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // Notification function
        function showNotification(message, type = 'success') {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.notification-toast');
            existingNotifications.forEach(notification => notification.remove());

            // Create new notification
            const notification = document.createElement('div');
            notification.className = `notification-toast ${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                ${message}
            `;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            // Hide notification after 5 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 5000);
        }

        // Auto-refresh every 5 minutes
        setInterval(function() {
            location.reload();
        }, 300000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close modals
            if (e.key === 'Escape') {
                closeModal('returnModal');
                closeModal('cancelReservationModal');
            }
            
            // Alt + 1 for borrowed books tab
            if (e.altKey && e.key === '1') {
                e.preventDefault();
                showTab('borrowed');
            }
            
            // Alt + 2 for pending books tab
            if (e.altKey && e.key === '2') {
                e.preventDefault();
                showTab('pending');
            }
            
            // Alt + 3 for reservations tab
            if (e.altKey && e.key === '3') {
                e.preventDefault();
                showTab('reservations');
            }
        });

        // Enhanced animations
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

        // Observe elements for animation
        document.querySelectorAll('.stat-card, .book-item, .card').forEach(element => {
            observer.observe(element);
        });

        // Enhanced button interactions
        document.querySelectorAll('.btn-return, .btn-cancel').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px) scale(1.05)';
            });
            
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Update current time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('th-TH', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }

        // Update time immediately and then every second
        updateTime();
        setInterval(updateTime, 1000);

        // Console branding
        console.log('%cห้องสมุดดิจิทัล - วิทยาลัยเทคนิคหาดใหญ่', 'color: #667eea; font-size: 20px; font-weight: bold;');
        console.log('%cDashboard with Return System - Developed with ❤️', 'color: #764ba2; font-size: 14px;');
        console.log('พัฒนาโดย นายปิยพัชร์ ทองวงศ์');

        // Performance monitoring
        if (performance.mark) {
            performance.mark('dashboard-load-start');
            
            window.addEventListener('load', function() {
                performance.mark('dashboard-load-end');
                performance.measure('dashboard-load-time', 'dashboard-load-start', 'dashboard-load-end');
                
                const loadTime = performance.getEntriesByName('dashboard-load-time')[0];
                console.log(`Dashboard loaded in ${loadTime.duration.toFixed(2)}ms`);
            });
        }

        // Update reservation expiry times in real-time
        function updateReservationTimers() {
            const reservationItems = document.querySelectorAll('#reservations-content .book-item');
            reservationItems.forEach(item => {
                const expiryText = item.querySelector('p:nth-of-type(4)');
                if (expiryText && expiryText.textContent.includes('หมดอายุ:')) {
                    // This could be enhanced to show real-time countdown
                    // For now, we'll leave it as is and rely on page refresh
                }
            });
        }

        // Update timers every minute
        setInterval(updateReservationTimers, 60000);

        // Initialize tooltips for status badges
        document.querySelectorAll('.due-date').forEach(badge => {
            badge.addEventListener('mouseenter', function() {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                
                if (this.classList.contains('overdue')) {
                    tooltip.textContent = 'หนังสือเล่มนี้เกินกำหนดคืนแล้ว กรุณาคืนโดยเร็วที่สุด';
                } else if (this.classList.contains('due-soon')) {
                    tooltip.textContent = 'หนังสือเล่มนี้ใกล้ครบกำหนดคืนแล้ว';
                } else if (this.classList.contains('pending-return')) {
                    tooltip.textContent = 'คำขอคืนหนังสือรอการยืนยันจากแอดมิน';
                } else if (this.classList.contains('reserved-status')) {
                    tooltip.textContent = 'การจองรอการอนุมัติจากแอดมิน';
                }
                
                tooltip.style.cssText = `
                    position: absolute;
                    background: #333;
                    color: white;
                    padding: 0.5rem;
                    border-radius: 4px;
                    font-size: 0.8rem;
                    z-index: 1000;
                    pointer-events: none;
                    transform: translate(-50%, -100%);
                    margin-top: -10px;
                    white-space: nowrap;
                `;
                
                this.style.position = 'relative';
                this.appendChild(tooltip);
            });
            
            badge.addEventListener('mouseleave', function() {
                const tooltip = this.querySelector('.tooltip');
                if (tooltip) {
                    tooltip.remove();
                }
            });
        });
    </script>
</body>
</html>