# Operations: backups, monitoring, maintenance

## Backups
- `bin/backup.sh` (daily cron 02:15): `mysqldump --single-transaction` + uploads archive + SHA-256 checksums, kept 14 days in `~/blissful-gigs/backups` (chmod 700).
- Copy backups **off the server** weekly (DirectAdmin remote backup or manual download). A server failure otherwise loses both data and backups.
- `bin/restore-verify.sh <dump> <scratch_db>` restores into a separate database, compares row counts with live and runs the app health check against the copy. It refuses to target the live database.

**Restore drill performed 28 Oct 2026 (local MariaDB 10.11, development data):** backup written, checksums OK,
all compared tables matched (users 9/9, requests 1/1, offers 2/2, schema_migrations 5/5, others 0/0), app health on the restored copy `ok`.
Repeat the drill on the real staging server after deployment and monthly afterwards.

### Full restore procedure
1. Put the site in maintenance: rename `public_html/app/index.php` → `index.php.off` and upload a static "maintenance" `index.html`.
2. Verify the chosen dump with `sha256sum -c SHA256SUMS-<stamp>` and `restore-verify.sh` into a scratch DB.
3. Import into the live DB: `gunzip -c db-<stamp>.sql.gz | mysql -u USER -p DBNAME`.
4. Restore uploads: `tar -xzf uploads-<stamp>.tar.gz -C ~/blissful-gigs/backend/storage`.
5. `php bin/migrate.php` (applies any newer migrations), remove maintenance page, run the checks in DEPLOYMENT.md §7.
6. Note the data-loss window (time between backup and failure) and tell affected users.

## Monitoring
- **Uptime**: point a free monitor (UptimeRobot, Better Stack) at `https://app.blissfulevents.co.zw/health` every 5 minutes; alert by email/SMS.
- **Logs**: JSON lines in `storage/logs/app-YYYY-MM-DD.log` (`level`, `event`, redacted context). Watch for `error`, `unhandled`, `job_failed`,
  `webhook_bad_signature`, `payment_amount_mismatch`. Every 500 response includes a `request_id` that appears in the log.
  Delete logs older than 90 days (add `find storage/logs -mtime +90 -delete` to the backup cron).
- **Cron health**: the admin Overview should show reminders/expiry working; if `job_locks.locked_until` is far in the past while open requests are past their deadline, cron is not running.
- **Push**: `notification_outbox` rows with `status='failed'` indicate FCM problems.
- Error monitoring service (e.g. Sentry) is **not integrated**; recommended for production (requires an account and DSN; the PHP SDK needs Composer).

## Routine maintenance
| When | Task |
|---|---|
| Daily | Glance at admin Overview: disputes, reports, pending verifications, payouts due |
| Weekly | Process payouts; review audit log; download off-site backup |
| Monthly | Restore drill; review staff accounts; check PHP version support on the host; update Android dependencies via CI |
| Each release | `php bin/migrate.php --status` before/after; run deploy checks; bump `versionName` |

## Data retention (configurable in code/settings, confirm with legal)
- Verification documents: deleted 7 days after review, at most 90 days after upload (`verification_doc_retention_days`).
- Deleted accounts: personal data erased immediately; bookings, payments and reviews kept anonymised.
- Rate-limit, idempotency, expired tokens and codes: purged automatically by cron.
