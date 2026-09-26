resource "aws_instance" "reverb" {
  ami                    = data.aws_ami.ubuntu.id
  instance_type          = var.reverb_instance_type
  key_name               = aws_key_pair.main.key_name
  subnet_id              = aws_subnet.private[0].id
  vpc_security_group_ids = [aws_security_group.reverb.id]
  iam_instance_profile   = aws_iam_instance_profile.reverb.name

  metadata_options {
    http_tokens = "required"
  }

  # cloud-init only runs user_data once, at first boot — without this,
  # editing the bootstrap script wouldn't actually fix an already-running
  # (or already-broken) instance; Terraform would just update the stored
  # value and leave the real instance untouched.
  user_data_replace_on_change = true

  user_data = base64encode(templatefile("${path.module}/user_data/reverb.sh.tpl", {
    project_name   = var.project_name
    app_repo_url   = var.app_repo_url
    app_git_ref    = var.app_git_ref
    aws_region     = var.aws_region
    app_secret_arn = aws_secretsmanager_secret.app_env.arn
    db_secret_arn  = aws_db_instance.main.master_user_secret[0].secret_arn
    db_host        = aws_db_instance.main.address
    db_port        = aws_db_instance.main.port
    db_name        = var.db_name
    db_username    = var.db_username
    redis_host     = aws_elasticache_replication_group.main.primary_endpoint_address
    redis_port     = 6379
    app_url        = local.app_url
  }))

  tags = { Name = "${var.project_name}-reverb" }
}

resource "aws_lb_target_group_attachment" "reverb" {
  target_group_arn = aws_lb_target_group.reverb.arn
  target_id        = aws_instance.reverb.id
  port             = 8080
}
