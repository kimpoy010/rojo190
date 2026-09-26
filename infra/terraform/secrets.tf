# App-level secrets Terraform generates itself (distinct from the RDS
# master password, which AWS generates/stores via manage_master_user_password
# in rds.tf — Terraform never touches that one).

resource "random_password" "app_key" {
  length  = 32
  special = false
}

resource "random_password" "reverb_app_secret" {
  length  = 32
  special = false
}

resource "random_id" "reverb_app_id" {
  byte_length = 8
}

resource "random_id" "reverb_app_key" {
  byte_length = 16
}

resource "aws_secretsmanager_secret" "app_env" {
  name        = "${var.project_name}/app-env"
  description = "Laravel APP_KEY and Reverb app credentials for ${var.project_name}."
}

resource "aws_secretsmanager_secret_version" "app_env" {
  secret_id = aws_secretsmanager_secret.app_env.id
  secret_string = jsonencode({
    APP_KEY           = "base64:${base64encode(random_password.app_key.result)}"
    REVERB_APP_ID     = random_id.reverb_app_id.hex
    REVERB_APP_KEY    = random_id.reverb_app_key.hex
    REVERB_APP_SECRET = random_password.reverb_app_secret.result
    # kimpoy010/offline-bets is a private repo — an anonymous `git clone`
    # from the instance gets a 401, unlike from a dev machine that already
    # has GitHub auth configured. A fine-grained PAT scoped to just this
    # repo's contents (read-only) lets user-data clone it at boot.
    GITHUB_TOKEN = var.github_token
  })
}
