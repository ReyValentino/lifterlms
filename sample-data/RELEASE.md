# AHSA v2.4.0 — Release Notes & Deployment Guide

**Release date:** 2026-03-22
**Platform:** LifterLMS on WordPress 6.6+ / PHP 8.2 / MariaDB 10.11

---

## 1. Release Summary

AHSA v2.4.0 is the first fully validated release of the American High School
Academy platform. It delivers a complete K–12 virtual-school environment with
84 semester courses, strict progression enforcement, Safe Exam Browser
integration, rubric-based grading, parent monitoring, transcript generation,
and Florida standards alignment.

### Features Delivered

| Feature | Status |
|---|---|
| 84-course catalog (HS, MS, FL Prep, Aviation) | Complete |
| Sequential lesson progression with 60% pass threshold | Complete |
| 252 quizzes with auto-grading and SEB lockdown | Complete |
| Parent dashboard with teacher notes | Complete |
| Rubric system (2 templates, per-course cloning) | Complete |
| Florida standards alignment (6 frameworks, 40 standards, 678 alignments) | Complete |
| 4-year transcript with 4.0 GPA and transfer courses | Complete |
| Student dashboard with progress stats | Complete |
| Email notifications (4 triggers) | Complete |
| Admin dashboard widget | Complete |
| AHSA branding CSS (14+ component classes) | Complete |
| Compliance CSV export | Complete |
| Academic progress CSV export | Complete |
| Docker Compose local runtime | Complete |
| Bootstrap automation (single-command setup) | Complete |

### Validation Results

| Check | Result |
|---|---|
| Integration tests | **43/43 passed** |
| Compliance export | **84/84 courses passing** |
| Catalog verification | All 84 courses present and functional |
| Site structure verification | All 19 pages published |
| SEB quiz coverage | 252/252 quizzes enabled |
| Standards alignment | 678 alignments across 84 courses |
| Rubric assignment | 84/84 courses assigned |
| Progression prerequisites | 704 lesson-to-lesson |
| PHP lint (all 26 PHP files) | 0 errors |
| Shell script syntax (2 scripts) | Clean |

---

## 2. Module Inventory

| # | Module | File | Purpose |
|---|---|---|---|
| 1 | Progression Enforcement | `class-ahsa-progression-enforcement.php` | Sequential lesson flow, passing-grade gates |
| 2 | Parent Monitor | `class-ahsa-parent-monitor.php` | Parent-student linking, dashboard, teacher notes |
| 3 | Rubrics | `class-ahsa-rubrics.php` | Rubric templates, per-course assignment, admin UI |
| 4 | Standards | `class-ahsa-standards.php` | Florida standards library, course alignment |
| 5 | Exam Security | `class-ahsa-exam-security.php` | SEB validation, session tracking, risk scoring |
| 6 | Branding | `class-ahsa-branding.php` | CSS, logo, color palette |
| 7 | Transcript | `class-ahsa-transcript.php` | 4-year transcript, GPA, transfer courses |
| 8 | Student Dashboard | `class-ahsa-student-dashboard.php` | Student-facing progress widget |
| 9 | Email Notifications | `class-ahsa-email-notifications.php` | Grade drop, teacher note, weekly digest, flagged exam |
| 10 | Admin Dashboard | `class-ahsa-admin-dashboard.php` | WP admin overview widget |

---

## 3. Deployment Checklist

### Prerequisites

- [ ] Docker Engine 20.10+ and Docker Compose v2
- [ ] Git clone of the `lifterlms` repository
- [ ] No conflicting services on port 8080 (or set `AHSA_PORT`)

### Deployment Order

```text
1.  cd sample-data/runtime
2.  docker compose -f docker-compose.school.yml up -d
3.  (wait for MariaDB healthcheck to pass)
4.  wp core install  (or use bootstrap-school-env.sh)
5.  wp plugin activate lifterlms
6.  wp eval-file build-ahsa-site.php
7.  wp eval-file import-openstax-florida.php
8.  wp eval-file setup-progression-rules.php
9.  wp eval-file setup-parent-monitoring.php
10. wp eval-file setup-rubrics.php
11. wp eval-file setup-standards.php
12. wp eval-file setup-exam-security.php
13. wp eval-file setup-bulk-seb.php
14. Install mu-plugin: copy ahsa-core.php → wp-content/mu-plugins/
15. wp eval-file verify-openstax-florida.php
16. wp eval-file verify-ahsa-site.php
17. wp eval-file integration-test.php
18. wp eval-file export-openstax-compliance-report.php
19. wp eval-file export-academic-progress-report.php
```

