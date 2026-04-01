#!/usr/bin/env bash

set -euo pipefail

# Parent of unit_tests/ is repo/
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_JUNIT="${1:-${ROOT_DIR}/test_reports/unit-tests.junit.xml}"
TMP_DIR="$(mktemp -d)"
BACKEND_JUNIT="${TMP_DIR}/backend-unit.junit.xml"
FRONTEND_JUNIT="${TMP_DIR}/frontend-unit.junit.xml"
FRONTEND_TMP_OUTPUT="/workspace/test_reports/frontend-unit.tmp.junit.xml"

mkdir -p "$(dirname "${OUTPUT_JUNIT}")"
mkdir -p "${ROOT_DIR}/test_reports"

echo "[unit] running backend unit tests"
set +e
docker compose -f "${ROOT_DIR}/docker-compose.yml" -p vetops exec -T backend \
  sh -lc 'php artisan test --testsuite=Unit --log-junit=/tmp/backend-unit.junit.xml >/dev/null 2>&1; ec=$?; cat /tmp/backend-unit.junit.xml 2>/dev/null; exit $ec' \
  > "${BACKEND_JUNIT}"
BACKEND_EXIT=$?
set -e
if [[ ! -s "${BACKEND_JUNIT}" ]]; then
  printf '%s\n' '<?xml version="1.0" encoding="UTF-8"?>' '<testsuites name="backend" tests="0" failures="1" errors="0"/>' > "${BACKEND_JUNIT}"
fi
if [[ "${BACKEND_EXIT}" -ne 0 ]]; then
  echo "[unit] backend unit tests failed (exit ${BACKEND_EXIT})"
  exit "${BACKEND_EXIT}"
fi

echo "[unit] running frontend unit tests"
# Git Bash on Windows converts leading / paths (e.g. -w /workspace/frontend); disable for Docker CLI.
MSYS_NO_PATHCONV=1 docker run --rm \
  -v "${ROOT_DIR}:/workspace" \
  -w /workspace/frontend \
  node:20-alpine \
  sh -lc "npm ci --silent && npx vitest run --reporter=junit --outputFile=${FRONTEND_TMP_OUTPUT}"
cp "${ROOT_DIR}/test_reports/frontend-unit.tmp.junit.xml" "${FRONTEND_JUNIT}"
rm -f "${ROOT_DIR}/test_reports/frontend-unit.tmp.junit.xml"

cat > "${OUTPUT_JUNIT}" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<testsuites name="unit_tests_aggregate">
$(sed '1d;$d' "${BACKEND_JUNIT}")
$(sed '1d;$d' "${FRONTEND_JUNIT}")
</testsuites>
EOF

echo "[unit] combined junit: ${OUTPUT_JUNIT}"
