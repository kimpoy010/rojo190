data "aws_vpc" "default" {
  default = true
}

data "aws_subnets" "default" {
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.default.id]
  }
}

data "aws_ami" "ubuntu" {
  most_recent = true
  owners      = ["099720109477"] # Canonical

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

resource "tls_private_key" "ssh" {
  algorithm = "RSA"
  rsa_bits  = 4096
}

resource "aws_key_pair" "main" {
  key_name   = var.key_pair_name
  public_key = tls_private_key.ssh.public_key_openssh
}

resource "aws_security_group" "runner" {
  name        = "${var.project_name}-loadtest-runner"
  description = "k6 load-generator - SSH only, from ssh_allowed_cidr. Outbound is unrestricted since it needs to hit whatever app server URL you point it at."
  vpc_id      = data.aws_vpc.default.id

  ingress {
    description = "SSH"
    from_port   = 22
    to_port     = 22
    protocol    = "tcp"
    cidr_blocks = [var.ssh_allowed_cidr]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = { Name = "${var.project_name}-loadtest-runner-sg" }
}

data "aws_caller_identity" "current" {}

locals {
  reports_bucket_name = var.reports_bucket_name != "" ? var.reports_bucket_name : "${var.project_name}-loadtest-reports-${data.aws_caller_identity.current.account_id}"
}

resource "aws_s3_bucket" "reports" {
  bucket = local.reports_bucket_name

  tags = { Name = "${var.project_name}-loadtest-reports" }
}

resource "aws_s3_bucket_public_access_block" "reports" {
  bucket = aws_s3_bucket.reports.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_lifecycle_configuration" "reports" {
  bucket = aws_s3_bucket.reports.id

  rule {
    id     = "expire-old-reports"
    status = "Enabled"

    filter {}

    expiration {
      days = 90
    }
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

resource "aws_iam_role" "runner" {
  name               = "${var.project_name}-loadtest-runner"
  assume_role_policy = data.aws_iam_policy_document.ec2_assume.json
}

data "aws_iam_policy_document" "reports_write" {
  statement {
    actions   = ["s3:PutObject", "s3:GetObject", "s3:ListBucket"]
    resources = [aws_s3_bucket.reports.arn, "${aws_s3_bucket.reports.arn}/*"]
  }
}

resource "aws_iam_role_policy" "reports_write" {
  name   = "${var.project_name}-loadtest-reports-write"
  role   = aws_iam_role.runner.id
  policy = data.aws_iam_policy_document.reports_write.json
}

resource "aws_secretsmanager_secret" "github_token" {
  name        = "${var.project_name}-loadtest/github-token"
  description = "GitHub PAT so the runner can clone the private app_repo_url at boot."
}

resource "aws_secretsmanager_secret_version" "github_token" {
  secret_id     = aws_secretsmanager_secret.github_token.id
  secret_string = var.github_token
}

data "aws_iam_policy_document" "read_github_token" {
  statement {
    actions   = ["secretsmanager:GetSecretValue"]
    resources = [aws_secretsmanager_secret.github_token.arn]
  }
}

resource "aws_iam_role_policy" "read_github_token" {
  name   = "${var.project_name}-loadtest-github-token-read"
  role   = aws_iam_role.runner.id
  policy = data.aws_iam_policy_document.read_github_token.json
}

resource "aws_iam_role_policy_attachment" "ssm" {
  role       = aws_iam_role.runner.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

resource "aws_iam_instance_profile" "runner" {
  name = "${var.project_name}-loadtest-runner"
  role = aws_iam_role.runner.name
}

resource "aws_instance" "runner" {
  ami                    = data.aws_ami.ubuntu.id
  instance_type          = var.instance_type
  key_name               = aws_key_pair.main.key_name
  subnet_id              = data.aws_subnets.default.ids[0]
  vpc_security_group_ids = [aws_security_group.runner.id]
  iam_instance_profile   = aws_iam_instance_profile.runner.name

  associate_public_ip_address = true

  root_block_device {
    volume_size = 40
    volume_type = "gp3"
  }

  metadata_options {
    http_tokens = "required"
  }

  user_data_replace_on_change = true

  user_data = base64encode(templatefile("${path.module}/user_data/runner.sh.tpl", {
    app_repo_url        = var.app_repo_url
    app_git_ref         = var.app_git_ref
    reports_bucket      = aws_s3_bucket.reports.bucket
    aws_region          = var.aws_region
    github_token_secret = aws_secretsmanager_secret.github_token.arn
  }))

  tags = { Name = "${var.project_name}-loadtest-runner" }
}
