terraform {
  required_version = ">= 1.6.0"

  required_providers {
    # Manages containers on the local Docker Desktop engine
    docker = {
      source  = "kreuzwerker/docker"
      version = "3.9.0"
    }
    # Generates the shared realtime secret
    random = {
      source  = "hashicorp/random"
      version = "3.9.1"
    }
    # Writes config files the PHP app and Prometheus read
    local = {
      source  = "hashicorp/local"
      version = "2.9.1"
    }
  }
}

provider "docker" {
  host = var.docker_host
}
