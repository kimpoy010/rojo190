#!/bin/bash
# Bootstraps an app-tier instance: installs the stack, checks out the app,
# builds it, and points it at RDS/Redis/secrets. Runs once per instance
# boot (ASG launch), so it deliberately does NOT run `migrate` — that's a
# separate, one-off deploy step (running it here would race concurrent
# migrations on every scale-out event).
set -euxo pipefail

exec > >(tee /var/log/user-data.log) 2>&1

export DEBIAN_FRONTEND=noninteractive
# cloud-init runs this as root without $HOME set — Composer's installer
# needs it (to know where to put its cache), and later so does `composer
# install` itself.
export HOME=/root
apt-get update -y
apt-get install -y software-properties-common curl unzip jq

add-apt-repository -y ppa:ondrej/php
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt-get update -y
apt-get install -y nginx mysql-client redis-tools nodejs \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-redis php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-gd php8.4-intl

curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Ubuntu 24.04 (noble) dropped the `awscli` apt package entirely — the
# official installer is the only reliable way to get it now.
curl -fsSL "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o /tmp/awscliv2.zip
unzip -q /tmp/awscliv2.zip -d /tmp
/tmp/aws/install

# xtrace prints every variable's expanded value, including the result of a
# command substitution once it's assigned — with -x left on, each line
# below would write its secret in cleartext straight into
# /var/log/user-data.log (readable by anyone with SSM/console access to
# this instance, and swept up by any log shipper). Off for this whole
# block, back on once nothing sensitive is being expanded in a traced
# command any more.
set +x

SECRETS=$(aws secretsmanager get-secret-value --region "${aws_region}" --secret-id "${app_secret_arn}" --query SecretString --output text)
DB_SECRETS=$(aws secretsmanager get-secret-value --region "${aws_region}" --secret-id "${db_secret_arn}" --query SecretString --output text)

APP_KEY_VAL=$(echo "$SECRETS" | jq -r .APP_KEY)
REVERB_APP_ID=$(echo "$SECRETS" | jq -r .REVERB_APP_ID)
REVERB_APP_KEY=$(echo "$SECRETS" | jq -r .REVERB_APP_KEY)
REVERB_APP_SECRET=$(echo "$SECRETS" | jq -r .REVERB_APP_SECRET)
GITHUB_TOKEN=$(echo "$SECRETS" | jq -r .GITHUB_TOKEN)
DB_PASSWORD=$(echo "$DB_SECRETS" | jq -r .password)

# ${app_repo_url} is private — anonymous clone gets a 401 from an
# instance with no GitHub auth configured, unlike a dev machine.
APP_DIR=/var/www/${project_name}
AUTHED_REPO_URL=$(echo "${app_repo_url}" | sed "s#https://#https://x-access-token:$GITHUB_TOKEN@#")
git clone --branch "${app_git_ref}" --depth 1 "$AUTHED_REPO_URL" "$APP_DIR"
cd "$APP_DIR"

# The heredoc body below isn't echoed by xtrace either way (only the `cat`
# command itself would be), but resuming here rather than after it keeps
# the boundary obvious rather than relying on that.
set -x

cat > .env <<ENV
APP_NAME="${project_name}"
APP_ENV=production
APP_KEY=$APP_KEY_VAL
APP_DEBUG=false
APP_URL=${app_url}

DB_CONNECTION=mysql
DB_HOST=${db_host}
DB_PORT=${db_port}
DB_DATABASE=${db_name}
DB_USERNAME=${db_username}
DB_PASSWORD=$DB_PASSWORD

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
# tls:// prefix is required — ElastiCache has transit_encryption_enabled,
# and phpredis recognizes this scheme prefix to negotiate TLS with no
# other Laravel-side config needed.
REDIS_HOST=tls://${redis_host}
REDIS_PORT=${redis_port}

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=$REVERB_APP_ID
REVERB_APP_KEY=$REVERB_APP_KEY
REVERB_APP_SECRET=$REVERB_APP_SECRET
REVERB_HOST=${reverb_public_host}
REVERB_PORT=${reverb_public_port}
REVERB_SCHEME=${reverb_public_scheme}

VITE_REVERB_APP_KEY=$REVERB_APP_KEY
VITE_REVERB_HOST=${reverb_public_host}
VITE_REVERB_PORT=${reverb_public_port}
VITE_REVERB_SCHEME=${reverb_public_scheme}
ENV

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data "$APP_DIR"

install -m 644 deploy/nginx/app.conf /etc/nginx/sites-available/app.conf
ln -sf /etc/nginx/sites-available/app.conf /etc/nginx/sites-enabled/app.conf
rm -f /etc/nginx/sites-enabled/default

# Stock Ubuntu nginx.conf ships with worker_connections 768 — nowhere near
# enough at real target concurrency (confirmed live: "768 worker_connections
# are not enough while connecting to upstream" errors during a stress test
# at 5,000 VUs). worker_rlimit_nofile raises nginx's own fd ceiling to
# match; the systemd override below raises the OS-level limit nginx
# actually inherits, since worker_rlimit_nofile can't exceed it.
sed -i 's/worker_connections .*/worker_connections 8192;/' /etc/nginx/nginx.conf
sed -i '/^user /a worker_rlimit_nofile 65535;' /etc/nginx/nginx.conf
mkdir -p /etc/systemd/system/nginx.service.d
cat > /etc/systemd/system/nginx.service.d/override.conf <<'SYSTEMD'
[Service]
LimitNOFILE=65535
SYSTEMD

install -m 644 deploy/php-fpm/www-app.conf /etc/php/8.4/fpm/pool.d/www-app.conf
rm -f /etc/php/8.4/fpm/pool.d/www.conf

systemctl daemon-reload
systemctl enable php8.4-fpm nginx
systemctl restart php8.4-fpm
systemctl restart nginx
