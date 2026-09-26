variable "aws_region" {
  description = "AWS region to deploy into. mx-central-1 (Mexico) by default, matching where players actually are — change here (and re-run plan) if it turns out that region lacks an instance type/service this config needs."
  type        = string
  default     = "mx-central-1"
}

variable "project_name" {
  type    = string
  default = "offline-bets"
}

variable "environment" {
  type    = string
  default = "production"
}

variable "vpc_cidr" {
  type    = string
  default = "10.20.0.0/16"
}

variable "az_count" {
  description = "Number of availability zones to spread subnets across."
  type        = number
  default     = 2
}

variable "domain_name" {
  description = "Root domain the app will be served on (e.g. \"poolsabong.mx\"). Leave blank to stand everything up without TLS/DNS for now (ALB reachable over plain HTTP at its own AWS-assigned hostname) — fill in and re-apply once you have one, before going live."
  type        = string
  default     = ""
}

variable "ws_subdomain" {
  description = "Subdomain Reverb (the WebSocket server) is reached on, e.g. \"ws\" -> ws.<domain_name>. Only used when domain_name is set."
  type        = string
  default     = "ws"
}

variable "manage_dns" {
  description = "If true AND domain_name is set, create a Route53 hosted zone + records for it. Set false if the domain's DNS is managed somewhere else (e.g. already on Cloudflare/Namecheap) — you'll instead need to point it at this stack's outputs manually."
  type        = bool
  default     = false
}

variable "key_pair_name" {
  description = "Name for the EC2 key pair Terraform generates for SSH access. The private key is emitted as a sensitive output — save it (terraform output -raw ssh_private_key > key.pem && chmod 400 key.pem) and don't commit it."
  type        = string
  default     = "offline-bets-production"
}

variable "ssh_allowed_cidr" {
  description = "CIDR allowed to SSH into the app/Reverb instances. Default is deliberately unset (empty = no SSH access from anywhere) — set this to your own office/VPN IP before you'll need to log in, rather than leaving it open to 0.0.0.0/0."
  type        = string
  default     = ""
}

variable "app_repo_url" {
  type    = string
  default = "https://github.com/kimpoy010/offline-bets.git"
}

variable "app_git_ref" {
  description = "Branch/tag to deploy. master, per this repo's established workflow this session."
  type        = string
  default     = "master"
}

variable "github_token" {
  description = "A fine-grained GitHub PAT scoped to just this repo's Contents (read-only), so app-tier and Reverb instances can clone it at boot despite it being private. No default on purpose — set it in terraform.tfvars (gitignored), never commit it."
  type        = string
  sensitive   = true
}

# --- Compute ---

variable "app_instance_type" {
  type    = string
  default = "c6i.xlarge" # 4 vCPU / 8GB
}

variable "app_asg_min_size" {
  type    = number
  default = 1
}

variable "app_asg_max_size" {
  type    = number
  default = 3
}

variable "app_asg_desired_capacity" {
  type    = number
  default = 1
}

variable "reverb_instance_type" {
  type    = string
  default = "c6i.large" # 2 vCPU / 4GB
}

# --- Database ---

variable "db_instance_class" {
  type    = string
  default = "db.r6g.xlarge" # 4 vCPU / 32GB
}

variable "db_multi_az" {
  description = "Automatic failover to a standby in a second AZ. Roughly doubles RDS cost; defaulted on since this app handles real money."
  type        = bool
  default     = true
}

variable "db_allocated_storage" {
  description = "Initial storage in GB (gp3, autoscales up to db_max_allocated_storage)."
  type        = number
  default     = 100
}

variable "db_max_allocated_storage" {
  type    = number
  default = 500
}

variable "db_name" {
  type    = string
  default = "offline_bets"
}

variable "db_username" {
  type    = string
  default = "offline_bets"
}

variable "db_backup_retention_days" {
  type    = number
  default = 7
}

# --- Cache ---

variable "redis_node_type" {
  type    = string
  default = "cache.r6g.large"
}
