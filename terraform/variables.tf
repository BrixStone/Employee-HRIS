variable "docker_host" {
  description = "Docker engine endpoint. Docker Desktop on Windows: npipe:////./pipe/docker_engine"
  type        = string
  default     = "npipe:////./pipe/docker_engine"
}

variable "realtime_port" {
  description = "Host port for the WebSocket server (browser connects to ws://localhost:<port>/ws)"
  type        = number
  default     = 8090
}

variable "metrics_port" {
  description = "Host port for the PHP metrics exporter"
  type        = number
  default     = 9091
}

variable "prometheus_port" {
  description = "Host port for Prometheus"
  type        = number
  default     = 9090
}

variable "grafana_port" {
  description = "Host port for Grafana"
  type        = number
  default     = 3000
}

variable "grafana_admin_password" {
  description = "Grafana admin password (same default as monitoring/docker-compose.yml)"
  type        = string
  default     = "hris@admin"
  sensitive   = true
}

# MySQL runs on the Windows host (XAMPP), reached from containers through
# host.docker.internal. Defaults match config/database.php.
variable "db_host" {
  type    = string
  default = "host.docker.internal"
}

variable "db_port" {
  type    = number
  default = 3306
}

variable "db_name" {
  type    = string
  default = "hris"
}

variable "db_user" {
  type    = string
  default = "root"
}

variable "db_password" {
  type      = string
  default   = ""
  sensitive = true
}
