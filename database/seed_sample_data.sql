-- =====================================================================
-- HRIS sample data (for local testing)
--   3 employee accounts, 1 HR account, 1 admin account
--   + departments, positions, leave types, balances, leave requests,
--     transaction history and attendance so every page has data.
--
-- Import AFTER database/hris_mysql.sql, into an EMPTY hris database:
--   phpMyAdmin -> select "hris" -> Import -> choose this file -> Go
--   or: C:\xampp\mysql\bin\mysql.exe -u root < database\seed_sample_data.sql
--
-- Uses fixed ids, so importing twice fails on the first INSERT and
-- nothing is duplicated.
--
-- Every account uses the password:  Hris@2026
-- These are demo credentials. Change them before using this anywhere real.
--
-- Dates are relative to the day you import, so the data always looks recent.
-- =====================================================================

USE hris;
SET NAMES utf8mb4;
SET time_zone = '+08:00';

START TRANSACTION;

-- ---------------------------------------------------------------------
-- Departments & positions
-- ---------------------------------------------------------------------
INSERT INTO departments (id, code, name, description, status) VALUES
(1, 'HRD', 'Human Resources',        'Recruitment, employee relations and leave administration', 'Active'),
(2, 'IT',  'Information Technology', 'Software development, systems and technical support',      'Active'),
(3, 'FIN', 'Finance',                'Accounting, payroll and budgeting',                         'Active');

INSERT INTO positions (id, code, title, description, status) VALUES
(1, 'HRO', 'HR Officer',            'Handles leave approvals and employee records',  'Active'),
(2, 'DEV', 'Software Developer',    'Builds and maintains internal applications',    'Active'),
(3, 'ACC', 'Accountant',            'Prepares financial reports and payroll',        'Active'),
(4, 'ITS', 'IT Support Specialist', 'Provides helpdesk and hardware support',        'Active'),
(5, 'SYS', 'System Administrator',  'Manages servers, accounts and system security', 'Active');

-- ---------------------------------------------------------------------
-- Employees (HR records)
-- ---------------------------------------------------------------------
INSERT INTO employees
    (id, employee_id, first_name, middle_name, last_name, suffix, date_of_birth, gender, phone, email,
     address, department_id, position_id, date_hired, employment_type, employment_status)
VALUES
(1, 'EMP-001', 'Juan',  'Santos', 'Dela Cruz',  NULL, '1995-03-14', 'Male',   '09171234501', 'juan.delacruz@example.com',
    '123 Rizal Ave, Quezon City',    2, 2, '2023-06-01', 'Full-time', 'Active'),
(2, 'EMP-002', 'Maria', 'Lopez',  'Santos',     NULL, '1993-08-22', 'Female', '09171234502', 'maria.santos@example.com',
    '45 Mabini St, Makati City',     3, 3, '2022-01-10', 'Full-time', 'Active'),
(3, 'EMP-003', 'Paolo', 'Garcia', 'Mendoza',    NULL, '1999-11-05', 'Male',   '09171234503', 'paolo.mendoza@example.com',
    '78 Bonifacio Rd, Pasig City',   2, 4, '2025-02-17', 'Full-time', 'Active'),
(4, 'EMP-004', 'Ana',   'Cruz',   'Reyes',      NULL, '1990-05-30', 'Female', '09171234504', 'ana.reyes@example.com',
    '9 Luna St, Mandaluyong City',   1, 1, '2021-04-05', 'Full-time', 'Active'),
(5, 'EMP-005', 'Carlo', 'Ramos',  'Villanueva', NULL, '1988-12-12', 'Male',   '09171234505', 'carlo.villanueva@example.com',
    '210 Aguinaldo Hwy, Cavite',     2, 5, '2020-09-14', 'Full-time', 'Active');

-- ---------------------------------------------------------------------
-- Login accounts (password for all: Hris@2026, bcrypt via password_hash)
-- Sign in with the username or the employee email.
-- ---------------------------------------------------------------------
INSERT INTO users (id, employee_id, username, password, role, status) VALUES
(1, 1, 'juan',  '$2y$10$IJ0.EAgv/fsvX5yKvdU7A.JvgvdaP7Q6/hvtqGf7qRMqwd8m1oBSC', 'employee', 'Active'),
(2, 2, 'maria', '$2y$10$7PzeGw.Gr9Bqhf0nMX7nD.RmQ6xeqCRrxl6o054UXhM0S2gK//HOi', 'employee', 'Active'),
(3, 3, 'paolo', '$2y$10$GqQKJqQ41sr07OqIVKygneLpdHfyUI7bHfuKir2eVWDvnob9LP1Iu', 'employee', 'Active'),
(4, 4, 'hr',    '$2y$10$Gt32fab9DNjxVD.vloBwzuITCMkdwhcQavqqG1jUZn0dZ/S1FRJ2m', 'hr',       'Active'),
(5, 5, 'admin', '$2y$10$.DcT05bVrYvEkxfiUa2CFuLVeC6p4gjtVkTTund7df85yBwbjleJO', 'admin',    'Active');

