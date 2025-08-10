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

// Get current borrowed books
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
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
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
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
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

        .logo i { margin-right: 10px; font-size: 2rem; }

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

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .quick-action {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .quick-action::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            transition: left 0.3s ease;
            z-index: 0;
        }

        .quick-action:hover::before {
            left: 0;
        }

        .quick-action:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }

        .quick-action i,
        .quick-action h4,
        .quick-action p {
            position: relative;
            z-index: 1;
        }

        .quick-action i {
            font-size: 2.5rem;
            color: #667eea;
            margin-bottom: 1rem;
        }

        .quick-action h4 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .quick-action p {
            color: #666;
            font-size: 0.9rem;
        }

        /* Welcome Section */
        .welcome-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2.5rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 2rem;
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
        }

        .welcome-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        .profile-img {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            border: 4px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            position: relative;
            z-index: 2;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }

        .welcome-text {
            position: relative;
            z-index: 2;
        }

        .welcome-text h1 {
            font-size: 2.8rem;
            margin-bottom: 0.8rem;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .welcome-text p { 
            opacity: 0.95; 
            font-size: 1.1rem; 
            margin-bottom: 0.3rem;
        }

        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            padding: 2rem 1.5rem;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            border-radius: 20px 20px 0 0;
        }

        .stat-card.borrowed::before { background: linear-gradient(90deg, #667eea, #764ba2); }
        .stat-card.returned::before { background: linear-gradient(90deg, #4caf50, #45a049); }
        .stat-card.overdue::before { background: linear-gradient(90deg, #f44336, #e53935); }
        .stat-card.pending::before { background: linear-gradient(90deg, #ff9800, #f57c00); }
        .stat-card.reserved::before { background: linear-gradient(90deg, #9c27b0, #8e24aa); }

        .stat-card:hover { 
            transform: translateY(-8px) scale(1.02); 
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }

        .stat-card i { 
            font-size: 3rem; 
            margin-bottom: 1.2rem; 
            display: block;
        }
        .stat-card.borrowed i { color: #667eea; }
        .stat-card.returned i { color: #4caf50; }
        .stat-card.overdue i { color: #f44336; }
        .stat-card.pending i { color: #ff9800; }
        .stat-card.reserved i { color: #9c27b0; }

        .stat-number { 
            font-size: 2.2rem; 
            font-weight: 700; 
            margin-bottom: 0.5rem; 
            color: #333; 
        }
        .stat-label { 
            color: #666; 
            font-size: 0.95rem; 
            font-weight: 500;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2.5rem;
            margin-bottom: 2rem;
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.08);
            border-radius: 25px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
        }

        /* Enhanced Tabs */
        .tabs {
            background: transparent;
            padding: 1.5rem 1.5rem 0 1.5rem;
            display: flex;
            gap: 0.5rem;
        }

        .tab {
            flex: 1;
            padding: 1.2rem 1rem;
            text-align: center;
            cursor: pointer;
            background: rgba(102, 126, 234, 0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(102, 126, 234, 0.15);
            border-radius: 15px 15px 0 0;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }

        .tab::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .tab:hover:not(.active) {
            transform: translateY(-3px);
            background: rgba(102, 126, 234, 0.12);
            border-color: rgba(102, 126, 234, 0.25);
        }

        .tab.active {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.15), rgba(118, 75, 162, 0.15));
            backdrop-filter: blur(20px);
            border-color: rgba(102, 126, 234, 0.3);
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.2);
            color: #667eea;
            font-weight: 600;
        }

        .tab.active::before {
            transform: scaleX(1);
        }

        .tab-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.8rem;
            margin-left: 0.5rem;
            min-width: 20px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }

        .tab.active .tab-badge {
            background: linear-gradient(135deg, #5a67d8, #667eea);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        /* Tab Content */
        .tab-content {
            display: none;
            background: transparent;
            padding: 2rem;
            max-height: 550px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(102, 126, 234, 0.3) transparent;
        }

        .tab-content::-webkit-scrollbar {
            width: 6px;
        }

        .tab-content::-webkit-scrollbar-track {
            background: transparent;
        }

        .tab-content::-webkit-scrollbar-thumb {
            background: rgba(102, 126, 234, 0.3);
            border-radius: 3px;
        }

        .tab-content.active { display: block; }

        /* Enhanced Book Items */
        .book-item {
            display: flex;
            gap: 1.5rem;
            padding: 2rem;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(248, 249, 250, 0.9));
            backdrop-filter: blur(15px);
            border: 1px solid rgba(102, 126, 234, 0.1);
            border-radius: 20px;
            margin-bottom: 1.5rem;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            overflow: hidden;
        }

        .book-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .book-item:hover {
            transform: translateY(-8px) scale(1.01);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12), 0 8px 25px rgba(102, 126, 234, 0.15);
        }

        .book-item:hover::before {
            transform: scaleX(1);
        }

        .book-cover {
            width: 80px;
            height: 110px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .book-item:hover .book-cover { 
            transform: scale(1.05) rotateY(10deg); 
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .book-details { 
            flex: 1; 
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .book-details h4 {
            font-size: 1.3rem;
            color: #333;
            margin-bottom: 0.8rem;
            font-weight: 600;
            line-height: 1.3;
        }

        .book-details p {
            color: #666;
            font-size: 0.95rem;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .book-details p i { 
            color: #667eea; 
            width: 18px; 
            font-size: 0.9rem;
        }

        /* Book Actions */
        .book-actions {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
            align-items: flex-end;
            min-width: 140px;
        }

        /* Enhanced Due Date Badges */
        .due-date {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
        }



        /* Enhanced Buttons */
        .btn-return, .btn-cancel {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-cancel { 
            background: linear-gradient(135deg, #dc3545, #e55353); 
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        

        .btn-return:hover:not(:disabled) { 
            background: linear-gradient(135deg, #218838, #1e7e34); 
            transform: translateY(-2px) scale(1.05); 
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        
        .btn-cancel:hover:not(:disabled) { 
            background: linear-gradient(135deg, #c82333, #bd2130); 
            transform: translateY(-2px) scale(1.05); 
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
        }

        /* Enhanced Notifications */
        .card-header {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            color: #333;
            padding: 2rem 1.5rem 1.5rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid rgba(102, 126, 234, 0.15);
            position: relative;
        }

        .card-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 1.5rem;
            right: 1.5rem;
            height: 2px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 2px;
        }

        .card-header i {
            font-size: 1.8rem;
            color: #667eea;
            animation: bellShake 3s infinite;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            padding: 0.5rem;
            border-radius: 12px;
        }

        @keyframes bellShake {
            0%, 50%, 100% { transform: rotate(0deg); }
            10%, 30% { transform: rotate(-15deg); }
            20%, 40% { transform: rotate(15deg); }
        }

        .card-content { 
            padding: 0; 
            max-height: 450px; 
            overflow-y: auto; 
        }

        .notification-item {
            display: flex;
            gap: 1.5rem;
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(102, 126, 234, 0.08);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.5), rgba(248, 249, 250, 0.5));
            transition: all 0.3s ease;
            position: relative;
        }

        .notification-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, #667eea, #764ba2);
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }

        .notification-item:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.8), rgba(248, 249, 250, 0.8));
            transform: translateX(8px);
        }

        .notification-item:hover::before {
            transform: scaleY(1);
        }

        .notification-icon {
            width: 50px;
            height: 50px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }

        .notification-icon::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.2), transparent);
            transform: rotate(45deg);
            transition: all 0.5s ease;
        }

        .notification-item:hover .notification-icon::before {
            animation: shimmer 1s ease;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .notification-icon.warning { background: linear-gradient(135deg, #ff9800, #f57c00); }
        .notification-icon.success { background: linear-gradient(135deg, #4caf50, #2e7d32); }
        .notification-icon.info { background: linear-gradient(135deg, #667eea, #764ba2); }
        .notification-icon.error { background: linear-gradient(135deg, #f44336, #d32f2f); }

        .notification-content { 
            flex: 1; 
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .notification-content h4 { 
            font-size: 1.1rem; 
            color: #333; 
            font-weight: 600;
            margin-bottom: 0.3rem; 
        }
        
        .notification-content p { 
            color: #666; 
            font-size: 0.95rem; 
            line-height: 1.5;
            margin-bottom: 0.5rem; 
        }
        
        .notification-time { 
            color: #999; 
            font-size: 0.8rem; 
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Enhanced Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #666;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.5), rgba(248, 249, 250, 0.5));
            border: 2px dashed rgba(102, 126, 234, 0.2);
            border-radius: 25px;
            margin: 2rem;
            position: relative;
            overflow: hidden;
        }

        .empty-state::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, #c0caf7ff, #c0caf7ff, #c0caf7ff, #c0caf7ff);
            background-size: 400% 400%;
            border-radius: 25px;
            z-index: -1;
            animation: borderGlow 4s ease-in-out infinite;
        }

        @keyframes borderGlow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .empty-state i { 
            font-size: 4rem; 
            color: rgba(102, 126, 234, 0.4); 
            margin-bottom: 1.5rem; 
            display: block;
        }
        
        .empty-state h4 { 
            font-size: 1.4rem; 
            margin-bottom: 0.8rem; 
            color: #333; 
            font-weight: 600;
        }

        .empty-state p {
            font-size: 1rem;
            line-height: 1.6;
            opacity: 0.8;
        }

        /* Modal Styles - Enhanced */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
        }

        .modal-content {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            margin: 3% auto;
            padding: 0;
            border-radius: 25px;
            width: 90%;
            max-width: 550px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            overflow: hidden;
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        @keyframes modalSlideIn {
            from { 
                opacity: 0; 
                transform: translateY(-100px) scale(0.8); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1); 
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }

        .modal-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1), transparent);
        }

        .modal-header h3 {
            font-size: 1.4rem;
            font-weight: 600;
            position: relative;
            z-index: 2;
        }

        .close {
            background: rgba(255,255,255,0.1);
            border: 2px solid rgba(255,255,255,0.2);
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            position: relative;
            z-index: 2;
        }

        .close:hover {
            background: rgba(255,255,255,0.2);
            transform: scale(1.1);
        }

        .modal-body { 
            padding: 2.5rem; 
        }

        .modal-book-info {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(118, 75, 162, 0.05));
            border-radius: 15px;
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .modal-book-cover {
            width: 70px;
            height: 95px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8rem;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
        }

        .modal-book-details {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .modal-book-details h4 {
            font-size: 1.2rem;
            color: #333;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .modal-book-details p {
            color: #666;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 1rem;
            border-top: 1px solid rgba(102, 126, 234, 0.1);
        }

        .btn-modal {
            padding: 0.8rem 1.8rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .btn-modal-primary { 
            background: linear-gradient(135deg, #667eea, #764ba2); 
            color: white; 
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-modal-danger { 
            background: linear-gradient(135deg, #dc3545, #e55353); 
            color: white; 
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        
        .btn-modal-secondary { 
            background: #f8f9fa; 
            color: #666; 
            border: 2px solid #e1e5e9; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .btn-modal:hover {
            transform: translateY(-2px) scale(1.05);
        }

        .btn-modal-primary:hover {
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-modal-danger:hover {
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
        }

        .btn-modal-secondary:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }

        /* Enhanced Info Box */
        .info-box {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.08), rgba(118, 75, 162, 0.08));
            border: 1px solid rgba(102, 126, 234, 0.15);
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1.5rem;
        }

        .info-box h4 {
            color: #333;
            margin-bottom: 0.8rem;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-box p {
            margin: 0.4rem 0;
            color: #666;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .warning-box {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.1), rgba(255, 235, 59, 0.1));
            border: 1px solid rgba(255, 193, 7, 0.3);
        }

        .warning-box p {
            color: #856404;
        }

        /* Notification Toast - Enhanced */
        .notification-toast {
            position: fixed;
            top: 120px;
            right: 20px;
            padding: 1.2rem 1.8rem;
            border-radius: 15px;
            color: white;
            font-weight: 500;
            z-index: 9998;
            transform: translateX(120%);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            display: flex;
            align-items: center;
            gap: 1rem;
            min-width: 320px;
            max-width: 420px;
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255,255,255,0.2);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .notification-toast.show { 
            transform: translateX(0); 
        }

        .notification-toast i {
            font-size: 1.3rem;
        }

        .notification-toast .close-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            margin-left: auto;
        }

        .notification-toast .close-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.1);
        }

        /* Loading Overlay - Enhanced */
        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(8px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            color: white;
        }

        .loading-content {
            text-align: center;
            background: rgba(255,255,255,0.1);
            padding: 3rem;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(20px);
        }

        .spinner {
            border: 4px solid rgba(255,255,255,0.3);
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading p {
            font-size: 1.1rem;
            font-weight: 500;
        }

        /* Fade out animation for removed items */
        .fade-out {
            opacity: 0 !important;
            transform: translateX(-100%) scale(0.8) !important;
            transition: all 0.4s ease !important;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-container { 
                padding: 0 1rem; 
                margin-top: 80px; 
            }
            .nav-links { 
                display: none; 
            }
            .welcome-section { 
                flex-direction: column; 
                text-align: center; 
                padding: 2rem;
            }
            .welcome-text h1 {
                font-size: 2.2rem;
            }
            .dashboard-grid { 
                grid-template-columns: 1fr; 
            }
            .book-item { 
                flex-direction: column; 
                text-align: center;
            }
            .book-actions {
                align-items: center;
                flex-direction: row;
                justify-content: center;
                gap: 1rem;
            }
            .tabs {
                flex-direction: column;
                gap: 0.5rem;
            }
            .tab {
                border-radius: 15px;
            }
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
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
                <li><a href="index_user.php">หน้าแรก</a></li>
                <li><a href="dashboard.php" class="active">แดชบอร์ด</a></li>
                <li><a href="profile.php">โปรไฟล์</a></li>
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
                <?php if (!empty($user['profile_image']) && file_exists('../' . $user['profile_image'])): ?>
                    <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <div class="welcome-text">
                <h1>ยินดีต้อนรับ <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h1>
                <p>รหัสนักเรียน: <?php echo htmlspecialchars($user['student_id']); ?> | แผนก: <?php echo htmlspecialchars($user['department'] ?: 'ไม่ระบุ'); ?></p>
                <p>เวลา: <span id="current-time"></span> | สมาชิกตั้งแต่: <?php echo date('d/m/Y', strtotime($user['created_at'])); ?></p>
            </div>
        </section>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="dashboard.php" class="quick-action">
                <i class="fas fa-tachometer-alt"></i>
                <h4>แดชบอร์ด</h4>
                <p>ดูสถานะการยืมและข้อมูลส่วนตัว</p>
            </a>
            <a href="profile.php" class="quick-action">
                <i class="fas fa-user-cog"></i>
                <h4>แก้ไขโปรไฟล์</h4>
                <p>จัดการข้อมูลส่วนตัว เปลี่ยนรหัสผ่าน และการตั้งค่าต่างๆ</p>
            </a>
        </div>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card borrowed">
                <i class="fas fa-book-reader"></i>
                <div class="stat-number" id="borrowed-count"><?php echo count(array_filter($current_borrows, fn($b) => $b['status'] === 'borrowed')); ?></div>
                <div class="stat-label">กำลังยืมอยู่</div>
            </div>
            <div class="stat-card pending">
                <i class="fas fa-clock"></i>
                <div class="stat-number" id="pending-count"><?php echo $stats['pending_return_count']; ?></div>
                <div class="stat-label">รอยืนยันการคืน</div>
            </div>
            <div class="stat-card reserved">
                <i class="fas fa-bookmark"></i>
                <div class="stat-number" id="reserved-count"><?php echo count($current_reservations); ?></div>
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
                <div class="tabs">
                    <button class="tab active" onclick="showTab('borrowed')">
                        <i class="fas fa-book-reader"></i> 
                        <span>หนังสือที่ยืม</span>
                        <span class="tab-badge" id="borrowed-tab-count"><?php echo count(array_filter($current_borrows, fn($b) => $b['status'] === 'borrowed')); ?></span>
                    </button>
                    <button class="tab" onclick="showTab('pending')">
                        <i class="fas fa-hourglass-half"></i> 
                        <span>รอคืน</span>
                        <span class="tab-badge" id="pending-tab-count"><?php echo $stats['pending_return_count']; ?></span>
                    </button>
                    <button class="tab" onclick="showTab('reservations')">
                        <i class="fas fa-bookmark"></i> 
                        <span>การจอง</span>
                        <span class="tab-badge" id="reservations-tab-count"><?php echo count($current_reservations); ?></span>
                    </button>
                </div>

                <!-- Borrowed Books Content -->
                <div id="borrowed-content" class="tab-content active">
                    <?php 
                    $borrowed_books = array_filter($current_borrows, fn($b) => $b['status'] === 'borrowed');
                    if (count($borrowed_books) > 0): ?>
                        <?php foreach ($borrowed_books as $borrow): ?>
                            <div class="book-item" data-borrow-id="<?php echo $borrow['borrow_id']; ?>">
                                <div class="book-cover">
                                    <?php if (!empty($borrow['cover_image']) && file_exists('../' . $borrow['cover_image'])): ?>
                                        <img src="../<?php echo htmlspecialchars($borrow['cover_image']); ?>" alt="Book cover" style="width: 100%; height: 100%; border-radius: 12px; object-fit: cover;">
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
                            <div class="book-item" data-borrow-id="<?php echo $borrow['borrow_id']; ?>">
                                <div class="book-cover">
                                    <?php if (!empty($borrow['cover_image']) && file_exists('../' . $borrow['cover_image'])): ?>
                                        <img src="../<?php echo htmlspecialchars($borrow['cover_image']); ?>" alt="Book cover" style="width: 100%; height: 100%; border-radius: 12px; object-fit: cover;">
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
                            <div class="book-item" data-reservation-id="<?php echo $reservation['reservation_id']; ?>">
                                <div class="book-cover">
                                    <?php if (!empty($reservation['cover_image']) && file_exists('../' . $reservation['cover_image'])): ?>
                                        <img src="../<?php echo htmlspecialchars($reservation['cover_image']); ?>" alt="Book cover" style="width: 100%; height: 100%; border-radius: 12px; object-fit: cover;">
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
                                    $hours_remaining = $expiry > $now ? 
                                        (int)$now->diff($expiry)->format('%a') * 24 + (int)$now->diff($expiry)->format('%h') : 0;
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
                <div class="card-content" id="notifications-container">
                    <?php if (count($notifications) > 0): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item">
                                <div class="notification-icon <?php 
                                    echo match($notification['type'] ?? 'info') {
                                        'overdue' => 'warning',
                                        'returned' => 'success',
                                        'reserved' => 'info',
                                        'reservation_cancelled' => 'warning',
                                        default => 'info'
                                    };
                                ?>">
                                    <i class="fas fa-<?php 
                                        echo match($notification['type'] ?? 'info') {
                                            'overdue' => 'exclamation-triangle',
                                            'returned' => 'check-circle',
                                            'reserved' => 'bookmark',
                                            'reservation_cancelled' => 'times-circle',
                                            default => 'info-circle'
                                        };
                                    ?>"></i>
                                </div>
                                <div class="notification-content">
                                    <h4><?php echo htmlspecialchars($notification['title']); ?></h4>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <div class="notification-time">
                                        <i class="fas fa-clock"></i>
                                        <?php echo date('d/m/Y H:i', strtotime($notification['sent_date'])); ?>
                                    </div>
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
                
                <div class="info-box">
                    <h4><i class="fas fa-info-circle"></i> การแจ้งคืน</h4>
                    <p>เมื่อคุณกดยืนยัน ระบบจะส่งคำขอคืนหนังสือไปยังแอดมิน</p>
                    <p>แอดมินจะตรวจสอบสภาพหนังสือและยืนยันการคืน</p>
                    <p>สถานะจะเปลี่ยนเป็น "รอแอดมินยืนยัน" จนกว่าจะได้รับการอนุมัติ</p>
                </div>

                <div class="modal-actions">
                    <button class="btn-modal btn-modal-secondary" onclick="closeModal('returnModal')">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button class="btn-modal btn-modal-primary" onclick="confirmReturn()">
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
                
                <div class="info-box warning-box">
                    <h4><i class="fas fa-exclamation-triangle"></i> คำเตือน</h4>
                    <p><strong>การยกเลิกการจองไม่สามารถยกเลิกได้</strong> หากต้องการหนังสือเล่มนี้ คุณจะต้องจองใหม่อีกครั้ง</p>
                </div>

                <div class="modal-actions">
                    <button class="btn-modal btn-modal-secondary" onclick="closeModal('cancelReservationModal')">
                        <i class="fas fa-arrow-left"></i> กลับ
                    </button>
                    <button class="btn-modal btn-modal-danger" onclick="confirmCancelReservation()">
                        <i class="fas fa-times"></i> ยืนยันยกเลิก
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <p>กำลังดำเนินการ...</p>
        </div>
    </div>

    <script>
        // Global variables
        let currentBorrowId = null;
        let currentReservationId = null;
        let activeTab = 'borrowed';

        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            updateTime();
            setInterval(updateTime, 1000);
            initializeEventListeners();
            console.log('%cห้องสมุดดิจิทัล - Enhanced Dashboard Loaded', 'color: #667eea; font-size: 16px; font-weight: bold;');
        });

        // Enhanced tab switching with active tab tracking
        function showTab(tabName) {
            activeTab = tabName;
            
            // Remove active from all
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            
            // Add active to selected
            document.getElementById(tabName + '-content').classList.add('active');
            event.target.classList.add('active');
        }

        // Modal functions
        function showReturnModal(borrowId, title, author, borrowDate, dueDate, coverImage) {
            currentBorrowId = borrowId;
            
            document.getElementById('modal-book-title').textContent = title;
            document.getElementById('modal-book-author').innerHTML = '<i class="fas fa-user"></i> ' + author;
            document.getElementById('modal-borrow-date').innerHTML = '<i class="fas fa-calendar"></i> ยืมเมื่อ: ' + borrowDate;
            document.getElementById('modal-due-date').innerHTML = '<i class="fas fa-calendar-alt"></i> กำหนดคืน: ' + dueDate;
            
            const coverElement = document.getElementById('modal-book-cover');
            if (coverImage && coverImage !== '') {
                coverElement.innerHTML = `<img src="../${coverImage}" alt="Book cover" style="width: 100%; height: 100%; border-radius: 12px; object-fit: cover;">`;
            } else {
                coverElement.innerHTML = '<i class="fas fa-book"></i>';
            }
            
            document.getElementById('returnModal').style.display = 'block';
        }

        function showCancelReservationModal(reservationId, title, coverImage) {
            currentReservationId = reservationId;
            
            document.getElementById('cancel-modal-book-title').textContent = title;
            
            const coverElement = document.getElementById('cancel-modal-book-cover');
            if (coverImage && coverImage !== '') {
                coverElement.innerHTML = `<img src="../${coverImage}" alt="Book cover" style="width: 100%; height: 100%; border-radius: 12px; object-fit: cover;">`;
            } else {
                coverElement.innerHTML = '<i class="fas fa-book"></i>';
            }
            
            document.getElementById('cancelReservationModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            currentBorrowId = null;
            currentReservationId = null;
        }

        // Enhanced notification system
        function showNotification(message, type = 'success', duration = 6000) {
            // Remove existing notifications first
            const existingToasts = document.querySelectorAll('.notification-toast');
            existingToasts.forEach(toast => {
                toast.style.transform = 'translateX(120%)';
                setTimeout(() => toast.remove(), 300);
            });

            const notification = document.createElement('div');
            notification.className = `notification-toast ${type}`;
            
            // Set background based on type
            const backgrounds = {
                success: 'linear-gradient(135deg, #28a745, #20c997)',
                error: 'linear-gradient(135deg, #dc3545, #e55353)',
                warning: 'linear-gradient(135deg, #ffc107, #ffca2c)',
                info: 'linear-gradient(135deg, #17a2b8, #20c997)'
            };
            
            const icons = {
                success: 'check-circle',
                error: 'times-circle',
                warning: 'exclamation-triangle',
                info: 'info-circle'
            };
            
            notification.style.background = backgrounds[type] || backgrounds.success;
            
            notification.innerHTML = `
                <i class="fas fa-${icons[type] || icons.success}"></i>
                <span style="flex: 1;">${message}</span>
                <button class="close-btn" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            document.body.appendChild(notification);
            
            // Trigger animation
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            // Auto remove after duration
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 400);
            }, duration);
            
            return notification;
        }

        // Enhanced loading overlay
        function showLoading(message = 'กำลังดำเนินการ...') {
            const loadingOverlay = document.getElementById('loadingOverlay');
            const loadingText = loadingOverlay.querySelector('p');
            if (loadingText) {
                loadingText.textContent = message;
            }
            loadingOverlay.style.display = 'flex';
        }

        function hideLoading() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.style.display = 'none';
        }

        // Enhanced function to update counts immediately
        function updateCounts(type, change) {
            const countElements = {
                'borrowed': ['borrowed-count', 'borrowed-tab-count'],
                'pending': ['pending-count', 'pending-tab-count'],
                'reserved': ['reserved-count', 'reservations-tab-count']
            };
            
            if (countElements[type]) {
                countElements[type].forEach(id => {
                    const element = document.getElementById(id);
                    if (element) {
                        const currentCount = parseInt(element.textContent) || 0;
                        const newCount = Math.max(0, currentCount + change);
                        element.textContent = newCount;
                        
                        // Add pulse animation for visual feedback
                        element.style.transform = 'scale(1.2)';
                        element.style.transition = 'transform 0.3s ease';
                        setTimeout(() => {
                            element.style.transform = 'scale(1)';
                        }, 300);
                    }
                });
            }
        }

        // Enhanced function to remove item from UI immediately
        function removeBookItem(borrowId, reservationId = null, type = 'borrow') {
            const selector = type === 'borrow' 
                ? `[data-borrow-id="${borrowId}"]`
                : `[data-reservation-id="${reservationId}"]`;
            
            const bookItem = document.querySelector(selector);
            
            if (bookItem) {
                // Add fade out animation
                bookItem.classList.add('fade-out');
                
                // Remove from DOM after animation
                setTimeout(() => {
                    bookItem.remove();
                    
                    // Check if the current tab is now empty and show empty state
                    const activeTabContent = document.querySelector('.tab-content.active');
                    const remainingItems = activeTabContent.querySelectorAll('.book-item');
                    
                    if (remainingItems.length === 0) {
                        const emptyStateHtml = getEmptyStateForTab(activeTab);
                        activeTabContent.innerHTML = emptyStateHtml;
                    }
                    
                }, 400);
                
                return true;
            }
            return false;
        }

        // Function to get appropriate empty state for each tab
        function getEmptyStateForTab(tab) {
            const emptyStates = {
                'borrowed': `
                    <div class="empty-state">
                        <i class="fas fa-book-open"></i>
                        <h4>ไม่มีหนังสือที่กำลังยืมอยู่</h4>
                        <p>ไปค้นหาและยืมหนังสือที่สนใจได้เลย!</p>
                    </div>
                `,
                'pending': `
                    <div class="empty-state">
                        <i class="fas fa-hourglass-half"></i>
                        <h4>ไม่มีหนังสือที่รอยืนยันการคืน</h4>
                        <p>หนังสือที่คุณแจ้งคืนจะแสดงในหน้านี้</p>
                    </div>
                `,
                'reservations': `
                    <div class="empty-state">
                        <i class="fas fa-bookmark"></i>
                        <h4>ไม่มีการจองหนังสือ</h4>
                        <p>คุณสามารถจองหนังสือที่ต้องการได้จากหน้าค้นหา</p>
                    </div>
                `
            };
            
            return emptyStates[tab] || emptyStates['borrowed'];
        }

        // Add notification to panel for immediate feedback
        function addNotificationToPanel(notificationData) {
            const cardContent = document.getElementById('notifications-container');
            const emptyState = cardContent.querySelector('.empty-state');
            
            // Remove empty state if it exists
            if (emptyState) {
                emptyState.remove();
            }
            
            // Create notification element
            const notificationItem = document.createElement('div');
            notificationItem.className = 'notification-item';
            notificationItem.style.opacity = '0';
            notificationItem.style.transform = 'translateX(50px)';
            notificationItem.style.transition = 'all 0.5s ease';
            
            const iconClass = {
                'return_pending': 'hourglass-half',
                'reservation_cancelled': 'times-circle',
                'success': 'check-circle',
                'info': 'info-circle'
            };
            
            const iconTypeClass = {
                'return_pending': 'warning',
                'reservation_cancelled': 'warning',
                'success': 'success',
                'info': 'info'
            };
            
            const currentTime = new Date().toLocaleString('th-TH', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            notificationItem.innerHTML = `
                <div class="notification-icon ${iconTypeClass[notificationData.type] || 'info'}">
                    <i class="fas fa-${iconClass[notificationData.type] || 'info-circle'}"></i>
                </div>
                <div class="notification-content">
                    <h4>${notificationData.title}</h4>
                    <p>${notificationData.message}</p>
                    <div class="notification-time">
                        <i class="fas fa-clock"></i>
                        ${currentTime}
                    </div>
                </div>
            `;
            
            // Add to beginning of notifications
            cardContent.insertBefore(notificationItem, cardContent.firstChild);
            
            // Trigger animation
            setTimeout(() => {
                notificationItem.style.opacity = '1';
                notificationItem.style.transform = 'translateX(0)';
            }, 100);
        }

        // Enhanced confirm return with immediate UI updates
        async function confirmReturn() {
            if (!currentBorrowId || currentBorrowId <= 0) {
                showNotification('เกิดข้อผิดพลาด: ไม่พบรหัสการยืม', 'error');
                return;
            }
            
            const borrowIdToSend = currentBorrowId;
            const bookTitle = document.getElementById('modal-book-title').textContent;
            
            showLoading('กำลังแจ้งคืนหนังสือ...');
            closeModal('returnModal');
            
            try {
                const response = await fetch('request_return.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ borrow_id: borrowIdToSend })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('ระบบตอบกลับในรูปแบบที่ไม่ถูกต้อง');
                }
                
                const data = await response.json();
                hideLoading();
                
                if (data.success) {
                    // เก็บข้อความแจ้งเตือนใน sessionStorage ก่อนรีเฟรซ
                    sessionStorage.setItem('notification', JSON.stringify({
                        message: `แจ้งคืนหนังสือ "${bookTitle}" สำเร็จ! รอแอดมินยืนยัน`,
                        type: 'success',
                        duration: 8000
                    }));
                    
                    // รีเฟรซทันที
                    window.location.reload();
                    
                } else {
                    // เก็บข้อความแสดงข้อผิดพลาด
                    sessionStorage.setItem('notification', JSON.stringify({
                        message: `เกิดข้อผิดพลาด: ${data.message || 'ไม่สามารถแจ้งคืนได้'}`,
                        type: 'error',
                        duration: 8000
                    }));
                    
                    window.location.reload();
                }
            } catch (error) {
                hideLoading();
                console.error('Return error:', error);
                
                // เก็บข้อความแสดงข้อผิดพลาดการเชื่อมต่อ
                sessionStorage.setItem('notification', JSON.stringify({
                    message: 'เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่อีกครั้ง',
                    type: 'error',
                    duration: 8000
                }));
                
                window.location.reload();
            }
        }

        // เพิ่มฟังก์ชันนี้ในส่วน DOMContentLoaded
        document.addEventListener('DOMContentLoaded', function() {
            updateTime();
            setInterval(updateTime, 1000);
            initializeEventListeners();
            
            // ตรวจสอบการแจ้งเตือนหลังรีเฟรซ
            checkPendingNotification();
            
            console.log('%cห้องสมุดดิจิทัล - Enhanced Dashboard Loaded', 'color: #667eea; font-size: 16px; font-weight: bold;');
        });

        // ฟังก์ชันตรวจสอบและแสดงการแจ้งเตือนหลังรีเฟรซ
        function checkPendingNotification() {
            const pendingNotification = sessionStorage.getItem('notification');
            if (pendingNotification) {
                try {
                    const notification = JSON.parse(pendingNotification);
                    
                    // แสดงการแจ้งเตือนหลังหน้าเว็บโหลดเสร็จ
                    setTimeout(() => {
                        showNotification(notification.message, notification.type, notification.duration);
                    }, 500); // รอให้หน้าเว็บโหลดเสร็จก่อน
                    
                    // ลบการแจ้งเตือนออกจาก sessionStorage
                    sessionStorage.removeItem('notification');
                    
                } catch (error) {
                    console.error('Error parsing notification:', error);
                    sessionStorage.removeItem('notification');
                }
            }
        }



        // Enhanced confirm cancel reservation with immediate UI updates
        async function confirmCancelReservation() {
            if (!currentReservationId || currentReservationId <= 0) {
                showNotification('เกิดข้อผิดพลาด: ไม่พบรหัสการจอง', 'error');
                return;
            }
            
            const reservationIdToSend = currentReservationId;
            const bookTitle = document.getElementById('cancel-modal-book-title').textContent;
            
            showLoading('กำลังยกเลิกการจอง...');
            closeModal('cancelReservationModal');
            
            // Immediately update UI
            const itemRemoved = removeBookItem(null, reservationIdToSend, 'reservation');
            if (itemRemoved) {
                updateCounts('reserved', -1);
            }
            
            try {
                const response = await fetch('cancel_reservation.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ reservation_id: reservationIdToSend })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('ระบบตอบกลับในรูปแบบที่ไม่ถูกต้อง');
                }
                
                const data = await response.json();
                hideLoading();
                
                if (data.success) {
                    showNotification(`ยกเลิกการจองหนังสือ "${bookTitle}" เรียบร้อยแล้ว`, 'success', 8000);
                    
                    // Add notification to panel
                    addNotificationToPanel({
                        type: 'reservation_cancelled',
                        title: 'ยกเลิกการจอง',
                        message: `คุณได้ยกเลิกการจองหนังสือ "${bookTitle}" เรียบร้อยแล้ว`,
                        sent_date: new Date().toISOString()
                    });
                    
                } else {
                    // Revert UI changes on error
                    showNotification(`เกิดข้อผิดพลาด: ${data.message || 'ไม่สามารถยกเลิกการจองได้'}`, 'error', 8000);
                    setTimeout(() => window.location.reload(), 2000);
                }
            } catch (error) {
                hideLoading();
                console.error('Cancel reservation error:', error);
                showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่อีกครั้ง', 'error', 8000);
                // Revert UI changes on error
                setTimeout(() => window.location.reload(), 2000);
            }
        }

        // Utility functions
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('th-TH', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            const timeElement = document.getElementById('current-time');
            if (timeElement) timeElement.textContent = timeString;
        }

        // Event listeners
        function initializeEventListeners() {
            // Close modals with escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeModal('returnModal');
                    closeModal('cancelReservationModal');
                }
            });

            // Close modals when clicking outside
            window.onclick = function(event) {
                const returnModal = document.getElementById('returnModal');
                const cancelModal = document.getElementById('cancelReservationModal');
                
                if (event.target === returnModal) closeModal('returnModal');
                if (event.target === cancelModal) closeModal('cancelReservationModal');
            };

            // Enhanced button animations
            document.querySelectorAll('.btn-return, .btn-cancel').forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    if (!this.disabled) {
                        this.style.transform = 'translateY(-2px) scale(1.05)';
                    }
                });
                
                btn.addEventListener('mouseleave', function() {
                    if (!this.disabled) {
                        this.style.transform = 'translateY(0) scale(1)';
                    }
                });
            });

            // Intersection Observer for animations
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '0';
                        entry.target.style.transform = 'translateY(30px)';
                        entry.target.style.transition = 'all 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
                        
                        setTimeout(() => {
                            entry.target.style.opacity = '1';
                            entry.target.style.transform = 'translateY(0)';
                        }, 100);
                        
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });

            document.querySelectorAll('.stat-card, .book-item, .card').forEach(el => {
                observer.observe(el);
            });
        }

        // Auto refresh every 10 minutes (increased from 5 for better performance)
        setInterval(() => {
            console.log('Auto-refreshing dashboard...');
            window.location.reload();
        }, 600000);
    </script>
</body>
</html>