Or run the automated bootstrap:

```bash
cd sample-data/runtime
bash bootstrap-school-env.sh
```

### Post-Deploy Smoke Tests

- [ ] Integration tests pass: `wp eval-file integration-test.php` → 43/43
- [ ] Compliance export: `wp eval-file export-openstax-compliance-report.php` → 84/84
- [ ] Course verification: `wp eval-file verify-openstax-florida.php` → Success
- [ ] Site verification: `wp eval-file verify-ahsa-site.php` → Success
- [ ] Home page loads: `curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/` → 200 or 301
- [ ] Courses page loads
- [ ] Student dashboard page loads

### Backup/Export Points

| What | Command |
|---|---|
| Full database dump | `docker compose exec db mariadb-dump -u wordpress -pwordpress wordpress > backup.sql` |
| Compliance report | `wp eval-file export-openstax-compliance-report.php` |
| Academic progress | `wp eval-file export-academic-progress-report.php` |
| WordPress export | `wp export --dir=/tmp/` |

---

## 4. Environment Variables

All variables have safe defaults for local development. Override for production.

| Variable | Default | Purpose |
|---|---|---|
| `AHSA_PORT` | `8080` | Host port for WordPress |
| `AHSA_DB_USER` | `wordpress` | MariaDB user |
| `AHSA_DB_PASSWORD` | `wordpress` | MariaDB password |
| `AHSA_DB_NAME` | `wordpress` | MariaDB database |
| `AHSA_DB_ROOT_PASSWORD` | `root` | MariaDB root password |
| `SITE_URL` | `http://localhost:${AHSA_PORT}` | WordPress site URL |
| `SITE_TITLE` | `American High School Academy` | WordPress site title |
| `ADMIN_USER` | `admin` | WordPress admin username |
| `ADMIN_PASSWORD` | `adminpass123!` | WordPress admin password |
| `ADMIN_EMAIL` | `admin@example.org` | WordPress admin email |

---

## 5. Production Hardening Notes

### Secrets & Credentials

- **All default passwords must be changed** for any non-local deployment.
- Set `AHSA_DB_PASSWORD`, `AHSA_DB_ROOT_PASSWORD`, `ADMIN_PASSWORD` via
  environment variables or a `.env` file (not committed to version control).
- Consider using Docker secrets or a vault for credential management.

### WordPress Configuration

- Set `WP_DEBUG` to `false` in production.
- Set `DISALLOW_FILE_EDIT` to `true` to prevent admin-panel file editing.
- Set `WP_AUTO_UPDATE_CORE` to `minor` or `false` depending on policy.
- Ensure `wp-config.php` sets unique salt keys (`wp config shuffle-salts`).

### File Permissions

- The Docker WordPress image runs Apache as `www-data` (UID 33).
- `wp-content/uploads/` must be writable for CSV report export.
- `wp-content/mu-plugins/` must contain `ahsa-core.php` (read-only is fine after install).
- No other directories require write access beyond standard WordPress needs.

### Database

- MariaDB runs inside the compose network; port 3306 is not exposed to the host by default.
- For production, use a managed database service or add TLS to the MariaDB connection.
- Regular backups recommended: `mariadb-dump` or WAL-based replication.

### Email / Cron

- `wp_mail()` requires a working SMTP transport in production. Install an SMTP
  plugin (e.g., WP Mail SMTP) or configure the server's mail relay.
- WordPress cron relies on page visits by default. For production, disable
  `DISABLE_WP_CRON` and use a system cron:
  ```
  */5 * * * * curl -s http://localhost:8080/wp-cron.php > /dev/null 2>&1
  ```

### Monitoring Recommendations

| What | How |
|---|---|
| HTTP uptime | Monitor `GET /` for 200/301 response |
| Database connectivity | MariaDB healthcheck (built into compose) |
| Cron execution | Check `wp cron event list` for overdue events |
| SEB session anomalies | Query `ahsa_exam_sessions` for `risk_level = 'high'` |
| Failed quiz attempts | Monitor LifterLMS quiz attempt data |
| Disk usage | Monitor `wp-content/uploads/openstax-reports/` growth |
| Error log | Watch `/var/log/apache2/error.log` inside the container |

### HTTPS

- The Docker runtime serves HTTP on port 80 (mapped to `AHSA_PORT`).
- For production, place a reverse proxy (nginx, Caddy, Traefik) in front with
  TLS termination, and set `SITE_URL` to the `https://` address.
- Update WordPress `siteurl` and `home` options to match.

