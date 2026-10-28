-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 27, 2026 at 02:11 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hris`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `email` varchar(255) DEFAULT NULL,
  `time_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `workhours` decimal(10,2) DEFAULT NULL,
  `Break_Time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `created_at`, `email`, `time_in`, `time_out`, `workhours`, `Break_Time`) VALUES
(1, '2026-10-20 08:00:00', 'juan.delacruz@example.com', '2026-10-20 08:00:00', '2026-10-20 17:00:00', 8.00, '2026-10-20 12:00:00'),
(2, '2026-10-21 08:00:00', 'juan.delacruz@example.com', '2026-10-21 08:00:00', '2026-10-21 17:00:00', 8.00, '2026-10-21 12:00:00'),
(3, '2026-10-20 08:15:00', 'maria.santos@example.com', '2026-10-20 08:15:00', '2026-10-20 17:15:00', 8.00, '2026-10-20 12:00:00'),
(4, '2026-10-21 07:55:00', 'maria.santos@example.com', '2026-10-21 07:55:00', '2026-10-21 16:55:00', 8.00, '2026-10-21 12:00:00'),
(5, '2026-10-20 09:00:00', 'paolo.mendoza@example.com', '2026-10-20 09:00:00', '2026-10-20 18:00:00', 8.00, '2026-10-20 12:00:00'),
(6, '2026-10-21 08:30:00', 'paolo.mendoza@example.com', '2026-10-21 08:30:00', '2026-10-21 17:30:00', 8.00, '2026-10-21 12:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` bigint(20) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `code`, `name`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'HRD', 'Human Resources', 'Recruitment, employee relations and leave administration', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(2, 'IT', 'Information Technology', 'Software development, systems and technical support', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(3, 'FIN', 'Finance', 'Accounting, payroll and budgeting', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` bigint(20) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `department_id` bigint(20) DEFAULT NULL,
  `position_id` bigint(20) DEFAULT NULL,
  `date_hired` date NOT NULL,
  `employment_type` varchar(50) NOT NULL DEFAULT 'Full-time',
  `employment_status` varchar(30) NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `employee_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `date_of_birth`, `gender`, `phone`, `email`, `address`, `department_id`, `position_id`, `date_hired`, `employment_type`, `employment_status`, `created_at`, `updated_at`) VALUES
(6, 'EMP-001', 'Brandon', 'Romero', 'Babatuan', NULL, '2026-10-27', 'Male', '09123456789', 'peppafa111@gmail.com', 'Pulpogan 1, Consolacion Cebu', 2, 4, '2026-10-27', 'Full-time', 'Active', '2026-10-27 03:38:06', '2026-10-27 03:38:06'),
(7, 'EMP-002', 'Zyed', 'Kai', 'ser', NULL, '2006-07-06', 'Male', '09123456789', 'peppafa111@gmail.com', 'LapuLapu City', 2, 2, '2026-10-27', 'Full-time', 'Active', '2026-10-27 04:32:56', '2026-10-27 04:32:56');

-- --------------------------------------------------------

--
-- Table structure for table `leave`
--

CREATE TABLE `leave` (
  `id` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `annual_leave` smallint(6) DEFAULT NULL,
  `sick_leave` smallint(6) DEFAULT NULL,
  `personal_leave` smallint(6) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave`
--

INSERT INTO `leave` (`id`, `created_at`, `annual_leave`, `sick_leave`, `personal_leave`, `email`) VALUES
(1, '2026-10-22 21:50:27', 12, 4, 3, 'juan.delacruz@example.com'),
(2, '2026-10-22 21:50:27', 12, 6, 3, 'maria.santos@example.com'),
(3, '2026-10-22 21:50:27', 12, 6, 3, 'paolo.mendoza@example.com'),
(4, '2026-10-27 03:39:54', 12, 6, 3, 'peppafa111@gmail.com');

-- --------------------------------------------------------

--
-- Table structure for table `leave_balances`
--

CREATE TABLE `leave_balances` (
  `id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `leave_type_id` bigint(20) NOT NULL,
  `year` int(11) NOT NULL,
  `allocated_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `used_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `remaining_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `leave_type_id` bigint(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `number_of_days` decimal(6,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_requests`
--

INSERT INTO `leave_requests` (`id`, `employee_id`, `leave_type_id`, `start_date`, `end_date`, `number_of_days`, `reason`, `status`, `approved_by`, `approved_at`, `created_at`) VALUES
(8, 7, 2, '2026-10-27', '2026-10-30', 4.00, 'sakit tyan man', 'Pending', NULL, NULL, '2026-10-27 04:34:41');

-- --------------------------------------------------------

--
-- Table structure for table `leave_request_logs`
--

CREATE TABLE `leave_request_logs` (
  `id` bigint(20) NOT NULL,
  `leave_request_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `action` varchar(30) NOT NULL,
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) DEFAULT NULL,
  `performed_by_id` bigint(20) DEFAULT NULL,
  `performed_by_name` varchar(255) DEFAULT NULL,
  `performed_by_email` varchar(255) DEFAULT NULL,
  `performed_by_role` varchar(30) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave_request_logs`
--

INSERT INTO `leave_request_logs` (`id`, `leave_request_id`, `employee_id`, `action`, `old_status`, `new_status`, `performed_by_id`, `performed_by_name`, `performed_by_email`, `performed_by_role`, `remarks`, `created_at`) VALUES
(1, 1, 1, 'Filed', NULL, 'Pending', 1, 'Juan Dela Cruz', 'juan.delacruz@example.com', 'employee', 'Fever and flu, with medical certificate', '2026-10-01 21:50:27'),
(2, 1, 1, 'Approved', 'Pending', 'Approved', 4, 'Ana Reyes', 'ana.reyes@example.com', 'hr', 'Medical certificate received', '2026-10-02 18:50:27'),
(3, 2, 1, 'Filed', NULL, 'Pending', 1, 'Juan Dela Cruz', 'juan.delacruz@example.com', 'employee', 'Family vacation in Baguio', '2026-10-19 21:50:27'),
(4, 3, 2, 'Filed', NULL, 'Pending', 2, 'Maria Santos', 'maria.santos@example.com', 'employee', 'Bank and government errands', '2026-10-10 21:50:27'),
(5, 3, 2, 'Rejected', 'Pending', 'Rejected', 4, 'Ana Reyes', 'ana.reyes@example.com', 'hr', 'Month-end closing, please refile next week', '2026-10-11 21:50:27'),
(6, 4, 2, 'Filed', NULL, 'Pending', 2, 'Maria Santos', 'maria.santos@example.com', 'employee', 'Out-of-town trip', '2026-10-16 21:50:27'),
(7, 4, 2, 'Cancelled', 'Pending', 'Cancelled', 2, 'Maria Santos', 'maria.santos@example.com', 'employee', NULL, '2026-10-17 21:50:27'),
(8, 5, 3, 'Filed', NULL, 'Pending', 3, 'Paolo Mendoza', 'paolo.mendoza@example.com', 'employee', 'Dental appointment', '2026-10-20 21:50:27'),
(9, 2, 1, 'Approved', 'Pending', 'Approved', 4, NULL, NULL, 'hr', NULL, '2026-10-22 22:10:38'),
(10, 6, 6, 'Filed', NULL, 'Pending', 6, 'Brandon Babatuan', 'peppafa111@gmail.com', 'employee', 'Sakit tyan boss', '2026-10-27 03:40:40'),
(11, 7, 6, 'Filed', NULL, 'Pending', 6, 'Brandon Babatuan', 'peppafa111@gmail.com', 'employee', 'Sakit tyan boss', '2026-10-27 03:41:20'),
(12, 8, 7, 'Filed', NULL, 'Pending', 7, 'Zyed ser', 'peppafa111@gmail.com', 'employee', 'sakit tyan man', '2026-10-27 04:34:41');

-- --------------------------------------------------------

--
-- Table structure for table `leave_types`
--

CREATE TABLE `leave_types` (
  `id` bigint(20) NOT NULL,
  `leave_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `default_days` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_types`
--

INSERT INTO `leave_types` (`id`, `leave_name`, `description`, `default_days`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Annual Leave', 'Paid time off for vacation, rest, and personal recreation', 12, 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(2, 'Sick Leave', 'Paid leave for illness, medical appointments, and health recovery', 6, 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(3, 'Personal Leave', 'Leave for urgent personal matters or emergencies', 3, 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(4, 'Maternity Leave', 'Paid leave for female employees during childbirth and postpartum recovery', 105, 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(5, 'Paternity Leave', 'Paid leave for married male employees upon child delivery', 7, 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(6, 'Bereavement Leave', 'Leave taken upon the death of an immediate family member', 3, 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(7, 'Emergency Leave', 'Leave for unforeseen emergencies, calamities, or disasters', 3, 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27');

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `id` bigint(20) NOT NULL,
  `code` varchar(20) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `positions`
--

INSERT INTO `positions` (`id`, `code`, `title`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'HRO', 'HR Officer', 'Handles leave approvals and employee records', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(2, 'DEV', 'Software Developer', 'Builds and maintains internal applications', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(3, 'ACC', 'Accountant', 'Prepares financial reports and payroll', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(4, 'ITS', 'IT Support Specialist', 'Provides helpdesk and hardware support', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
(5, 'SYS', 'System Administrator', 'Manages servers, accounts and system security', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) NOT NULL,
  `employee_id` bigint(20) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(30) NOT NULL DEFAULT 'employee',
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `employee_id`, `username`, `password`, `role`, `status`, `created_at`, `updated_at`) VALUES
(4, NULL, 'hr', '$2b$10$JdTkXK51HUb76njzk/4J9.CJzueiS9ix9xDsrZWcYKjuHRwrCtxG.', 'hr', 'Active', '2026-10-22 21:50:27', '2026-10-27 02:46:09'),
(5, NULL, 'admin', '$2b$10$JdTkXK51HUb76njzk/4J9.CJzueiS9ix9xDsrZWcYKjuHRwrCtxG.', 'admin', 'Active', '2026-10-22 21:50:27', '2026-10-27 03:39:04'),
(6, 6, 'brandon', '$2y$12$pXxVHfZFnhiNFbH34GLlGu97OQB62sG3kFZcsbiuFz8qciuAxr5Ey', 'employee', 'Active', '2026-10-27 03:39:36', '2026-10-27 03:39:36'),
(7, 7, 'kai', '$2y$12$ISSNra.BMFJMeExjSVFJMuJoGOnBpPkr95NHJ7Ln1X68Dox80A23.', 'employee', 'Active', '2026-10-27 04:33:22', '2026-10-27 04:33:22');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_attendance_email_time_in` (`email`,`time_in`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `departments_code_key` (`code`),
  ADD UNIQUE KEY `departments_name_key` (`name`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employees_employee_id_key` (`employee_id`),
  ADD KEY `idx_employees_department` (`department_id`),
  ADD KEY `idx_employees_position` (`position_id`),
  ADD KEY `idx_employees_status` (`employment_status`),
  ADD KEY `idx_employees_last_name` (`last_name`);

--
-- Indexes for table `leave`
--
ALTER TABLE `leave`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_leave_email` (`email`);

--
-- Indexes for table `leave_balances`
--
ALTER TABLE `leave_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `leave_balances_emp_type_year_key` (`employee_id`,`leave_type_id`,`year`),
  ADD KEY `idx_lb_type` (`leave_type_id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lr_emp` (`employee_id`),
  ADD KEY `idx_lr_status` (`status`),
  ADD KEY `idx_lr_start_end` (`start_date`,`end_date`),
  ADD KEY `idx_lr_type` (`leave_type_id`);

--
-- Indexes for table `leave_request_logs`
--
ALTER TABLE `leave_request_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_leave_request_logs_employee` (`employee_id`,`created_at`),
  ADD KEY `idx_leave_request_logs_request` (`leave_request_id`);

--
-- Indexes for table `leave_types`
--
ALTER TABLE `leave_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `leave_types_leave_name_key` (`leave_name`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `positions_code_key` (`code`),
  ADD UNIQUE KEY `positions_title_key` (`title`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_username_key` (`username`),
  ADD KEY `idx_users_employee` (`employee_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `leave`
--
ALTER TABLE `leave`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `leave_balances`
--
ALTER TABLE `leave_balances`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `leave_request_logs`
--
ALTER TABLE `leave_request_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `leave_types`
--
ALTER TABLE `leave_types`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_employee_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_employee_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `leave_balances`
--
ALTER TABLE `leave_balances`
  ADD CONSTRAINT `fk_lb_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lb_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `fk_lr_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lr_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_user_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
