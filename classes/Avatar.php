<?php
/**
 * Avatar helper.
 *
 * Centralises how an employee's profile picture is resolved so the sidebar,
 * the Pocket Profile modal and the profile page all show the same image.
 *
 * - Uploaded pictures live in /uploads/avatars and are referenced by a
 *   web path stored in employees.avatar.
 * - When no picture has been uploaded, a generated ui-avatars image is used
 *   as a graceful fallback (same as before this feature existed).
 */
final class Avatar
{
    /** Web path (relative to the site root) of the uploads directory. */
    public const WEB_DIR = '/uploads/avatars';

    /** Allowed upload types mapped to canonical extensions. */
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /** Max upload size in bytes (3 MB). */
    public const MAX_BYTES = 3 * 1024 * 1024;

    /** Absolute filesystem path of the uploads directory. */
    public static function storageDir(): string
    {
        return dirname(__DIR__) . '/uploads/avatars';
    }

    /**
     * Make sure the employees.avatar column exists. Safe to call on every
     * request: it only runs an ALTER when the column is missing.
     */
    public static function ensureColumn(PDO $pdo): void
    {
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM employees')->fetchAll(PDO::FETCH_COLUMN);
            $has = false;
            foreach ($cols as $c) {
                if (strcasecmp($c, 'avatar') === 0) { $has = true; break; }
            }
            if (!$has) {
                $pdo->exec("ALTER TABLE employees ADD COLUMN avatar VARCHAR(255) NULL");
            }
        } catch (Throwable $e) {
            // Non-fatal: if we cannot inspect/alter, callers fall back to the
            // generated avatar and simply cannot persist uploads.
            error_log('Avatar::ensureColumn failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolve the avatar URL to display for an employee row.
     *
     * @param array $employee Row that may contain 'avatar', names, email.
     */
    public static function url(array $employee): string
    {
        $stored = trim((string)($employee['avatar'] ?? ''));
        if ($stored !== '') {
            // Stored value is a site-relative web path; verify the file is
            // still on disk before trusting it.
            $abs = dirname(__DIR__) . $stored;
            if (is_file($abs)) {
                // Cache-bust with the file's mtime so a new upload shows at once.
                return $stored . '?v=' . @filemtime($abs);
            }
        }
        return self::fallback($employee);
    }

    /** Generated placeholder avatar (initials on a coloured disc). */
    public static function fallback(array $employee): string
    {
        $name = trim(
            ($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')
        );
        if ($name === '') {
            $name = trim((string)($employee['name'] ?? $employee['full_name'] ?? 'User'));
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($name ?: 'User')
            . '&background=4763ff&color=fff';
    }
}
