#!/usr/bin/env bash
# One-time (re-runnable) staging installer for DirectAdmin + SSH.
#
# Before running, in DirectAdmin:
#   1. Subdomain Management → add "app" to blissfulevents.co.zw
#   2. MySQL Management → create a database + user for staging (note the names and password)
#   3. Select PHP 8.2 or newer for the domain
# Then upload and unzip blissful-gigs.zip in your home folder and run:
#   bash ~/blissful-gigs/backend/bin/install-staging.sh
#
# It writes .env (only if missing), copies the web files, runs migrations, optionally loads sample data,
# creates the first admin, installs the cron jobs, and checks the site. It never deletes data.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DOMAIN="${DOMAIN:-blissfulevents.co.zw}"
SUB="${SUB:-app}"
HOST="$SUB.$DOMAIN"
WEB_ROOT="${WEB_ROOT:-$HOME/domains/$DOMAIN/public_html/$SUB}"
ENV_FILE="$APP_DIR/.env"
say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
ask() { local p="$1" d="${2:-}" v; read -r -p "$p${d:+ [$d]}: " v; printf '%s' "${v:-$d}"; }
ask_secret() { local v; read -r -s -p "$1: " v; echo >&2; printf '%s' "$v"; }

# ---- PHP 8.2+
PHP_BIN="${PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then
  for c in php /usr/local/bin/php /usr/local/php83/bin/php /usr/local/php82/bin/php php83 php82; do
    if command -v "$c" >/dev/null 2>&1 && "$c" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' 2>/dev/null; then PHP_BIN="$(command -v "$c")"; break; fi
  done
fi
[ -n "$PHP_BIN" ] || { echo "No PHP 8.2+ found on the command line. Re-run with PHP_BIN=/path/to/php8.2" >&2; exit 1; }
say "Using $($PHP_BIN -r 'echo PHP_BINARY, " (PHP ", PHP_VERSION, ")";')"
missing=$("$PHP_BIN" -r 'foreach (["pdo_mysql","sodium","fileinfo","gd","curl","mbstring","openssl"] as $e) if (!extension_loaded($e)) echo $e, " ";')
[ -z "$missing" ] || { echo "Missing PHP extensions: $missing — enable them in DirectAdmin's PHP settings." >&2; exit 1; }

[ -d "$WEB_ROOT" ] || { echo "Web root $WEB_ROOT not found. Create the '$SUB' subdomain in DirectAdmin first (or set WEB_ROOT=...)." >&2; exit 1; }

# ---- .env
mkdir -p "$APP_DIR/storage/app" "$APP_DIR/storage/logs"
chmod 750 "$APP_DIR/storage"
if [ -f "$ENV_FILE" ]; then
  say ".env already exists — keeping it."
else
  say "Database details (from DirectAdmin → MySQL Management)"
  DB_NAME=$(ask "Database name")
  DB_USER=$(ask "Database user" "$DB_NAME")
  DB_PASS=$(ask_secret "Database password")
  SUPPORT=$(ask "Support email shown to testers" "support@$DOMAIN")
  DL_PASS=$("$PHP_BIN" -r 'echo bin2hex(random_bytes(6));')
  umask 077
  cat > "$ENV_FILE" <<EOF
APP_ENV=staging
APP_URL=https://$HOST
APP_KEY=$("$PHP_BIN" -r 'echo base64_encode(random_bytes(32));')
DB_HOST=localhost
DB_PORT=3306
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS="$DB_PASS"
STORAGE_PATH=$APP_DIR/storage
MAIL_DRIVER=mail
MAIL_FROM=no-reply@$DOMAIN
SUPPORT_EMAIL=$SUPPORT
PAYMENT_PROVIDER=sandbox
SANDBOX_WEBHOOK_SECRET=$("$PHP_BIN" -r 'echo bin2hex(random_bytes(24));')
PAYNOW_MODE=test
PAYMENTS_LIVE_ENABLED=false
FCM_SERVICE_ACCOUNT_FILE=
TEST_APK_PATH=$APP_DIR/storage/app/blissful-gigs-testing.apk
DOWNLOAD_USER=testers
DOWNLOAD_PASS=$DL_PASS
EOF
  chmod 600 "$ENV_FILE"
  say ".env written (chmod 600). Tester download login: testers / $DL_PASS"
