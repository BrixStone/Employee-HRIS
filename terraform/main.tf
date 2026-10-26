# =====================================================================
# HRIS local infrastructure (Docker Desktop)
#
#   hris_realtime    WebSocket server (realtime/)          ws://localhost:8090/ws
#   hris_exporter    PHP metrics endpoint (monitoring/)     http://localhost:9091/monitoring/metrics.php
#   hris_prometheus  scrapes the exporter + realtime server http://localhost:9090
#   hris_grafana     HRIS dashboard                         http://localhost:3000
#
# Connects to the PHP app by writing config/realtime.json (shared secret +
# publish URL) which classes/Realtime.php reads.
#
# Usage (from this folder, Docker Desktop and XAMPP MySQL running):
#   terraform init
#   terraform apply
#   terraform destroy      # removes the containers; data volumes are kept
#
# This replaces `docker compose up` in monitoring/ (same ports and container
# names), so run one or the other, not both.
# =====================================================================

locals {
  project_dir    = abspath("${path.module}/..")
  realtime_dir   = "${local.project_dir}/realtime"
  monitoring_dir = "${local.project_dir}/monitoring"
  exporter_dir   = "${local.monitoring_dir}/exporter"

  # Rebuild images whenever their source files change
  realtime_hash = sha1(join("", [for f in ["Dockerfile", "package.json", "package-lock.json", "server.js"] : filesha1("${local.realtime_dir}/${f}")]))
  exporter_hash = sha1(join("", [for f in ["Dockerfile", "router.php"] : filesha1("${local.exporter_dir}/${f}")]))

  # Prometheus: the compose config plus a scrape job for the realtime server
  prometheus_base = yamldecode(file("${local.monitoring_dir}/prometheus.yml"))
  prometheus_config = merge(local.prometheus_base, {
    scrape_configs = concat(local.prometheus_base.scrape_configs, [{
      job_name        = "hris_realtime"
      scrape_interval = "15s"
      metrics_path    = "/metrics"
      static_configs = [{
        targets = ["hris-realtime:8090"]
        labels  = { app = "hris" }
      }]
    }])
  })
}

# ---------------------------------------------------------------------
# Shared secret between the PHP app and the WebSocket server
# ---------------------------------------------------------------------
resource "random_password" "realtime_secret" {
  length  = 48
  special = false
}

# Read by classes/Realtime.php (and by realtime/server.js when run with node
# directly). Git-ignored.
resource "local_sensitive_file" "realtime_config" {
  filename        = "${local.project_dir}/config/realtime.json"
  file_permission = "0600"
  content = jsonencode({
    secret      = random_password.realtime_secret.result
    ws_port     = var.realtime_port
    publish_url = "http://127.0.0.1:${var.realtime_port}/publish"
    managed_by  = "terraform/main.tf"
  })
}

resource "local_file" "prometheus_config" {
  filename = "${path.module}/.generated/prometheus.yml"
  content  = yamlencode(local.prometheus_config)
}

# ---------------------------------------------------------------------
# Network and volumes
# ---------------------------------------------------------------------
resource "docker_network" "hris" {
  name = "hris_net"
}

resource "docker_volume" "prometheus_data" {
  name = "hris_prometheus_data"
  lifecycle {
    prevent_destroy = false
  }
}

resource "docker_volume" "grafana_data" {
  name = "hris_grafana_data"
}

# ---------------------------------------------------------------------
# WebSocket server
# ---------------------------------------------------------------------
resource "docker_image" "realtime" {
  name         = "hris-realtime:latest"
  keep_locally = true
  build {
    context = local.realtime_dir
  }
  triggers = {
    source = local.realtime_hash
  }
}

resource "docker_container" "realtime" {
  name    = "hris_realtime"
  image   = docker_image.realtime.image_id
  restart = "unless-stopped"

  env = [
    "HRIS_REALTIME_SECRET=${random_password.realtime_secret.result}",
    "PORT=8090",
  ]

  ports {
    internal = 8090
    external = var.realtime_port
    ip       = "127.0.0.1"
  }

  networks_advanced {
    name    = docker_network.hris.name
    aliases = ["hris-realtime"]
  }
}