---

## 6. WP-CLI Script Reference

| Script | Purpose | Runtime |
|---|---|---|
| `build-ahsa-site.php` | Create 19 pages, nav menu, site settings, school info | < 5s |
| `import-openstax-florida.php` | Import 84 courses with sections, lessons, quizzes | < 60s |
| `setup-progression-rules.php` | Set 704 prerequisites, 192 quiz passing gates | < 30s |
| `setup-parent-monitoring.php` | Create parent role, DB tables, dashboard page | < 5s |
| `setup-rubrics.php` | Create 2 templates, assign to 84 courses | < 10s |
| `setup-standards.php` | Seed 40 standards, create 678 alignments | < 15s |
| `setup-exam-security.php` | Create exam tables, set SEB defaults | < 5s |
| `setup-bulk-seb.php` | Enable SEB on all 252 quizzes | < 10s |
| `verify-openstax-florida.php` | Verify all 84 courses are complete | < 30s |
| `verify-ahsa-site.php` | Verify page structure and course count | < 5s |
| `integration-test.php` | Run 43 assertions across all modules | < 15s |
| `export-openstax-compliance-report.php` | Generate compliance CSV | < 30s |
| `export-academic-progress-report.php` | Generate progress + readiness CSVs | < 15s |

---

## 7. Database Tables

| Table | Module | Purpose |
|---|---|---|
| `ahsa_parent_student` | Parent Monitor | Parent↔student relationships |
| `ahsa_teacher_notes` | Parent Monitor | Teacher notes on students |
| `ahsa_rubrics` | Rubrics | Rubric definitions and course assignments |
| `ahsa_rubric_categories` | Rubrics | Rubric grading categories and weights |
| `ahsa_standards` | Standards | Florida standards library |
| `ahsa_standards_alignment` | Standards | Course↔standard alignment mappings |
| `ahsa_exam_sessions` | Exam Security | SEB exam sessions with risk scoring |
| `ahsa_exam_logs` | Exam Security | Per-session event log |
| `ahsa_transfer_courses` | Transcript | Transfer course records for transcripts |

---

## 8. Known Limitations

| Item | Detail |
|---|---|
| Admin-side inline styles | ~62 inline `style=` in admin PHP (form layout widths); frontend uses CSS classes |
| In-container HTTP smoke tests | WordPress redirects port 80→8080 inside Docker; page existence verified via DB |
| `@since` tag versions | Reflect when code was introduced (2.0.0–2.3.0), not current platform version |
| Course content | Generated placeholder content; real content must be authored per-course |
| Email transport | `wp_mail()` requires SMTP configuration for production delivery |

---

## 9. File Manifest

```
sample-data/
├── AHSA-README.md                      ← Developer documentation (v2.4.0)
├── RELEASE.md                          ← This file
├── openstax-course-catalog.php         ← 84-course catalog definitions
├── build-ahsa-site.php                 ← Site structure builder
├── import-openstax-florida.php         ← Course importer
├── setup-progression-rules.php         ← Progression enforcement
├── setup-parent-monitoring.php         ← Parent role and tables
├── setup-rubrics.php                   ← Rubric templates and assignment
├── setup-standards.php                 ← Florida standards alignment
├── setup-exam-security.php             ← SEB tables and defaults
├── setup-bulk-seb.php                  ← Bulk SEB enable
├── verify-openstax-florida.php         ← Catalog verifier
├── verify-ahsa-site.php               ← Site verifier
├── integration-test.php                ← 43-assertion test suite
├── export-openstax-compliance-report.php ← Compliance CSV
├── export-academic-progress-report.php ← Progress + readiness CSVs
├── deploy-openstax-florida.sh          ← 3-mode deploy script
├── mu-plugins/
│   └── ahsa-core.php                  ← MU-plugin loader (v2.4.0)
├── modules/
│   ├── class-ahsa-progression-enforcement.php
│   ├── class-ahsa-parent-monitor.php
│   ├── class-ahsa-rubrics.php
│   ├── class-ahsa-standards.php
│   ├── class-ahsa-exam-security.php
│   ├── class-ahsa-branding.php
│   ├── class-ahsa-transcript.php
│   ├── class-ahsa-student-dashboard.php
│   ├── class-ahsa-email-notifications.php
│   └── class-ahsa-admin-dashboard.php
├── assets/
│   └── ahsa-branding.css              ← Unified stylesheet
└── runtime/
    ├── docker-compose.school.yml      ← Docker Compose runtime
    └── bootstrap-school-env.sh        ← Automated bootstrap
```
