-- Leave transaction history / audit trail.
-- classes/LeaveAuditLog.php creates this automatically on the first logged
-- action; run it manually in the Supabase SQL editor if you prefer.

CREATE TABLE IF NOT EXISTS public.leave_request_logs (
    id                 BIGSERIAL PRIMARY KEY,
    leave_request_id   BIGINT       NOT NULL,
    employee_id        BIGINT       NOT NULL,   -- owner of the leave request
    action             VARCHAR(30)  NOT NULL,   -- Filed | Approved | Rejected | Cancelled
    old_status         VARCHAR(30),
    new_status         VARCHAR(30),
    performed_by_id    BIGINT,                  -- account that did the action
    performed_by_name  VARCHAR(255),
    performed_by_email VARCHAR(255),
    performed_by_role  VARCHAR(30),             -- employee | hr | admin
    remarks            TEXT,
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_leave_request_logs_employee
    ON public.leave_request_logs (employee_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_leave_request_logs_request
    ON public.leave_request_logs (leave_request_id);
