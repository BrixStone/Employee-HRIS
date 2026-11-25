<?php
/**
 * Attendance rules: shift lifecycle, stale-shift handling and overtime.
 *
 * Shift lifecycle (attendance.status)
 *   OPEN         Time In recorded, Time Out not yet.
 *   COMPLETED    Time Out recorded; hours and overtime calculated.
 *   MISSING_OUT  Time Out was never recorded and the shift went stale.
 *                time_out stays NULL so HR can correct it. Hours are not paid
 *                and overtime is 0 until the record is corrected.
 *
 * A shift is stale when either
 *   - more than MAX_SHIFT_HOURS have passed since time_in, or
 *   - it belongs to an earlier local date and the scheduled shift end plus
 *     OT_CARRYOVER_HOURS has already passed.
 * A night shift that crosses midnight is therefore still valid in the morning,
 * while yesterday's forgotten day shift is not.
 *
 * Dates: every shift date is the local (Asia/Manila) calendar date of time_in,
 * stored in attendance.shift_date. Overnight shifts keep the date they started on.
 *
 * Overtime
 *   overtime_minutes = minutes past the scheduled shift end, but only when that
 *   exceeds the shift's grace buffer, and never more than the hours actually
 *   worked beyond standard_hours. A late arrival that works a normal day gets
 *   no overtime.
 *   overtime_status: NONE | PENDING_APPROVAL | APPROVED | REJECTED.
 *   NULL means the row predates overtime tracking (legacy rule applies).
 */
final class AttendanceService
{
    public const STATUS_OPEN        = 'OPEN';
    public const STATUS_COMPLETED   = 'COMPLETED';
    public const STATUS_MISSING_OUT = 'MISSING_OUT';

    public const OT_NONE             = 'NONE';
    public const OT_PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const OT_APPROVED         = 'APPROVED';
    public const OT_REJECTED         = 'REJECTED';

    /** Longest a shift may stay open before it is treated as forgotten. */
    public const MAX_SHIFT_HOURS = 16;

    /** Hours past the scheduled end that an earlier-date shift may still be closed. */
    public const OT_CARRYOVER_HOURS = 4;

    public const TIMEZONE = 'Asia/Manila';

    /** Used when work_shifts has no usable row. */
    public const DEFAULT_SHIFT = [
        'name'             => 'Regular Day Shift',
        'start_time'       => '08:00:00',
        'end_time'         => '17:00:00',
        'standard_hours'   => 8.0,
        'break_hours'      => 1.0,
        'ot_grace_minutes' => 15,
    ];

    private static bool $schemaReady = false;

