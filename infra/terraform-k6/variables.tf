variable "aws_region" {
  description = "mx-central-1 by default — same region as production. The app launches solely in Mexico, so a same-region runner measures the server's own processing time with the network hop stripped out, rather than approximating a real player's path from wherever the load-generator happens to sit. That's a deliberate tradeoff, not a more 'realistic' number — see the README."
  type        = string
  default     = "mx-central-1"
}

variable "project_name" {
  type    = string
  default = "offline-bets"
}

variable "instance_type" {
  description = "k6 is CPU-bound at high VU counts — size this to your target concurrency. Temporarily t3.medium (2 vCPU/4GB) rather than the ideal c6i.2xlarge (8 vCPU/16GB): this stack now shares production's mx-central-1 vCPU quota (currently 8, with production alone using 6), so there's only ~2 vCPU of headroom until that quota clears. Bump back to c6i.2xlarge once it does — t3.medium will itself become the throughput bottleneck well before reaching the 5,000-10,000 VU target."
  type        = string
  default     = "t3.medium"
}

variable "key_pair_name" {
  type    = string
  default = "offline-bets-loadtest"
}

variable "ssh_allowed_cidr" {
  description = "CIDR allowed to SSH into the runner. Required (no default) — this box has no reason to be reachable from the whole internet the way the app's ALB does."
  type        = string
}

variable "app_repo_url" {
  type    = string
  default = "https://github.com/kimpoy010/offline-bets.git"
}

variable "app_git_ref" {
  type    = string
  default = "master"
}

variable "reports_bucket_name" {
  description = "S3 bucket the runner uploads each run's summary.html/summary.json to, so results survive the instance being torn down and are easy to share without scp. Must be globally unique — the default appends the AWS account ID via a data source in s3.tf."
  type        = string
  default     = ""
}

variable "github_token" {
  description = "A fine-grained GitHub PAT scoped to just this repo's Contents (read-only) — app_repo_url is private, so an anonymous clone from this instance would 401. No default on purpose — set it in terraform.tfvars (gitignored), never commit it."
  type        = string
  sensitive   = true
}
