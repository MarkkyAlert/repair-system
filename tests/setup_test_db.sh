#!/usr/bin/env bash
# (Re)create the isolated test database from schema + seed. Run once before the workflow tests.
#   ./tests/setup_test_db.sh
# Override defaults with env: MYSQL_BIN, TEST_DB_NAME, DB_USERNAME.
set -euo pipefail

MYSQL="${MYSQL_BIN:-/Applications/XAMPP/xamppfiles/bin/mysql}"
DB="${TEST_DB_NAME:-repair_system_test}"
USER="${DB_USERNAME:-root}"
DIR="$(cd "$(dirname "$0")/.." && pwd)"

"$MYSQL" -u"$USER" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"$MYSQL" -u"$USER" "$DB" < "$DIR/database/schema.sql"
"$MYSQL" -u"$USER" "$DB" < "$DIR/database/seed.sql"

echo "Test DB '$DB' ready (schema + seed loaded)."
