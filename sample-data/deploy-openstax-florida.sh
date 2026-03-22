#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
IMPORTER_RELATIVE="sample-data/import-openstax-florida.php"
IMPORTER_ABSOLUTE="${REPO_ROOT}/${IMPORTER_RELATIVE}"
IMPORTER_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${IMPORTER_RELATIVE}"
VERIFY_RELATIVE="sample-data/verify-openstax-florida.php"
VERIFY_ABSOLUTE="${REPO_ROOT}/${VERIFY_RELATIVE}"
VERIFY_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${VERIFY_RELATIVE}"
SITE_BUILD_RELATIVE="sample-data/build-ahsa-site.php"
SITE_BUILD_ABSOLUTE="${REPO_ROOT}/${SITE_BUILD_RELATIVE}"
SITE_BUILD_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${SITE_BUILD_RELATIVE}"
SITE_VERIFY_RELATIVE="sample-data/verify-ahsa-site.php"
SITE_VERIFY_ABSOLUTE="${REPO_ROOT}/${SITE_VERIFY_RELATIVE}"
SITE_VERIFY_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${SITE_VERIFY_RELATIVE}"
PROGRESSION_RELATIVE="sample-data/setup-progression-rules.php"
PROGRESSION_ABSOLUTE="${REPO_ROOT}/${PROGRESSION_RELATIVE}"
PROGRESSION_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${PROGRESSION_RELATIVE}"
PARENT_MON_RELATIVE="sample-data/setup-parent-monitoring.php"
PARENT_MON_ABSOLUTE="${REPO_ROOT}/${PARENT_MON_RELATIVE}"
PARENT_MON_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${PARENT_MON_RELATIVE}"
RUBRICS_RELATIVE="sample-data/setup-rubrics.php"
RUBRICS_ABSOLUTE="${REPO_ROOT}/${RUBRICS_RELATIVE}"
RUBRICS_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${RUBRICS_RELATIVE}"
STANDARDS_RELATIVE="sample-data/setup-standards.php"
STANDARDS_ABSOLUTE="${REPO_ROOT}/${STANDARDS_RELATIVE}"
STANDARDS_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${STANDARDS_RELATIVE}"
EXAM_SEC_RELATIVE="sample-data/setup-exam-security.php"
EXAM_SEC_ABSOLUTE="${REPO_ROOT}/${EXAM_SEC_RELATIVE}"
EXAM_SEC_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${EXAM_SEC_RELATIVE}"
BULK_SEB_RELATIVE="sample-data/setup-bulk-seb.php"
BULK_SEB_ABSOLUTE="${REPO_ROOT}/${BULK_SEB_RELATIVE}"
BULK_SEB_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${BULK_SEB_RELATIVE}"
INT_TEST_RELATIVE="sample-data/integration-test.php"
INT_TEST_ABSOLUTE="${REPO_ROOT}/${INT_TEST_RELATIVE}"
INT_TEST_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${INT_TEST_RELATIVE}"
ACAD_REPORT_RELATIVE="sample-data/export-academic-progress-report.php"
ACAD_REPORT_ABSOLUTE="${REPO_ROOT}/${ACAD_REPORT_RELATIVE}"
ACAD_REPORT_CONTAINER="/var/www/html/wp-content/plugins/lifterlms/${ACAD_REPORT_RELATIVE}"

cd "${REPO_ROOT}"

echo "Deploying AHSA site structure and OpenStax Florida 9-12 semester catalog to LifterLMS..."

run_local_wp() {
  if ! command -v wp >/dev/null 2>&1; then
    return 1
  fi

  echo "Using local WP-CLI"
  wp eval-file "${SITE_BUILD_ABSOLUTE}" && \
    wp eval-file "${IMPORTER_ABSOLUTE}" && \
    wp eval-file "${PROGRESSION_ABSOLUTE}" && \
    wp eval-file "${PARENT_MON_ABSOLUTE}" && \
    wp eval-file "${RUBRICS_ABSOLUTE}" && \
    wp eval-file "${STANDARDS_ABSOLUTE}" && \
    wp eval-file "${EXAM_SEC_ABSOLUTE}" && \
    wp eval-file "${BULK_SEB_ABSOLUTE}" && \
    wp eval-file "${VERIFY_ABSOLUTE}" && \
    wp eval-file "${SITE_VERIFY_ABSOLUTE}" && \
    wp eval-file "${INT_TEST_ABSOLUTE}" && \
    wp eval-file "${ACAD_REPORT_ABSOLUTE}"
}