-- ---------------------------------------------------------------------
-- Leave types (same defaults employee/file_leave.php creates)
-- ---------------------------------------------------------------------
INSERT INTO leave_types (id, leave_name, description, default_days, status) VALUES
(1, 'Annual Leave',      'Paid time off for vacation, rest, and personal recreation',                  12,  'Active'),
(2, 'Sick Leave',        'Paid leave for illness, medical appointments, and health recovery',          6,   'Active'),
(3, 'Personal Leave',    'Leave for urgent personal matters or emergencies',                           3,   'Active'),
(4, 'Maternity Leave',   'Paid leave for female employees during childbirth and postpartum recovery', 105, 'Active'),
(5, 'Paternity Leave',   'Paid leave for married male employees upon child delivery',                  7,   'Active'),
(6, 'Bereavement Leave', 'Leave taken upon the death of an immediate family member',                   3,   'Active'),
(7, 'Emergency Leave',   'Leave for unforeseen emergencies, calamities, or disasters',                 3,   'Active');

-- ---------------------------------------------------------------------
-- Leave balances
--   `leave`          : simple per-email balances shown on the dashboard
--   leave_balances   : per type / year, used by HR approvals
-- Juan's sick leave reflects his approved 2-day request below.
-- ---------------------------------------------------------------------
INSERT INTO `leave` (email, annual_leave, sick_leave, personal_leave) VALUES
('juan.delacruz@example.com', 12, 4, 3),
('maria.santos@example.com',  12, 6, 3),
('paolo.mendoza@example.com', 12, 6, 3);

INSERT INTO leave_balances (employee_id, leave_type_id, year, allocated_days, used_days, remaining_days) VALUES
(1, 1, YEAR(CURDATE()), 12, 0, 12), (1, 2, YEAR(CURDATE()), 6, 2, 4), (1, 3, YEAR(CURDATE()), 3, 0, 3),
(2, 1, YEAR(CURDATE()), 12, 0, 12), (2, 2, YEAR(CURDATE()), 6, 0, 6), (2, 3, YEAR(CURDATE()), 3, 0, 3),
(3, 1, YEAR(CURDATE()), 12, 0, 12), (3, 2, YEAR(CURDATE()), 6, 0, 6), (3, 3, YEAR(CURDATE()), 3, 0, 3);

-- ---------------------------------------------------------------------
-- Leave requests: one of each status (all filed > 24h ago, so the
-- 24-hour filing limit does not block you from filing a new one)
--   approved_by = users.id of the HR account (4)
-- ---------------------------------------------------------------------
INSERT INTO leave_requests
    (id, employee_id, leave_type_id, start_date, end_date, number_of_days, reason, status, approved_by, approved_at, created_at)
VALUES
(1, 1, 2, CURDATE() - INTERVAL 20 DAY, CURDATE() - INTERVAL 19 DAY, 2, 'Fever and flu, with medical certificate',
    'Approved',  4,    NOW() - INTERVAL 20 DAY - INTERVAL 3 HOUR, NOW() - INTERVAL 21 DAY),
(2, 1, 1, CURDATE() + INTERVAL 14 DAY, CURDATE() + INTERVAL 16 DAY, 3, 'Family vacation in Baguio',
    'Pending',   NULL, NULL,                                      NOW() - INTERVAL 3 DAY),
(3, 2, 3, CURDATE() - INTERVAL 10 DAY, CURDATE() - INTERVAL 10 DAY, 1, 'Bank and government errands',
    'Rejected',  4,    NOW() - INTERVAL 11 DAY,                   NOW() - INTERVAL 12 DAY),
(4, 2, 1, CURDATE() + INTERVAL 7 DAY,  CURDATE() + INTERVAL 8 DAY,  2, 'Out-of-town trip',
    'Cancelled', NULL, NULL,                                      NOW() - INTERVAL 6 DAY),
(5, 3, 2, CURDATE() + INTERVAL 2 DAY,  CURDATE() + INTERVAL 2 DAY,  1, 'Dental appointment',
    'Pending',   NULL, NULL,                                      NOW() - INTERVAL 2 DAY);

-- ---------------------------------------------------------------------
-- Transaction history (shown in employee/leave_history.php)
--   employee actions: performed_by_id = employees.id
--   HR actions:       performed_by_id = users.id
-- ---------------------------------------------------------------------
INSERT INTO leave_request_logs
    (leave_request_id, employee_id, action, old_status, new_status,
     performed_by_id, performed_by_name, performed_by_email, performed_by_role, remarks, created_at)
VALUES
(1, 1, 'Filed',     NULL,      'Pending',   1, 'Juan Dela Cruz', 'juan.delacruz@example.com', 'employee',
    'Fever and flu, with medical certificate', NOW() - INTERVAL 21 DAY),
