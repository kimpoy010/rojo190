output "public_ip" {
  value = aws_instance.runner.public_ip
}

output "ssh_command" {
  value = "ssh -i offline-bets-loadtest.pem ubuntu@${aws_instance.runner.public_ip}"
}

output "ssh_private_key" {
  value     = tls_private_key.ssh.private_key_pem
  sensitive = true
}

output "reports_bucket" {
  value = aws_s3_bucket.reports.bucket
}

output "reports_bucket_console_url" {
  value = "https://s3.console.aws.amazon.com/s3/buckets/${aws_s3_bucket.reports.bucket}"
}