run_docker_exec() {
  if ! command -v docker >/dev/null 2>&1; then
    return 1
  fi

  if ! docker compose ps --services 2>/dev/null | grep -qx "wordpress"; then
    return 1
  fi

  echo "Using Docker Compose service: wordpress (exec)"
  docker compose exec -T wordpress wp eval-file "${SITE_BUILD_CONTAINER}" && \
    docker compose exec -T wordpress wp eval-file "${IMPORTER_CONTAINER}" && \
    docker compose exec -T wordpress wp eval-file "${PROGRESSION_CONTAINER}" && \
    docker compose exec -T wordpress wp eval-file "${PARENT_MON_CONTAINER}" && \
    docker compose exec -T wordpress wp eval-file "${RUBRICS_CONTAINER}" && \
    docker compose exec -T wordpress wp eval-file "${STANDARDS_CONTAINER}" && \
    docker compose exec -T wordpress wp eval-file "${EXAM_SEC_CONTAINER}" && \
    docker compose exec -T wordpress wp eval-file "${BULK_SEB_CONTAINER}" && \
    docker compose exec -T wordpress wp eval-file "${VERIFY_CONTAINER}" && \
    docker compose exec -T wordpress wp eval-file "${SITE_VERIFY_CONTAINER}" && \
    docker compose exec -T wordpress wp eval-file "${INT_TEST_CONTAINER}" && \
    docker compose exec -T wordpress wp eval-file "${ACAD_REPORT_CONTAINER}"
}

run_docker_run() {
  if ! command -v docker >/dev/null 2>&1; then
    return 1
  fi

  if ! docker compose config --services 2>/dev/null | grep -qx "wordpress"; then
    return 1
  fi

  echo "Using Docker Compose service: wordpress (run)"
  docker compose run --rm wordpress wp eval-file "${SITE_BUILD_CONTAINER}" && \
    docker compose run --rm wordpress wp eval-file "${IMPORTER_CONTAINER}" && \
    docker compose run --rm wordpress wp eval-file "${PROGRESSION_CONTAINER}" && \
    docker compose run --rm wordpress wp eval-file "${PARENT_MON_CONTAINER}" && \
    docker compose run --rm wordpress wp eval-file "${RUBRICS_CONTAINER}" && \
    docker compose run --rm wordpress wp eval-file "${STANDARDS_CONTAINER}" && \
    docker compose run --rm wordpress wp eval-file "${EXAM_SEC_CONTAINER}" && \
    docker compose run --rm wordpress wp eval-file "${BULK_SEB_CONTAINER}" && \
    docker compose run --rm wordpress wp eval-file "${VERIFY_CONTAINER}" && \
    docker compose run --rm wordpress wp eval-file "${SITE_VERIFY_CONTAINER}" && \
    docker compose run --rm wordpress wp eval-file "${INT_TEST_CONTAINER}" && \
    docker compose run --rm wordpress wp eval-file "${ACAD_REPORT_CONTAINER}"
}

if run_local_wp; then
  echo "Deployment complete via local WP-CLI"
  exit 0
fi

if run_docker_exec; then
  echo "Deployment complete via docker compose exec"
  exit 0
fi

if run_docker_run; then
  echo "Deployment complete via docker compose run"
  exit 0
fi

echo "Unable to find a runnable WP-CLI context."
echo "Run one of the following manually:"
echo "  wp eval-file ${SITE_BUILD_RELATIVE}"
echo "  wp eval-file ${IMPORTER_RELATIVE}"
echo "  wp eval-file ${VERIFY_RELATIVE}"
echo "  wp eval-file ${SITE_VERIFY_RELATIVE}"
echo "  docker compose exec -T wordpress wp eval-file ${SITE_BUILD_CONTAINER}"
echo "  docker compose exec -T wordpress wp eval-file ${IMPORTER_CONTAINER}"
echo "  docker compose exec -T wordpress wp eval-file ${VERIFY_CONTAINER}"
echo "  docker compose exec -T wordpress wp eval-file ${SITE_VERIFY_CONTAINER}"
echo "  docker compose run --rm wordpress wp eval-file ${SITE_BUILD_CONTAINER}"
echo "  docker compose run --rm wordpress wp eval-file ${IMPORTER_CONTAINER}"
echo "  docker compose run --rm wordpress wp eval-file ${VERIFY_CONTAINER}"
echo "  docker compose run --rm wordpress wp eval-file ${SITE_VERIFY_CONTAINER}"
exit 1
