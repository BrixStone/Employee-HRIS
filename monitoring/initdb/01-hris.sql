SET SQL_MODE   = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone  = "+08:00";
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0; -- disable while building tables

CREATE DATABASE IF NOT EXISTS hris
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hris;

-- ============================================================
-- 1. DEPARTMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS departments (
    id          BIGINT       NOT NULL AUTO_INCREMENT,
    code        VARCHAR(20)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description TEXT         DEFAULT NULL,
    status      VARCHAR(20)  NOT NULL DEFAULT 'Active',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY departments_code_key (code),
    UNIQUE KEY departments_name_key (name),
    CONSTRAINT departments_status_check CHECK (status IN ('Active','Inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO departments
    (code, name, description, status)
VALUES
    ('HRD', 'Human Resources',
     'Recruitment, employee relations and leave administration',
     'Active'),

    ('IT', 'Information Technology',
     'Software development, systems and technical support',
     'Active'),

    ('FIN', 'Finance',
     'Accounting, payroll and budgeting',
     'Active');

-- ============================================================
-- 2. POSITIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS positions (
    id          BIGINT       NOT NULL AUTO_INCREMENT,
    code        VARCHAR(20)  NOT NULL,
    title       VARCHAR(100) NOT NULL,
    description TEXT         DEFAULT NULL,
    status      VARCHAR(20)  NOT NULL DEFAULT 'Active',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY positions_code_key  (code),
    UNIQUE KEY positions_title_key (title),
    CONSTRAINT positions_status_check CHECK (status IN ('Active','Inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INITIAL POSITIONS
-- ============================================================
INSERT IGNORE INTO positions
    (code, title, description, status)
VALUES
    ('HRO', 'HR Officer',
     'Handles leave approvals and employee records',
     'Active'),

    ('DEV', 'Software Developer',
     'Builds and maintains internal applications',
     'Active'),

    ('ACC', 'Accountant',
     'Prepares financial reports and payroll',
     'Active'),

    ('ITS', 'IT Support Specialist',
     'Provides helpdesk and hardware support',
     'Active'),

    ('SYS', 'System Administrator',
     'Manages servers, accounts and system security',
     'Active');

-- ============================================================
-- 3. EMPLOYEES
-- ============================================================
CREATE TABLE IF NOT EXISTS employees (
    id                BIGINT       NOT NULL AUTO_INCREMENT,
    employee_id       VARCHAR(20)  NOT NULL,
    first_name        VARCHAR(100) NOT NULL,
    middle_name       VARCHAR(100) DEFAULT NULL,
    last_name         VARCHAR(100) NOT NULL,
    suffix            VARCHAR(20)  DEFAULT NULL,
    date_of_birth     DATE         DEFAULT NULL,
    gender            VARCHAR(20)  DEFAULT NULL,
    phone             VARCHAR(30)  DEFAULT NULL,
    email             VARCHAR(150) DEFAULT NULL,
    address           TEXT         DEFAULT NULL,
    department_id     BIGINT       DEFAULT NULL,
    position_id       BIGINT       DEFAULT NULL,
    date_hired        DATE         NOT NULL,
    employment_type   VARCHAR(50)  NOT NULL DEFAULT 'Full-time',
    employment_status VARCHAR(30)  NOT NULL DEFAULT 'Active',
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY employees_employee_id_key (employee_id),
    KEY idx_employees_department  (department_id),
    KEY idx_employees_position    (position_id),
    KEY idx_employees_status      (employment_status),
    KEY idx_employees_last_name   (last_name),
    CONSTRAINT fk_employee_department
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_employee_position
        FOREIGN KEY (position_id) REFERENCES positions (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT employee_status_check
        CHECK (employment_status IN ('Active','Inactive','On Leave','Suspended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id              BIGINT       NOT NULL AUTO_INCREMENT,
    employee_id     BIGINT       DEFAULT NULL,
    username        VARCHAR(100) NOT NULL,
    password        VARCHAR(255) NOT NULL,
    role            VARCHAR(30)  NOT NULL DEFAULT 'employee',
    status          VARCHAR(20)  NOT NULL DEFAULT 'Active',
    setup_completed TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY users_username_key (username),
    KEY idx_users_employee (employee_id),
    CONSTRAINT fk_user_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_setup_tokens (
    id         BIGINT       NOT NULL AUTO_INCREMENT,
    user_id    BIGINT       NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     DEFAULT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_setup_token_hash (token_hash),
    KEY idx_setup_token_user (user_id),
    KEY idx_setup_token_expiry (expires_at),

    CONSTRAINT fk_setup_token_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Passwords below are PHP password_hash() $2y$ hashes for 'password123'
-- To regenerate: php -r "echo password_hash('password123', PASSWORD_DEFAULT);"
-- ============================================================
-- 5. ATTENDANCE (after employees so FK works)
-- ============================================================
CREATE TABLE IF NOT EXISTS attendance (
    id          BIGINT        NOT NULL AUTO_INCREMENT,
    employee_id BIGINT        DEFAULT NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    email       VARCHAR(255)  DEFAULT NULL,
    time_in     DATETIME      DEFAULT NULL,
    time_out    DATETIME      DEFAULT NULL,
    workhours   DECIMAL(10,2) DEFAULT NULL,
    Break_Time  DATETIME      DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_attendance_email_time_in (email, time_in),
    KEY idx_attendance_employee      (employee_id),
    CONSTRAINT fk_attendance_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- employee_id backfilled from email via subquery (MySQL 8 compatible)
-- ============================================================
-- 6. LEAVE (legacy simple balance)
-- ============================================================
CREATE TABLE IF NOT EXISTS `leave` (
    id             BIGINT      NOT NULL AUTO_INCREMENT,
    created_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    annual_leave   SMALLINT    DEFAULT NULL,
    sick_leave     SMALLINT    DEFAULT NULL,
    personal_leave SMALLINT    DEFAULT NULL,
    email          VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_leave_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. LEAVE TYPES
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_types (
    id           BIGINT       NOT NULL AUTO_INCREMENT,
    leave_name   VARCHAR(100) NOT NULL,
    description  TEXT         DEFAULT NULL,
    default_days INT          NOT NULL DEFAULT 0,
    status       VARCHAR(20)  NOT NULL DEFAULT 'Active',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY leave_types_leave_name_key (leave_name),
    CONSTRAINT leave_types_status_check CHECK (status IN ('Active','Inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO leave_types (id, leave_name, description, default_days, status, created_at, updated_at) VALUES
(1,'Annual Leave',      'Paid time off for vacation, rest, and personal recreation',12, 'Active','2026-10-22 21:50:27','2026-10-22 21:50:27'),
(2,'Sick Leave',        'Paid leave for illness, medical appointments, and health recovery',6,'Active','2026-10-22 21:50:27','2026-10-22 21:50:27'),
(3,'Personal Leave',    'Leave for urgent personal matters or emergencies',3,'Active','2026-10-22 21:50:27','2026-10-22 21:50:27'),
(4,'Maternity Leave',   'Paid leave for female employees during childbirth and postpartum recovery',105,'Active','2026-10-22 21:50:27','2026-10-22 21:50:27'),
(5,'Paternity Leave',   'Paid leave for married male employees upon child delivery',7,'Active','2026-10-22 21:50:27','2026-10-22 21:50:27'),
(6,'Bereavement Leave', 'Leave taken upon the death of an immediate family member',3,'Active','2026-10-22 21:50:27','2026-10-22 21:50:27'),
(7,'Emergency Leave',   'Leave for unforeseen emergencies, calamities, or disasters',3,'Active','2026-10-22 21:50:27','2026-10-22 21:50:27');

-- ============================================================
-- 8. LEAVE BALANCES
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_balances (
    id             BIGINT       NOT NULL AUTO_INCREMENT,
    employee_id    BIGINT       NOT NULL,
    leave_type_id  BIGINT       NOT NULL,
    year           INT          NOT NULL,
    allocated_days DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    used_days      DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    remaining_days DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY leave_balances_emp_type_year_key (employee_id, leave_type_id, year),
    KEY idx_lb_type (leave_type_id),
    CONSTRAINT fk_lb_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lb_type
        FOREIGN KEY (leave_type_id) REFERENCES leave_types (id)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. LEAVE REQUESTS
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_requests (
    id             BIGINT       NOT NULL AUTO_INCREMENT,
    employee_id    BIGINT       NOT NULL,
    leave_type_id  BIGINT       NOT NULL,
    start_date     DATE         NOT NULL,
    end_date       DATE         NOT NULL,
    number_of_days DECIMAL(6,2) NOT NULL,
    reason         TEXT         DEFAULT NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'Pending',
    approved_by    BIGINT       DEFAULT NULL, -- fixed: was INT
    approved_at    DATETIME     DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lr_emp       (employee_id),
    KEY idx_lr_status    (status),
    KEY idx_lr_start_end (start_date, end_date),
    KEY idx_lr_type      (leave_type_id),
    KEY idx_lr_approved  (approved_by),
    CONSTRAINT fk_lr_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lr_type
        FOREIGN KEY (leave_type_id) REFERENCES leave_types (id)
        ON UPDATE CASCADE,
    CONSTRAINT fk_lr_approved_by
        FOREIGN KEY (approved_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_lr_dates CHECK (end_date >= start_date),
    CONSTRAINT chk_lr_days  CHECK (number_of_days > 0),
    CONSTRAINT leave_requests_status_check
        CHECK (status IN ('Pending','Approved','Rejected','Cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 10. LEAVE REQUEST LOGS (no FK on purpose — audit trail)
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_request_logs (
    id                 BIGINT       NOT NULL AUTO_INCREMENT,
    leave_request_id   BIGINT       NOT NULL,
    employee_id        BIGINT       NOT NULL,
    action             VARCHAR(30)  NOT NULL,
    old_status         VARCHAR(30)  DEFAULT NULL,
    new_status         VARCHAR(30)  DEFAULT NULL,
    performed_by_id    BIGINT       DEFAULT NULL,
    performed_by_name  VARCHAR(255) DEFAULT NULL,
    performed_by_email VARCHAR(255) DEFAULT NULL,
    performed_by_role  VARCHAR(30)  DEFAULT NULL,
    remarks            TEXT         DEFAULT NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_leave_request_logs_employee (employee_id, created_at),
    KEY idx_leave_request_logs_request  (leave_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only insert logs that reference existing employees (6 and 7)
-- Historical logs for employees 1,2,3 are excluded (orphaned)

-- ============================================================
-- 11. DEDUCTION RATES
-- ============================================================
CREATE TABLE IF NOT EXISTS deduction_rates (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(100) NOT NULL,
    type       ENUM('Percentage','Fixed') NOT NULL DEFAULT 'Percentage',
    rate       DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    applies_to ENUM('Basic','Gross') NOT NULL DEFAULT 'Basic',
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deduction_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO deduction_rates (name, type, rate, applies_to) VALUES
('SSS',             'Percentage',  4.5000, 'Basic'),
('PhilHealth',      'Percentage',  2.5000, 'Basic'),
('Pag-IBIG',        'Fixed',      100.0000,'Basic'),
('Withholding Tax', 'Percentage',  0.0000, 'Gross');

-- ============================================================
-- 12. EMPLOYEE SALARIES
-- ============================================================
CREATE TABLE IF NOT EXISTS employee_salaries (
    id             BIGINT        NOT NULL AUTO_INCREMENT,
    employee_id    BIGINT        NOT NULL,
    pay_type       ENUM('Monthly','Daily','Hourly') NOT NULL DEFAULT 'Monthly',
    basic_salary   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    allowance      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    effective_date DATE          NOT NULL,
    status         ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_emp_salary_employee (employee_id),
    CONSTRAINT fk_emp_salary_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE CASCADE,
    CONSTRAINT chk_basic_salary CHECK (basic_salary >= 0),
    CONSTRAINT chk_allowance    CHECK (allowance    >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. PAYROLL PERIODS
-- ============================================================
CREATE TABLE IF NOT EXISTS payroll_periods (
    id          BIGINT       NOT NULL AUTO_INCREMENT,
    period_name VARCHAR(100) NOT NULL,
    start_date  DATE         NOT NULL,
    end_date    DATE         NOT NULL,
    pay_date    DATE         NOT NULL,
    status      ENUM('Draft','Processed','Paid','Cancelled') NOT NULL DEFAULT 'Draft',
    created_by  BIGINT       DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_period_range (start_date, end_date),
    KEY idx_period_created_by (created_by),
    CONSTRAINT fk_period_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_period_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. PAYSLIPS
-- ============================================================
CREATE TABLE IF NOT EXISTS payslips (
    id                 BIGINT        NOT NULL AUTO_INCREMENT,
    payroll_period_id  BIGINT        NOT NULL,
    employee_id        BIGINT        NOT NULL,
    pay_type           ENUM('Monthly','Daily','Hourly') NOT NULL DEFAULT 'Monthly',
    basic_salary       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    working_days       DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
    days_worked        DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
    absent_days        DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
    late_minutes       INT           NOT NULL DEFAULT 0,
    ot_hours           DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
    ot_rate_multiplier DECIMAL(4,2)  NOT NULL DEFAULT 1.25,
    basic_pay          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    allowance          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    overtime_pay       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    other_earnings     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gross_pay          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sss                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    philhealth         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pagibig            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    withholding_tax    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    absences_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    late_deduction     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    other_deductions   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_deductions   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_pay            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    remarks            VARCHAR(255)  DEFAULT NULL,
    status             ENUM('Draft','Finalized','Paid') NOT NULL DEFAULT 'Draft',
    created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payslip_period_employee (payroll_period_id, employee_id),
    KEY idx_payslip_employee (employee_id),
    KEY idx_payslip_status   (status),
    CONSTRAINT fk_payslip_period
        FOREIGN KEY (payroll_period_id) REFERENCES payroll_periods (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_payslip_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AUTO_INCREMENT VALUES
-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- END OF HRIS DATABASE INITIALIZATION
-- ============================================================