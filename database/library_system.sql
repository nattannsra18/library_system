SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ===============================
-- CREATE TABLES
-- ===============================

CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admins` (
  `admin_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','librarian','assistant') DEFAULT 'librarian',
  `phone` varchar(15) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `authors` (
  `author_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `biography` text DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `nationality` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Updated books table with 'reserved' status added to enum
CREATE TABLE `books` (
  `book_id` int(11) NOT NULL,
  `isbn` varchar(20) DEFAULT NULL,
  `title` varchar(300) NOT NULL,
  `subtitle` varchar(300) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `publisher_id` int(11) DEFAULT NULL,
  `publication_year` year(4) DEFAULT NULL,
  `edition` varchar(50) DEFAULT NULL,
  `pages` int(11) DEFAULT NULL,
  `language` varchar(50) DEFAULT 'Thai',
  `description` text DEFAULT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `total_copies` int(11) DEFAULT 1,
  `available_copies` int(11) DEFAULT 1,
  `price` decimal(10,2) DEFAULT NULL,
  `acquisition_date` date DEFAULT NULL,
  `status` enum('available','unavailable','reserved','damaged','lost') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `book_authors` (
  `book_id` int(11) NOT NULL,
  `author_id` int(11) NOT NULL,
  `role` enum('author','co-author','editor','translator') DEFAULT 'author'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `borrowing` (
  `borrow_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `borrow_date` date NOT NULL,
  `due_date` date NOT NULL,
  `return_date` date DEFAULT NULL,
  `status` enum('borrowed','returned','overdue','lost','pending_return') DEFAULT 'borrowed',
  `renewal_count` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `category_code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fines` (
  `fine_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `borrow_id` int(11) DEFAULT NULL,
  `fine_type` enum('overdue','damage','lost') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `days_overdue` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `status` enum('unpaid','paid','waived') DEFAULT 'unpaid',
  `fine_date` date NOT NULL,
  `paid_date` date DEFAULT NULL,
  `paid_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `type` enum('due_reminder','overdue','reservation_ready','fine_notice','general','return_request','return_pending') NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `sent_date` datetime DEFAULT current_timestamp(),
  `read_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `publishers` (
  `publisher_id` int(11) NOT NULL,
  `publisher_name` varchar(200) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `website` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reservations` (
  `reservation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `reservation_date` datetime DEFAULT current_timestamp(),
  `expiry_date` datetime DEFAULT NULL,
  `status` enum('active','fulfilled','cancelled','expired') DEFAULT 'active',
  `priority_number` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reviews` (
  `review_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `rating` int(11) DEFAULT NULL CHECK (`rating` >= 1 and `rating` <= 5),
  `review_text` text DEFAULT NULL,
  `review_date` datetime DEFAULT current_timestamp(),
  `status` enum('approved','pending','rejected') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `description` text DEFAULT NULL,
  `updated_by_admin_id` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `user_type` enum('student','teacher','staff') DEFAULT 'student',
  `department` varchar(100) DEFAULT NULL,
  `class_level` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `registration_date` datetime DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===============================
-- ALTER TABLE CONSTRAINTS
-- ===============================

ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `admin_id` (`admin_id`);

ALTER TABLE `admins`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

ALTER TABLE `authors`
  ADD PRIMARY KEY (`author_id`);

ALTER TABLE `books`
  ADD PRIMARY KEY (`book_id`),
  ADD UNIQUE KEY `isbn` (`isbn`),
  ADD KEY `publisher_id` (`publisher_id`),
  ADD KEY `idx_books_title` (`title`),
  ADD KEY `idx_books_isbn` (`isbn`),
  ADD KEY `idx_books_category` (`category_id`);

ALTER TABLE `book_authors`
  ADD PRIMARY KEY (`book_id`,`author_id`),
  ADD KEY `author_id` (`author_id`);

ALTER TABLE `borrowing`
  ADD PRIMARY KEY (`borrow_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `idx_borrowing_user` (`user_id`),
  ADD KEY `idx_borrowing_book` (`book_id`),
  ADD KEY `idx_borrowing_status` (`status`),
  ADD KEY `idx_borrowing_due_date` (`due_date`);

ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`),
  ADD UNIQUE KEY `category_code` (`category_code`);

ALTER TABLE `fines`
  ADD PRIMARY KEY (`fine_id`),
  ADD KEY `borrow_id` (`borrow_id`),
  ADD KEY `paid_by_admin_id` (`paid_by_admin_id`),
  ADD KEY `idx_fines_user` (`user_id`),
  ADD KEY `idx_fines_status` (`status`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `admin_id` (`admin_id`);

ALTER TABLE `publishers`
  ADD PRIMARY KEY (`publisher_id`);

ALTER TABLE `reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `idx_reservations_user` (`user_id`),
  ADD KEY `idx_reservations_book` (`book_id`);

ALTER TABLE `reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD UNIQUE KEY `unique_user_book_review` (`user_id`,`book_id`),
  ADD KEY `book_id` (`book_id`);

ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `updated_by_admin_id` (`updated_by_admin_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `student_id` (`student_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_student_id` (`student_id`),
  ADD KEY `idx_users_email` (`email`);

-- ===============================
-- AUTO_INCREMENT
-- ===============================

ALTER TABLE `activity_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `admins`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `authors`
  MODIFY `author_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `books`
  MODIFY `book_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `borrowing`
  MODIFY `borrow_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `fines`
  MODIFY `fine_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `publishers`
  MODIFY `publisher_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `reviews`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `system_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;

-- ===============================
-- FOREIGN KEY CONSTRAINTS
-- ===============================

ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `activity_logs_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL;

ALTER TABLE `books`
  ADD CONSTRAINT `books_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `books_ibfk_2` FOREIGN KEY (`publisher_id`) REFERENCES `publishers` (`publisher_id`) ON DELETE SET NULL;

ALTER TABLE `book_authors`
  ADD CONSTRAINT `book_authors_ibfk_1` FOREIGN KEY (`book_id`) REFERENCES `books` (`book_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `book_authors_ibfk_2` FOREIGN KEY (`author_id`) REFERENCES `authors` (`author_id`) ON DELETE CASCADE;

ALTER TABLE `borrowing`
  ADD CONSTRAINT `borrowing_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `borrowing_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `books` (`book_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `borrowing_ibfk_3` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL;

ALTER TABLE `fines`
  ADD CONSTRAINT `fines_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fines_ibfk_2` FOREIGN KEY (`borrow_id`) REFERENCES `borrowing` (`borrow_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fines_ibfk_3` FOREIGN KEY (`paid_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL;

ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE;

ALTER TABLE `reservations`
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `books` (`book_id`) ON DELETE CASCADE;

ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `books` (`book_id`) ON DELETE CASCADE;

ALTER TABLE `system_settings`
  ADD CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL;

-- ===============================
-- INSERT SAMPLE DATA (Categories)
-- ===============================

INSERT INTO categories (category_name, category_code, description) VALUES 
('คอมพิวเตอร์และเทคโนโลยี', 'COMP', 'หนังสือเกี่ยวกับคอมพิวเตอร์ โปรแกรมมิ่ง และเทคโนโลยี'),
('วิทยาศาสตร์', 'SCI', 'หนังสือวิทยาศาสตร์ทั่วไป ฟิสิกส์ เคมี ชีววิทยา'),
('คณิตศาสตร์', 'MATH', 'หนังสือคณิตศาสตร์และสถิติ'),
('ภาษาอังกฤษ', 'ENG', 'หนังสือภาษาอังกฤษและวรรณกรรม'),
('ภาษาไทย', 'THAI', 'หนังสือภาษาไทยและวรรณกรรม'),
('ประวัติศาสตร์', 'HIST', 'หนังสือประวัติศาสตร์และสังคมศาสตร์'),
('ศิลปะและการออกแบบ', 'ART', 'หนังสือศิลปะ การออกแบบ และสถาปัตยกรรม'),
('การจัดการและธุรกิจ', 'BUS', 'หนังสือการจัดการ การตลาด และธุรกิจ'),
('การศึกษา', 'EDU', 'หนังสือด้านการศึกษาและการสอน'),
('วิศวกรรม', 'ENG_TECH', 'หนังสือด้านวิศวกรรมและเทคนิค'),
('การแพทย์และสาธารณสุข', 'MED', 'หนังสือด้านการแพทย์และสุขภาพ'),
('กฎหมาย', 'LAW', 'หนังสือกฎหมายและนิติศาสตร์'),
('เศรษฐศาสตร์', 'ECON', 'หนังสือเศรษฐศาสตร์และการเงิน'),
('จิตวิทยา', 'PSY', 'หนังสือจิตวิทยาและพฤติกรรมศาสตร์'),
('การเกษตร', 'AGR', 'หนังสือด้านการเกษตรและประมง'),
('การท่องเที่ยว', 'TOUR', 'หนังสือด้านการท่องเที่ยวและบริการ'),
('ดนตรีและนาฏศิลป์', 'MUSIC', 'หนังสือด้านดนตรี การแสดง และนาฏศิลป์'),
('กีฬาและนันทนาการ', 'SPORT', 'หนังสือด้านกีฬาและสุขภาพ'),
('ศาสนาและปรัชญา', 'REL', 'หนังสือด้านศาสนาและปรัชญา'),
('นิยายและวรรณกรรม', 'NOVEL', 'หนังสือนิยายและเรื่องสั้น');

-- ===============================
-- STATUS UPDATE QUERIES (Optional)
-- ===============================

-- อัปเดตสถานะหนังสือที่มีการจองแต่ไม่ได้ตั้งสถานะเป็น reserved
-- UPDATE books b
-- SET status = 'reserved'
-- WHERE b.book_id IN (
--     SELECT DISTINCT r.book_id
--     FROM reservations r
--     WHERE r.status = 'active'
-- ) 
-- AND b.available_copies = 0 
-- AND b.status = 'unavailable';

-- อัปเดตสถานะหนังสือที่ไม่มีการจองและมีสำเนาว่าง
-- UPDATE books b
-- SET status = 'available'
-- WHERE b.book_id NOT IN (
--     SELECT DISTINCT r.book_id
--     FROM reservations r
--     WHERE r.status = 'active'
-- ) 
-- AND b.available_copies > 0 
-- AND b.status IN ('unavailable', 'reserved');

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
