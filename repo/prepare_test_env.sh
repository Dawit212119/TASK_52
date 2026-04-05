#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.yml"

if [[ ! -f "${COMPOSE_FILE}" ]]; then
  echo "[prepare] missing compose file at ${COMPOSE_FILE}"
  exit 1
fi

echo "[prepare] ensuring docker compose services are up"
if ! docker compose -f "${COMPOSE_FILE}" -p vetops up -d --build; then
  echo "[prepare] compose up failed — recent db engine change or wrong passwords often leave a stale MySQL data volume."
  echo "[prepare] fix stale MySQL data (WIPES DB): from repo/, run: npm run reset:mysql-volumes"
  echo "[prepare] or: docker compose -f \"${COMPOSE_FILE}\" -p vetops down && docker volume rm vetops_vetops_db_data vetops_vetops_db_test_data"
  echo "[prepare] db logs:"
  docker compose -f "${COMPOSE_FILE}" -p vetops logs db --tail 80 2>/dev/null || true
  exit 1
fi

echo "[prepare] waiting for db and backend health"
for service in db backend; do
  attempts=0
  max_attempts=30
  until docker compose -f "${COMPOSE_FILE}" -p vetops ps "${service}" | grep -q "(healthy)"; do
    attempts=$((attempts + 1))
    if [[ "${attempts}" -ge "${max_attempts}" ]]; then
      echo "[prepare] service '${service}' is not healthy after ${max_attempts} attempts"
      docker compose -f "${COMPOSE_FILE}" -p vetops ps
      exit 1
    fi
    sleep 2
  done
done

echo "[prepare] verifying API health endpoint"
if ! curl -fsS "http://127.0.0.1:8080/api/v1/health" >/dev/null; then
  echo "[prepare] health endpoint check failed"
  exit 1
fi

echo "[prepare] environment is ready"
