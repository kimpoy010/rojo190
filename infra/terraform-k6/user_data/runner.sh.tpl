#!/bin/bash
# Bootstraps the k6 load-generator box: installs k6, tunes the OS for
# thousands of concurrent outbound connections (the default file-descriptor
# and ephemeral-port limits are sized for a normal server, not a box whose
# entire job is opening tens of thousands of sockets), and checks out just
# the loadtest scripts from the app repo.
set -euxo pipefail

exec > >(tee /var/log/user-data.log) 2>&1

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y gnupg curl ca-certificates git unzip jq

# Ubuntu 24.04 (noble) dropped the `awscli` apt package entirely — the
# official installer is the only reliable way to get it now.
curl -fsSL "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o /tmp/awscliv2.zip
unzip -q /tmp/awscliv2.zip -d /tmp
/tmp/aws/install

gpg -k
gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
  --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" > /etc/apt/sources.list.d/k6.list
apt-get update -y
apt-get install -y k6

# --- OS tuning for high concurrent-connection counts ---
cat > /etc/security/limits.d/loadtest.conf <<'LIMITS'
*   soft   nofile   1048576
*   hard   nofile   1048576
LIMITS

cat > /etc/sysctl.d/99-loadtest.conf <<'SYSCTL'
fs.file-max = 2097152
net.ipv4.ip_local_port_range = 1024 65535
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_fin_timeout = 15
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
SYSCTL
sysctl --system

# ${app_repo_url} is private — anonymous clone gets a 401. xtrace prints
# every variable's expanded value once assigned, so -x is off for this
# whole block — left on, the token ends up in cleartext in
# /var/log/user-data.log.
set +x
GITHUB_TOKEN=$(aws secretsmanager get-secret-value --region "${aws_region}" --secret-id "${github_token_secret}" --query SecretString --output text)
AUTHED_REPO_URL=$(echo "${app_repo_url}" | sed "s#https://#https://x-access-token:$GITHUB_TOKEN@#")

REPO_DIR=/home/ubuntu/offline-bets
git clone --branch "${app_git_ref}" --depth 1 "$AUTHED_REPO_URL" "$REPO_DIR"
chown -R ubuntu:ubuntu "$REPO_DIR"
set -x

cat > /home/ubuntu/run-loadtest.sh <<'RUNNER'
#!/bin/bash
# Runs a k6 script from deploy/loadtest/ and uploads its summary.html /
# summary.json to S3 under a timestamped prefix, so results survive this
# box being torn down after the test. Usage:
#   ./run-loadtest.sh bet-load-test.js -e BASE_URL=... -e FIGHT_ID=... -e COOKIE_NAME=...
set -euo pipefail

SCRIPT="$1"
shift

cd /home/ubuntu/offline-bets/deploy/loadtest
RUN_ID=$(date +%Y%m%d-%H%M%S)-$$
mkdir -p "/tmp/loadtest-$RUN_ID"
cd "/tmp/loadtest-$RUN_ID"
cp "/home/ubuntu/offline-bets/deploy/loadtest/$SCRIPT" .
[ -f /home/ubuntu/offline-bets/deploy/loadtest/loadtest-sessions.csv ] && \
  cp /home/ubuntu/offline-bets/deploy/loadtest/loadtest-sessions.csv .

k6 run "$@" "$SCRIPT"

aws s3 cp summary.html "s3://${reports_bucket}/$RUN_ID/summary.html" || true
aws s3 cp summary.json "s3://${reports_bucket}/$RUN_ID/summary.json" || true

echo "Uploaded results to s3://${reports_bucket}/$RUN_ID/"
RUNNER
chmod +x /home/ubuntu/run-loadtest.sh
chown ubuntu:ubuntu /home/ubuntu/run-loadtest.sh
