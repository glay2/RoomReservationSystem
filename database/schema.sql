-- Room Reservation System Database Schema & Initial Seed Data

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `reservation_history`;
DROP TABLE IF EXISTS `reservations`;
DROP TABLE IF EXISTS `rooms`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- Users Table
CREATE TABLE `users` (
  `user_id` INT AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('Administrator', 'Room Manager', 'Requester') NOT NULL DEFAULT 'Requester',
  `account_status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rooms Table
CREATE TABLE `rooms` (
  `room_id` INT AUTO_INCREMENT PRIMARY KEY,
  `room_number` VARCHAR(50) NOT NULL UNIQUE,
  `capacity` INT NOT NULL,
  `location` VARCHAR(100) NOT NULL,
  `room_status` ENUM('Operational', 'Maintenance', 'Unavailable') NOT NULL DEFAULT 'Operational',
  `amenities` VARCHAR(255) DEFAULT 'High-Speed Wi-Fi, Whiteboard',
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reservations Table
CREATE TABLE `reservations` (
  `reservation_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `room_id` INT NOT NULL,
  `purpose` TEXT NOT NULL,
  `attendance` INT NOT NULL DEFAULT 1,
  `reservation_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `status` ENUM('Pending', 'Approved', 'Rejected', 'Cancelled', 'Completed') NOT NULL DEFAULT 'Pending',
  `approved_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_room_date_status` (`room_id`, `reservation_date`, `status`),
  CONSTRAINT `fk_res_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_res_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`room_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_res_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reservation History Table (Audit Log)
CREATE TABLE `reservation_history` (
  `history_id` INT AUTO_INCREMENT PRIMARY KEY,
  `reservation_id` INT NOT NULL,
  `changed_by` INT NOT NULL,
  `previous_status` VARCHAR(50) DEFAULT NULL,
  `new_status` VARCHAR(50) NOT NULL,
  `remarks` TEXT DEFAULT NULL,
  `changed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_history_res` (`reservation_id`),
  CONSTRAINT `fk_hist_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hist_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications Table
CREATE TABLE `notifications` (
  `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `reservation_id` INT DEFAULT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- SEED DATA
-- Administrator credentials: Username: ReserveAdmin | Password: managerroom (same as RoomManager / ReserveManager)
-- Room Manager (Operator) credentials: Username: RoomManager (or ReserveManager) | Password: managerroom
-- Default password for other seeded users: Password123!
-- ==========================================

INSERT INTO `users` (`user_id`, `full_name`, `email`, `password_hash`, `role`, `account_status`) VALUES
(1, 'ReserveAdmin', 'ReserveAdmin', '$2y$10$BHdO5Wt4PYMd3SI33R4cceW7NK6xq6IvgRsDxFf728pDM5iuiOUD.', 'Administrator', 'Active'),
(2, 'RoomManager', 'RoomManager', '$2y$10$BHdO5Wt4PYMd3SI33R4cceW7NK6xq6IvgRsDxFf728pDM5iuiOUD.', 'Room Manager', 'Active'),
(3, 'Elena Ramos (Requester)', 'requester@system.com', '$2y$10$jdjrFieV1afj00ECoUsyB.NcHzZTZgmqi05agllG6XcV3TF/P7EPK', 'Requester', 'Active'),
(4, 'David Chen (Faculty)', 'david@system.com', '$2y$10$jdjrFieV1afj00ECoUsyB.NcHzZTZgmqi05agllG6XcV3TF/P7EPK', 'Requester', 'Active'),
(5, 'Sophia Taylor (Student Rep)', 'sophia@system.com', '$2y$10$jdjrFieV1afj00ECoUsyB.NcHzZTZgmqi05agllG6XcV3TF/P7EPK', 'Requester', 'Active');

INSERT INTO `rooms` (`room_id`, `room_number`, `capacity`, `location`, `room_status`, `amenities`, `description`) VALUES
(1, 'CR-101 (Executive Boardroom)', 24, 'Building A, 1st Floor', 'Operational', '4K Display, Video Conference, Sound System, Smart Podium', 'Premium conference room ideal for high-level executive meetings and hybrid video calls.'),
(2, 'CR-102 (Innovation Lab)', 18, 'Building A, 1st Floor', 'Operational', 'Dual Projectors, Whiteboards, High-Speed Wi-Fi, Modular Desks', 'Flexible collaborative workspace designed for brainstorming, sprint workshops, and team hackathons.'),
(3, 'AUD-201 (Main Auditorium)', 150, 'Building B, 2nd Floor', 'Operational', 'Stage Lighting, Pro Audio System, Dual Giant Screens, Microphones', 'Spacious lecture and keynote auditorium with tiered seating and professional AV system.'),
(4, 'SEM-301 (Seminar Room 3)', 40, 'Building B, 3rd Floor', 'Operational', 'HD Projector, Central AC, Whiteboard, PA System', 'Medium capacity lecture hall configured for workshops, faculty training, and student seminars.'),
(5, 'TR-401 (Tech Training Lab)', 30, 'Building C, 4th Floor', 'Maintenance', '30 Workstations, Projector, Gigabit LAN, AC', 'Computer and software lab. Currently undergoing quarterly hardware upgrade and network recabling.'),
(6, 'MR-205 (Focus Meeting Pod)', 6, 'Building A, 2nd Floor', 'Operational', '55-inch Monitor, Ergonomic Chairs, Conference Cam', 'Cozy huddle room for small 1-on-1s, client interviews, and intimate project reviews.'),
(7, 'MR-206 (Strategy Pod B)', 8, 'Building A, 2nd Floor', 'Unavailable', 'Screen, Whiteboard', 'Temporarily closed for HVAC duct replacement and acoustic panel installation.');

-- Sample Reservations
-- Today's date is assumed around current system time
INSERT INTO `reservations` (`reservation_id`, `user_id`, `room_id`, `purpose`, `attendance`, `reservation_date`, `start_time`, `end_time`, `status`, `approved_by`) VALUES
(1, 3, 1, 'Q4 Strategic Executive Review & Budget Planning', 15, CURDATE(), '09:00:00', '11:00:00', 'Approved', 1),
(2, 4, 1, 'Product Roadmap & Engineering Sync', 12, CURDATE(), '13:00:00', '15:00:00', 'Approved', 1),
(3, 3, 2, 'Design System Alignment & Figma Review', 8, CURDATE(), '10:00:00', '12:00:00', 'Pending', NULL),
(4, 5, 3, 'Annual Tech Symposium Orientation', 110, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '14:00:00', '17:00:00', 'Approved', 1),
(5, 4, 4, 'Guest Speaker: Artificial Intelligence in Modern DevOps', 35, DATE_ADD(CURDATE(), INTERVAL 2 DAY), '10:00:00', '12:00:00', 'Pending', NULL),
(6, 3, 6, 'Vendor Consultation & Cloud Security Demo', 4, DATE_ADD(CURDATE(), INTERVAL -1 DAY), '14:00:00', '15:30:00', 'Completed', 1),
(7, 5, 2, 'Ad-hoc Gaming Club Meetup', 15, DATE_ADD(CURDATE(), INTERVAL -2 DAY), '16:00:00', '18:00:00', 'Rejected', 1);

-- Reservation History Initial Logs
INSERT INTO `reservation_history` (`reservation_id`, `changed_by`, `previous_status`, `new_status`, `remarks`, `changed_at`) VALUES
(1, 3, NULL, 'Pending', 'Reservation submitted by requester.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 1, 'Pending', 'Approved', 'Approved by administrator. Room schedule verified.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 4, NULL, 'Pending', 'Reservation submitted for engineering sync.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 1, 'Pending', 'Approved', 'Approved. No schedule conflicts detected.', DATE_SUB(NOW(), INTERVAL 12 HOUR)),
(3, 3, NULL, 'Pending', 'Initial submission awaiting administrator review.', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(4, 5, NULL, 'Pending', 'Symposium orientation submitted.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(4, 1, 'Pending', 'Approved', 'Auditorium reserved and confirmed.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(5, 4, NULL, 'Pending', 'Submitted for guest lecture.', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(6, 3, NULL, 'Pending', 'Submitted for vendor demo.', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(6, 1, 'Pending', 'Approved', 'Approved.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(6, 1, 'Approved', 'Completed', 'Session ended successfully.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(7, 5, NULL, 'Pending', 'Submitted for club meetup.', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(7, 1, 'Pending', 'Rejected', 'Rejected: Personal recreational gatherings not permitted in Innovation Lab during academic hours.', DATE_SUB(NOW(), INTERVAL 3 DAY));

-- Initial Notifications
INSERT INTO `notifications` (`user_id`, `reservation_id`, `title`, `message`, `is_read`, `created_at`) VALUES
(3, 1, 'Reservation Approved', 'Your reservation request for CR-101 (Executive Boardroom) has been approved by the Administrator.', 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(4, 2, 'Reservation Approved', 'Your reservation request for CR-101 (Product Roadmap) has been confirmed.', 1, DATE_SUB(NOW(), INTERVAL 12 HOUR)),
(5, 4, 'Reservation Approved', 'Your request for AUD-201 (Annual Tech Symposium) is approved.', 0, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(5, 7, 'Reservation Request Rejected', 'Your request for CR-102 was rejected: Personal recreational gatherings not permitted.', 1, DATE_SUB(NOW(), INTERVAL 3 DAY));
