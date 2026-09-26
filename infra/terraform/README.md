# Production infrastructure (Terraform)

VPC (2 AZs) → ALB → app tier (Auto Scaling Group, EC2) + Reverb tier
(single EC2) → RDS MySQL (Multi-AZ) + ElastiCache Redis. Region defaults to
`mx-central-1` since production traffic is in Mexico.

## First-time setup

1. Set up remote state before the first real `apply` (uncomment the `backend "s3"`
   block in `versions.tf` once you've created the bucket + DynamoDB lock table —
   state on a laptop/session disk is unsafe once more than one person touches this).
2. `cp terraform.tfvars.example terraform.tfvars` and adjust (at minimum
   `ssh_allowed_cidr` if you want SSH access at all — otherwise use SSM
   Session Manager, already granted to both instance roles).
3. Export AWS credentials (`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` /
   `AWS_SESSION_TOKEN`) — never commit them, never put them in `.tfvars`.
4. `terraform init`
5. `terraform plan` — review before applying anything.
6. `terraform apply`

## After the first apply

- Nothing runs `php artisan migrate` automatically (deliberately — running
  it from instance user-data would race concurrent migrations on every
  ASG scale-out). Run it once by hand against an app instance (SSM or SSH):
  `cd /var/www/offline-bets && php artisan migrate --force`
- `terraform output -raw ssh_private_key > offline-bets-production.pem && chmod 400 offline-bets-production.pem`
  if you set `ssh_allowed_cidr` and want to SSH in.
- If `domain_name` is set but `manage_dns = false`, create the records
  Terraform prints in `terraform output acm_validation_records` and point
  your DNS provider's A/ALIAS records at `terraform output alb_dns_name`
  yourself (root + `ws.<domain>`).
- If `manage_dns = true`, delegate your registrar to
  `terraform output route53_name_servers`.

## Redeploying app code

The ASG only pulls `app_git_ref` at instance boot. To roll out a new
commit: bump `app_git_ref` (or just trigger an instance refresh) and
either `terraform apply` or use an ASG instance refresh so new instances
pick up the new code — there's no in-place deploy hook yet.

## Known single point of failure

Reverb runs on one instance, not an ASG (WebSocket state doesn't horizontally
scale without a pub/sub broker between Reverb nodes — out of scope for
now). If that instance needs more headroom or HA, that's a deliberate
follow-up, not an oversight.
