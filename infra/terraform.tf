terraform {
  required_version = ">= 1.10.0"

  backend "s3" {
    bucket       = "techchallenge-tfstate-477478162709-eks"
    key          = "services-ms/terraform.tfstate"
    region       = "us-east-1"
    use_lockfile = true
  }

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
}

provider "aws" {
  region = var.aws_region
}
