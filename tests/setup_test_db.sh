#!/usr/bin/env bash
# (Re)create the isolated test database from schema + seed. Run once before the workflow tests.
#   ./tests/setup_test_db.sh
# Override defaults with env: MYSQL_BIN, TEST_DB_NAME, DB_USERNAME.
set -euo pipefail

# Portable mysql resolution: explicit MYSQL_BIN wins; otherwise use `mysql` from PATH (Linux/Windows/most
# hosts); only fall back to the local XAMPP path when neither is available. (ux-refactor F7)
if [ -n "${MYSQL_BIN:-}" ]; then
  MYSQL="$MYSQL_BIN"
elif command -v mysql >/dev/null 2>&1; then
  MYSQL="mysql"
elif [ -x /Applications/XAMPP/xamppfiles/bin/mysql ]; then
  MYSQL="/Applications/XAMPP/xamppfiles/bin/mysql"
else
  MYSQL="mysql"
fi
DB="${TEST_DB_NAME:-repair_system_test}"
USER="${DB_USERNAME:-root}"
DIR="$(cd "$(dirname "$0")/.." && pwd)"

"$MYSQL" -u"$USER" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"$MYSQL" -u"$USER" "$DB" < "$DIR/database/schema.sql"
"$MYSQL" -u"$USER" "$DB" < "$DIR/database/seed_reference.sql"
"$MYSQL" -u"$USER" "$DB" < "$DIR/database/seed_demo.sql"

echo "Test DB '$DB' ready (schema + reference + demo seed loaded)."
