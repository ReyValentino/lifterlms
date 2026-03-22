#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="${SCRIPT_DIR}/docker-compose.school.yml"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PLUGIN_DIR="${REPO_ROOT}"

SITE_URL="${SITE_URL:-http://localhost:${AHSA_PORT:-8080}}"
SITE_TITLE="${SITE_TITLE:-American High School Academy}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-adminpass123!}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.org}"

log() {
  echo "[school-bootstrap] $*"
}

ensure_plugin_dependencies() {
  if [ -f "${PLUGIN_DIR}/vendor/autoload.php" ]; then
    log "Plugin composer dependencies already installed"
    return 0
  fi

  log "Installing plugin composer dependencies"

  if command -v composer >/dev/null 2>&1; then
    if (cd "${PLUGIN_DIR}" && composer install --no-interaction --prefer-dist); then
      return 0
    fi
    log "Local composer failed, falling back to Docker composer"
  fi

  docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "${PLUGIN_DIR}:/app" \
    -w /app \
    composer:2 install --no-interaction --prefer-dist
}

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required but not found." >&2
  exit 1
fi

ensure_plugin_dependencies

log "Starting WordPress and database containers"
docker compose -f "${COMPOSE_FILE}" up -d db wordpress

log "Waiting for WordPress to respond"
for i in $(seq 1 90); do
  if curl -fsS "${SITE_URL}" >/dev/null 2>&1; then
    break
  fi
  sleep 2
  if [ "$i" -eq 90 ]; then
    echo "WordPress did not become ready at ${SITE_URL}" >&2
    exit 1
  fi
done

log "Installing WordPress core if needed"
if ! docker compose -f "${COMPOSE_FILE}" run --rm wpcli core is-installed >/dev/null 2>&1; then
  docker compose -f "${COMPOSE_FILE}" run --rm wpcli core install \
    --url="${SITE_URL}" \
    --title="${SITE_TITLE}" \
    --admin_user="${ADMIN_USER}" \
    --admin_password="${ADMIN_PASSWORD}" \
    --admin_email="${ADMIN_EMAIL}" \
    --skip-email
else
  log "WordPress already installed"
fi

log "Activating LifterLMS"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli plugin activate lifterlms

log "Building AHSA site structure"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/build-ahsa-site.php

log "Importing school catalog"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/import-openstax-florida.php

log "Enforcing progression rules"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/setup-progression-rules.php

log "Setting up parent monitoring"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/setup-parent-monitoring.php

log "Setting up rubric system"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/setup-rubrics.php

log "Setting up Florida standards alignment"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/setup-standards.php

log "Setting up exam security"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/setup-exam-security.php

log "Configuring bulk SEB on all quizzes"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/setup-bulk-seb.php

log "Installing AHSA mu-plugin"
docker compose -f "${COMPOSE_FILE}" exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
docker compose -f "${COMPOSE_FILE}" exec -T wordpress cp /var/www/html/wp-content/plugins/lifterlms/sample-data/mu-plugins/ahsa-core.php /var/www/html/wp-content/mu-plugins/ahsa-core.php

log "Verifying school catalog"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/verify-openstax-florida.php

log "Verifying AHSA site structure"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/verify-ahsa-site.php

log "Exporting compliance report"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/export-openstax-compliance-report.php

log "Running integration tests"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/integration-test.php

log "Exporting academic progress report"
docker compose -f "${COMPOSE_FILE}" run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/export-academic-progress-report.php

log "Runtime smoke test — checking key pages"
SMOKE_FAIL=0
for slug in "" courses student-dashboard student-transcript parent-dashboard; do
  HTTP_CODE=$(curl -o /dev/null -s -w '%{http_code}' "${SITE_URL}/${slug}")
  if [ "${HTTP_CODE}" -ge 200 ] && [ "${HTTP_CODE}" -lt 400 ]; then
    log "  ✓ /${slug} → HTTP ${HTTP_CODE}"
  else
    log "  ✗ /${slug} → HTTP ${HTTP_CODE}"
    SMOKE_FAIL=1
  fi
done

if [ "${SMOKE_FAIL}" -eq 1 ]; then
  log "WARNING: One or more pages returned unexpected HTTP status"
fi

log "Done"
log "Site: ${SITE_URL}"
log "Admin user: ${ADMIN_USER}"
log "Admin password: ${ADMIN_PASSWORD}"
log "Compliance reports path (container): /var/www/html/wp-content/uploads/openstax-reports"
