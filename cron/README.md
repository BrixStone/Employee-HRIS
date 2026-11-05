# HRIS scheduled jobs (cron)

This folder holds background jobs that run on a schedule, outside of any user
request.

## What's here

| File | Purpose |
|------|---------|
| `expire_pending_leaves.php` | The job: auto-reject leave requests left `Pending` too long. |
| `hris-crontab` | Crontab lines for a Linux host or Docker container. |
| `register-windows-task.ps1` | Registers the job in Windows Task Scheduler (runs it via `docker exec`). |

## The job: expire pending leaves

A leave request that HR never acts on stays `Pending` forever. This job rejects
any request still `Pending` after a cut-off (default **14 days** from when it
was filed).

Each rejection goes through the same `LeaveRequest::reject()` the HR page and the
API use, so it:

- writes a row to the leave audit log (actor shows as **System (auto-expiry)**), and
- fires the realtime `leave.rejected` event to the employee and to HR.

The job is **safe to run repeatedly**: it only ever touches rows that are still
`Pending`, so running it twice changes nothing the second time, and a request an
HR user approved a moment earlier is skipped.

### Running it by hand

Run it inside the app container (it has PHP and the DB settings):

```powershell
# Default 14-day cut-off
docker exec hris_app php /app/cron/expire_pending_leaves.php

# See what it WOULD do, without changing anything
docker exec hris_app php /app/cron/expire_pending_leaves.php --dry-run

# Custom cut-off (e.g. 30 days)
docker exec hris_app php /app/cron/expire_pending_leaves.php --days=30

# Help
docker exec hris_app php /app/cron/expire_pending_leaves.php --help
```

If you have PHP installed directly on your machine, you can also run it from the
project root (it reads the same `DB_*` settings, defaulting to a local XAMPP
MySQL):

```powershell
php cron\expire_pending_leaves.php --dry-run
```

Exit code is `0` on success (even when there was nothing to reject) and `1` if a
rejection failed, so a scheduler can detect problems.

## Scheduling it

Pick the option that matches where you run HRIS.

### Option A — Windows Task Scheduler (your host)

Your app runs in Docker on Windows, so the simplest reliable option is a Windows
scheduled task that calls the job inside the container.

```powershell
# From the project root. Creates a task that runs daily at 02:00.
powershell -ExecutionPolicy Bypass -File cron\register-windows-task.ps1

# Custom time / cut-off
powershell -ExecutionPolicy Bypass -File cron\register-windows-task.ps1 -Time 23:30 -Days 30
```

Manage it afterwards:

```powershell
# Run it immediately (good for testing)
Start-ScheduledTask -TaskName HRIS-ExpirePendingLeaves

# Check when it last ran / will next run
Get-ScheduledTask -TaskName HRIS-ExpirePendingLeaves | Get-ScheduledTaskInfo

# Remove it
powershell -ExecutionPolicy Bypass -File cron\register-windows-task.ps1 -Remove
```

The task runs whether or not you are logged in, but it needs Docker Desktop (and
the `hris_app` container) to be running at that time.

### Option B — Linux / Docker crontab

On a Linux host or inside a long-running container, install the crontab:

```bash
crontab cron/hris-crontab        # replace the current crontab
# or run `crontab -e` and paste the lines from cron/hris-crontab
```

The provided line runs the job daily at 02:00 and appends output to
`/var/log/hris-cron.log`.

> Note: the default `php:8.3-cli` image used by `hris_app` does not run `cron`
> itself, so Option A (Windows Task Scheduler) is the recommended way to
> schedule it for this project. Option B is for a Linux deployment or a
> container that includes a cron daemon.

## Changing the schedule or cut-off

- **Schedule (when it runs):** edit the time in `hris-crontab`, or re-run
  `register-windows-task.ps1` with a different `-Time`.
- **Cut-off (how old is "too old"):** pass `--days=N` to the script, or
  `-Days N` to the Windows setup script.
