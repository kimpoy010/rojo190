# Shared policy: read the app secrets + the RDS-managed master password,
# nothing else. Attached to both app and Reverb instance roles — Reverb
# doesn't currently need DB access, but bootstrapping is identical either
# way and the extra grant costs nothing to leave in for now.
data "aws_iam_policy_document" "read_secrets" {
  statement {
    sid     = "ReadAppSecrets"
    actions = ["secretsmanager:GetSecretValue"]
    resources = [
      aws_secretsmanager_secret.app_env.arn,
      aws_db_instance.main.master_user_secret[0].secret_arn,
    ]
  }

  # The RDS master-password secret is encrypted under our own CMK (rds.tf's
  # master_user_secret_kms_key_id), not the account's default
  # aws/secretsmanager key — secretsmanager:GetSecretValue alone isn't
  # enough to read it back out, the caller also needs kms:Decrypt on the
  # specific key it was encrypted with.
  statement {
    sid       = "DecryptRdsSecret"
    actions   = ["kms:Decrypt"]
    resources = [aws_kms_key.rds.arn]
  }
}

data "aws_iam_policy_document" "ec2_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

# --- App tier ---

resource "aws_iam_role" "app" {
  name               = "${var.project_name}-app"
  assume_role_policy = data.aws_iam_policy_document.ec2_assume.json
}

resource "aws_iam_role_policy" "app_secrets" {
  name   = "${var.project_name}-app-secrets"
  role   = aws_iam_role.app.id
  policy = data.aws_iam_policy_document.read_secrets.json
}

resource "aws_iam_role_policy_attachment" "app_cloudwatch" {
  role       = aws_iam_role.app.name
  policy_arn = "arn:aws:iam::aws:policy/CloudWatchAgentServerPolicy"
}

resource "aws_iam_role_policy_attachment" "app_ssm" {
  role       = aws_iam_role.app.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

resource "aws_iam_instance_profile" "app" {
  name = "${var.project_name}-app"
  role = aws_iam_role.app.name
}

# App-only, not Reverb — lets the app server upload its own
# loadtest-sessions.csv (from loadtest:seed-players) directly to S3 for
# the k6 runner (infra/terraform-k6, a separate region/stack) to pick up.
# Relaying it through chunked SSM command output worked for a handful of
# accounts but falls apart well before real target concurrency (a 5,000-row
# CSV is a couple MB; SSM's per-invocation output caps around 24KB).
# Hardcoded bucket name rather than a cross-stack reference — separate
# Terraform state/account-region from this stack, and the name is already
# fixed (infra/terraform-k6/main.tf's local.reports_bucket_name).
data "aws_iam_policy_document" "loadtest_transfer" {
  statement {
    sid       = "UploadLoadTestSessions"
    actions   = ["s3:PutObject"]
    resources = ["arn:aws:s3:::offline-bets-loadtest-reports-693333082584/transfer/*"]
  }
}

resource "aws_iam_role_policy" "app_loadtest_transfer" {
  name   = "${var.project_name}-app-loadtest-transfer"
  role   = aws_iam_role.app.id
  policy = data.aws_iam_policy_document.loadtest_transfer.json
}

# --- Reverb tier ---

resource "aws_iam_role" "reverb" {
  name               = "${var.project_name}-reverb"
  assume_role_policy = data.aws_iam_policy_document.ec2_assume.json
}

resource "aws_iam_role_policy" "reverb_secrets" {
  name   = "${var.project_name}-reverb-secrets"
  role   = aws_iam_role.reverb.id
  policy = data.aws_iam_policy_document.read_secrets.json
}

resource "aws_iam_role_policy_attachment" "reverb_cloudwatch" {
  role       = aws_iam_role.reverb.name
  policy_arn = "arn:aws:iam::aws:policy/CloudWatchAgentServerPolicy"
}

resource "aws_iam_role_policy_attachment" "reverb_ssm" {
  role       = aws_iam_role.reverb.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

resource "aws_iam_instance_profile" "reverb" {
  name = "${var.project_name}-reverb"
  role = aws_iam_role.reverb.name
}
