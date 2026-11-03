#!/usr/bin/env bash
# Build an installable plugin ZIP: dist/ostoja-tpay-givewp-<version>.zip
set -euo pipefail
cd "$(dirname "$0")/.."

PLUGIN=ostoja-tpay-givewp
VERSION=$(sed -n 's/^ \* Version: *//p' "$PLUGIN/$PLUGIN.php" | tr -d '[:space:]')
mkdir -p dist
OUT="dist/$PLUGIN-$VERSION.zip"
rm -f "$OUT"
zip -rq "$OUT" "$PLUGIN" -x '*.DS_Store' '*/.git*'
echo "$OUT"
