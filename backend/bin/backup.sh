#!/usr/bin/env bash
# Nightly backup: consistent DB dump + uploads, kept 14 days. Reads DB settings from the app's .env.
# DirectAdmin cron (daily 02:15):  15 2 * * *  bash /home/USER/blissful-gigs/backend/bin/backup.sh >/dev/null 2>&1
# Copy the backup folder OFF the server too (DirectAdmin's own backup to remote FTP, or download weekly).
set -euo pipefail
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${BLISSFUL_ENV_FILE:-$APP_DIR/.env}"
get() { { grep -E "^$1=" "$ENV_FILE" || true; } | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_HOST=$(get DB_HOST); DB_PORT=$(get DB_PORT); DB_NAME=$(get DB_NAME); DB_USER=$(get DB_USER); DB_PASS=$(get DB_PASS)
STORAGE=$(get STORAGE_PATH); STORAGE=${STORAGE:-$APP_DIR/storage}
DEST="${BACKUP_DIR:-$APP_DIR/../backups}"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$DEST"; chmod 700 "$DEST"
umask 077
CNF=$(mktemp); trap 'rm -f "$CNF"' EXIT
printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$DB_USER" "$DB_PASS" "${DB_HOST:-localhost}" "${DB_PORT:-3306}" > "$CNF"
mysqldump --defaults-extra-file="$CNF" --single-transaction --routines --triggers --no-tablespaces "$DB_NAME" | gzip -9 > "$DEST/db-$STAMP.sql.gz"
if [ -d "$STORAGE/uploads" ]; then tar -czf "$DEST/uploads-$STAMP.tar.gz" -C "$STORAGE" uploads; fi
sha256sum "$DEST"/*-"$STAMP".* > "$DEST/SHA256SUMS-$STAMP"
find "$DEST" -type f -mtime +14 -delete
echo "Backup written: $DEST/db-$STAMP.sql.gz"
