-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 24, 2026 at 10:39 PM
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
-- Database: `mut_dms`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_reg_number` varchar(50) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_reg` varchar(50) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `affected_record` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `course_name` varchar(255) NOT NULL,
  `department_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `dept_code` varchar(20) NOT NULL,
  `dept_name` varchar(100) NOT NULL,
  `school` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `reg_number` varchar(50) NOT NULL,
  `module_type` enum('Bursary','Resit','Retake','Fees','Special_Exam') NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `status` enum('Draft','Pending_COD','Pending_Dean','Pending_Registrar','Pending_Finance','Pending_DVC','Approved','Rejected','Completed') DEFAULT 'Draft',
  `current_approver` enum('none','cod','dean','registrar','finance') DEFAULT 'none',
  `cod_approved` tinyint(1) DEFAULT 0,
  `cod_approved_by` int(11) DEFAULT NULL,
  `cod_approved_at` timestamp NULL DEFAULT NULL,
  `cod_comments` text DEFAULT NULL,
  `cod_signer_name` varchar(100) DEFAULT NULL,
  `cod_signed_at` timestamp NULL DEFAULT NULL,
  `cod_recommendation` enum('recommended','not_recommended') DEFAULT NULL,
  `cod_rejection_reason` text DEFAULT NULL,
  `dean_approved` tinyint(1) DEFAULT 0,
  `dean_approved_by` int(11) DEFAULT NULL,
  `dean_approved_at` timestamp NULL DEFAULT NULL,
  `dean_comments` text DEFAULT NULL,
  `dean_signer_name` varchar(100) DEFAULT NULL,
  `dean_signed_at` timestamp NULL DEFAULT NULL,
  `dean_decision` enum('approved','rejected') DEFAULT NULL,
  `dean_rejection_reason` text DEFAULT NULL,
  `registrar_approved` tinyint(1) DEFAULT 0,
  `registrar_approved_by` int(11) DEFAULT NULL,
  `registrar_approved_at` timestamp NULL DEFAULT NULL,
  `registrar_comments` text DEFAULT NULL,
  `finance_approved` tinyint(1) DEFAULT 0,
  `finance_approved_by` int(11) DEFAULT NULL,
  `finance_approved_at` timestamp NULL DEFAULT NULL,
  `finance_signer_name` varchar(100) DEFAULT NULL,
  `finance_signed_at` timestamp NULL DEFAULT NULL,
  `finance_comments` text DEFAULT NULL,
  `finance_rejection_reason` text DEFAULT NULL,
  `registrar_signer_name` varchar(100) DEFAULT NULL,
  `registrar_signed_at` timestamp NULL DEFAULT NULL,
  `registrar_stamp` varchar(50) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `last_rejection_reason` text DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `student_visible_status` varchar(100) DEFAULT 'Submitted - Awaiting Processing',
  `is_read` tinyint(1) DEFAULT 0,
  `admin_message` text DEFAULT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `registrar_finance_approved` tinyint(1) DEFAULT 0,
  `amount_paid` varchar(50) DEFAULT NULL,
  `registrar_finalised` tinyint(1) NOT NULL DEFAULT 0,
  `registrar_finalised_at` datetime DEFAULT NULL,
  `dvc_approved` tinyint(1) NOT NULL DEFAULT 0,
  `dvc_approved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `exam_applications_summary`
-- (See below for the actual view)
--
CREATE TABLE `exam_applications_summary` (
`document_id` int(11)
,`reg_number` varchar(50)
,`student_name` varchar(100)
,`course` varchar(100)
,`dept_name` varchar(100)
,`school` varchar(100)
,`module_type` enum('Bursary','Resit','Retake','Fees','Special_Exam')
,`title` varchar(200)
,`status` enum('Draft','Pending_COD','Pending_Dean','Pending_Registrar','Pending_Finance','Pending_DVC','Approved','Rejected','Completed')
,`upload_date` timestamp
,`exam_type` enum('Resit','Retake','Special')
,`exam_month` varchar(20)
,`exam_year` int(11)
,`application_type` enum('Financial','Medical','Compassionate')
,`special_exam_phase` enum('approval_request','digital_form')
);