# ---------------------------------------------------------------------
# PHP metrics exporter (same image as monitoring/docker-compose.yml)
# ---------------------------------------------------------------------
resource "docker_image" "exporter" {
  name         = "hris-exporter:latest"
  keep_locally = true
  build {
    context = local.exporter_dir
  }
  triggers = {
    source = local.exporter_hash
  }
}

resource "docker_container" "exporter" {
  name        = "hris_exporter"
  image       = docker_image.exporter.image_id
  restart     = "unless-stopped"
  working_dir = "/app"

  env = [
    "DB_HOST=${var.db_host}",
    "DB_PORT=${var.db_port}",
    "DB_NAME=${var.db_name}",
    "DB_USER=${var.db_user}",
    "DB_PASS=${var.db_password}",
  ]

  # MySQL (XAMPP) runs on the Windows host
  host {
    host = "host.docker.internal"
    ip   = "host-gateway"
  }

  volumes {
    host_path      = local.project_dir
    container_path = "/app"
    read_only      = true
  }

  ports {
    internal = 9091
    external = var.metrics_port
    ip       = "127.0.0.1"
  }

  networks_advanced {
    name    = docker_network.hris.name
    aliases = ["hris-exporter"]
  }
}

# ---------------------------------------------------------------------
# Prometheus
# ---------------------------------------------------------------------
resource "docker_image" "prometheus" {
  name         = "prom/prometheus:v3.15.0"
  keep_locally = true
}

resource "docker_container" "prometheus" {
  name    = "hris_prometheus"
  image   = docker_image.prometheus.image_id
  restart = "unless-stopped"

  command = [
    "--config.file=/etc/prometheus/prometheus.yml",
    "--storage.tsdb.path=/prometheus",
    "--storage.tsdb.retention.time=30d",
    "--web.enable-lifecycle",
  ]

  volumes {
    host_path      = abspath(local_file.prometheus_config.filename)
    container_path = "/etc/prometheus/prometheus.yml"
    read_only      = true
  }

  volumes {
    volume_name    = docker_volume.prometheus_data.name
    container_path = "/prometheus"
  }

  ports {
    internal = 9090
    external = var.prometheus_port
    ip       = "127.0.0.1"
  }

  networks_advanced {
    name    = docker_network.hris.name
    aliases = ["prometheus"]
  }

  # Recreate when the generated scrape config changes
  labels {
    label = "hris.prometheus-config-sha1"
    value = sha1(local_file.prometheus_config.content)
  }

  depends_on = [docker_container.exporter, docker_container.realtime]
}

# ---------------------------------------------------------------------
# Grafana (provisioning from monitoring/grafana, datasource uid "prometheus")
# ---------------------------------------------------------------------
resource "docker_image" "grafana" {
  name         = "grafana/grafana:13.2.4"
  keep_locally = true
}

resource "docker_container" "grafana" {
  name    = "hris_grafana"
  image   = docker_image.grafana.image_id
  restart = "unless-stopped"

  env = [
    "GF_SECURITY_ADMIN_USER=admin",
    "GF_SECURITY_ADMIN_PASSWORD=${var.grafana_admin_password}",
    "GF_USERS_ALLOW_SIGN_UP=false",
    "GF_SERVER_DOMAIN=localhost",
    "GF_ANALYTICS_REPORTING_ENABLED=false",
  ]

  volumes {
    volume_name    = docker_volume.grafana_data.name
    container_path = "/var/lib/grafana"
  }

  volumes {
    host_path      = "${local.monitoring_dir}/grafana/provisioning"
    container_path = "/etc/grafana/provisioning"
    read_only      = true
  }

  ports {
    internal = 3000
    external = var.grafana_port
    ip       = "127.0.0.1"
  }

  networks_advanced {
    name = docker_network.hris.name
  }

  depends_on = [docker_container.prometheus]
}
