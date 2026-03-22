# AHSA — American High School Academy

## LifterLMS Platform Architecture & Developer Guide

**Version 2.4.0** — Full K–12 virtual school platform built on LifterLMS inside the
WordPress ecosystem, with Florida state standards alignment, Safe Exam Browser
integration, rubric-based grading, parent monitoring, transcript generation,
student dashboard, email notifications, and strict academic progression
enforcement.

---

## Table of Contents

1. [Current Verified State](#current-verified-state)
2. [Overview](#overview)
3. [Course Catalog](#course-catalog)
4. [Module Architecture](#module-architecture)
5. [Progression Enforcement](#progression-enforcement)
6. [Parent Monitoring](#parent-monitoring)
7. [Rubric System](#rubric-system)
8. [Florida Standards Alignment](#florida-standards-alignment)
9. [Exam Security (SEB)](#exam-security-seb)
10. [Transcript Generator](#transcript-generator)
11. [Student Dashboard](#student-dashboard)
12. [Email Notifications](#email-notifications)
13. [Admin Dashboard Widget](#admin-dashboard-widget)
14. [Branding](#branding)
15. [Site Structure](#site-structure)
16. [Deployment](#deployment)
17. [Docker Compose Runtime](#docker-compose-runtime)
18. [WP-CLI Scripts](#wp-cli-scripts)
19. [Database Tables](#database-tables)
20. [REST API](#rest-api)
21. [Shortcodes](#shortcodes)
22. [Configuration Reference](#configuration-reference)
23. [Troubleshooting](#troubleshooting)
24. [Release & Handoff](#release--handoff)

---

## Current Verified State

Last validated: **2026-03-22** against Docker Compose runtime.

| Metric | Value |
|---|---|
| Integration tests | **43/43 passed** |
| Compliance export | **84/84 courses passing** |
| Published courses | 84 (28 HS + 24 MS + 12 FL Prep + 20 Aviation) |
| Published sections | 252 |
| Published lessons | 1,008 |
| Published quizzes (SEB-enabled) | 252 |
| Site pages | 19 |
| AHSA modules loaded | 10/10 |
| Custom DB tables | 9 |
| Shortcodes registered | 5 |
| REST endpoints | 1 (`ahsa/v1/exam-event`) |
| Rubric templates | 2 (core academic + aviation STEM) |
| Standards seeded | 40 across 6 frameworks |
| Course-to-standard alignments | 678 |
| Progression prerequisites | 704 lesson-to-lesson |
| Quiz passing threshold | 60% (global) |

### Verified User Flows

- **Enrollment** — Student enrolled in course, enrollment confirmed
- **Progression** — Lesson prerequisites enforced; completing L1 unlocks L2
- **Transcript** — Renders per-student 4-year transcript with GPA calculation
- **Student Dashboard** — Course progress and stats rendered
- **Parent Dashboard** — Linked students and teacher notes visible
- **SEB Lifecycle** — Session created, events logged, risk scored, session closed
- **Exports** — Compliance CSV (84/84), Academic Progress CSV, Compliance Readiness CSV

### Key Fixes in v2.4.0

- Lessons and sections are now bulk-published after import (were stuck as `draft`)
- Standards matching uses normalized text (`B.E.S.T.` → `best`, punctuation stripped)
- Compliance report queries rubrics from `ahsa_rubrics` table (not post meta)
- Compliance report queries SEB from quiz-level meta (not course-level)
- `setup-progression-rules.php` queries `post_status=any` (not just `publish`)
- Academic progress report builds transcript data before calculating GPA
- Docker Compose credentials are env-driven (`AHSA_DB_USER`, `AHSA_DB_PASSWORD`, etc.)
- `ahsa_school_info` option seeded by `build-ahsa-site.php` for transcript headers

---

## Overview

AHSA is a fully accredited online high-school and middle-school platform. The
technical stack is:

| Layer           | Technology                    |
|-----------------|-------------------------------|
| LMS Engine      | LifterLMS (WordPress plugin)  |
| CMS             | WordPress 6.6+                |
| PHP             | 8.2                           |
| Database        | MariaDB 10.11                 |
| Container       | Docker Compose                |
| CLI             | WP-CLI                        |

All custom AHSA code lives under `sample-data/` in the LifterLMS plugin
repository and is loaded via a MU-plugin (`ahsa-core.php`).

---

## Course Catalog

**File:** `sample-data/openstax-course-catalog.php`

The shared catalog defines every course as a PHP array and is consumed by both
the importer and the compliance exporter.

### Catalog Groups

| Group                          | Count | Grades  |
|--------------------------------|------:|---------|
| High School Core               |    28 | 9–12    |
| Middle School Core             |    24 | 6–8     |
| Florida EOC / FSA Prep         |    12 | 6–12    |
| Aviation / Aerospace / Drone   |    20 | 9–12    |
| **Total**                      | **84**|         |

### Entry Schema

```php
array(
    'subject'        => 'Algebra 1',
    'grade'          => 9,
    'semester'       => 1,
    'credit'         => 0.5,
    'track'          => 'Mathematics',
    'textbook'       => 'openstax-algebra-2e',
    'state_standard' => 'BEST-Math',
    'ncaa_core_area' => 'Mathematics',
    'prerequisite'   => null,           // or subject title string
    'catalog_group'  => 'HS Core Academics',
    'course_track'   => 'Mathematics',
    'pathway_type'   => 'core',         // or 'elective'
)
```

### Adding New Courses

1. Add entries to the array returned by `llms_openstax_expected_catalog_entries()`.
2. Update the expected count in `verify-ahsa-site.php`.
3. If a new quiz domain is needed, add it to the importer's `$quiz_banks` array.
4. Re-run the import: `wp eval-file sample-data/import-openstax-florida.php`.

---

## Module Architecture

All custom modules are PHP singletons loaded by the MU-plugin loader.

```
sample-data/
├── mu-plugins/
│   └── ahsa-core.php                          ← MU-plugin entry point (v2.4.0)
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
│   └── ahsa-branding.css
├── import-openstax-florida.php                ← Course importer
├── build-ahsa-site.php                        ← Site page builder
├── setup-progression-rules.php
├── setup-parent-monitoring.php
├── setup-rubrics.php
├── setup-standards.php
├── setup-exam-security.php
├── setup-bulk-seb.php                         ← Bulk SEB enable/disable
├── integration-test.php                       ← Integration test suite
├── verify-openstax-florida.php
├── verify-ahsa-site.php
├── export-openstax-compliance-report.php
├── deploy-openstax-florida.sh
└── runtime/
    ├── bootstrap-school-env.sh
    └── docker-compose.school.yml
```

### Loading Order

The MU-plugin hooks into `plugins_loaded` at priority 20 (after LifterLMS) and
loads modules in this order:

1. **Progression Enforcement** — `AHSA_Progression_Enforcement::instance()`
2. **Parent Monitoring** — `AHSA_Parent_Monitor::instance()`
3. **Rubrics** — `AHSA_Rubrics::instance()`
4. **Standards** — `AHSA_Standards::instance()`
5. **Exam Security** — `AHSA_Exam_Security::instance()`
6. **Branding** — `AHSA_Branding::instance()`
7. **Transcript** — `AHSA_Transcript::instance()`
8. **Student Dashboard** — `AHSA_Student_Dashboard::instance()`
9. **Email Notifications** — `AHSA_Email_Notifications::instance()`
10. **Admin Dashboard** — `AHSA_Admin_Dashboard::instance()`

A module is only loaded if its file is readable:

```php
$file = AHSA_MODULES_DIR . '/class-ahsa-rubrics.php';
if ( is_readable( $file ) ) {
    require_once $file;
    AHSA_Rubrics::instance();
}
```

---

## Progression Enforcement

**File:** `modules/class-ahsa-progression-enforcement.php`
**Class:** `AHSA_Progression_Enforcement`

Prevents students from progressing until they meet passing thresholds.

### Enforcement Levels

| Level   | Hook                          | Behavior                     |
|---------|-------------------------------|------------------------------|
| Quiz    | `llms_quiz_attempt_new`       | Block if prerequisite quiz < pass % |
| Lesson  | `llms_can_user_bypass_restrictions` | Block if previous lesson incomplete |
| Section | `llms_can_user_bypass_restrictions` | Block if previous section incomplete |

### Admin Settings

- **Global passing %**: `ahsa_global_passing_percent` option (default 60).
- **Per-course override**: `_ahsa_passing_percent` post meta.
- **Per-quiz override**: `_ahsa_passing_percent` on quiz post.

### Course Metabox

Appears on course editor → "AHSA Progression" panel:
- Enable/disable enforcement toggle.
- Passing percentage override field.

---

## Parent Monitoring

**File:** `modules/class-ahsa-parent-monitor.php`
**Class:** `AHSA_Parent_Monitor`

### Custom DB Tables

| Table                           | Purpose                        |
|---------------------------------|--------------------------------|
| `{prefix}ahsa_parent_student`  | Parent ↔ student relationships |
| `{prefix}ahsa_teacher_notes`   | Teacher notes per student      |

### Parent Role

Creates a custom `ahsa_parent` role with `read` capability.

### Features

- **Parent Dashboard** — `[ahsa_parent_dashboard]` shortcode.
- **Student linking** — Admin panel to link parent users to student users.
- **Teacher notes** — CRUD interface for adding notes visible to parents.
- **Admin tabs** — LifterLMS sub-menu page with Relationships and Teacher Notes tabs.

---

## Rubric System

**File:** `modules/class-ahsa-rubrics.php`
**Class:** `AHSA_Rubrics`

### Custom DB Tables

| Table                            | Purpose                    |
|----------------------------------|----------------------------|
| `{prefix}ahsa_rubrics`          | Rubric definitions         |
| `{prefix}ahsa_rubric_categories`| Weighted grading categories|

### Default Categories

| Category          | Weight |
|-------------------|-------:|
| Content Mastery   |    30% |
| Assignments       |    25% |
| Quizzes & Exams   |    25% |
| Participation     |     5% |
| Projects          |    10% |
| Academic Integrity|     5% |

### Template System

Rubrics can be created as templates (`is_template = 1`) and then cloned to
individual courses via `clone_to_course( $template_id, $course_id )`.

### Admin UI

- **Admin page**: LifterLMS → AHSA Rubrics.
- **Course metabox**: "AHSA Rubric" panel on course editor for template selection.

### Frontend

Shortcode: `[ahsa_course_rubric]` — displays the rubric table for the current
course (or specify `course_id` attribute).

---

## Florida Standards Alignment

**File:** `modules/class-ahsa-standards.php`
**Class:** `AHSA_Standards`

### Custom DB Tables

| Table                                | Purpose                        |
|--------------------------------------|--------------------------------|
| `{prefix}ahsa_standards`            | Standards library              |
| `{prefix}ahsa_standards_alignment`  | Standard ↔ post alignments     |

### Supported Frameworks

| Constant Key            | Framework                  |
|-------------------------|----------------------------|
| `BEST_ELA`              | B.E.S.T. ELA               |
| `BEST_MATH`             | B.E.S.T. Mathematics       |
| `NGSSS_SCIENCE`         | NGSSS Science              |
| `FL_SOCIAL_STUDIES`     | Florida Social Studies      |
| `FL_CTE`                | Florida CTE                |
| `FAA_KNOWLEDGE`         | FAA Knowledge Areas         |
| `NCAA_ELIGIBILITY`      | NCAA Eligibility            |

### Alignment Types

- **primary** — core alignment
- **supporting** — supporting standard
- **assessed** — directly assessed on the course

### Auto-Alignment

`auto_align_courses()` matches courses to standards by checking
`_llms_state_standard_framework` metadata on each course against
framework mappings.

### Seeding

`seed_florida_standards()` inserts ~40 sample standards across all 7
frameworks. Invoked by `setup-standards.php`.

### Metaboxes

Standards alignment metaboxes appear on `course`, `lesson`, and `llms_quiz`
post types, allowing per-post standard attachments.

### Frontend

Shortcode: `[ahsa_standards_alignment]` — displays aligned standards for the
current post.

---

## Exam Security (SEB)

**File:** `modules/class-ahsa-exam-security.php`
**Class:** `AHSA_Exam_Security`

### Custom DB Tables

| Table                          | Purpose                         |
|--------------------------------|---------------------------------|
| `{prefix}ahsa_exam_sessions`  | Exam session records            |
| `{prefix}ahsa_exam_logs`      | Per-session event log entries    |

### SEB Validation

When a quiz attempt starts (via `llms_quiz_attempt_new`), the module:

1. Checks the user agent for `SEB/` prefix.
2. Validates the config key hash against stored `_ahsa_seb_config_key`.
3. Validates the exam key against stored `_ahsa_seb_exam_key`.
4. Detects and blocks concurrent sessions for the same student+quiz.

### Risk Scoring

| Level    | Score Threshold | Example Events                |
|----------|----------------:|-------------------------------|
| Low      |            0–2  | Normal exam activity          |
| Moderate |            3–5  | Minor focus losses            |
| High     |            6–9  | Copy attempts, disconnections |
| Critical |           10+   | SEB validation failure, concurrent session |

### Event Types (16)

`exam_start`, `exam_end`, `validation_failed`, `focus_loss`, `focus_return`,
`disconnect`, `reconnect`, `abnormal_exit`, `copy_attempt`, `paste_attempt`,
`screenshot_attempt`, `concurrent_session`, `seb_key_mismatch`,
`browser_blocked`, `attempt_submitted`, `time_expired`

### Client-Side Monitoring

Inline JavaScript injected on quiz pages monitors:
- Visibility changes (tab switch)
- Window blur/focus
- Copy/paste/cut prevention
- Right-click blocking
- Keyboard shortcut blocking (Ctrl/Cmd+C/V/P/S/U/Shift+I, F12, PrintScreen)
- Network disconnect detection
- Page unload detection

Events are logged via the REST API endpoint.

### Quiz Metabox

Appears on `llms_quiz` editor:
- **SEB Required** toggle.
- **Config Key** text field.
- **Exam Key** text field.

### Admin UI

LifterLMS → Exam Security with 3 tabs:
1. **Exam Sessions** — all sessions with filtering.
2. **Flagged Sessions** — sessions with moderate+ risk.
3. **Settings** — global SEB requirement toggle, dashboard statistics.

Each session has a detail/review page with the full event timeline and admin
notes field.

### Exam Lifecycle

| Phase     | Hook                          | Actions                        |
|-----------|-------------------------------|--------------------------------|
| Start     | `llms_quiz_attempt_new`       | SEB validation, session create, `exam_start` log |
| Monitor   | Client-side JS + REST API     | Focus loss, copy/paste, disconnect logging |
| End       | `llms_quiz_attempt_graded`    | `attempt_submitted` + `exam_end` log, session close |

### Bulk SEB Configuration

**Script:** `setup-bulk-seb.php`

Enable SEB on all quizzes:
```bash
wp eval-file sample-data/setup-bulk-seb.php
```

Disable SEB on all quizzes:
```bash
wp eval-file sample-data/setup-bulk-seb.php -- --disable
```

---

## Transcript Generator

**File:** `modules/class-ahsa-transcript.php`
**Class:** `AHSA_Transcript`

### Custom DB Tables

| Table                            | Purpose                    |
|----------------------------------|----------------------------|
| `{prefix}ahsa_transfer_courses` | Transfer course records    |

### 4.0 GPA Scale

| Letter | GPA Points | Letter | GPA Points |
|--------|-----------|--------|-----------|
| A+     | 4.0       | C+     | 2.3       |
| A      | 4.0       | C      | 2.0       |
| A-     | 3.7       | C-     | 1.7       |
| B+     | 3.3       | D+     | 1.3       |
| B      | 3.0       | D      | 1.0       |
| B-     | 2.7       | D-     | 0.7       |
|        |           | F      | 0.0       |

Special: P (Pass), W (Withdrawn), TR (Transfer), I (Incomplete) — excluded from GPA.

### Percentage → Letter Grade Boundaries

97+ = A+, 93–96 = A, 90–92 = A−, 87–89 = B+, 83–86 = B, 80–82 = B−,
77–79 = C+, 73–76 = C, 70–72 = C−, 67–69 = D+, 63–66 = D, 60–62 = D−,
below 60 = F.

### Transfer Courses

Supports importing courses from previous schools with full metadata:
- School name, city, state
- Course name, code, academic year, grade level, semester
- Credit hours, letter grade, GPA points
- Course type, subject area, NCAA approval flag, notes

### Transcript Structure

Four-year format (grades 9–12), each containing two semesters. Combines:
- AHSA LifterLMS enrollment data (calculated from quiz grades)
- Imported transfer courses from previous schools

### School & Student Info

- **School info** stored in `ahsa_school_info` WordPress option (name, address,
  CEEB code, accreditation, principal, counselor, grading scale note, etc.)
- **Student info** stored per user in `_ahsa_transcript_*` user meta fields
  (student ID, DOB, gender, graduation date, parent/guardian, SAT/ACT scores, etc.)

### Admin UI

LifterLMS → Transcripts with 4 tabs:
1. **Generate Transcript** — select student and render printable transcript.
2. **Transfer Courses** — add, view, delete transfer course records.
3. **School Info** — configure school header details.
4. **Student Info** — edit per-student transcript metadata.

### Frontend

Shortcode: `[ahsa_transcript]` — displays the logged-in student's transcript with
print-friendly styling. Admin users can view any student's transcript via query
parameter.

---

## Student Dashboard

**File:** `modules/class-ahsa-student-dashboard.php`
**Class:** `AHSA_Student_Dashboard`

### Features

- **Overview stats**: enrolled courses, completed, in-progress, credits earned, cumulative GPA.
- **In-progress courses**: cards with progress bars color-coded by status
  (on-track/behind/at-risk).
- **Completed courses**: table with credit, letter grade, GPA points.
- **Quick links**: link to full transcript page.

### Frontend

Shortcode: `[ahsa_student_dashboard]` — must be logged in as a student.

---

## Email Notifications

**File:** `modules/class-ahsa-email-notifications.php`
**Class:** `AHSA_Email_Notifications`

### Notification Triggers

| Trigger                  | Hook                           | Recipient   | Behavior                    |
|--------------------------|--------------------------------|-------------|------------------------------|
| Grade drop alert         | `llms_quiz_attempt_graded`     | Parent(s)   | When student scores below threshold (default 70%) |
| Teacher note posted      | `ahsa_teacher_note_created`    | Parent(s)   | Immediate email with note content |
| Weekly student reminder  | `ahsa_daily_student_reminders` | Students    | Cron-based, rate-limited 1/week per student |
| Flagged exam session     | `ahsa_exam_session_flagged`    | Admin       | Alert when exam session flagged moderate+ |

### Admin UI

LifterLMS → Email Alerts:
- From name/email overrides
- Per-notification-type enable/disable toggles
- Grade drop threshold configuration

---

## Admin Dashboard Widget

**File:** `modules/class-ahsa-admin-dashboard.php`
**Class:** `AHSA_Admin_Dashboard`

Adds an "AHSA School Overview" widget to the WordPress admin dashboard:

- **Stats**: published courses, enrolled students, parent accounts, transfer courses.
- **Compliance**: courses with rubrics assigned, courses with standards aligned.
- **Alerts**: flagged exam sessions requiring review.
- **Quick links**: Transcript Manager, Rubrics, Standards, Email Alerts.

---

## Branding

**Files:**
- `modules/class-ahsa-branding.php` — CSS enqueue loader.
- `assets/ahsa-branding.css` — unified stylesheet.

### Color Palette (CSS Custom Properties)

| Variable             | Value     | Usage                |
|----------------------|-----------|----------------------|
| `--ahsa-navy`        | `#003366` | Primary brand color  |
| `--ahsa-navy-dark`   | `#002244` | Hover states         |
| `--ahsa-gold`        | `#C8A415` | Accent               |
| `--ahsa-gold-light`  | `#F5E6A3` | Light accent bg      |
| `--ahsa-success`     | `#28a745` | Pass, low risk       |
| `--ahsa-warning`     | `#ffc107` | In-progress, moderate|
| `--ahsa-danger`      | `#dc3545` | Critical, fail       |
| `--ahsa-orange`      | `#fd7e14` | High risk            |

### Enqueue Strategy

The stylesheet is loaded on:
- **Frontend** — all pages (small file, caches well).
- **Admin** — AHSA admin pages, LifterLMS pages, and post editors.
- **Login** — WP login page for branded login screen.

### CSS Classes

| Class                     | Purpose                             |
|---------------------------|-------------------------------------|
| `.ahsa-badge`             | Inline status/type badges           |
| `.ahsa-badge--{variant}`  | Color variants (primary, success…)  |
| `.ahsa-table`             | Styled data tables                  |
| `.ahsa-progression-notice`| Prerequisite lock notices           |
| `.ahsa-parent-dashboard`  | Parent dashboard wrapper            |
| `.ahsa-student-card`      | Student progress cards              |
| `.ahsa-progress-track`    | Progress bar track                  |
| `.ahsa-progress-fill`     | Progress bar fill                   |
| `.ahsa-rubric`            | Rubric shortcode wrapper            |
| `.ahsa-standards-alignment`| Standards shortcode wrapper        |
| `.ahsa-risk-{level}`      | Risk level text coloring            |
| `.ahsa-exam-stat`         | Dashboard statistic cards           |

---

## Site Structure

**File:** `build-ahsa-site.php`

Creates the following WordPress pages:

| Page Slug                    | Content                              |
|------------------------------|--------------------------------------|
| `home`                       | Academy home page                    |
| `about`                      | About AHSA                           |
| `courses`                    | Course catalog listing page          |
| `high-school-courses`        | HS Core courses                      |
| `middle-school-courses`      | MS Core courses                      |
| `florida-exam-prep`          | EOC/FSA prep courses                 |
| `aviation-aerospace-courses` | Aviation/Aerospace/Drone pathway     |
| `admissions`                 | Admissions information               |
| `academy-network`            | Academy network partners             |
| `student-dashboard`          | Student progress dashboard           |
| `student-transcript`         | Student transcript view              |
| `parent-dashboard`           | Parent monitoring dashboard          |
| `teacher-tools`              | Teacher notes and tools              |
| `news`                       | School news blog                     |
| `contact`                    | Contact information                  |

Navigation menu `AHSA Primary Navigation` links all public-facing pages.

---

## Deployment

### Quick Start (Docker)

```bash
cd sample-data/runtime
bash bootstrap-school-env.sh
```

This runs the complete setup sequence inside Docker containers, including:
1. Start MariaDB and WordPress containers (with database healthcheck)
2. Install WordPress core
3. Activate LifterLMS
4. Build AHSA site structure (pages, menus)
5. Import all 84 courses
6. Configure progression enforcement
7. Set up parent monitoring
8. Create rubric templates
9. Seed Florida standards and auto-align
10. Create exam security tables
11. Bulk-enable SEB on all quizzes
12. Install AHSA mu-plugin
13. Verify course catalog and site structure
14. Export compliance report
15. Run integration tests
16. Smoke-test key pages via HTTP

### Environment Variables

| Variable         | Default                        | Purpose                  |
|------------------|--------------------------------|--------------------------|
| `SITE_URL`       | `http://localhost:8080`        | WordPress site URL       |
| `AHSA_PORT`      | `8080`                         | Host-side port mapping   |
| `SITE_TITLE`     | `American High School Academy` | WordPress site title     |
| `ADMIN_USER`     | `admin`                        | WordPress admin username |
| `ADMIN_PASSWORD`  | `adminpass123!`               | WordPress admin password |
| `ADMIN_EMAIL`    | `admin@example.org`            | WordPress admin email    |

---

## Docker Compose Runtime

**File:** `runtime/docker-compose.school.yml`

### Services

| Service     | Image                         | Purpose                |
|-------------|-------------------------------|------------------------|
| `db`        | `mariadb:10.11`               | Database with healthcheck |
| `wordpress` | `wordpress:6.6-php8.2-apache` | Web server (depends on healthy db) |
| `wpcli`     | `wordpress:cli-php8.2`        | WP-CLI command runner  |

### Volumes

- `school_db_data` — persistent database storage.
- `school_wp_data` — persistent WordPress files.
- LifterLMS plugin is bind-mounted from the host repository via `../../`.

### Port

Default: `8080` (configurable via `AHSA_PORT` env var).

### Health Checks

The `db` service includes a MariaDB healthcheck. The `wordpress` service only
starts after the database reports healthy, preventing boot-ordering race conditions.

---

## WP-CLI Scripts

All scripts are invoked via `wp eval-file`:

| Script                              | Purpose                           |
|-------------------------------------|-----------------------------------|
| `import-openstax-florida.php`       | Create/update all 84 courses      |
| `build-ahsa-site.php`              | Create WordPress pages & menu     |
| `setup-progression-rules.php`      | Enable progression enforcement    |
| `setup-parent-monitoring.php`      | Create parent monitoring tables   |
| `setup-rubrics.php`                | Create rubric templates & assign  |
| `setup-standards.php`              | Seed standards & auto-align       |
| `setup-exam-security.php`          | Create exam security tables       |
| `setup-bulk-seb.php`              | Bulk enable/disable SEB on quizzes|
| `verify-openstax-florida.php`      | Verify course import data         |
| `verify-ahsa-site.php`            | Verify site pages exist           |
| `export-openstax-compliance-report.php` | Export CSV compliance report |
| `integration-test.php`             | Run full integration test suite   |

---

## Database Tables

### Custom Tables (9)

| Table                             | Module              | Key Columns                                                      |
|-----------------------------------|---------------------|------------------------------------------------------------------|
| `ahsa_parent_student`             | Parent Monitor      | `id`, `parent_user_id`, `student_user_id`                        |
| `ahsa_teacher_notes`              | Parent Monitor      | `id`, `teacher_user_id`, `student_user_id`, `note`, `created_at` |
| `ahsa_rubrics`                    | Rubrics             | `id`, `title`, `course_id`, `is_template`                        |
| `ahsa_rubric_categories`          | Rubrics             | `id`, `rubric_id`, `category_key`, `label`, `weight`             |
| `ahsa_standards`                  | Standards           | `id`, `framework`, `standard_code`, `title`, `grade_band`        |
| `ahsa_standards_alignment`        | Standards           | `id`, `standard_id`, `post_id`, `post_type`, `alignment_type`    |
| `ahsa_exam_sessions`              | Exam Security       | `id`, `student_user_id`, `quiz_id`, `seb_validated`, `risk_score`|
| `ahsa_exam_logs`                  | Exam Security       | `id`, `session_id`, `event_type`, `event_data`, `ip_address`     |
| `ahsa_transfer_courses`           | Transcript          | `id`, `student_user_id`, `school_name`, `course_name`, `letter_grade`, `gpa_points` |

### Post Meta Keys

| Meta Key                            | Post Type   | Module        | Values                  |
|-------------------------------------|-------------|---------------|-------------------------|
| `_ahsa_progression_enforced`        | course      | Progression   | `yes` / `no`            |
| `_ahsa_passing_percent`             | course/quiz | Progression   | Integer 0–100           |
| `_ahsa_rubric_id`                   | course      | Rubrics       | Rubric table ID         |
| `_ahsa_seb_required`                | llms_quiz   | Exam Security | `yes` / `no`            |
| `_ahsa_seb_config_key`              | llms_quiz   | Exam Security | SEB config key string   |
| `_ahsa_seb_exam_key`                | llms_quiz   | Exam Security | SEB exam key string     |
| `_llms_pathway_type`                | course      | Importer      | `core` / `elective`     |
| `_llms_state_standard_framework`    | course      | Importer      | Framework identifier    |
| `_llms_credit_value`                | course      | Importer      | Decimal credit value    |
| `_llms_semester`                    | course      | Importer      | `1` or `2`              |
| `_llms_grade_level`                 | course      | Importer      | Grade number            |

### WordPress Options

| Option Key                   | Module         | Default    | Purpose                       |
|------------------------------|----------------|------------|-------------------------------|
| `ahsa_global_passing_percent`| Progression    | `60`       | Site-wide passing threshold   |
| `ahsa_seb_global_default`   | Exam Security  | `no`       | Global SEB requirement        |
| `ahsa_school_info`           | Transcript     | `array()`  | School transcript header data |
| `ahsa_email_settings`        | Notifications  | `array()`  | Email notification config     |

---

## REST API

### Exam Event Logging

```
POST /wp-json/ahsa/v1/exam-event
```

**Authentication:** Cookie-based (logged-in student).

**Request Body (JSON):**

```json
{
    "session_id": 42,
    "event_type": "focus_loss",
    "event_data": {"timestamp": 1700000000}
}
```

**Response:**

```json
{
    "success": true,
    "event_id": 123
}
```

---

## Shortcodes

| Shortcode                     | Attributes                | Module           |
|-------------------------------|---------------------------|------------------|
| `[ahsa_parent_dashboard]`    | —                         | Parent Monitor   |
| `[ahsa_course_rubric]`       | `course_id` (optional)    | Rubrics          |
| `[ahsa_standards_alignment]` | —                         | Standards        |
| `[ahsa_transcript]`          | —                         | Transcript       |
| `[ahsa_student_dashboard]`   | —                         | Student Dashboard|

---

## Configuration Reference

### Environment Variables (Docker)

| Variable              | Default                        | Usage                      |
|-----------------------|--------------------------------|----------------------------|
| `WORDPRESS_DB_HOST`   | `db:3306`                      | Database host              |
| `WORDPRESS_DB_USER`   | `wordpress`                    | Database user              |
| `AHSA_DB_USER`        | `wordpress`                    | Database user              |
| `AHSA_DB_PASSWORD`    | `wordpress`                    | Database password          |
| `AHSA_DB_NAME`        | `wordpress`                    | Database name              |
| `AHSA_DB_ROOT_PASSWORD`| `root`                        | MariaDB root password      |
| `AHSA_PORT`           | `8080`                         | Host-side port mapping     |
| `ADMIN_USER`          | `admin`                        | WP admin username          |
| `ADMIN_PASSWORD`      | `adminpass123!`                | WP admin password          |
| `ADMIN_EMAIL`         | `admin@example.org`            | WP admin email             |

### PHP Constants

| Constant            | Default                       | Purpose                   |
|---------------------|-------------------------------|---------------------------|
| `AHSA_MODULES_DIR`  | Auto-detected                 | Path to modules directory |

---

## Troubleshooting

### Module not loading

1. Confirm `ahsa-core.php` is in `wp-content/mu-plugins/`.
2. Confirm `AHSA_MODULES_DIR` points to the modules directory.
3. Confirm LifterLMS is active (`wp plugin list`).

### Courses not imported

1. Run `wp eval-file sample-data/verify-openstax-florida.php`.
2. Check `sample-data/openstax-course-catalog.php` has correct entry count.
3. Re-run the importer: `wp eval-file sample-data/import-openstax-florida.php`.

### Standards not aligning

1. Ensure `setup-standards.php` ran successfully.
2. Check that courses have `_llms_state_standard_framework` meta set.
3. Standards matching normalizes text: `B.E.S.T.` → `best`, punctuation stripped, case-insensitive.
4. If a new framework name doesn't match, add a pattern to `match_framework_key()` in `class-ahsa-standards.php`.
5. Re-run: `wp eval-file sample-data/setup-standards.php`.

### SEB not validating

1. Verify the quiz has `_ahsa_seb_required` = `yes`.
2. Verify `_ahsa_seb_config_key` and `_ahsa_seb_exam_key` are set.
3. Ensure the student's browser sends `SEB/` in the user agent string.
4. Check the Exam Security admin page for session logs and error details.

### Transcript not showing courses

1. Verify the student has LifterLMS enrollments.
2. Check that courses have `_llms_grade_level` and `_llms_semester` meta.
3. Verify transfer courses exist in the `ahsa_transfer_courses` table.

### Email notifications not sending

1. Check `ahsa_email_settings` option in the database.
2. Verify `wp_mail()` is functional (SMTP plugin may be needed).
3. Check if the cron job is scheduled: `wp cron event list`.

### Docker runtime issues

1. Ensure Docker is running: `docker info`.
2. Check container status: `docker compose -f runtime/docker-compose.school.yml ps`.
3. View logs: `docker compose -f runtime/docker-compose.school.yml logs wordpress`.
4. If the database fails healthcheck, wait 30 seconds and retry.
5. To reset: `docker compose -f runtime/docker-compose.school.yml down -v` and re-run bootstrap.

### Integration tests

```bash
wp eval-file sample-data/integration-test.php
```

Tests 9 database tables, 10 module classes, 5 shortcodes, page structure,
course catalog integrity, rubric templates, standards seeding, cron scheduling,
and REST API registration.

### PHP lint (development)

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.2-cli php -l path/to/file.php
```

(Host PHP may have OpenSSL version mismatches — always lint via Docker.)

---

## Release & Handoff

See [RELEASE.md](RELEASE.md) for the complete release notes, deployment
checklist, production hardening guide, environment variable reference, and
GitHub release summary for AHSA v2.4.0.
