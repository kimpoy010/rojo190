resource "aws_db_subnet_group" "main" {
  name       = "${var.project_name}-db"
  subnet_ids = aws_subnet.private[*].id

  tags = { Name = "${var.project_name}-db-subnet-group" }
}

# A dedicated CMK instead of the account's default aws/rds and
# aws/secretsmanager managed keys — those are normally auto-provisioned on
# first use, but that lazy provisioning didn't happen cleanly for this
# brand-new account in a freshly-enabled region (CreateDBInstance failed
# with KMSKeyNotAccessibleFault even after granting kms:* in IAM). A
# Terraform-managed key sidesteps that entirely since nothing implicit is
# left to fail. No custom key policy — the default one Terraform applies
# (full access for the account root) is enough given IAM already governs
# who can use it.
resource "aws_kms_key" "rds" {
  description             = "${var.project_name} RDS storage + master-password secret encryption"
  deletion_window_in_days = 30
  enable_key_rotation     = true

  tags = { Name = "${var.project_name}-rds-kms" }
}

resource "aws_kms_alias" "rds" {
  name          = "alias/${var.project_name}-rds"
  target_key_id = aws_kms_key.rds.key_id
}

# Master password is generated and stored by RDS itself in Secrets Manager
# (manage_master_user_password) — Terraform never sees or stores the
# plaintext password, so it can't leak into state or a committed file.
resource "aws_db_instance" "main" {
  identifier     = "${var.project_name}-db"
  engine         = "mysql"
  engine_version = "8.0"

  instance_class        = var.db_instance_class
  allocated_storage     = var.db_allocated_storage
  max_allocated_storage = var.db_max_allocated_storage
  storage_type          = "gp3"
  storage_encrypted     = true
  kms_key_id            = aws_kms_key.rds.arn

  db_name  = var.db_name
  username = var.db_username

  manage_master_user_password   = true
  master_user_secret_kms_key_id = aws_kms_key.rds.arn

  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.rds.id]

  multi_az                = var.db_multi_az
  backup_retention_period = var.db_backup_retention_days
  backup_window           = "17:00-17:30" # 11:00-11:30 local (Mexico City, UTC-6) — low-traffic window
  maintenance_window      = "sun:18:00-sun:19:00"

  auto_minor_version_upgrade = true
  deletion_protection        = true
  skip_final_snapshot        = false
  final_snapshot_identifier  = "${var.project_name}-db-final"

  performance_insights_enabled    = true
  performance_insights_kms_key_id = aws_kms_key.rds.arn

  tags = { Name = "${var.project_name}-db" }
}