fi

# ---- database check + migrations
"$PHP_BIN" -r 'require "'"$APP_DIR"'/src/bootstrap.php"; \Blissful\Db::get()->value("SELECT 1"); echo "Database connection OK\n";' \
  || { echo "Cannot connect to the database. Fix DB_* in $ENV_FILE and re-run." >&2; exit 1; }
say "Running migrations"
"$PHP_BIN" "$APP_DIR/bin/migrate.php"

# ---- web files
say "Installing web files into $WEB_ROOT"
for f in index.php .htaccess robots.txt; do cp "$APP_DIR/public/$f" "$WEB_ROOT/$f"; done
printf '%s\n' "$APP_DIR" > "$WEB_ROOT/.app-path"
chmod 644 "$WEB_ROOT/index.php" "$WEB_ROOT/.htaccess" "$WEB_ROOT/robots.txt" "$WEB_ROOT/.app-path"
# DirectAdmin creates a placeholder index.html that would shadow index.php
if [ -f "$WEB_ROOT/index.html" ]; then mv "$WEB_ROOT/index.html" "$WEB_ROOT/index.html.directadmin-default"; fi

# ---- sample data
users=$("$PHP_BIN" -r 'require "'"$APP_DIR"'/src/bootstrap.php"; echo \Blissful\Db::get()->value("SELECT COUNT(*) FROM users");')
if [ "$users" = "0" ] && [ "$(ask "Load fictional sample users and a sample request for testers? (y/n)" y)" = "y" ]; then
  SEED_PW=$(ask "Password for all sample accounts" "Sample-$("$PHP_BIN" -r 'echo bin2hex(random_bytes(3));')")
  SEED_PASSWORD="$SEED_PW" "$PHP_BIN" "$APP_DIR/bin/seed.php"
fi

# ---- first admin
staff=$("$PHP_BIN" -r 'require "'"$APP_DIR"'/src/bootstrap.php"; echo \Blissful\Db::get()->value("SELECT COUNT(*) FROM staff");')
if [ "$staff" = "0" ]; then
  say "Create the first administrator"
  A_EMAIL=$(ask "Admin email")
  A_NAME=$(ask "Admin full name")
  "$PHP_BIN" "$APP_DIR/bin/create-admin.php" "$A_EMAIL" "$A_NAME" superadmin
fi

# ---- cron
say "Installing cron jobs"
CRON_JOBS="* * * * * $PHP_BIN $APP_DIR/bin/cron.php >/dev/null 2>&1
15 2 * * * bash $APP_DIR/bin/backup.sh >/dev/null 2>&1"
if command -v crontab >/dev/null 2>&1 && \
   { crontab -l 2>/dev/null | grep -v "$APP_DIR/bin/" || true; echo "$CRON_JOBS"; } | crontab - 2>/dev/null; then
  echo "Cron installed:"; crontab -l | grep "$APP_DIR/bin/"
else
  echo "Could not install cron from SSH. Add these two jobs in DirectAdmin → Advanced Features → Cron Jobs:"
  echo "$CRON_JOBS"
fi
"$PHP_BIN" "$APP_DIR/bin/cron.php" -v >/dev/null && echo "Scheduled jobs ran once successfully."

# ---- checks
say "Checking https://$HOST"
code=$(curl -s -o /dev/null -w '%{http_code}' "https://$HOST/health" || true)
if [ "$code" = "200" ]; then
  echo "  /health                 OK"
  echo "  /api/catalog            $(curl -s -o /dev/null -w '%{http_code}' "https://$HOST/api/catalog")"
  echo "  /admin (expect 303)     $(curl -s -o /dev/null -w '%{http_code}' "https://$HOST/admin/")"
  echo "  /.app-path (expect 403) $(curl -s -o /dev/null -w '%{http_code}' "https://$HOST/.app-path")"
  echo "  http → https (expect 301) $(curl -s -o /dev/null -w '%{http_code}' "http://$HOST/health")"
else
  echo "  HTTPS check returned '$code'. If the SSL certificate isn't issued yet: DirectAdmin → SSL Certificates →"
  echo "  Let's Encrypt for $HOST, enable 'Force SSL', wait a few minutes, then re-run this script."
fi
say "Done. Admin: https://$HOST/admin   Testers: https://$HOST/"
