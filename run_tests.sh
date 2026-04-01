#!/usr/bin/env bash

set -euo pipefail

# Repo folder is the test harness root (unit_tests/, API_tests/, test_reports/).
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Prefer docs/ at repository root; fallback to sibling docs for older layouts.
DOCS_DIR="${ROOT_DIR}/docs"
if [[ ! -d "${DOCS_DIR}" ]]; then
  DOCS_DIR="$(cd "${ROOT_DIR}/.." && pwd)/docs"
fi
REPORT_ROOT="${ROOT_DIR}/test_reports"
TIMESTAMP="$(date +"%Y%m%d-%H%M%S")"
REPORT_DIR="${REPORT_ROOT}/${TIMESTAMP}"
LATEST_LINK="${REPORT_ROOT}/latest"
HUMAN_LOG="${REPORT_DIR}/run.log"
SUMMARY_JSON="${REPORT_DIR}/summary.json"
SUMMARY_MD="${REPORT_DIR}/summary.md"

mkdir -p "${REPORT_DIR}"

log() {
  local message="$1"
  printf '[%s] %s\n' "$(date +"%Y-%m-%d %H:%M:%S")" "${message}" | tee -a "${HUMAN_LOG}"
}

require_path() {
  local path="$1"
  if [[ ! -e "${path}" ]]; then
    log "FAIL missing required path: ${path}"
    exit 1
  fi
}

# Optional documentation paths: warn only (do not block test execution).
warn_optional_path() {
  local path="$1"
  if [[ ! -e "${path}" ]]; then
    log "WARN missing optional path (see README acceptance docs): ${path}"
  fi
}

# PHPUnit nests suites and repeats failures/tests on parent and child; only count the root suite line.
junit_phpunit_root_attr() {
  local file="$1"
  local attr="$2"
  local line val
  line="$(grep -m1 'name="/var/www/phpunit.xml"' "${file}" 2>/dev/null || true)"
  if [[ -z "${line}" ]]; then
    printf '0'
    return
  fi
  val="$(printf '%s' "${line}" | sed -n "s/.*${attr}=\"\([0-9][0-9]*\)\".*/\1/p" | head -n1)"
  if [[ -z "${val}" ]]; then
    printf '0'
  else
    printf '%s' "${val}"
  fi
}

# Vitest JUnit reporter puts totals on the root testsuites line.
junit_vitest_root_attr() {
  local file="$1"
  local attr="$2"
  local line val
  line="$(grep -m1 'testsuites name="vitest tests"' "${file}" 2>/dev/null || true)"
  if [[ -z "${line}" ]]; then
    printf '0'
    return
  fi
  val="$(printf '%s' "${line}" | sed -n "s/.*${attr}=\"\([0-9][0-9]*\)\".*/\1/p" | head -n1)"
  if [[ -z "${val}" ]]; then
    printf '0'
  else
    printf '%s' "${val}"
  fi
}

sum_junit_attr() {
  local attr="$1"
  shift
  local files=("$@")
  local sum=0
  local f
  for f in "${files[@]}"; do
    [[ -f "${f}" ]] || continue
    sum=$((sum + $(junit_phpunit_root_attr "${f}" "${attr}")))
    sum=$((sum + $(junit_vitest_root_attr "${f}" "${attr}")))
  done
  printf '%s' "${sum}"
}

to_json_bool() {
  if [[ "$1" == "true" ]]; then
    echo "true"
  else
    echo "false"
  fi
}

require_path "${ROOT_DIR}/backend"
require_path "${ROOT_DIR}/frontend"
require_path "${ROOT_DIR}/docker-compose.yml"
require_path "${ROOT_DIR}/unit_tests"
require_path "${ROOT_DIR}/API_tests"
warn_optional_path "${DOCS_DIR}/test_plan.md"
warn_optional_path "${DOCS_DIR}/test_report_template.md"
warn_optional_path "${DOCS_DIR}/test_traceability_matrix.md"
require_path "${ROOT_DIR}/unit_tests/run_unit_tests.sh"
require_path "${ROOT_DIR}/API_tests/run_api_tests.sh"

log "Starting acceptance test execution (standard 3.3.4)"
log "Report directory: ${REPORT_DIR}"

log "Preparing test environment"
bash "${ROOT_DIR}/prepare_test_env.sh" | tee -a "${HUMAN_LOG}"

UNIT_JUNIT="${REPORT_DIR}/unit-tests.junit.xml"
API_JUNIT="${REPORT_DIR}/api-tests.junit.xml"
UNIT_LOG="${REPORT_DIR}/unit-tests.log"
API_LOG="${REPORT_DIR}/api-tests.log"

UNIT_OK=true
API_OK=true

log "Running unit test suite"
if bash "${ROOT_DIR}/unit_tests/run_unit_tests.sh" "${UNIT_JUNIT}" >"${UNIT_LOG}" 2>&1; then
  log "PASS unit test suite completed"
