locals {
  app_url              = var.domain_name != "" ? "https://${var.domain_name}" : "http://${aws_lb.main.dns_name}"
  reverb_public_host   = var.domain_name != "" ? "${var.ws_subdomain}.${var.domain_name}" : aws_lb.main.dns_name
  reverb_public_port   = var.domain_name != "" ? 443 : 80
  reverb_public_scheme = var.domain_name != "" ? "https" : "http"
}

resource "tls_private_key" "ssh" {
  algorithm = "RSA"
  rsa_bits  = 4096
}

resource "aws_key_pair" "main" {
  key_name   = var.key_pair_name
  public_key = tls_private_key.ssh.public_key_openssh
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

resource "aws_launch_template" "app" {
  name_prefix   = "${var.project_name}-app-"
  image_id      = data.aws_ami.ubuntu.id
  instance_type = var.app_instance_type
  key_name      = aws_key_pair.main.key_name

  iam_instance_profile {
    name = aws_iam_instance_profile.app.name
  }

  vpc_security_group_ids = [aws_security_group.app.id]

  metadata_options {
    http_tokens = "required" # IMDSv2 only
  }

  user_data = base64encode(templatefile("${path.module}/user_data/app.sh.tpl", {
    project_name         = var.project_name
    app_repo_url         = var.app_repo_url
    app_git_ref          = var.app_git_ref
    aws_region           = var.aws_region
    app_secret_arn       = aws_secretsmanager_secret.app_env.arn
    db_secret_arn        = aws_db_instance.main.master_user_secret[0].secret_arn
    db_host              = aws_db_instance.main.address
    db_port              = aws_db_instance.main.port
    db_name              = var.db_name
    db_username          = var.db_username
    redis_host           = aws_elasticache_replication_group.main.primary_endpoint_address
    redis_port           = 6379
    app_url              = local.app_url
    reverb_public_host   = local.reverb_public_host
    reverb_public_port   = local.reverb_public_port
    reverb_public_scheme = local.reverb_public_scheme
  }))

  tag_specifications {
    resource_type = "instance"
    tags          = { Name = "${var.project_name}-app" }
  }
}

resource "aws_autoscaling_group" "app" {
  name                = "${var.project_name}-app"
  vpc_zone_identifier = aws_subnet.private[*].id
  min_size            = var.app_asg_min_size
  max_size            = var.app_asg_max_size
  desired_capacity    = var.app_asg_desired_capacity

  target_group_arns         = [aws_lb_target_group.app.arn]
  health_check_type         = "ELB"
  health_check_grace_period = 120

  launch_template {
    id = aws_launch_template.app.id
    # Not the literal "$Latest" — that string never changes, so Terraform
    # never sees a diff on this block and the instance_refresh below
    # (however it's triggered) never actually fires. Confirmed the hard
    # way, twice: apply succeeds, the launch template gets a new version,
    # and the already-running instance just sits there unrefreshed with
    # nothing in describe-instance-refreshes. latest_version is a real
    # tracked attribute that changes value on every new version, which is
    # what the automatic refresh detection actually needs to see.
    version = aws_launch_template.app.latest_version
  }

  # An ASG doesn't replace already-running instances just because the
  # launch template changed underneath it — without this, a fixed/updated
  # bootstrap script would only apply to future scale-out events, never to
  # whatever's already running. min_healthy_percentage = 0 (rather than the
  # usual 90) is specifically because desired_capacity defaults to 1: a
  # rolling refresh that insists on keeping 100%+ of a single instance
  # healthy the whole time can never actually replace it. Revisit once
  # desired_capacity is raised past 1 — 0 means a refresh briefly has zero
  # capacity in front of the ALB.
  instance_refresh {
    strategy = "Rolling"
    preferences {
      min_healthy_percentage = 0
    }
    # No explicit triggers — a launch_template change auto-triggers a
    # refresh already (Terraform warns that listing it here is redundant).
    # What actually blocked that detection was pinning the launch_template
    # block's version to the literal "$Latest" above; see that comment.
  }

  tag {
    key                 = "Name"
    value               = "${var.project_name}-app"
    propagate_at_launch = true
  }
}

# CPU-based target tracking, not yet load-tested itself — confirmed the
# hard way that without ANY scaling policy, the ASG just sits at
# desired_capacity forever regardless of load (a stress test at 5,000 VUs
# never scaled past 1 instance). 60% is a starting point, not a measured
# number; ALBRequestCountPerTarget would track actual throughput more
# directly for this I/O-bound app, but needs a resource_label wired to the
# ALB/target group and CPU is the simpler place to start.
resource "aws_autoscaling_policy" "app_cpu" {
  name                      = "${var.project_name}-app-cpu-target"
  autoscaling_group_name    = aws_autoscaling_group.app.name
  policy_type               = "TargetTrackingScaling"
  estimated_instance_warmup = 120

  target_tracking_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ASGAverageCPUUtilization"
    }
    target_value = 60
  }
}
