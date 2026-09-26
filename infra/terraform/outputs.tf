output "alb_dns_name" {
  value = aws_lb.main.dns_name
}

output "app_url" {
  value = local.app_url
}

output "reverb_public_host" {
  value = local.reverb_public_host
}

output "db_endpoint" {
  value = aws_db_instance.main.address
}

output "db_master_user_secret_arn" {
  description = "Secrets Manager ARN holding the RDS master password (AWS-managed)."
  value       = aws_db_instance.main.master_user_secret[0].secret_arn
}

output "app_env_secret_arn" {
  value = aws_secretsmanager_secret.app_env.arn
}

output "redis_primary_endpoint" {
  value = aws_elasticache_replication_group.main.primary_endpoint_address
}

output "ssh_private_key" {
  description = "PEM private key for the generated key pair. Save once: terraform output -raw ssh_private_key > offline-bets-production.pem && chmod 400 offline-bets-production.pem"
  value       = tls_private_key.ssh.private_key_pem
  sensitive   = true
}

output "acm_validation_records" {
  description = "DNS validation records to create manually if manage_dns=false and domain_name is set."
  value = var.domain_name != "" ? {
    for dvo in aws_acm_certificate.main[0].domain_validation_options : dvo.domain_name => {
      name  = dvo.resource_record_name
      type  = dvo.resource_record_type
      value = dvo.resource_record_value
    }
  } : {}
}

output "route53_name_servers" {
  description = "Delegate your registrar's NS records to these if manage_dns=true."
  value       = var.domain_name != "" && var.manage_dns ? aws_route53_zone.main[0].name_servers : []
}
