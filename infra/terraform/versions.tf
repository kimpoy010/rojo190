terraform {
  required_version = ">= 1.11" # use_lockfile (S3-native locking) needs 1.11+

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
    tls = {
      source  = "hashicorp/tls"
      version = "~> 4.0"
    }
  }

  # State lives in S3 (bucket created once, out-of-band, alongside the IAM
  # user that runs `apply`); locking is S3-native (conditional writes) via
  # use_lockfile rather than a DynamoDB table, since that's one fewer thing
  # this stack's IAM policy needs to grant. Left empty deliberately — a
  # backend block can't reference variables, and the actual bucket name
  # includes the AWS account ID, which doesn't need to be sitting in git
  # history. Supply it at `terraform init` time instead:
  #   terraform init \
  #     -backend-config="bucket=offline-bets-terraform-state-<account-id>" \
  #     -backend-config="key=production/terraform.tfstate" \
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
      Environment = var.environment
      ManagedBy   = "terraform"
    }
  }
}
