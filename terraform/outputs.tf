output "realtime_websocket_url" {
  description = "Browsers connect here (pages get a token from /api/realtime_token.php first)"
  value       = "ws://localhost:${var.realtime_port}/ws"
}

output "realtime_health_url" {
  value = "http://localhost:${var.realtime_port}/health"
}

output "metrics_url" {
  value = "http://localhost:${var.metrics_port}/monitoring/metrics.php"
}

output "prometheus_url" {
  value = "http://localhost:${var.prometheus_port}"
}

output "grafana_url" {
  description = "Login: admin / grafana_admin_password"
  value       = "http://localhost:${var.grafana_port}"
}

output "php_realtime_config" {
  description = "Written for the PHP app (git-ignored)"
  value       = local_sensitive_file.realtime_config.filename
}
