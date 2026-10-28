output "grafana_url" {
  description = "Grafana dashboard URL"
  value       = "http://localhost:${var.grafana_port}"
}

output "prometheus_url" {
  description = "Prometheus web interface"
  value       = "http://localhost:${var.prometheus_port}"
}

output "realtime_url" {
  description = "WebSocket endpoint"
  value       = "ws://localhost:${var.realtime_port}/ws"
}

output "docker_network" {
  description = "Shared Docker network"
  value       = docker_network.hris.name
}
