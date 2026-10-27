Employee HRIS

An Employee Human Resource Information System built with PHP, MySQL, Docker, and Terraform. The project includes monitoring and real-time communication services using Prometheus, Grafana, and a WebSocket server.

Table of Contents
Overview
Technology Stack
Prerequisites
Project Structure
Getting Started
Accessing the Application
Monitoring
Starting and Stopping the System
Docker and Terraform
Troubleshooting
Important Notes
Overview

Employee HRIS is a web-based human resource management system. Docker provides a consistent environment for running the application and its supporting services. Terraform provisions the monitoring and real-time infrastructure.

The project separates the main application stack from the monitoring infrastructure to make development and deployment easier to manage.

Technology Stack
Technology	Purpose
PHP	Application backend
MySQL 8.0	Database
Docker	Runs applications and services in containers
Docker Compose	Starts and manages the application stack
Terraform	Provisions monitoring and real-time infrastructure
Prometheus	Collects and stores metrics
Grafana	Visualizes metrics through dashboards
WebSocket server	Supports real-time communication
Prerequisites

Install the following before running the project:

Git
Docker Desktop
Terraform
PHP dependencies managed by Composer, if required by your local workflow

Make sure Docker Desktop is running before executing Docker Compose or Terraform commands.

Project Structure
text
Employee-HRIS/
├── admin/                  # HR administration
├── api/                    # API endpoints
├── authentication/         # Authentication features
├── classes/                # PHP classes
├── config/                 # Application configuration
├── database/               # Database-related files
├── employee/                # Employee features
├── hr/                     # HR features
├── monitoring/
│   ├── docker-compose.yml  # Application and database services
│   ├── prometheus.yml      # Prometheus scrape configuration
│   ├── grafana/
│   │   └── provisioning/   # Grafana provisioning
│   └── initdb/             # MySQL initialization scripts
├── realtime/               # WebSocket server
├── terraform/              # Terraform infrastructure configuration
├── tests/                  # Tests
├── composer.json           # PHP dependencies
├── hris-start.ps1          # Startup helper
├── hris-stop.ps1           # Shutdown helper
├── index.php               # Application entry point
└── README.md

Getting Started
1. Clone the repository
powershell
git clone <https://github.com/Stuwart-afk/Employee-HRIS.git>
cd Employee-HRIS



2. Configure environment variables

Create the environment file expected by Docker Compose in the monitoring/ directory.

For example, monitoring/.env:

dotenv
MYSQL_ROOT_PASSWORD=replace_with_a_secure_root_password
MYSQL_PASSWORD=replace_with_a_secure_app_password
GRAFANA_ADMIN_PASSWORD=replace_with_a_secure_grafana_password


Use your own strong passwords. Do not commit .env files or actual secrets to GitHub.

3. Start the Docker application stack

From the repository root, run:

powershell
docker compose -f monitoring/docker-compose.yml up -d --build


This builds the required application images and starts the services defined in the Compose file.

Check the running containers:

powershell
docker ps


Check the service logs:

powershell
docker compose -f monitoring/docker-compose.yml logs -f


Press Ctrl+C to stop following logs. This does not stop the containers.

4. Initialize Terraform

Open a separate terminal in the Terraform directory:

powershell
cd terraform
terraform init
terraform validate
terraform plan

terraform init initializes Terraform and downloads the required providers.
terraform validate checks the configuration for errors.
terraform plan previews the infrastructure changes without applying them.

Review the plan before continuing.

Important: The current Terraform configuration creates the Grafana, Prometheus, and WebSocket containers. The current Compose file also defines services with overlapping container names and ports. Do not start both configurations simultaneously until the overlapping resources have been separated or reconciled.

If you are using the provided hris-start.ps1 helper, review its commands and configuration before using it. It may start both the Terraform infrastructure and the Docker Compose stack.

5. Apply Terraform

After reviewing the plan, run:

powershell
terraform apply


Review the proposed changes and enter yes when Terraform asks for confirmation.

Terraform will create or update the resources described in its configuration.

Accessing the Application

Once the relevant services are running, use the following addresses:

Service	URL
PHP application	http://localhost:8000
Grafana	http://localhost:3000
Prometheus	http://localhost:9090
Metrics endpoint	http://localhost:9091/monitoring/metrics.php
WebSocket server	ws://localhost:8090/ws

