# Only does anything when both domain_name and manage_dns are set. If the
# domain's DNS lives elsewhere (Cloudflare, Namecheap, etc.), leave
# manage_dns false and create the equivalent records there yourself:
#   <domain_name>            A/ALIAS  -> aws_lb.main.dns_name
#   <ws_subdomain>.<domain>  A/ALIAS  -> aws_lb.main.dns_name
#   plus the ACM DNS validation CNAME(s) from `terraform output acm_validation_records`.

resource "aws_route53_zone" "main" {
  count = var.domain_name != "" && var.manage_dns ? 1 : 0
  name  = var.domain_name
}

resource "aws_route53_record" "acm_validation" {
  for_each = var.domain_name != "" && var.manage_dns ? {
    for dvo in aws_acm_certificate.main[0].domain_validation_options : dvo.domain_name => {
      name   = dvo.resource_record_name
      record = dvo.resource_record_value
      type   = dvo.resource_record_type
    }
  } : {}

  zone_id = aws_route53_zone.main[0].zone_id
  name    = each.value.name
  type    = each.value.type
  records = [each.value.record]
  ttl     = 60
}

resource "aws_acm_certificate_validation" "main" {
  count                   = var.domain_name != "" && var.manage_dns ? 1 : 0
  certificate_arn         = aws_acm_certificate.main[0].arn
  validation_record_fqdns = [for r in aws_route53_record.acm_validation : r.fqdn]
}

resource "aws_route53_record" "root" {
  count   = var.domain_name != "" && var.manage_dns ? 1 : 0
  zone_id = aws_route53_zone.main[0].zone_id
  name    = var.domain_name
  type    = "A"

  alias {
    name                   = aws_lb.main.dns_name
    zone_id                = aws_lb.main.zone_id
    evaluate_target_health = true
  }
}

resource "aws_route53_record" "ws" {
  count   = var.domain_name != "" && var.manage_dns ? 1 : 0
  zone_id = aws_route53_zone.main[0].zone_id
  name    = "${var.ws_subdomain}.${var.domain_name}"
  type    = "A"

  alias {
    name                   = aws_lb.main.dns_name
    zone_id                = aws_lb.main.zone_id
    evaluate_target_health = true
  }
}
