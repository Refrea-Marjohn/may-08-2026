-- Fresh database (hosting-safe)
-- No DROP/CREATE DATABASE statements
-- Admin login:
--   username: sdoofcabuyao
--   password: Deped_2026

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `deductions`;
DROP TABLE IF EXISTS `loan_skip_months`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `loans`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `fund_ledger`;
DROP TABLE IF EXISTS `password_reset_pending`;
DROP TABLE IF EXISTS `registration_pending`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','borrower','accountant') DEFAULT 'borrower',
  `profile_photo` varchar(255) DEFAULT NULL,
  `deped_id` varchar(50) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `civil_status` varchar(20) DEFAULT NULL,
  `home_address` text DEFAULT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `surname` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_status` varchar(20) NOT NULL DEFAULT 'active',
  `last_login_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=2;

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `actor_id` int(11) DEFAULT NULL,
  `actor_name` varchar(150) NOT NULL,
  `user_role` varchar(50) NOT NULL,
  `action_type` varchar(20) NOT NULL,
  `target` varchar(255) DEFAULT NULL,
  `page_name` varchar(120) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_actor_id` (`actor_id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_user_role` (`user_role`),
  KEY `idx_page_name` (`page_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1;

CREATE TABLE `loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `loan_amount` decimal(10,2) NOT NULL,
  `loan_purpose` text NOT NULL,
  `loan_term` int(11) NOT NULL,
  `net_pay` decimal(10,2) NOT NULL,
  `school_assignment` varchar(255) NOT NULL,
  `position` varchar(255) NOT NULL,
  `salary_grade` varchar(10) NOT NULL,
  `employment_status` varchar(50) NOT NULL,
  `co_maker_full_name` varchar(150) NOT NULL,
  `co_maker_position` varchar(150) NOT NULL,
  `co_maker_school_assignment` varchar(255) NOT NULL,
  `co_maker_net_pay` decimal(10,2) NOT NULL,
  `co_maker_employment_status` varchar(50) NOT NULL,
  `payslip_filename` varchar(255) NOT NULL,
  `co_maker_payslip_filename` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
  `reviewed_by_id` int(11) DEFAULT NULL,
  `reviewed_by_role` varchar(50) DEFAULT NULL,
  `reviewed_by_name` varchar(150) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `monthly_payment` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `total_interest` decimal(10,2) DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `application_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin_comment` text DEFAULT NULL,
  `co_maker_email` varchar(150) NOT NULL DEFAULT '',
  `previous_loan_id` int(11) DEFAULT NULL,
  `offset_amount` decimal(10,2) DEFAULT NULL,
  `borrower_date_of_birth` date DEFAULT NULL,
  `borrower_years_of_service` int(11) DEFAULT NULL,
  `borrower_id_front_filename` varchar(255) NOT NULL DEFAULT '',
  `borrower_id_back_filename` varchar(255) NOT NULL DEFAULT '',
  `co_maker_date_of_birth` date DEFAULT NULL,
  `co_maker_years_of_service` int(11) DEFAULT NULL,
  `co_maker_id_front_filename` varchar(255) NOT NULL DEFAULT '',
  `co_maker_id_back_filename` varchar(255) NOT NULL DEFAULT '',
  `is_existing_loan` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `loans_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1;

CREATE TABLE `deductions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `borrower_id` int(11) NOT NULL,
  `deduction_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `receipt_filename` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  KEY `borrower_id` (`borrower_id`),
  KEY `posted_by` (`posted_by`),
  CONSTRAINT `deductions_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deductions_ibfk_2` FOREIGN KEY (`borrower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deductions_ibfk_3` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1;

CREATE TABLE `loan_skip_months` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `skip_ym` char(7) NOT NULL COMMENT 'Year-month YYYY-MM',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_loan_skip` (`loan_id`,`skip_ym`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `loan_skip_months_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_skip_months_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1;

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(30) DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1;

CREATE TABLE `fund_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entry_date` date NOT NULL,
  `entry_type` enum('collection','release','adjustment') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1;

CREATE TABLE `password_reset_pending` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `expires_at` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1;

CREATE TABLE `registration_pending` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `expires_at` int(11) NOT NULL,
  `reg_data` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1;

INSERT INTO `users` (
  `id`, `username`, `email`, `password`, `full_name`, `role`,
  `profile_photo`, `deped_id`, `contact_number`, `birth_date`,
  `gender`, `civil_status`, `home_address`, `first_name`,
  `middle_name`, `surname`, `created_at`, `user_status`, `last_login_at`
) VALUES (
  1, 'sdoofcabuyao', 'sdoofcabuyao@gmail.com',
  '$2y$10$70pRi/fVTAY0dT.X6YSJieqWD.keu3UXu61AuqOJrAYYfPrvJRowK',
  'SDO Cabuyao', 'admin',
  NULL, NULL, NULL, NULL,
  NULL, NULL, NULL, NULL,
  NULL, NULL, CURRENT_TIMESTAMP, 'active', NULL
);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
