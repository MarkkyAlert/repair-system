#!/usr/bin/env sh
# bin/package-release.sh — build a clean, sellable release ZIP (run by the SELLER, not the buyer).
#
# Produces dist/repair-system-<stamp>.zip that a buyer can upload and install as-is:
#   INCLUDES : all tracked source + the PRODUCTION vendor/ (composer install --no-dev) so a shared host with
#              no Composer/CLI still runs — QR, email, PDF and Excel all need those libraries.
#   EXCLUDES : .git history, .env, real uploads/backups/logs, and dev-only tooling — so no secret or dev data
#              ever leaks into the package.
#
# Needs (on the seller's machine): git, composer, zip. Usage: sh bin/package-release.sh [version-stamp]
set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

for t in git composer zip; do
    command -v "$t" >/dev/null 2>&1 || { echo "Error: '$t' is required to build a release." >&2; exit 1; }
done

STAMP="${1:-$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo release)}"
STAGING="$(mktemp -d)"
trap 'rm -rf "$STAGING"' EXIT

# 1) tracked files ONLY — git archive omits .git, vendor/, .env and all gitignored storage data by design.
git -C "$ROOT" archive HEAD | tar -x -C "$STAGING"

# 2) production dependencies only (drops phpstan / php-cs-fixer / pdfparser dev tools).
( cd "$STAGING" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )

# 3) strip dev-only / internal files a buyer never needs. Includes the test suite (tests/): it needs a test
#    DB + dev tooling to run, carries internal review references, and is not part of the shipped product.
( cd "$STAGING" && rm -rf .github .githooks tools e2e tests docs \
    phpstan.neon phpstan-baseline.neon phpstan-bootstrap.php \
    .php-cs-fixer.dist.php .php-cs-fixer.cache handoff.md prompt.md )

# 4) belt-and-suspenders: never ship secrets or real data even if a stray copy slipped in.
( cd "$STAGING" && rm -f .env && rm -rf .git \
    && rm -f storage/backups/*.gz storage/logs/*.log storage/mail-logs/*.json storage/uploads/tickets/* )

# 4b) warn (do not block) if LICENSE.md still has unfilled placeholders — the buyer must not
#     receive a licence with no licensor named. Fill name/year/contact before shipping.
if grep -qE '\[ชื่อผู้ขาย|© \[ปี\]|\[อีเมล' "$STAGING/LICENSE.md" 2>/dev/null; then
    echo "  ⚠️  WARNING: LICENSE.md still has unfilled placeholders ([ชื่อผู้ขาย]/© [ปี]/[อีเมล]) — fill them before selling."
fi

# 5) zip it up (keep the release artifacts out of git via a self-ignoring dist/ dir).
OUT="$ROOT/dist"
mkdir -p "$OUT"
printf '*\n' > "$OUT/.gitignore"
ZIP="$OUT/repair-system-${STAMP}.zip"
rm -f "$ZIP"
( cd "$STAGING" && zip -rqX "$ZIP" . )

echo "Built: $ZIP"
if ( cd "$STAGING" && [ -f vendor/autoload.php ] ); then
    echo "  vendor/ bundled (production deps included)"
else
    echo "  WARNING: vendor/autoload.php missing — composer install may have failed" >&2
    exit 1
fi
