$ErrorActionPreference = "Continue"

Write-Host "Stopping HRIS Docker Compose services..." -ForegroundColor Cyan
Push-Location "$PSScriptRoot\monitoring"
docker compose stop
Pop-Location

Write-Host "Stopping Terraform-managed containers..." -ForegroundColor Cyan
docker stop terraform-hris-grafana terraform-hris-prometheus terraform-hris-realtime 2>$null

Write-Host "HRIS has been stopped. Your data is preserved." -ForegroundColor Green