    // ─── Time helpers ────────────────────────────────────────────────────────

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::tz());
    }

    private static function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, self::tz());
    }

    // ─── Schema ──────────────────────────────────────────────────────────────

    /**
     * Adds the attendance/overtime columns and the work_shifts table when they
     * are missing. Safe to call on every request. Run it outside a transaction
     * because MySQL commits implicitly on DDL.
     */
    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaReady) {
            return;
        }

        $cols = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN));

        $definitions = [
            'shift_date'             => 'DATE NULL',
            'status'                 => 'VARCHAR(20) NULL',
            'regular_hours'          => 'DECIMAL(10,2) NULL',
            'overtime_minutes'       => 'INT NULL',
            'overtime_hours'         => 'DECIMAL(10,2) NULL',
            'overtime_status'        => 'VARCHAR(20) NULL',
            'overtime_reviewed_by'   => 'BIGINT NULL',
            'overtime_reviewed_at'   => 'DATETIME NULL',
            'missing_out_flagged_at' => 'DATETIME NULL',
        ];

        $added = [];
        foreach ($definitions as $name => $definition) {
            if (!in_array($name, $cols, true)) {
                $pdo->exec("ALTER TABLE attendance ADD COLUMN `{$name}` {$definition}");
                $added[$name] = true;
            }
        }

        if (isset($added['shift_date'])) {
            $pdo->exec('UPDATE attendance SET shift_date = DATE(time_in) WHERE shift_date IS NULL AND time_in IS NOT NULL');
        }
        if (isset($added['status'])) {
            // Existing closed shifts are COMPLETED. Existing open shifts stay NULL
            // (treated as OPEN) and are flagged by the stale check on the next punch.
            $pdo->exec("UPDATE attendance SET status = 'COMPLETED' WHERE status IS NULL AND time_out IS NOT NULL");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS work_shifts (
                id               BIGINT       NOT NULL AUTO_INCREMENT,
                name             VARCHAR(80)  NOT NULL,
                start_time       TIME         NOT NULL DEFAULT '08:00:00',
                end_time         TIME         NOT NULL DEFAULT '17:00:00',
                standard_hours   DECIMAL(4,2) NOT NULL DEFAULT 8.00,
                break_hours      DECIMAL(4,2) NOT NULL DEFAULT 1.00,
                ot_grace_minutes SMALLINT     NOT NULL DEFAULT 15,
                is_default       TINYINT(1)   NOT NULL DEFAULT 0,
                created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if ((int)$pdo->query('SELECT COUNT(*) FROM work_shifts')->fetchColumn() === 0) {
            $d = self::DEFAULT_SHIFT;
            $pdo->prepare('
                INSERT INTO work_shifts (name, start_time, end_time, standard_hours, break_hours, ot_grace_minutes, is_default)
                VALUES (:n, :s, :e, :h, :b, :g, 1)
            ')->execute([
                ':n' => $d['name'],
                ':s' => $d['start_time'],
                ':e' => $d['end_time'],
                ':h' => $d['standard_hours'],
                ':b' => $d['break_hours'],
                ':g' => $d['ot_grace_minutes'],
            ]);
        }

        $empCols = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM employees')->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array('shift_id', $empCols, true)) {
            $pdo->exec('ALTER TABLE employees ADD COLUMN shift_id BIGINT NULL');
        }

        self::$schemaReady = true;
    }

    // ─── Shift configuration ─────────────────────────────────────────────────

    /**
     * The employee's assigned shift (employees.shift_id), else the default
     * work_shifts row, else the built-in default.
     *
     * @return array{name:string,start_time:string,end_time:string,standard_hours:float,break_hours:float,ot_grace_minutes:int}
     */
    public static function getShiftConfig(PDO $pdo, int $employeeId): array
    {
        $stmt = $pdo->prepare('
            SELECT ws.*
            FROM employees e
            JOIN work_shifts ws ON ws.id = e.shift_id
            WHERE e.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $row = $pdo->query('SELECT * FROM work_shifts ORDER BY is_default DESC, id ASC LIMIT 1')
                ->fetch(PDO::FETCH_ASSOC);
        }

        return self::normalizeShift($row ?: []);
    }

    /** @param array<string,mixed> $row */
    public static function normalizeShift(array $row): array
    {
        $d = self::DEFAULT_SHIFT;
        return [
            'name'             => (string)($row['name'] ?? $d['name']),
            'start_time'       => (string)($row['start_time'] ?? $d['start_time']),
            'end_time'         => (string)($row['end_time'] ?? $d['end_time']),
            'standard_hours'   => max(0.0, (float)($row['standard_hours'] ?? $d['standard_hours'])),
            'break_hours'      => max(0.0, (float)($row['break_hours'] ?? $d['break_hours'])),
            'ot_grace_minutes' => max(0, (int)($row['ot_grace_minutes'] ?? $d['ot_grace_minutes'])),
        ];
    }

    /** Scheduled end of the shift that starts on $shiftDate (handles overnight shifts). */
    public static function scheduledEnd(array $shift, string $shiftDate): DateTimeImmutable
    {
        $start = self::at("{$shiftDate} {$shift['start_time']}");
        $end   = self::at("{$shiftDate} {$shift['end_time']}");
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }
        return $end;
    }

    // ─── Pure rules (no database) ────────────────────────────────────────────

    /**
     * @param array{time_in:string,shift_date?:?string} $row An open shift.
     */
    public static function isStale(array $row, array $shift, DateTimeImmutable $now): bool
    {
        $timeIn    = self::at((string)$row['time_in']);
        $shiftDate = !empty($row['shift_date']) ? (string)$row['shift_date'] : $timeIn->format('Y-m-d');

        $elapsedHours = ($now->getTimestamp() - $timeIn->getTimestamp()) / 3600;
        if ($elapsedHours > self::MAX_SHIFT_HOURS) {
            return true;
        }

        if ($shiftDate < $now->format('Y-m-d')) {
            $limit = self::scheduledEnd($shift, $shiftDate)->modify('+' . self::OT_CARRYOVER_HOURS . ' hours');
            return $now > $limit;
        }

        return false;
    }

    /**
     * Hours and overtime for a closed shift.
     *
     * @return array{workhours:float,regular_hours:float,overtime_minutes:int,overtime_hours:float,overtime_status:string}
     */
    public static function computeMetrics(array $shift, string $timeIn, ?string $breakTime, string $timeOut): array
    {
        $in  = self::at($timeIn);
        $out = self::at($timeOut);

        $grossHours = max(0, ($out->getTimestamp() - $in->getTimestamp()) / 3600);
        $workhours  = round(max(0, $grossHours - (!empty($breakTime) ? $shift['break_hours'] : 0.0)), 2);

        $end        = self::scheduledEnd($shift, $in->format('Y-m-d'));
        $pastEndMin = max(0, intdiv($out->getTimestamp() - $end->getTimestamp(), 60));
        $overStdMin = max(0, (int)round(($workhours - $shift['standard_hours']) * 60));

        $otMinutes = $pastEndMin > $shift['ot_grace_minutes'] ? min($pastEndMin, $overStdMin) : 0;
        $otHours   = round($otMinutes / 60, 2);

        return [
            'workhours'        => $workhours,
            'regular_hours'    => round(max(0, $workhours - $otHours), 2),
            'overtime_minutes' => $otMinutes,
            'overtime_hours'   => $otHours,
            'overtime_status'  => $otMinutes > 0 ? self::OT_PENDING_APPROVAL : self::OT_NONE,
        ];
    }

    /** Status of a row, including rows that predate the status column. */
    public static function effectiveStatus(array $row): string
    {
        $status = strtoupper((string)($row['status'] ?? ''));
        if (in_array($status, [self::STATUS_OPEN, self::STATUS_COMPLETED, self::STATUS_MISSING_OUT], true)) {
            return $status;
        }
        return empty($row['time_out']) ? self::STATUS_OPEN : self::STATUS_COMPLETED;
    }

    /**
     * Regular and overtime hours for display. Legacy rows (no overtime_status)
     * fall back to workhours against the standard hours.
     *
     * @return array{regular:float,overtime:float}
     */
    public static function rowHours(array $row, float $standardHours = 8.0): array
    {
        if (self::effectiveStatus($row) !== self::STATUS_COMPLETED) {
            return ['regular' => 0.0, 'overtime' => 0.0];
        }

        $work = (float)($row['workhours'] ?? 0);

        if (!isset($row['overtime_status'])) {
            return [
                'regular'  => round(min($work, $standardHours), 2),
                'overtime' => round(max($work - $standardHours, 0), 2),
            ];
        }

        $ot  = (float)($row['overtime_hours'] ?? 0);
        $reg = isset($row['regular_hours']) ? (float)$row['regular_hours'] : max($work - $ot, 0);
        return ['regular' => round($reg, 2), 'overtime' => round($ot, 2)];
    }

    // ─── Shift lifecycle ─────────────────────────────────────────────────────

    /**
     * Time In. Flags any stale open shift as MISSING_OUT first, then starts a
     * new shift unless a valid one is still running or today's shift is done.
     *
     * @return array{ok:bool,code:string,message:string,id:?int,flagged_dates:string[],time_in:string}
     */
    public static function punchIn(PDO $pdo, int $employeeId, string $email, ?DateTimeImmutable $now = null): array
    {
        self::ensureSchema($pdo);
        $now   ??= self::now();
        $shift   = self::getShiftConfig($pdo, $employeeId);
        $nowStr  = $now->format('Y-m-d H:i:s');
        $today   = $now->format('Y-m-d');

        $pdo->beginTransaction();
        try {
            $flagged = [];
            $active  = self::resolveOpenShifts($pdo, $employeeId, $email, $shift, $now, $flagged);

            $result = ['ok' => false, 'code' => '', 'message' => '', 'id' => null, 'flagged_dates' => $flagged, 'time_in' => $nowStr];

            if ($active !== null) {
                $result['code']    = 'ACTIVE_SHIFT';
                $result['message'] = 'You already have an active shift or break. Please check your status.';
            } else {
                $done = $pdo->prepare('
                    SELECT id FROM attendance
                    WHERE (employee_id = :eid OR email = :email)
                      AND time_out IS NOT NULL
                      AND COALESCE(shift_date, DATE(time_in)) = :today
                    LIMIT 1
                ');
                $done->execute([':eid' => $employeeId, ':email' => $email, ':today' => $today]);

                if ($done->fetch()) {
                    $result['code']    = 'ALREADY_COMPLETED';
                    $result['message'] = 'You have already completed your shift for today and cannot time in again.';
                } else {
                    $pdo->prepare('
                        INSERT INTO attendance (employee_id, email, time_in, shift_date, status)
                        VALUES (:eid, :email, :time_in, :shift_date, :status)
                    ')->execute([
                        ':eid'        => $employeeId,
                        ':email'      => $email,
                        ':time_in'    => $nowStr,
                        ':shift_date' => $today,
                        ':status'     => self::STATUS_OPEN,
                    ]);
                    $result['ok']   = true;
                    $result['code'] = 'OK';
                    $result['id']   = (int)$pdo->lastInsertId();
                }
            }

            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Time Out. Closes the valid open shift as COMPLETED and calculates hours
     * and overtime. Stale open shifts are flagged MISSING_OUT instead of closed.
     *
     * @return array{ok:bool,code:string,message:string,flagged_dates:string[],metrics:?array}
     */
    public static function punchOut(PDO $pdo, int $employeeId, string $email, ?DateTimeImmutable $now = null): array
    {
        self::ensureSchema($pdo);
        $now ??= self::now();
        $shift  = self::getShiftConfig($pdo, $employeeId);
        $nowStr = $now->format('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $flagged = [];
            $active  = self::resolveOpenShifts($pdo, $employeeId, $email, $shift, $now, $flagged);

            if ($active === null) {
                $pdo->commit();
                return [
                    'ok'            => false,
                    'code'          => 'NO_ACTIVE_SHIFT',
                    'message'       => $flagged
                        ? 'Your previous shift has no Time Out and was flagged as Missing Time Out for correction. Time In to start a new shift.'
                        : 'No active shift found to Time Out.',
                    'flagged_dates' => $flagged,
                    'metrics'       => null,
                ];
            }

            $metrics = self::computeMetrics($shift, (string)$active['time_in'], $active['Break_Time'] ?? null, $nowStr);

            $pdo->prepare('
                UPDATE attendance
                SET time_out         = :time_out,
                    workhours        = :workhours,
                    regular_hours    = :regular,
                    overtime_minutes = :ot_min,
                    overtime_hours   = :ot_hours,
                    overtime_status  = :ot_status,
                    shift_date       = COALESCE(shift_date, DATE(time_in)),
                    status           = :status
                WHERE id = :id
            ')->execute([
                ':time_out'  => $nowStr,
                ':workhours' => $metrics['workhours'],
                ':regular'   => $metrics['regular_hours'],
                ':ot_min'    => $metrics['overtime_minutes'],
                ':ot_hours'  => $metrics['overtime_hours'],
                ':ot_status' => $metrics['overtime_status'],
                ':status'    => self::STATUS_COMPLETED,
                ':id'        => $active['id'],
            ]);

            $pdo->commit();
            return ['ok' => true, 'code' => 'OK', 'message' => '', 'flagged_dates' => $flagged, 'metrics' => $metrics];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The employee's running shift, after flagging stale ones. Used by the
     * break action and the dashboard.
     *
     * @return ?array<string,mixed>
     */
    public static function findActiveShift(PDO $pdo, int $employeeId, string $email, ?DateTimeImmutable $now = null): ?array
    {
        self::ensureSchema($pdo);
        $now ??= self::now();
        $shift = self::getShiftConfig($pdo, $employeeId);

        $pdo->beginTransaction();
        try {
            $flagged = [];
            $active  = self::resolveOpenShifts($pdo, $employeeId, $email, $shift, $now, $flagged);
            $pdo->commit();
            return $active;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Approve or reject pending overtime on a completed shift.
     *
     * @param string $decision OT_APPROVED or OT_REJECTED
     */
    public static function reviewOvertime(PDO $pdo, int $attendanceId, string $decision, int $reviewerEmployeeId, ?DateTimeImmutable $now = null): bool
    {
        if (!in_array($decision, [self::OT_APPROVED, self::OT_REJECTED], true)) {
            throw new InvalidArgumentException('Decision must be APPROVED or REJECTED.');
        }
        self::ensureSchema($pdo);
        $now ??= self::now();

        $stmt = $pdo->prepare('
            UPDATE attendance
            SET overtime_status      = :decision,
                overtime_reviewed_by = :reviewer,
                overtime_reviewed_at = :at
            WHERE id = :id AND overtime_status = :pending
        ');
        $stmt->execute([
            ':decision' => $decision,
            ':reviewer' => $reviewerEmployeeId,
            ':at'       => $now->format('Y-m-d H:i:s'),
            ':id'       => $attendanceId,
            ':pending'  => self::OT_PENDING_APPROVAL,
        ]);
        return $stmt->rowCount() > 0;
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * Locks the employee's open shifts, flags stale ones as MISSING_OUT and
     * returns the latest valid one. Must run inside a transaction.
     *
     * @param string[] $flaggedDates Filled with the shift dates that were flagged.
     * @return ?array<string,mixed>
     */
    private static function resolveOpenShifts(PDO $pdo, int $employeeId, string $email, array $shift, DateTimeImmutable $now, array &$flaggedDates): ?array
    {
        $stmt = $pdo->prepare("
            SELECT id, time_in, shift_date, `Break_Time`
            FROM attendance
            WHERE (employee_id = :eid OR email = :email)
              AND time_out IS NULL
              AND time_in IS NOT NULL
              AND (status IS NULL OR status = 'OPEN')
            ORDER BY time_in DESC
            FOR UPDATE
        ");
        $stmt->execute([':eid' => $employeeId, ':email' => $email]);

        $flag   = $pdo->prepare("
            UPDATE attendance
            SET status                 = 'MISSING_OUT',
                shift_date             = COALESCE(shift_date, DATE(time_in)),
                regular_hours          = NULL,
                overtime_minutes       = 0,
                overtime_hours         = 0,
                overtime_status        = 'NONE',
                missing_out_flagged_at = :at
            WHERE id = :id
        ");
        $active = null;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (self::isStale($row, $shift, $now)) {
                $flag->execute([':at' => $now->format('Y-m-d H:i:s'), ':id' => $row['id']]);
                $flaggedDates[] = self::at((string)$row['time_in'])->format('M d, Y');
            } elseif ($active === null) {
                $active = $row;
            }
        }

        return $active;
    }
}
