
-- ==========================================================
-- HRIS PAYROLL MODULE
-- MariaDB 10.4.32 compatible
-- ==========================================================

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
    avatar            VARCHAR(255) NULL,                    -- web path to uploaded profile photo
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
-- Add employee_id to attendance if it does not exist.
ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS employee_id BIGINT(20) NULL AFTER id;

-- Add attendance employee index only if missing.
SET @index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'attendance'
      AND INDEX_NAME = 'idx_attendance_employee'
);

SET @sql = IF(
    @index_exists = 0,
    'ALTER TABLE attendance ADD INDEX idx_attendance_employee (employee_id)',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add attendance foreign key if missing.
SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'attendance'
      AND CONSTRAINT_NAME = 'fk_attendance_employee'
);

SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE attendance ADD CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ==========================================================
-- DEDUCTION RATES
-- ==========================================================

CREATE TABLE IF NOT EXISTS deduction_rates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    type ENUM('Percentage', 'Fixed') NOT NULL DEFAULT 'Percentage',
    rate DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    applies_to ENUM('Basic', 'Gross') NOT NULL DEFAULT 'Basic',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deduction_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO deduction_rates
    (name, type, rate, applies_to)
VALUES
    ('SSS', 'Percentage', 4.5000, 'Basic'),
    ('PhilHealth', 'Percentage', 2.5000, 'Basic'),
    ('Pag-IBIG', 'Fixed', 100.0000, 'Basic'),
    ('Withholding Tax', 'Percentage', 0.0000, 'Gross');


-- ==========================================================
-- EMPLOYEE SALARIES
-- ==========================================================

CREATE TABLE IF NOT EXISTS employee_salaries (
    id BIGINT NOT NULL AUTO_INCREMENT,
    employee_id BIGINT(20) NOT NULL,
    pay_type ENUM('Monthly','Daily','Hourly') NOT NULL DEFAULT 'Monthly',
    basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    effective_date DATE NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_emp_salary_employee (employee_id),
    CONSTRAINT fk_emp_salary_employee
        FOREIGN KEY (employee_id) REFERENCES employees(id)
        ON DELETE CASCADE,
    CONSTRAINT chk_basic_salary CHECK (basic_salary >= 0),
    CONSTRAINT chk_allowance CHECK (allowance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==========================================================
-- PAYROLL PERIODS
-- ==========================================================

CREATE TABLE IF NOT EXISTS payroll_periods (
    id BIGINT NOT NULL AUTO_INCREMENT,
    period_name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    pay_date DATE NOT NULL,
    status ENUM('Draft','Processed','Paid','Cancelled')
        NOT NULL DEFAULT 'Draft',
    created_by BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_period_range (start_date, end_date),
    CONSTRAINT chk_period_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==========================================================
-- PAYSLIPS
-- ==========================================================

CREATE TABLE IF NOT EXISTS payslips (
    id BIGINT NOT NULL AUTO_INCREMENT,
    payroll_period_id BIGINT NOT NULL,
    employee_id BIGINT(20) NOT NULL,
    pay_type ENUM('Monthly','Daily','Hourly') NOT NULL DEFAULT 'Monthly',

    basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    working_days DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    days_worked DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    absent_days DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    late_minutes INT NOT NULL DEFAULT 0,
    ot_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    ot_rate_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.25,

    basic_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    overtime_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    other_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    sss DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    philhealth DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pagibig DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    withholding_tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    absences_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    late_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    net_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    remarks VARCHAR(255) DEFAULT NULL,
    status ENUM('Draft','Finalized','Paid') NOT NULL DEFAULT 'Draft',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_payslip_period_employee
        (payroll_period_id, employee_id),

    KEY idx_payslip_employee (employee_id),
    KEY idx_payslip_status (status),

    CONSTRAINT fk_payslip_period
        FOREIGN KEY (payroll_period_id)
        REFERENCES payroll_periods(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_payslip_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(id)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
