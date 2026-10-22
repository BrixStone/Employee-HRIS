-- =====================================================================
-- HRIS database for MySQL / MariaDB (XAMPP + phpMyAdmin)
-- Converted from the Supabase (PostgreSQL) schema.
--
-- Import: phpMyAdmin -> Import -> choose this file -> Go
--    or:  C:\xampp\mysql\bin\mysql.exe -u root < database\hris_mysql.sql
--
-- Type mapping used:
--   bigint generated ... identity  -> BIGINT AUTO_INCREMENT
--   character varying (no length)  -> VARCHAR(255)
--   timestamp with/without tz      -> DATETIME (stored in Asia/Manila time;
--                                     config/database.php sets the session
--                                     time zone to +08:00)
--   numeric (no precision)         -> DECIMAL(10,2)
--   CHECK (x = ANY (ARRAY[...]))   -> CHECK (x IN (...))
--   "Break_Time" (quoted)          -> Break_Time
-- =====================================================================

CREATE DATABASE IF NOT EXISTS hris
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hris;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- ---------------------------------------------------------------------
-- departments
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
    id          BIGINT       NOT NULL AUTO_INCREMENT,
    code        VARCHAR(20)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description TEXT         NULL,
    status      VARCHAR(20)  NOT NULL DEFAULT 'Active',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT departments_pkey PRIMARY KEY (id),
    CONSTRAINT departments_code_key UNIQUE (code),
    CONSTRAINT departments_name_key UNIQUE (name),
    CONSTRAINT departments_status_check CHECK (status IN ('Active', 'Inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- positions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS positions (
    id          BIGINT       NOT NULL AUTO_INCREMENT,
    code        VARCHAR(20)  NOT NULL,
    title       VARCHAR(100) NOT NULL,
    description TEXT         NULL,
    status      VARCHAR(20)  NOT NULL DEFAULT 'Active',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT positions_pkey PRIMARY KEY (id),
    CONSTRAINT positions_code_key UNIQUE (code),
    CONSTRAINT positions_title_key UNIQUE (title),
    CONSTRAINT positions_status_check CHECK (status IN ('Active', 'Inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- employees
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employees (
    id                BIGINT       NOT NULL AUTO_INCREMENT,
    employee_id       VARCHAR(20)  NOT NULL,
    first_name        VARCHAR(100) NOT NULL,
    middle_name       VARCHAR(100) NULL,
    last_name         VARCHAR(100) NOT NULL,
    suffix            VARCHAR(20)  NULL,
    date_of_birth     DATE         NULL,
    gender            VARCHAR(20)  NULL,
    phone             VARCHAR(30)  NULL,
    email             VARCHAR(150) NULL,
    address           TEXT         NULL,
    department_id     BIGINT       NULL,
    position_id       BIGINT       NULL,
    date_hired        DATE         NOT NULL,
    employment_type   VARCHAR(50)  NOT NULL DEFAULT 'Full-time',
    employment_status VARCHAR(30)  NOT NULL DEFAULT 'Active',
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT employees_pkey PRIMARY KEY (id),
    CONSTRAINT employees_employee_id_key UNIQUE (employee_id),
    KEY idx_employees_department (department_id),
    KEY idx_employees_position (position_id),
    KEY idx_employees_status (employment_status),
    KEY idx_employees_last_name (last_name),
    CONSTRAINT fk_employee_department FOREIGN KEY (department_id)
        REFERENCES departments (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_employee_position FOREIGN KEY (position_id)
        REFERENCES positions (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT employee_status_check
        CHECK (employment_status IN ('Active', 'Inactive', 'On Leave', 'Suspended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- users  (login accounts; not in the pasted DDL but required by
--         authentication/login.php, admin/*.php and HR approvals)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id          BIGINT       NOT NULL AUTO_INCREMENT,
    employee_id BIGINT       NULL,
    username    VARCHAR(100) NOT NULL,
    password    VARCHAR(255) NOT NULL,              -- password_hash() output
    role        VARCHAR(30)  NOT NULL DEFAULT 'employee',
    status      VARCHAR(20)  NOT NULL DEFAULT 'Active',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT users_pkey PRIMARY KEY (id),
    CONSTRAINT users_username_key UNIQUE (username),
    KEY idx_users_employee (employee_id),
    CONSTRAINT fk_user_employee FOREIGN KEY (employee_id)
        REFERENCES employees (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- leave  (simple per-email balances used by the employee dashboard)
-- The pasted DDL listed this table three times; it is created once.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leave` (
    id             BIGINT       NOT NULL AUTO_INCREMENT,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    annual_leave   SMALLINT     NULL,
    sick_leave     SMALLINT     NULL,
    personal_leave SMALLINT     NULL,
    email          VARCHAR(255) NULL,
    CONSTRAINT leave_pkey PRIMARY KEY (id),
    KEY idx_leave_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- leave_types
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leave_types (
    id           BIGINT       NOT NULL AUTO_INCREMENT,
    leave_name   VARCHAR(100) NOT NULL,
    description  TEXT         NULL,
    default_days INT          NOT NULL DEFAULT 0,
    status       VARCHAR(20)  NOT NULL DEFAULT 'Active',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT leave_types_pkey PRIMARY KEY (id),
    CONSTRAINT leave_types_leave_name_key UNIQUE (leave_name),
    CONSTRAINT leave_types_status_check CHECK (status IN ('Active', 'Inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- attendance
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
    id         BIGINT        NOT NULL AUTO_INCREMENT,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    email      VARCHAR(255)  NULL,
    time_in    DATETIME      NULL,
    time_out   DATETIME      NULL,
    workhours  DECIMAL(10,2) NULL,                  -- attendance.php rounds to 2 dp
    Break_Time DATETIME      NULL,
    CONSTRAINT attendance_pkey PRIMARY KEY (id),
    KEY idx_attendance_email_time_in (email, time_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- leave_requests
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leave_requests (
    id             BIGINT       NOT NULL AUTO_INCREMENT,
    employee_id    BIGINT       NOT NULL,
    leave_type_id  BIGINT       NOT NULL,
    start_date     DATE         NOT NULL,
    end_date       DATE         NOT NULL,
    number_of_days DECIMAL(6,2) NOT NULL,
    reason         TEXT         NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'Pending',
    approved_by    INT          NULL,               -- users.id of the reviewer
    approved_at    DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT leave_requests_pkey PRIMARY KEY (id),
    KEY idx_lr_emp (employee_id),
    KEY idx_lr_status (status),
    KEY idx_lr_start_end (start_date, end_date),
    KEY idx_lr_type (leave_type_id),
    CONSTRAINT fk_lr_employee FOREIGN KEY (employee_id)
        REFERENCES employees (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_lr_type FOREIGN KEY (leave_type_id)
        REFERENCES leave_types (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_lr_dates CHECK (end_date >= start_date),
    CONSTRAINT chk_lr_days CHECK (number_of_days > 0),
    CONSTRAINT leave_requests_status_check
        CHECK (status IN ('Pending', 'Approved', 'Rejected', 'Cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- leave_balances  (per employee / leave type / year; used by
--                  classes/LeaveBalance.php and HR approvals)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leave_balances (
    id             BIGINT       NOT NULL AUTO_INCREMENT,
    employee_id    BIGINT       NOT NULL,
    leave_type_id  BIGINT       NOT NULL,
    year           INT          NOT NULL,
    allocated_days DECIMAL(6,2) NOT NULL DEFAULT 0,
    used_days      DECIMAL(6,2) NOT NULL DEFAULT 0,
    remaining_days DECIMAL(6,2) NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT leave_balances_pkey PRIMARY KEY (id),
    -- Needed by LeaveBalance::upsertAllocated / ensureRow (ON DUPLICATE KEY)
    CONSTRAINT leave_balances_emp_type_year_key UNIQUE (employee_id, leave_type_id, year),
    KEY idx_lb_type (leave_type_id),
    CONSTRAINT fk_lb_employee FOREIGN KEY (employee_id)
        REFERENCES employees (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_lb_type FOREIGN KEY (leave_type_id)
        REFERENCES leave_types (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- leave_request_logs  (transaction history shown in leave_history.php;
--                      written by classes/LeaveAuditLog.php)
-- No foreign keys on purpose: the audit trail survives deleted requests.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leave_request_logs (
    id                 BIGINT       NOT NULL AUTO_INCREMENT,
    leave_request_id   BIGINT       NOT NULL,
    employee_id        BIGINT       NOT NULL,      -- owner of the request (employees.id)
    action             VARCHAR(30)  NOT NULL,      -- Filed | Approved | Rejected | Cancelled
    old_status         VARCHAR(30)  NULL,
    new_status         VARCHAR(30)  NULL,
    performed_by_id    BIGINT       NULL,          -- employees.id or users.id (see role)
    performed_by_name  VARCHAR(255) NULL,
    performed_by_email VARCHAR(255) NULL,
    performed_by_role  VARCHAR(30)  NULL,          -- employee | hr | admin
    remarks            TEXT         NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT leave_request_logs_pkey PRIMARY KEY (id),
    KEY idx_leave_request_logs_employee (employee_id, created_at),
    KEY idx_leave_request_logs_request (leave_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
