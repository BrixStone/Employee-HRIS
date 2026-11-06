# HRIS scheduled jobs (cron)

Background jobs that run on a schedule, outside of any user request.

## What's here

| File | Purpose |
|------|---------|
| `expire_pending_leaves.php` | The job: remind HR about `Pending` leave every 3 days, auto-reject at 14 days. |
| `scheduler.php` | Runs the job on a repeating interval. This is what the `hris_cron` Docker container runs. |
| `seed_test_request.php` | Testing helper: creates a backdated Pending request so you can see the job act. |
| `hris-crontab` | Crontab lines, only for a Linux host that has a cron daemon. |
| `register-windows-task.ps1` | Optional: schedule the job with Windows Task Scheduler instead of Docker. |

## How it runs (Docker, the default)

The `hris-cron` service in `monitoring/docker-compose.yml` runs `scheduler.php`
in its own container, named **`hris_cron`**. It:

- runs the job once as soon as the container starts, then every hour,
- starts and stops with the rest of the stack (`hris-start.ps1` / `hris-stop.ps1`),
- restarts itself if it crashes (`restart: unless-stopped`),
- shows **healthy** in `docker ps` while it keeps finishing runs,
- writes everything to its log: `docker logs hris_cron`.

Nothing to register on Windows. As long as Docker is running, the job runs.

### Start it the first time

```powershell
cd monitoring
docker compose up -d --build --no-deps hris-cron
```

`--no-deps` only creates the cron container and leaves MySQL, the app and the
seeder alone. After this first time, `.\hris-start.ps1` starts it with everything
else.

### Everyday commands

```powershell
docker ps --filter name=hris_cron          # is it running and healthy?
docker logs hris_cron                      # everything it has done
docker logs -f hris_cron                   # follow the log live (Ctrl+C to stop)
docker restart hris_cron                   # run the job right now, then continue hourly
docker stop hris_cron                      # pause the scheduler
docker start hris_cron                     # resume it
```

### Settings

Set these in `monitoring/.env` (or in your shell), then run
`docker compose up -d --no-deps hris-cron` from `monitoring/` to apply them:

| Setting | Default | Meaning |
|---------|---------|---------|
| `CRON_INTERVAL_SECONDS` | `3600` | Seconds between runs (10 to 86400). |
| `CRON_REMIND_EVERY` | `3` | Remind HR every N days. `0` turns reminders off. |
| `CRON_REJECT_DAYS` | `14` | Auto-reject a request after N days. |

A wrong value (for example `abc`) makes the container stop with a clear error
instead of being silently ignored.

## The job: remind, then reject

For every leave request still `Pending`, by how long ago it was filed:

- **Every 3 days** (day 3, 6, 9, 12): HR gets a reminder. It is logged and
  pushed live as a `leave.reminder` toast.
- **At 14 days**: the request is rejected through `LeaveRequest::reject()`, the
  same code the HR page uses. It is written to the audit log as
  **System (auto-expiry)** and pushed live as `leave.rejected`.

It is safe to run as often as you like. It only touches `Pending` rows, never
sends the same reminder twice, and skips a request HR just approved.

### Running it by hand

```powershell
docker exec hris_app php /app/cron/expire_pending_leaves.php --dry-run   # preview, no changes
docker exec hris_app php /app/cron/expire_pending_leaves.php             # run now
docker exec hris_app php /app/cron/expire_pending_leaves.php --remind-every=7 --days=30
docker exec hris_app php /app/cron/expire_pending_leaves.php --help
```

Exit code is `0` on success (even when there was nothing to do) and `1` if a
reminder or rejection failed.

## Trying it with a test request

```powershell
docker exec hris_app php /app/cron/seed_test_request.php --age=10   # 10 days old: gets a reminder
docker exec hris_app php /app/cron/seed_test_request.php --age=15   # 15 days old: gets rejected
docker exec hris_app php /app/cron/expire_pending_leaves.php        # run the job now
docker exec hris_app php /app/cron/seed_test_request.php --clear    # remove all test requests
```

To see the live toast, keep `http://localhost:8000/hr/leave_management.php` open
(signed in as HR, badge says **Live**) while the job runs.

## Other ways to schedule it

You only need one of these. Use Docker (above) unless you have a reason not to.
If you use another option, stop the Docker scheduler (`docker stop hris_cron`) so
the job is not run twice. Running twice is harmless, because the job never
repeats a reminder, but it is wasted work.

### Windows Task Scheduler

```powershell
powershell -ExecutionPolicy Bypass -File cron\register-windows-task.ps1          # hourly task
powershell -ExecutionPolicy Bypass -File cron\register-windows-task.ps1 -Remove  # delete it
```

It calls `docker exec hris_app ...`, so Docker Desktop must be running.

### Linux crontab

On a Linux host with a cron daemon: `crontab cron/hris-crontab`.
