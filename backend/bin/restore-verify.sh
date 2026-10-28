#!/usr/bin/env bash
# Restores a backup into a SEPARATE scratch database and checks it. Never touches the live database.
# Usage: bin/restore-verify.sh /path/to/db-YYYYmmddTHHMMSSZ.sql.gz <scratch_db_name>
# The DB user in .env needs CREATE/DROP rights on <scratch_db_name> (create it in DirectAdmin first on shared hosting).
set -euo pipefail
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${BLISSFUL_ENV_FILE:-$APP_DIR/.env}"
DUMP="$1"; SCRATCH="$2"
get() { { grep -E "^$1=" "$ENV_FILE" || true; } | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
LIVE=$(get DB_NAME)
if [ "$SCRATCH" = "$LIVE" ]; then echo "Refusing: scratch database equals the live database." >&2; exit 1; fi
CNF=$(mktemp); trap 'rm -f "$CNF"' EXIT
printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$(get DB_USER)" "$(get DB_PASS)" "$(h=$(get DB_HOST); echo ${h:-localhost})" "$(p=$(get DB_PORT); echo ${p:-3306})" > "$CNF"
mysql --defaults-extra-file="$CNF" -e "DROP DATABASE IF EXISTS \`$SCRATCH\`; CREATE DATABASE \`$SCRATCH\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c "$DUMP" | mysql --defaults-extra-file="$CNF" "$SCRATCH"
echo "Table            live_rows  restored_rows"
FAIL=0
for t in users requests offers bookings payments messages reviews schema_migrations; do
  a=$(mysql --defaults-extra-file="$CNF" -N -e "SELECT COUNT(*) FROM \`$LIVE\`.\`$t\`")
  b=$(mysql --defaults-extra-file="$CNF" -N -e "SELECT COUNT(*) FROM \`$SCRATCH\`.\`$t\`")
  printf '%-16s %9s %14s\n' "$t" "$a" "$b"
  [ "$a" = "$b" ] || FAIL=1
done
# The restored copy must also pass the application's own health check.
SCRATCH_ENV=$(mktemp); trap 'rm -f "$CNF" "$SCRATCH_ENV"' EXIT
sed "s/^DB_NAME=.*/DB_NAME=$SCRATCH/" "$ENV_FILE" > "$SCRATCH_ENV"
BLISSFUL_ENV_FILE="$SCRATCH_ENV" php -r '
  require "'"$APP_DIR"'/src/bootstrap.php";
  $r = \Blissful\Http\Api::health(); echo "App health on restored DB: ", $r->body, PHP_EOL; exit($r->status === 200 ? 0 : 1);' || FAIL=1
[ "$FAIL" = 0 ] && echo "RESTORE VERIFIED (counts match live at time of check; recent writes may differ slightly)." || { echo "RESTORE CHECK FAILED"; exit 1; }
