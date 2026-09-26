# k6 load-generator box

A standalone EC2 instance whose only job is running the `deploy/loadtest/`
k6 scripts against the production app (`infra/terraform/`). Kept as its own
Terraform root module — separate state, separate lifecycle — because this
box should be spun up around a test run and torn down afterward, not left
running as part of the permanent production footprint.

## Region

Defaults to `mx-central-1` — the same region as production. The app
launches solely in Mexico, so a same-region runner strips out the network
hop entirely and measures the server's own processing time. That's a
deliberate choice, not a more "realistic" number: real players won't be
sitting inside this VPC either, they'll be on normal residential/mobile
connections somewhere in Mexico with their own latency to the ALB. If you
want a rough approximation of an external network path instead, point
`aws_region` at wherever you're testing from — see "Reading results"
below for how to interpret cross-region numbers if you do.

## Usage

1. `cp terraform.tfvars.example terraform.tfvars`, set `ssh_allowed_cidr`
   to your own IP (`curl -s ifconfig.me` to find it).
2. Export AWS credentials, then:
   ```
   terraform init
   terraform plan
   terraform apply
   ```
3. Save the SSH key: `terraform output -raw ssh_private_key > offline-bets-loadtest.pem && chmod 400 offline-bets-loadtest.pem`
4. `terraform output ssh_command` to connect. The box already has k6
   installed, OS limits tuned for high concurrent-connection counts, and a
   shallow clone of this repo at `/home/ubuntu/offline-bets` (so
   `deploy/loadtest/*.js` is there without you copying it up by hand).

## Running a test

Follow `deploy/loadtest/README.md` on the **app server** side (seeding
`loadtest:seed-players`, opening a fight, etc.) first — this box is only
the "load-generator VPS" referenced throughout that guide. Once you have
`loadtest-sessions.csv` from the app server:

```bash
# from your laptop, or scp directly app-server -> this box
scp loadtest-sessions.csv ubuntu@<runner-ip>:offline-bets/deploy/loadtest/

# on the runner
./run-loadtest.sh bet-load-test.js \
  -e BASE_URL=https://your-app.example.com \
  -e FIGHT_ID=123 \
  -e COOKIE_NAME=sabong-pool-betting-session

./run-loadtest.sh wallet-latency-test.js \
  -e BASE_URL=https://your-app.example.com \
  -e FIGHT_ID=123 \
  -e COOKIE_NAME=sabong-pool-betting-session \
  -e WS_HOST=your-app.example.com -e WS_PORT=443 -e WS_SCHEME=wss \
  -e REVERB_APP_KEY=your-reverb-app-key
```

`run-loadtest.sh` wraps `k6 run`, then uploads that run's `summary.html`/
`summary.json` to `terraform output reports_bucket` under a timestamped
prefix — so results are retrievable (and shareable via the console URL)
even after you `terraform destroy` this box.

## Reading results

With `aws_region = "mx-central-1"` (the default), the runner sits in the
same region as production — `wallet-latency-test.js`'s `p(95)` against
the `<50ms` broadcast-latency target is close to a direct read of server
performance, with minimal network noise on top.

If you instead point `aws_region` at a different region (to approximate
an external network path, e.g. from wherever your team happens to be),
that adds real inter-region network RTT on top of everything the app
itself does, which will inflate every latency number in a way that has
nothing to do with server health. To get a signal that's actually about
the server in that case:

1. Run a quick baseline first: a single VU, no load, same script — this
   captures the pure network RTT floor for the day.
2. Run the real test at target concurrency.
3. Compare the **increase** in latency between the two, not the raw
   number from step 2 alone. A `p(95)` that's 300ms at baseline and 340ms
   under 10,000 VUs means the server added ~40ms under load — which is the
   number that answers "does it hold up," not the inflated absolute
   figure.

## Tearing down

```
terraform destroy
```

Safe to do between test runs — nothing on this box is stateful once
results are uploaded to S3 (step above). Re-`apply` next time you need it.
