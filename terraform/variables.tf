variable "realtime_port" {
  description = "Windows host port for the realtime WebSocket server"
  type        = number
  default     = 8090
}

variable "prometheus_port" {
  description = "Windows host port for Prometheus"
  type        = number
  default     = 9090
}

variable "grafana_port" {
  description = "Windows host port for Grafana"
  type        = number
  default     = 3000
}

variable "grafana_admin_password" {
  description = "Grafana administrator password"
  type        = string
  sensitive   = true
}