Grafana's administrator username is configured as admin. Its password comes from the applicable environment variable or Terraform variable.

The WebSocket address is intended for WebSocket clients; opening it as a normal webpage may not display a web interface.

Monitoring
Prometheus

Prometheus scrapes metrics from configured targets, including the PHP metrics exporter and the WebSocket server.

Open:

http://localhost:9090/targets

Check that the expected targets show UP.

If a target shows DOWN, check its scrape URL, container network, and application logs.

Grafana

Open:

http://localhost:3000

Sign in using the configured administrator credentials. The project provisions Grafana configuration from monitoring/grafana/provisioning/.

Select the Prometheus data source and open the configured HRIS dashboard.

If the dashboard is empty, verify that Prometheus targets are up and that the dashboard queries match the metrics exposed by the application.

Starting and Stopping the System
Start the Docker Compose stack

From the repository root:

powershell
docker compose -f monitoring/docker-compose.yml up -d


To rebuild images after changing application or Dockerfile code:

powershell
docker compose -f monitoring/docker-compose.yml up -d --build

Stop the Docker Compose stack
powershell
docker compose -f monitoring/docker-compose.yml stop


This stops the Compose-managed services without removing the containers.

To remove the Compose containers and network:

powershell
docker compose -f monitoring/docker-compose.yml down


Named volumes are normally retained by down, so database data can persist.

Warning: Avoid docker compose down -v unless you intentionally want to remove the associated named volumes and their stored data.

Stop Terraform-managed infrastructure

From the Terraform directory:

powershell
terraform destroy


Review the plan and confirm when prompted. This removes Terraform-managed infrastructure resources, including its managed containers and network. Named volumes may have separate lifecycle behavior, so verify what Terraform plans to remove before confirming.

Use the helper scripts

From the repository root, the provided PowerShell helpers can simplify daily operation:

powershell
.\hris-start.ps1

powershell
.\hris-stop.ps1


These scripts should be reviewed against the current configuration to confirm which services they start and stop. Avoid manually removing Terraform-managed containers while expecting Terraform to manage their lifecycle afterward.

Docker and Terraform

Docker and Terraform serve different purposes.

Docker and Docker Compose

Docker runs containers.
Docker Compose defines and starts a group of related services.
Compose is convenient for daily application development and local testing.

Terraform

Terraform describes infrastructure in configuration files.
Terraform creates and manages resources such as containers, networks, and volumes through providers.
Terraform tracks managed resources in its state file and can update infrastructure when the configuration changes.

For this project, the intended division is:

Docker Compose: PHP application, MySQL, and PHP metrics exporter.
Terraform: Grafana, Prometheus, and the WebSocket server.

To keep this separation reliable, each service should have one clearly defined owner. Avoid defining the same container in both Compose and Terraform.

Troubleshooting
Container name conflict

Example:

text
Conflict. The container name "/hris_mysql" is already in use


This means a container with that name already exists. Check:

powershell
docker ps -a


If the existing container is managed by your Compose project, start it with Compose instead of creating another container with the same name.

Prometheus target is DOWN
Open the Prometheus Targets page.
Check the target's configured hostname and port.
Confirm that Prometheus and the exporter share a Docker network.
Check the exporter logs:
powershell
docker logs hris_exporter

Test the metrics endpoint from the host:
powershell
Invoke-WebRequest http://localhost:9091/monitoring/metrics.php

Terraform wants to recreate containers

Run:

powershell
terraform plan


Inspect the proposed actions. A container may need recreation because of a name change, a configuration change, a missing resource, or a difference between Terraform state and the actual Docker environment.

Do not apply changes blindly if the plan unexpectedly replaces containers or affects persistent data.

Grafana has no data
Confirm Prometheus is running.
Confirm the relevant Prometheus targets are UP.
Check that the Grafana data source points to the correct Prometheus service.
Verify that dashboard queries reference metric names that actually exist.
Important Notes
Never commit passwords, shared secrets, .env files, or sensitive Terraform state to a public repository.
Keep the Terraform state file backed up and protected.
Review terraform plan before applying changes.
Use one owner for each container to avoid conflicts.
Back up important database data before removing containers or volumes.
Port availability and configuration may differ between development machines.