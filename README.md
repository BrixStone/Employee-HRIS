
# Employee HRIS

A web-based **Human Resource Information System (HRIS)** built with PHP, MySQL, Docker, and Terraform. The project includes monitoring and real-time communication services using Prometheus, Grafana, and a WebSocket server.

---

## 📑 Table of Contents

- [Overview](#overview)
- [Technology Stack](#technology-stack)
- [Prerequisites](#prerequisites)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Accessing the Application](#accessing-the-application)
- [Monitoring](#monitoring)
- [Starting and Stopping the System](#starting-and-stopping-the-system)
- [Docker and Terraform](#docker-and-terraform)
- [Troubleshooting](#troubleshooting)
- [Important Notes](#important-notes)

---

## 📖 Overview

Employee HRIS is a web-based human resource management system designed to manage employee-related operations.

Docker provides a consistent environment for running the application and its supporting services. Terraform provisions the monitoring and real-time infrastructure.

## 🛠️ Technology Stack

| Technology | Purpose |
|---|---|
| PHP | Application backend |
| MySQL 8.0 | Database management |
| Docker | Containerization |
| Docker Compose | Application orchestration |
| Terraform | Infrastructure provisioning |
| Prometheus | Metrics collection |
| Grafana | Monitoring dashboards |
| WebSocket Server | Real-time communication |

---

## 📋 Prerequisites

Install the following tools before running the project:

- [Git](https://git-scm.com/downloads)
- [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- [Terraform](https://developer.hashicorp.com/terraform/install)
- [PHP](https://www.php.net/downloads), if needed locally
- [Composer](https://getcomposer.org/download/), if needed locally

> **Note:** PHP and Composer may not need to be installed locally if your Docker configuration handles dependencies inside containers.

---

## 📁 Project Structure

```text
Employee-HRIS/
├── admin/                  # HR administration
├── api/                    # API endpoints
├── authentication/         # Authentication features
├── classes/                # PHP classes
├── config/                 # Application configuration
├── database/               # Database-related files
├── employee/               # Employee features
├── hr/                     # HR features
├── monitoring/
│   ├── docker-compose.yml  # Application and database services
│   ├── prometheus.yml      # Prometheus configuration
│   ├── grafana/
│   │   └── provisioning/   # Grafana provisioning
│   └── initdb/             # MySQL initialization scripts
├── realtime/               # WebSocket server
├── terraform/              # Infrastructure configuration
├── tests/                  # Tests
├── composer.json           # PHP dependencies
├── hris-start.ps1          # Startup helper
├── hris-stop.ps1           # Shutdown helper
├── index.php               # Application entry point
└── README.md
```

---

## 🚀 Getting Started

### 1. Clone the Repository

```powershell
git clone https://github.com/Stuwart-afk/Employee-HRIS.git
cd Employee-HRIS
```

### 2. Configure Environment Variables

Create a `monitoring/.env` file:

```dotenv
MYSQL_ROOT_PASSWORD=replace_with_a_secure_root_password
MYSQL_PASSWORD=replace_with_a_secure_app_password
GRAFANA_ADMIN_PASSWORD=replace_with_a_secure_grafana_password
```

Replace the example values with strong passwords.

> **Security:** Never commit `.env` files or actual secrets to GitHub.

### 3. Start the Docker Application Stack

From the repository root, run:

```powershell
docker compose -f monitoring/docker-compose.yml up -d --build
```

Check running containers:

```powershell
docker ps
```

View service logs:

```powershell
docker compose -f monitoring/docker-compose.yml logs -f
```

Press `Ctrl + C` to stop following logs. This does not stop the containers.

### 4. Initialize Terraform

Navigate to the Terraform directory:

```powershell
cd terraform
terraform init
terraform validate
terraform plan
```

- `terraform init` initializes Terraform and downloads providers.
- `terraform validate` checks the configuration.
- `terraform plan` previews infrastructure changes.

Review the plan before continuing.

> **Important:** Docker Compose and Terraform may define containers with overlapping names or ports. Reconcile the configurations before starting both stacks simultaneously.

### 5. Apply Terraform

After reviewing the plan, run:

```powershell
terraform apply
```

Review the proposed changes and enter `yes` when prompted.

---

## 🌐 Accessing the Application

Once the services are running, use these addresses:

| Service | URL |
|---|---|
| PHP Application | [http://localhost:8000](http://localhost:8000) |
| Grafana | [http://localhost:3000](http://localhost:3000) |
| Prometheus | [http://localhost:9090](http://localhost:9090) |
| Metrics Endpoint | [http://localhost:9091/monitoring/metrics.php](http://localhost:9091/monitoring/metrics.php) |
| WebSocket Server | `ws://localhost:8090/ws` |

Grafana's administrator username is configured as `admin`. The password comes from the applicable environment or Terraform variable.

---

## 📊 Monitoring

### Prometheus

Open the [Prometheus Targets page](http://localhost:9090/targets) and verify that the expected targets show `UP`.

If a target shows `DOWN`, check its scrape URL, hostname, port, Docker network, and application logs.

### Grafana

Open [Grafana](http://localhost:3000) and sign in using your configured administrator credentials.

The project provisions Grafana configuration from:

```text
monitoring/grafana/provisioning/
```

Select the Prometheus data source and open the configured HRIS dashboard.

If the dashboard is empty:

1. Verify that Prometheus is running.
2. Confirm that the targets are `UP`.
3. Check the Grafana data source.
4. Verify that the dashboard queries use existing metric names.

---

## 🐳 Starting and Stopping the System

### Start the Docker Compose Stack

```powershell
docker compose -f monitoring/docker-compose.yml up -d
```

Rebuild the images when necessary:

```powershell
docker compose -f monitoring/docker-compose.yml up -d --build
```

### Stop the Docker Compose Stack

Stop services without removing containers:

```powershell
docker compose -f monitoring/docker-compose.yml stop
```

Remove Compose-managed containers and networks:

```powershell
docker compose -f monitoring/docker-compose.yml down
```

Named volumes are normally retained by `docker compose down`.

> **Warning:** Avoid `docker compose down -v` unless you intend to delete the associated volumes and stored data.

### Stop Terraform-Managed Infrastructure

From the Terraform directory:

```powershell
terraform destroy
```

Review the proposed changes before confirming. Verify whether any persistent volumes or database data will be affected.

### Use the Helper Scripts

Start the system:

```powershell
.\hris-start.ps1
```

Stop the system:

```powershell
.\hris-stop.ps1
```

Review these scripts first to understand which services they manage.

---

## 🏗️ Docker and Terraform

Docker and Terraform have different responsibilities.

### Docker and Docker Compose

- Docker runs applications in containers.
- Docker Compose defines and starts related services.
- Compose supports local development and testing.

### Terraform

- Terraform defines infrastructure using configuration files.
- Terraform manages resources such as containers and networks through providers.
- Terraform tracks managed resources in its state file.

### Intended Infrastructure Separation

| Tool | Intended Responsibility |
|---|---|
| Docker Compose | PHP application, MySQL, and PHP metrics exporter |
| Terraform | Grafana, Prometheus, and WebSocket server |

Each service should have one clearly defined owner. Avoid managing the same container through both Compose and Terraform.

---

## 🔧 Troubleshooting

### Container Name Conflict

Example error:

```text
Conflict. The container name "/hris_mysql" is already in use
```

List all containers:

```powershell
docker ps -a
```

Check whether a container with the same name already exists before creating another one.

### Prometheus Target Is Down

1. Open the [Prometheus Targets page](http://localhost:9090/targets).
2. Check the configured hostname and port.
3. Confirm that Prometheus and the exporter share a Docker network.
4. Check exporter logs:

   ```powershell
   docker logs hris_exporter
   ```

5. Test the metrics endpoint:

   ```powershell
   Invoke-WebRequest http://localhost:9091/monitoring/metrics.php
   ```

### Terraform Wants to Recreate Containers

Run:

```powershell
terraform plan
```

Inspect the proposed actions before applying changes. Unexpected recreation may result from configuration changes, missing resources, container name changes, or differences between Terraform state and the actual environment.

### Grafana Has No Data

- Confirm that Prometheus is running.
- Verify that the targets are `UP`.
- Check the configured Grafana data source.
- Confirm that the dashboard queries reference existing metrics.

---

## 🔒 Important Notes

- Never commit passwords, shared secrets, `.env` files, or sensitive Terraform state to a public repository.
- Keep Terraform state backed up and protected.
- Review `terraform plan` before applying changes.
- Use one owner for each container.
- Back up important database data before removing containers or volumes.
- Verify port availability and configuration before deployment.

---

**Employee HRIS** — Human resource management with containerized infrastructure, monitoring, and real-time communication.
