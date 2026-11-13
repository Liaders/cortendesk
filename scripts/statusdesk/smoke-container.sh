#!/usr/bin/env bash
set -euo pipefail
image=${1:?Provide the built image}
name="statusdesk-smoke-${GITHUB_RUN_ID:-local}-${RANDOM}"
cleanup() { docker rm -f -v "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker run --rm -d --name "$name" --network none --memory 512m \
  -e APP_URL=http://localhost:8080 -e APP_NAME='StatusDesk Console' \
  -e APP_DEBUG=false -e MAIL_MAILER=log "$image" >/dev/null
ready=false
for attempt in {1..60}; do
  if docker exec "$name" wget -q -O /dev/null http://127.0.0.1:8080/health/ready; then
    ready=true
    break
  fi
  if [[ $(docker inspect -f '{{.State.Running}}' "$name") != true ]]; then break; fi
  sleep 2
done
if [[ $ready != true ]]; then
  docker logs --tail 80 "$name"
  exit 1
fi
docker exec "$name" php /app/scripts/statusdesk/container-smoke.php
docker exec "$name" sh -c 'pidof hbbs && pidof hbbr' >/dev/null
echo 'Isolated application, report, exports, ID server and relay checks passed.'
