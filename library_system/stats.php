<?php
// เชื่อมต่อฐานข้อมูล
$mysqli = new mysqli("localhost", "root", "", "library_system");
if ($mysqli->connect_errno) {
    die("Connect Error: " . $mysqli->connect_error);
}

// Query สถิติ
$totalBooks = $mysqli->query("SELECT COUNT(*) AS count FROM books")->fetch_assoc()['count'];
$totalUsers = $mysqli->query("SELECT COUNT(*) AS count FROM users")->fetch_assoc()['count'];
$totalBorrows = $mysqli->query("SELECT COUNT(*) AS count FROM borrowing")->fetch_assoc()['count'];

// ส่งข้อมูลเป็น JSON
echo json_encode([
    'totalBooks' => $totalBooks,
    'totalUsers' => $totalUsers,
    'totalBorrows' => $totalBorrows
]);

$mysqli->close();
?>
