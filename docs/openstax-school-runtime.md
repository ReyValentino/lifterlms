# OpenStax School Runtime Requirements and Setup

This guide covers what is required to build and verify the full Florida 6-12 core plus EOC/FSA prep semester catalog in a functioning WordPress environment.

This school build is configured as an AHSA deployment affiliated with americanhighschoolacademy.com.

## Minimum Requirements

- Docker Engine and Docker Compose plugin
- Composer dependencies available for this plugin checkout
- Open ports:
  - 8080 for WordPress HTTP
- Available local disk space:
  - at least 4 GB for images, database, and WordPress volumes

## Included Runtime Assets

- Runtime compose stack: sample-data/runtime/docker-compose.school.yml
- Bootstrap and build script: sample-data/runtime/bootstrap-school-env.sh
- Site structure build script: sample-data/build-ahsa-site.php
- Catalog import script: sample-data/import-openstax-florida.php
- Catalog verification script: sample-data/verify-openstax-florida.php
- Site verification script: sample-data/verify-ahsa-site.php
- Compliance report export script: sample-data/export-openstax-compliance-report.php

## What the Bootstrap Does

1. Starts MariaDB and WordPress containers
2. Waits until WordPress is reachable
3. Installs plugin Composer dependencies (local composer or composer container)
4. Installs WordPress (if not installed)
5. Activates the LifterLMS plugin
6. Builds an academy site structure (homepage, admissions, academics, enrollment, support, contact, and navigation)
7. Imports all configured semester courses (high school core, middle school core, and EOC/FSA prep)
8. Runs strict catalog verification and fails if anything is incomplete
9. Runs strict site verification and fails if structure is incomplete
10. Exports an auditable CSV compliance report

## Start and Build

Run from the repository root:

  bash sample-data/runtime/bootstrap-school-env.sh

Optional environment overrides:

  SITE_URL=http://localhost:8080
  SITE_TITLE="School LMS"
  ADMIN_USER=admin
  ADMIN_PASSWORD=adminpass123!
  ADMIN_EMAIL=admin@example.org

Example:

  SITE_TITLE="My District LMS" ADMIN_PASSWORD="ChangeMeNow123!" bash sample-data/runtime/bootstrap-school-env.sh

## Verify Manually (Optional)

  docker compose -f sample-data/runtime/docker-compose.school.yml run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/verify-openstax-florida.php
  docker compose -f sample-data/runtime/docker-compose.school.yml run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/verify-ahsa-site.php

## Export Compliance Report Manually (Optional)

  docker compose -f sample-data/runtime/docker-compose.school.yml run --rm wpcli eval-file /var/www/html/wp-content/plugins/lifterlms/sample-data/export-openstax-compliance-report.php

## Access

- Site: http://localhost:8080
- Admin: use ADMIN_USER and ADMIN_PASSWORD values used during bootstrap

## Notes for School Deployment

- The verification script is strict and will exit non-zero if any required course component is missing.
- The current expected catalog size is 64 published courses.
- Site verification also checks core LifterLMS operational pages (catalog, checkout, and my account) and published access plans.
- The bootstrap script now generates a CSV report in:
  - wp-content/uploads/openstax-reports/openstax-compliance-<timestamp>.csv
- Replace default credentials before production usage.
- Apply your district's approved domain, SSL, backup, and security controls before go-live.
