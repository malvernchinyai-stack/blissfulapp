# Deploying the staging environment — app.blissfulevents.co.zw

Status on 28 Oct 2026: **prepared, not deployed.** No hosting, DNS or SSH access was available from the build
environment. Deployment is complete only when every check in §7 passes over HTTPS *and* the Android test build
completes the core flows against it.

## 1. What runs where

| Path | Served by | Notes |
|---|---|---|
| `/` | `Pages::landing` | Testing landing page + APK link + install steps |
| `/api/*` | JSON API | Used by the Android app |
| `/admin` | Admin dashboard | Staff only: password + authenticator code |
| `/privacy`, `/terms` | Draft legal pages | Marked "for business and legal review" |
| `/health` | `{"status":"ok"}` | Nothing sensitive |
| `/download/app.apk` | Test APK | Optional HTTP Basic auth for testers |
| `/webhooks/sandbox`, `/webhooks/paynow` | Payment callbacks | Signature/hash verified |

Everything is `noindex, nofollow` (header + robots.txt) and every page carries a "Testing" banner.

## 2. Requirements on the DirectAdmin account (drive.serverbox.co.zw)

- PHP **8.2 or 8.3** for the subdomain, with extensions: `pdo_mysql`, `sodium`, `fileinfo`, `gd`, `curl`, `mbstring`, `openssl`.
- MySQL 8 or MariaDB 10.6+, one **new staging database and user** (never reuse the Blissful website's database).
- Cron jobs enabled (1-minute interval). **SSH access** is strongly recommended; without it see §8.
- `upload_max_filesize` ≥ 10M and `post_max_size` ≥ 12M (portfolio audio).
- Outbound HTTPS allowed (Paynow, and Firebase if push is enabled).

## 3. DNS

Where is `blissfulevents.co.zw`'s DNS managed? (Check the domain's nameservers with `nslookup -type=ns blissfulevents.co.zw`.)

- **If DNS is on the same DirectAdmin server**: adding the subdomain in DirectAdmin creates the record automatically.
- **If DNS is elsewhere** (registrar or another host), add:

| Type | Name | Value | TTL |
|---|---|---|---|
| A | `app` | *public IPv4 of the DirectAdmin server* (DirectAdmin → Account → "Server IP") | 3600 |
| AAAA | `app` | *server IPv6, only if the server has one* | 3600 |

Do not change existing MX or `www` records. If verification emails will be sent from `@blissfulevents.co.zw`,
make sure the domain's SPF TXT record includes the DirectAdmin server (e.g. `v=spf1 a mx ip4:SERVER_IP ~all`) and enable DKIM in DirectAdmin.

## 4. One-time server setup

1. **Domain/subdomain**: DirectAdmin → Domain Setup → make sure `blissfulevents.co.zw` is on this account → Subdomain Management → add `app`.
   Its web root is normally `~/domains/blissfulevents.co.zw/public_html/app`.
2. **PHP version**: Domain Setup → PHP version selector → 8.2+ for the domain.
3. **Database**: MySQL Management → create e.g. `USER_bgstaging` with its own user and a long random password.
4. **Code location (outside the web root)**: `~/blissful-gigs/backend/` (upload with the deploy workflow or File Manager/SFTP).
   Create `~/blissful-gigs/backend/storage/` (chmod 750).
5. **Web root**: copy the contents of `backend/public/` (including `.htaccess`) into `public_html/app/`, then create
   `public_html/app/.app-path` containing exactly the absolute path, e.g. `/home/USER/blissful-gigs/backend`.
6. **Configuration**: copy `backend/.env.example` to `~/blissful-gigs/backend/.env`, fill every `REPLACE_` value, `chmod 600 .env`.
   Generate secrets on any machine with PHP:
   `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'` (APP_KEY) and `php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'`.
7. **Schema and sample data** (SSH): `cd ~/blissful-gigs/backend && php bin/migrate.php && SEED_PASSWORD='choose-one' php bin/seed.php`
8. **Staff account**: `php bin/create-admin.php you@blissfulevents.co.zw "Your Name" superadmin` → sign in at `/admin`, scan the key into an authenticator app.
9. **Cron** (DirectAdmin → Advanced → Cron Jobs):
   - every minute: `/usr/local/bin/php /home/USER/blissful-gigs/backend/bin/cron.php >/dev/null 2>&1`
   - daily 02:15: `bash /home/USER/blissful-gigs/backend/bin/backup.sh >/dev/null 2>&1`
   (run `which php` over SSH to confirm the PHP path used by your PHP 8.2+ selector).
10. **HTTPS**: SSL Certificates → Free & automatic certificate from Let's Encrypt → include `app.blissfulevents.co.zw` → enable "Force SSL with https redirect".
11. **Test APK**: place the APK at the `TEST_APK_PATH` from `.env` (default `storage/app/blissful-gigs-testing.apk`) and set `DOWNLOAD_USER`/`DOWNLOAD_PASS` for testers.

## 5. Updating (repeatable)

GitHub Actions → **Deploy staging** (manual). It rsyncs code (never `.env` or `storage/`), copies `public/`,
runs migrations, optionally publishes the APK from a chosen Android CI run, then runs the HTTPS checks below.
Secrets needed in the GitHub `staging` environment: `STAGING_SSH_HOST`, `STAGING_SSH_PORT`, `STAGING_SSH_USER`,
`STAGING_SSH_KEY` (a deploy-only key), `STAGING_APP_DIR`, `STAGING_WEB_ROOT`. Add required reviewers on that environment.

## 6. Managed services

| Service | Needed for | Status |
|---|---|---|
| Firebase project (free tier) | Push notifications | Optional. Without it, the in-app Inbox still works. Put the service-account JSON outside the web root, set `FCM_SERVICE_ACCOUNT_FILE`, and add the four `FIREBASE_*` GitHub secrets for the app build. |
| Paynow merchant account | Real payments | Not needed for staging (sandbox). See PAYMENTS.md. |
| Off-server backup storage | Disaster recovery | DirectAdmin's backup to remote FTP, or download `~/blissful-gigs/backups` weekly. |

## 7. Verification (all must pass before calling staging "deployed")

```
curl -s https://app.blissfulevents.co.zw/health                     # {"status":"ok"}
curl -sI https://app.blissfulevents.co.zw/ | grep -i x-robots-tag    # noindex, nofollow
curl -s https://app.blissfulevents.co.zw/api/catalog | head -c 200   # JSON with instruments
curl -s -o /dev/null -w '%{http_code}\n' https://app.blissfulevents.co.zw/admin/   # 303 (redirect to login)
curl -s -o /dev/null -w '%{http_code}\n' https://app.blissfulevents.co.zw/.app-path # 403
curl -s -o /dev/null -w '%{http_code}\n' http://app.blissfulevents.co.zw/health     # 301 to https
```
Then, on a real phone with the test APK: sign up (code arrives by email) → client posts a request → second account
as musician sends an offer → client accepts → chat → sandbox payment succeeds → mark done (both) → review.
Record the results in docs/TEST_REPORT.md.

## 8. If there is no SSH

The app runs without SSH, but setup is manual: upload files with File Manager/SFTP; run migrations by importing
`migrations/001…005.sql` in order through phpMyAdmin; create the first admin by temporarily running
`bin/create-admin.php` through a cron job set to run once (then delete the job). The automated deploy workflow
needs SSH (or can be adapted to FTPS with `SamKirkland/FTP-Deploy-Action`).