-- --------------------------------------------------------

--
-- Table structure for table `form_units`
--

CREATE TABLE `form_units` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `form_type` enum('resit_retake','special_exam') DEFAULT 'resit_retake',
  `unit_code` varchar(20) NOT NULL,
  `unit_title` varchar(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_reg_number` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `type` enum('status_update','approval','rejection','general') DEFAULT 'general',
  `related_document_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `pending_approvals`
-- (See below for the actual view)
--
CREATE TABLE `pending_approvals` (
`id` int(11)
,`reg_number` varchar(50)
,`student_name` varchar(100)
,`module_type` enum('Bursary','Resit','Retake','Fees','Special_Exam')
,`title` varchar(200)
,`status` enum('Draft','Pending_COD','Pending_Dean','Pending_Registrar','Pending_Finance','Pending_DVC','Approved','Rejected','Completed')
,`current_approver` enum('none','cod','dean','registrar','finance')
,`upload_date` timestamp
,`department` varchar(100)
);

-- --------------------------------------------------------

--
-- Table structure for table `resit_retake_forms`
--

CREATE TABLE `resit_retake_forms` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `reg_number` varchar(50) NOT NULL,
  `exam_type` enum('Resit','Retake','Special') NOT NULL,
  `exam_month` varchar(20) NOT NULL,
  `exam_year` int(11) NOT NULL,
  `student_name` varchar(100) NOT NULL,
  `student_phone` varchar(20) DEFAULT NULL,
  `student_email` varchar(100) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `year_of_study` int(11) DEFAULT NULL,
  `student_declaration` tinyint(1) DEFAULT 0,
  `student_signature` varchar(255) DEFAULT NULL,
  `student_signature_date` date DEFAULT NULL,
  `amount_paid` decimal(10,2) DEFAULT NULL,
  `payment_confirmed` tinyint(1) DEFAULT 0,
  `payment_receipt_no` varchar(50) DEFAULT NULL,
  `status` enum('Pending_COD','Pending_Dean','Pending_Payment','Pending_Registrar','Pending_Finance','Approved','Rejected') DEFAULT 'Pending_COD'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `special_exam_applications`
--

CREATE TABLE `special_exam_applications` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `reg_number` varchar(50) NOT NULL,
  `application_type` enum('Financial','Medical','Compassionate') NOT NULL,
  `exam_month` varchar(20) NOT NULL,
  `exam_year` int(11) NOT NULL,
  `reason_description` text NOT NULL,
  `units` text NOT NULL,
  `evidence_file_name` varchar(255) DEFAULT NULL,
  `evidence_file_path` varchar(500) DEFAULT NULL,
  `evidence_file_size` int(11) DEFAULT NULL,
  `evidence_file_type` varchar(50) DEFAULT NULL,
  `evidence_description` text DEFAULT NULL,
  `student_name` varchar(100) NOT NULL,
  `student_phone` varchar(20) DEFAULT NULL,
  `student_email` varchar(100) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `year_of_study` int(11) DEFAULT NULL,
  `student_declaration` tinyint(1) DEFAULT 0,
  `student_signature_date` date DEFAULT NULL,
  `status` enum('Pending_COD','Pending_Dean','Pending_Registrar','Pending_Finance','Pending_DVC','Approved','Rejected','Approved_Letter_Sent') DEFAULT 'Pending_COD',
  `phase` enum('approval_request','digital_form') DEFAULT 'approval_request',
  `approval_letter_path` varchar(500) DEFAULT NULL,
  `approval_letter_sent_at` timestamp NULL DEFAULT NULL,
  `parent_application_id` int(11) DEFAULT NULL,
  `dean_recommendation` enum('recommended','not_recommended') DEFAULT NULL,
  `dean_rec_date` timestamp NULL DEFAULT NULL,
  `dean_rec_name` varchar(100) DEFAULT NULL,
  `registrar_rec` enum('recommended','not_recommended') DEFAULT NULL,
  `registrar_rec_date` timestamp NULL DEFAULT NULL,
  `registrar_rec_name` varchar(100) DEFAULT NULL,
  `dvc_decision` enum('approved','rejected') DEFAULT NULL,
  `dvc_decision_date` timestamp NULL DEFAULT NULL,
  `dvc_name` varchar(100) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `special_exam_units`
--

CREATE TABLE `special_exam_units` (
  `id` int(11) NOT NULL,
  `special_exam_id` int(11) NOT NULL,
  `unit_code` varchar(20) NOT NULL,
  `unit_title` varchar(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `student_document_summary`
-- (See below for the actual view)
--
CREATE TABLE `student_document_summary` (
`reg_number` varchar(50)
,`full_name` varchar(100)
,`total_documents` bigint(21)
,`pending_count` decimal(22,0)
,`approved_count` decimal(22,0)
,`rejected_count` decimal(22,0)
);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `reg_number` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `school` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('student','admin','super_admin') DEFAULT 'student',
  `admin_role` enum('none','cod','dean','registrar','finance') DEFAULT 'none',
  `department_id` int(11) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_of_study` int(11) DEFAULT NULL,
  `profile_pix` varchar(255) DEFAULT 'assets/images/default_avatar.png',
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(64) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure for view `exam_applications_summary`
--
DROP TABLE IF EXISTS `exam_applications_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `exam_applications_summary`  AS SELECT `d`.`id` AS `document_id`, `d`.`reg_number` AS `reg_number`, `u`.`full_name` AS `student_name`, `u`.`course` AS `course`, `dept`.`dept_name` AS `dept_name`, `dept`.`school` AS `school`, `d`.`module_type` AS `module_type`, `d`.`title` AS `title`, `d`.`status` AS `status`, `d`.`upload_date` AS `upload_date`, `rrf`.`exam_type` AS `exam_type`, `rrf`.`exam_month` AS `exam_month`, `rrf`.`exam_year` AS `exam_year`, `sea`.`application_type` AS `application_type`, `sea`.`phase` AS `special_exam_phase` FROM ((((`documents` `d` join `users` `u` on(`d`.`reg_number` = `u`.`reg_number`)) left join `departments` `dept` on(`u`.`department_id` = `dept`.`id`)) left join `resit_retake_forms` `rrf` on(`rrf`.`document_id` = `d`.`id`)) left join `special_exam_applications` `sea` on(`sea`.`document_id` = `d`.`id`)) WHERE `d`.`module_type` in ('Resit','Retake','Special_Exam') ;

-- --------------------------------------------------------

--
-- Structure for view `pending_approvals`
--
DROP TABLE IF EXISTS `pending_approvals`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `pending_approvals`  AS SELECT `d`.`id` AS `id`, `d`.`reg_number` AS `reg_number`, `u`.`full_name` AS `student_name`, `d`.`module_type` AS `module_type`, `d`.`title` AS `title`, `d`.`status` AS `status`, `d`.`current_approver` AS `current_approver`, `d`.`upload_date` AS `upload_date`, `dept`.`dept_name` AS `department` FROM ((`documents` `d` join `users` `u` on(`d`.`reg_number` = `u`.`reg_number`)) left join `departments` `dept` on(`u`.`department_id` = `dept`.`id`)) WHERE `d`.`status` in ('Pending_COD','Pending_Dean','Pending_Registrar','Pending_Finance','Pending_DVC') ;

-- --------------------------------------------------------

--
-- Structure for view `student_document_summary`
--
DROP TABLE IF EXISTS `student_document_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `student_document_summary`  AS SELECT `d`.`reg_number` AS `reg_number`, `u`.`full_name` AS `full_name`, count(0) AS `total_documents`, sum(case when `d`.`status` in ('Pending_COD','Pending_Dean','Pending_Registrar','Pending_Finance','Pending_DVC') then 1 else 0 end) AS `pending_count`, sum(case when `d`.`status` = 'Approved' then 1 else 0 end) AS `approved_count`, sum(case when `d`.`status` = 'Rejected' then 1 else 0 end) AS `rejected_count` FROM (`documents` `d` join `users` `u` on(`d`.`reg_number` = `u`.`reg_number`)) GROUP BY `d`.`reg_number`, `u`.`full_name` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_reg_number` (`user_reg_number`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_reg` (`user_reg`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dept_code` (`dept_code`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cod_approved_by` (`cod_approved_by`),
  ADD KEY `dean_approved_by` (`dean_approved_by`),
  ADD KEY `registrar_approved_by` (`registrar_approved_by`),
  ADD KEY `rejected_by` (`rejected_by`),
  ADD KEY `idx_documents_reg` (`reg_number`),
  ADD KEY `idx_documents_status` (`status`),
  ADD KEY `idx_documents_module` (`module_type`),
  ADD KEY `idx_documents_date` (`upload_date`);

--
-- Indexes for table `form_units`
--
ALTER TABLE `form_units`
  ADD PRIMARY KEY (`id`),
  ADD KEY `form_id` (`form_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `related_document_id` (`related_document_id`),
  ADD KEY `idx_notifications_user` (`user_reg_number`),
  ADD KEY `idx_notifications_read` (`is_read`);

--
-- Indexes for table `resit_retake_forms`
--
ALTER TABLE `resit_retake_forms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `idx_resit_forms_reg` (`reg_number`),
  ADD KEY `idx_resit_forms_status` (`status`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `special_exam_applications`
--
ALTER TABLE `special_exam_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `idx_special_exam_reg` (`reg_number`),
  ADD KEY `idx_special_exam_status` (`status`),
  ADD KEY `fk_parent_app` (`parent_application_id`);

--
-- Indexes for table `special_exam_units`
--
ALTER TABLE `special_exam_units`
  ADD PRIMARY KEY (`id`),
  ADD KEY `special_exam_id` (`special_exam_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reg_number` (`reg_number`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `department_id` (`department_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=503;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=102;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- AUTO_INCREMENT for table `form_units`
--
ALTER TABLE `form_units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=390;

--
-- AUTO_INCREMENT for table `resit_retake_forms`
--
ALTER TABLE `resit_retake_forms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `special_exam_applications`
--
ALTER TABLE `special_exam_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `special_exam_units`
--
ALTER TABLE `special_exam_units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_reg_number`) REFERENCES `users` (`reg_number`) ON DELETE CASCADE;

--
-- Constraints for table `courses`
--
ALTER TABLE `courses`
  ADD CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`reg_number`) REFERENCES `users` (`reg_number`) ON DELETE CASCADE,
  ADD CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`cod_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `documents_ibfk_3` FOREIGN KEY (`dean_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `documents_ibfk_4` FOREIGN KEY (`registrar_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `documents_ibfk_5` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `form_units`
--
ALTER TABLE `form_units`
  ADD CONSTRAINT `form_units_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `resit_retake_forms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_reg_number`) REFERENCES `users` (`reg_number`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`related_document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `resit_retake_forms`
--
ALTER TABLE `resit_retake_forms`
  ADD CONSTRAINT `resit_retake_forms_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `resit_retake_forms_ibfk_2` FOREIGN KEY (`reg_number`) REFERENCES `users` (`reg_number`) ON DELETE CASCADE,
  ADD CONSTRAINT `resit_retake_forms_ibfk_3` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `special_exam_applications`
--
ALTER TABLE `special_exam_applications`
  ADD CONSTRAINT `fk_parent_app` FOREIGN KEY (`parent_application_id`) REFERENCES `special_exam_applications` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `special_exam_applications_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `special_exam_applications_ibfk_2` FOREIGN KEY (`reg_number`) REFERENCES `users` (`reg_number`) ON DELETE CASCADE,
  ADD CONSTRAINT `special_exam_applications_ibfk_3` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `special_exam_units`
--
ALTER TABLE `special_exam_units`
  ADD CONSTRAINT `special_exam_units_ibfk_1` FOREIGN KEY (`special_exam_id`) REFERENCES `special_exam_applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;