else
  UNIT_OK=false
  log "FAIL unit test suite failed"
fi

log "Running API functional test suite"
if bash "${ROOT_DIR}/API_tests/run_api_tests.sh" "${API_JUNIT}" >"${API_LOG}" 2>&1; then
  log "PASS API functional suite completed"
else
  API_OK=false
  log "FAIL API functional suite failed"
fi

require_path "${UNIT_JUNIT}"
require_path "${API_JUNIT}"

TOTAL_TESTS="$(sum_junit_attr tests "${UNIT_JUNIT}" "${API_JUNIT}")"
TOTAL_FAILURES="$(sum_junit_attr failures "${UNIT_JUNIT}" "${API_JUNIT}")"
TOTAL_ERRORS="$(sum_junit_attr errors "${UNIT_JUNIT}" "${API_JUNIT}")"
TOTAL_SKIPPED="$(sum_junit_attr skipped "${UNIT_JUNIT}" "${API_JUNIT}")"
TOTAL_PASSED="$((TOTAL_TESTS - TOTAL_FAILURES - TOTAL_ERRORS - TOTAL_SKIPPED))"

OVERALL_OK=true
if [[ "${UNIT_OK}" != "true" || "${API_OK}" != "true" || "${TOTAL_FAILURES}" -gt 0 || "${TOTAL_ERRORS}" -gt 0 ]]; then
  OVERALL_OK=false
fi

cat > "${SUMMARY_JSON}" <<EOF
{
  "standard": "3.3.4",
  "timestamp": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
  "overall_success": $(to_json_bool "${OVERALL_OK}"),
  "suites": {
    "unit_tests": {
      "script_success": $(to_json_bool "${UNIT_OK}"),
      "junit_file": "${UNIT_JUNIT}"
    },
    "api_tests": {
      "script_success": $(to_json_bool "${API_OK}"),
      "junit_file": "${API_JUNIT}"
    }
  },
  "summary": {
    "total": ${TOTAL_TESTS},
    "passed": ${TOTAL_PASSED},
    "failed": ${TOTAL_FAILURES},
    "errors": ${TOTAL_ERRORS},
    "skipped": ${TOTAL_SKIPPED}
  },
  "artifacts": {
    "human_log": "${HUMAN_LOG}",
    "unit_log": "${UNIT_LOG}",
    "api_log": "${API_LOG}"
  }
}
EOF

cat > "${SUMMARY_MD}" <<EOF
# Test Execution Summary

- Standard: 3.3.4
- Timestamp (UTC): $(date -u +"%Y-%m-%dT%H:%M:%SZ")
- Overall success: ${OVERALL_OK}

## Counts
- Total: ${TOTAL_TESTS}
- Passed: ${TOTAL_PASSED}
- Failed: ${TOTAL_FAILURES}
- Errors: ${TOTAL_ERRORS}
- Skipped: ${TOTAL_SKIPPED}

## Suite Status
- Unit suite script success: ${UNIT_OK}
- API suite script success: ${API_OK}

## Artifact Paths
- Human log: \`${HUMAN_LOG}\`
- Unit log: \`${UNIT_LOG}\`
- API log: \`${API_LOG}\`
- JSON summary: \`${SUMMARY_JSON}\`
- Unit JUnit: \`${UNIT_JUNIT}\`
- API JUnit: \`${API_JUNIT}\`
EOF

# On Windows/Git Bash, prior runs may leave `latest` as a directory; rm -f does not remove directories.
rm -rf "${LATEST_LINK}" || true
ln -s "${REPORT_DIR}" "${LATEST_LINK}" 2>/dev/null || true
if [[ ! -L "${LATEST_LINK}" ]]; then
  rm -rf "${LATEST_LINK}" || true
  mkdir -p "${LATEST_LINK}"
  cp "${SUMMARY_JSON}" "${LATEST_LINK}/summary.json"
  cp "${SUMMARY_MD}" "${LATEST_LINK}/summary.md"
  cp "${HUMAN_LOG}" "${LATEST_LINK}/run.log"
  cp "${UNIT_LOG}" "${LATEST_LINK}/unit-tests.log"
  cp "${API_LOG}" "${LATEST_LINK}/api-tests.log"
  cp "${UNIT_JUNIT}" "${LATEST_LINK}/unit-tests.junit.xml"
  cp "${API_JUNIT}" "${LATEST_LINK}/api-tests.junit.xml"
fi

log "Final summary: total=${TOTAL_TESTS}, passed=${TOTAL_PASSED}, failed=${TOTAL_FAILURES}, errors=${TOTAL_ERRORS}, skipped=${TOTAL_SKIPPED}"
log "Summary JSON: ${SUMMARY_JSON}"
log "Summary Markdown: ${SUMMARY_MD}"

if [[ "${OVERALL_OK}" != "true" ]]; then
  log "Acceptance result: FAIL"
  exit 1
fi

log "Acceptance result: PASS"
