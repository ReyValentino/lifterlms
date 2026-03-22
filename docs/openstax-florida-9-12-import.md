# OpenStax Florida Core + Test-Prep Semester Course Import (0.5 Credit)

This package prebuilds a full school catalog in LifterLMS using OpenStax-aligned and open-source materials.

## What It Creates

- 64 semester courses total (18 weeks each)
- 28 high school core courses (grades 9-12)
- 24 middle school core courses (grades 6-8)
- 12 Florida EOC/FSA prep courses
- 0.5 credit per course
- Open enrollment access plans (free)
- 3 sections and 12 lessons per course
- 3 graded quiz lessons per course (one per section)
- 5-question auto-graded quizzes with retry limits and passing thresholds
- Subject-track specific quiz banks (Math, Science, ELA, Social Studies)
- Phase-specific checkpoint quizzes (roadmap, reading/practice, final readiness)
- Linked video resource blocks in lessons (OpenStax plus subject-relevant OER video libraries)
- Built-in pacing, grading policy, and assessment workflow content
- Metadata for Florida standards, program grouping, and core-area tracking

## Included Semester Courses

- High School Core (28): grades 9-12 English, Math, Science, and Social Studies
- Middle School Core (24): grades 6-8 English, Math, Science, and Social Studies
- Florida EOC/FSA Prep (12): Algebra 1 EOC, Geometry EOC, Biology EOC, Civics EOC, Grade 8 ELA, Grade 8 Math
- Full canonical list: sample-data/openstax-course-catalog.php

## Turn-Key Deploy

Use the wrapper script from the plugin root:

```bash
bash sample-data/deploy-openstax-florida.sh
```

The wrapper runs these scripts in sequence:

- sample-data/build-ahsa-site.php
- sample-data/import-openstax-florida.php
- sample-data/verify-openstax-florida.php
- sample-data/verify-ahsa-site.php

The wrapper auto-detects and tries these targets in order:

- Local WP-CLI
- docker compose exec on service wordpress
- docker compose run on service wordpress

If you prefer direct command execution, run:

```bash
wp eval-file sample-data/import-openstax-florida.php
wp eval-file sample-data/verify-openstax-florida.php
```

The script is idempotent for course slugs:

- Existing courses are skipped
- Missing courses are created
- Import exits with an error if any configured course is still missing after run
- Import reports success only when all catalog courses are verified present

Verification checks include:

- All expected course slugs/titles exist
- Each course has minimum section and lesson counts
- Each course has quiz-enabled lessons for auto-grading
- Each course has a free access plan
- Required compliance metadata exists on every course

## Auto-Grading and Video Notes

- Lesson quizzes are objective multiple-choice and auto-graded by LifterLMS
- Quiz settings include passing threshold and limited retries
- Lessons include optional video resource links where available
- Courses remain editable so you can replace linked resources with district-approved videos

## Deployment Readiness Checklist

- WordPress is running
- LifterLMS is active
- WP-CLI is available either locally or inside your wordpress container
- Run import command and confirm success log for generated courses

## OpenStax and Attribution

The package references OpenStax textbooks and includes attribution metadata:

- License: CC BY 4.0
- Attribution: OpenStax, Rice University

If you add copied/adapted OpenStax text or media into lessons, retain full CC BY attribution in your lesson content and course syllabus.

## NCAA and Florida Compliance Notes

The package adds tracking metadata for implementation workflows, including:

- _llms_credit_value = 0.5
- _llms_semester
- _llms_state_standard_framework
- _llms_ncaa_core_area

You still need district and institutional review for official transcript policy, NCAA Eligibility Center workflows, and local course approval.
