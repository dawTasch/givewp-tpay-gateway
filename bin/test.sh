#!/usr/bin/env bash
# Lint the plugin and run tests on PHP 7.4 and 8.3.
# Uses local PHP when DOCKER=0, otherwise official php:*-cli Docker images.
set -euo pipefail
cd "$(dirname "$0")/.."

run() {
  find ostoja-tpay-givewp -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true
  php tests/JwsVerifierTest.php
}

if [ "${DOCKER:-1}" = "0" ]; then
  run
  exit 0
fi

for v in ${PHP_VERSIONS:-7.4 8.3}; do
  echo "== PHP $v"
  docker run --rm -v "$PWD":/app:ro -w /app "php:$v-cli" bash -c "$(declare -f run); run"
done
