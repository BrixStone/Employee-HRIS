locals {
  project_dir    = abspath("${path.module}/..")
  realtime_dir   = "${local.project_dir}/realtime"
  monitoring_dir = "${local.project_dir}/monitoring"

  # Detect changes to realtime source files so Terraform
  # knows when the WebSocket image needs rebuilding.
  realtime_hash = sha1(join("", [
    for f in [
      "Dockerfile",
      "package.json",
      "package-lock.json",
      "server.js"
    ] : filesha1("${local.realtime_dir}/${f}")
  ]))

  # Read the base Prometheus configuration and add the
  # Terraform-managed realtime metrics scrape job.
  prometheus_base = yamldecode(
    file("${local.monitoring_dir}/prometheus.yml")
  )

  prometheus_config = merge(local.prometheus_base, {
    scrape_configs = concat(
      local.prometheus_base.scrape_configs,
      [{
        job_name        = "hris_realtime"
        scrape_interval = "15s"
        metrics_path    = "/metrics"

        static_configs = [{
          targets = ["hris-realtime:8090"]
          labels = {
            app = "hris"
          }
        }]
      }]
    )
  })
}

# ----------------------------------------------------------
# Generate a shared secret for realtime authentication
# ----------------------------------------------------------
resource "random_password" "realtime_secret" {
  length  = 48
  special = false
}

# ----------------------------------------------------------
# Write realtime configuration for the PHP application
# ----------------------------------------------------------
resource "local_sensitive_file" "realtime_config" {
  filename        = "${local.project_dir}/config/realtime.json"
  file_permission = "0600"

  content = jsonencode({
    secret      = random_password.realtime_secret.result
    ws_port     = var.realtime_port
    publish_url = "http://hris-realtime:8090/publish"
    managed_by  = "terraform/main.tf"
  })
}

# ----------------------------------------------------------
# Generate the complete Prometheus configuration
# ----------------------------------------------------------
resource "local_file" "prometheus_config" {
  filename = "${path.module}/prometheus.generated.yml"
  content  = yamlencode(local.prometheus_config)
}

# ----------------------------------------------------------
# Shared network
# ----------------------------------------------------------
resource "docker_network" "hris" {
  name = "hris_net"
}

# ----------------------------------------------------------
# Persistent monitoring data
# ----------------------------------------------------------
resource "docker_volume" "prometheus_data" {
  name = "hris_prometheus_data"
}

resource "docker_volume" "grafana_data" {
  name = "hris_grafana_data"
}

# ----------------------------------------------------------
# Build the WebSocket image
# ----------------------------------------------------------
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

# ----------------------------------------------------------
# WebSocket server
# ----------------------------------------------------------
resource "docker_container" "realtime" {
  name    = "terraform-hris-realtime"
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

  depends_on = [
    local_sensitive_file.realtime_config
  ]
}

# ----------------------------------------------------------
# Prometheus image
# ----------------------------------------------------------
resource "docker_image" "prometheus" {
  name         = "prom/prometheus:v3.15.0"
  keep_locally = true
}

# ----------------------------------------------------------
# Prometheus container
# ----------------------------------------------------------
resource "docker_container" "prometheus" {
  name    = "terraform-hris-prometheus"
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

  labels {
    label = "hris.prometheus-config-sha1"
    value = sha1(local_file.prometheus_config.content)
  }

  depends_on = [
    docker_container.realtime
  ]
}

# ----------------------------------------------------------
# Grafana image
# ----------------------------------------------------------
resource "docker_image" "grafana" {
  name         = "grafana/grafana:13.2.4"
  keep_locally = true
}

# ----------------------------------------------------------
# Grafana container
# ----------------------------------------------------------
resource "docker_container" "grafana" {
  name    = "terraform-hris-grafana"
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

  depends_on = [
    docker_container.prometheus
  ]
}