(1, 1, 'Approved',  'Pending', 'Approved',  4, 'Ana Reyes',      'ana.reyes@example.com',     'hr',
    'Medical certificate received',            NOW() - INTERVAL 20 DAY - INTERVAL 3 HOUR),
(2, 1, 'Filed',     NULL,      'Pending',   1, 'Juan Dela Cruz', 'juan.delacruz@example.com', 'employee',
    'Family vacation in Baguio',               NOW() - INTERVAL 3 DAY),
(3, 2, 'Filed',     NULL,      'Pending',   2, 'Maria Santos',   'maria.santos@example.com',  'employee',
    'Bank and government errands',             NOW() - INTERVAL 12 DAY),
(3, 2, 'Rejected',  'Pending', 'Rejected',  4, 'Ana Reyes',      'ana.reyes@example.com',     'hr',
    'Month-end closing, please refile next week', NOW() - INTERVAL 11 DAY),
(4, 2, 'Filed',     NULL,      'Pending',   2, 'Maria Santos',   'maria.santos@example.com',  'employee',
    'Out-of-town trip',                        NOW() - INTERVAL 6 DAY),
(4, 2, 'Cancelled', 'Pending', 'Cancelled', 2, 'Maria Santos',   'maria.santos@example.com',  'employee',
    NULL,                                      NOW() - INTERVAL 5 DAY),
(5, 3, 'Filed',     NULL,      'Pending',   3, 'Paolo Mendoza',  'paolo.mendoza@example.com', 'employee',
    'Dental appointment',                      NOW() - INTERVAL 2 DAY);

-- ---------------------------------------------------------------------
-- Attendance: two completed shifts per employee (yesterday and the day
-- before), 8:00 AM - 5:00 PM with a 12:00 PM break = 8.00 hours.
-- Nothing is logged for today, so Time In still works.
-- ---------------------------------------------------------------------
INSERT INTO attendance (created_at, email, time_in, Break_Time, time_out, workhours) VALUES
(CURDATE() - INTERVAL 2 DAY + INTERVAL 8 HOUR,                    'juan.delacruz@example.com',
 CURDATE() - INTERVAL 2 DAY + INTERVAL 8 HOUR,                    CURDATE() - INTERVAL 2 DAY + INTERVAL 12 HOUR,
 CURDATE() - INTERVAL 2 DAY + INTERVAL 17 HOUR,                   8.00),
(CURDATE() - INTERVAL 1 DAY + INTERVAL 8 HOUR,                    'juan.delacruz@example.com',
 CURDATE() - INTERVAL 1 DAY + INTERVAL 8 HOUR,                    CURDATE() - INTERVAL 1 DAY + INTERVAL 12 HOUR,
 CURDATE() - INTERVAL 1 DAY + INTERVAL 17 HOUR,                   8.00),
(CURDATE() - INTERVAL 2 DAY + INTERVAL 8 HOUR + INTERVAL 15 MINUTE, 'maria.santos@example.com',
 CURDATE() - INTERVAL 2 DAY + INTERVAL 8 HOUR + INTERVAL 15 MINUTE, CURDATE() - INTERVAL 2 DAY + INTERVAL 12 HOUR,
 CURDATE() - INTERVAL 2 DAY + INTERVAL 17 HOUR + INTERVAL 15 MINUTE, 8.00),
(CURDATE() - INTERVAL 1 DAY + INTERVAL 7 HOUR + INTERVAL 55 MINUTE, 'maria.santos@example.com',
 CURDATE() - INTERVAL 1 DAY + INTERVAL 7 HOUR + INTERVAL 55 MINUTE, CURDATE() - INTERVAL 1 DAY + INTERVAL 12 HOUR,
 CURDATE() - INTERVAL 1 DAY + INTERVAL 16 HOUR + INTERVAL 55 MINUTE, 8.00),
(CURDATE() - INTERVAL 2 DAY + INTERVAL 9 HOUR,                    'paolo.mendoza@example.com',
 CURDATE() - INTERVAL 2 DAY + INTERVAL 9 HOUR,                    CURDATE() - INTERVAL 2 DAY + INTERVAL 12 HOUR,
 CURDATE() - INTERVAL 2 DAY + INTERVAL 18 HOUR,                   8.00),
(CURDATE() - INTERVAL 1 DAY + INTERVAL 8 HOUR + INTERVAL 30 MINUTE, 'paolo.mendoza@example.com',
 CURDATE() - INTERVAL 1 DAY + INTERVAL 8 HOUR + INTERVAL 30 MINUTE, CURDATE() - INTERVAL 1 DAY + INTERVAL 12 HOUR,
 CURDATE() - INTERVAL 1 DAY + INTERVAL 17 HOUR + INTERVAL 30 MINUTE, 8.00);

COMMIT;
