terraform {
  required_version = ">= 1.11" # use_lockfile (S3-native locking) needs 1.11+

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
    tls = {
      source  = "hashicorp/tls"
      version = "~> 4.0"
    }
  }

  # Separate state key from infra/terraform/ (the production stack) on
  # purpose, same bucket — this box is spun up and torn down around test
  # runs, not left running, so its lifecycle shouldn't be coupled to
  # production's. Left empty for the same reason as infra/terraform/
  # versions.tf — supply at init time:
  #   terraform init \
  #     -backend-config="bucket=offline-bets-terraform-state-<account-id>" \
  #     -backend-config="key=loadtest/terraform.tfstate" \
  #     -backend-config="region=mx-central-1" \
  #     -backend-config="use_lockfile=true" \
  #     -backend-config="encrypt=true"
  backend "s3" {}
}

provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      Project     = var.project_name
      Environment = "loadtest"
      ManagedBy   = "terraform"
    }
  }
}
