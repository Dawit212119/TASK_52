#!/usr/bin/env bash

set -euo pipefail

# Parent of API_tests/ is the repository root.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_JUNIT="${1:-${ROOT_DIR}/test_reports/api-tests.junit.xml}"

mkdir -p "$(dirname "${OUTPUT_JUNIT}")"

echo "[api] executing Laravel feature/API functional tests"
set +e
docker compose -f "${ROOT_DIR}/docker-compose.yml" -p vetops exec -T backend \
  sh -lc 'php artisan config:clear >/dev/null 2>&1 || true; php artisan route:clear >/dev/null 2>&1 || true; VETOPS_AUTH_CAPTCHA_BYPASS_TOKEN=local-captcha-ok php artisan test --testsuite=Feature --log-junit=/tmp/api-feature.junit.xml >/dev/null 2>&1; ec=$?; cat /tmp/api-feature.junit.xml 2>/dev/null; exit $ec' \
  > "${OUTPUT_JUNIT}"
TEST_EXIT=$?
set -e

if [[ ! -s "${OUTPUT_JUNIT}" ]]; then
  printf '%s\n' '<?xml version="1.0" encoding="UTF-8"?>' '<testsuites name="api" tests="0" failures="1" errors="0"/>' > "${OUTPUT_JUNIT}"
fi

echo "[api] junit written to ${OUTPUT_JUNIT}"
exit "${TEST_EXIT}"
