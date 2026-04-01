#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.yml"

if [[ ! -f "${COMPOSE_FILE}" ]]; then
  echo "[prepare] missing compose file at ${COMPOSE_FILE}"
  exit 1
fi

echo "[prepare] ensuring docker compose services are up"
docker compose -f "${COMPOSE_FILE}" -p vetops up -d --build

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
