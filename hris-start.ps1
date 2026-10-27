$ErrorActionPreference = "Stop"

Write-Host "Starting Terraform infrastructure..."
Push-Location "$PSScriptRoot\terraform"
try {
    terraform apply -auto-approve
    if ($LASTEXITCODE -ne 0) {
        throw "Terraform apply failed."
    }
}
finally {
    Pop-Location
}

Write-Host "Starting HRIS application..."
Push-Location "$PSScriptRoot\monitoring"
try {
    docker compose start
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose failed."
    }
}
finally {
    Pop-Location
}

Write-Host "HRIS startup commands completed."
Write-Host "HRIS:       http://localhost:8000"
Write-Host "Grafana:    http://localhost:3000"
Write-Host "Prometheus: http://localhost:9090"
