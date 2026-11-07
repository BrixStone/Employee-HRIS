<#
.SYNOPSIS
    Registers (or removes) a Windows Task Scheduler job that runs the HRIS
    pending-leave job every hour.

.DESCRIPTION
    For every Pending leave request the job reminds HR every few days
    (realtime toast) and auto-rejects it once it passes the cut-off. Your app
    runs inside the "hris_app" Docker container, so the scheduled task calls
    `docker exec` to run the PHP job in that container. This keeps one source
    of truth: the same script runs whether triggered by Linux cron or Windows
    Task Scheduler.

    It runs hourly so reminders and rejections go out promptly (close to
    realtime). The job is idempotent, so running it every hour is safe: it
    never repeats a reminder or re-rejects a request.

.PARAMETER Days
    Reject requests older than this many days. Default 14.

.PARAMETER RemindEvery
    Remind HR every N days until the cut-off. Default 3. Use 0 to disable.

.PARAMETER Remove
    Delete the scheduled task instead of creating it.

.EXAMPLE
    # Create the hourly task (run from the project root):
    powershell -ExecutionPolicy Bypass -File cron\register-windows-task.ps1

.EXAMPLE
    # Remind weekly, reject after 30 days:
    powershell -ExecutionPolicy Bypass -File cron\register-windows-task.ps1 -RemindEvery 7 -Days 30

.EXAMPLE
    # Remove the task:
    powershell -ExecutionPolicy Bypass -File cron\register-windows-task.ps1 -Remove
#>
[CmdletBinding()]
param(
    [int]$Days = 14,
    [int]$RemindEvery = 3,
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'
$taskName = 'HRIS-PendingLeaveReminders'

# Clean up the task's former name from the earlier (reject-only) version.
foreach ($old in @('HRIS-ExpirePendingLeaves')) {
    if (Get-ScheduledTask -TaskName $old -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $old -Confirm:$false
        Write-Host "Removed old scheduled task '$old'."
    }
}

if ($Remove) {
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
        Write-Host "Removed scheduled task '$taskName'."
    } else {
        Write-Host "No scheduled task named '$taskName' was found."
    }
    return
}

# The task runs the PHP job inside the running app container.
$command = "docker exec hris_app php /app/cron/expire_pending_leaves.php --days=$Days --remind-every=$RemindEvery"
$action  = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -WindowStyle Hidden -Command `"$command`""

# Run hourly, forever, starting at the next whole hour.
$start   = (Get-Date).Date.AddHours((Get-Date).Hour + 1)
$trigger = New-ScheduledTaskTrigger -Once -At $start `
    -RepetitionInterval (New-TimeSpan -Hours 1) `
    -RepetitionDuration ([TimeSpan]::MaxValue)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopOnIdleEnd

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger `
    -Settings $settings -Description 'Remind HR about Pending HRIS leave, then auto-reject stale requests.' `
    -Force | Out-Null

Write-Host "Registered scheduled task '$taskName'."
Write-Host "  Runs every hour. Reminds HR every $RemindEvery day(s); rejects after $Days day(s)."
Write-Host ""
Write-Host "Useful commands:"
Write-Host "  Run it now:   Start-ScheduledTask -TaskName $taskName"
Write-Host "  View it:      Get-ScheduledTask -TaskName $taskName | Get-ScheduledTaskInfo"
Write-Host "  Remove it:    powershell -ExecutionPolicy Bypass -File cron\register-windows-task.ps1 -Remove"
