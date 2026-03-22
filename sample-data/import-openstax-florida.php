<?php
/**
 * Build OpenStax-based Florida 9-12 semester courses in LifterLMS.
 *
 * Usage:
 *   wp eval-file sample-data/import-openstax-florida.php
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "This script must be executed with WP-CLI.\n";
	return;
}

if ( ! class_exists( 'LLMS_Generator' ) ) {
	WP_CLI::error( 'LLMS_Generator is not available. Activate LifterLMS first.' );
}

require_once __DIR__ . '/openstax-course-catalog.php';

/**
 * Build a generic set of sections and lessons for a semester course.
 *
 * @param array $course Course definition.
 * @return array
 */
function llms_openstax_get_semester_sections( $course, $lesson_id_base = 0 ) {
	$book      = $course['book'];
	$subject   = $course['subject'];
	$semester  = (int) $course['semester'];
	$standards = implode( ', ', $course['standards'] );
	$track     = isset( $course['track'] ) ? $course['track'] : '';
	$grade_band = isset( $course['grade_band'] ) ? $course['grade_band'] : '9-12';
	$program   = isset( $course['catalog_group'] ) ? $course['catalog_group'] : 'Florida Core';
	$ncaa_area = isset( $course['ncaa_area'] ) ? $course['ncaa_area'] : 'Not Applicable';

	$video_resources = array(
		array(
			'label' => 'OpenStax YouTube Channel',
			'url'   => 'https://www.youtube.com/@OpenStax',
		),
	);

	if ( 'Mathematics' === $track ) {
		$video_resources[] = array(
			'label' => 'Khan Academy Math Video Library',
			'url'   => 'https://www.khanacademy.org/math',
		);
	} elseif ( 'Science' === $track ) {
		$video_resources[] = array(
			'label' => 'Khan Academy Science Video Library',
			'url'   => 'https://www.khanacademy.org/science',
		);
	} elseif ( 'Social Studies' === $track ) {
		$video_resources[] = array(
			'label' => 'Khan Academy Humanities and Social Studies Videos',
			'url'   => 'https://www.khanacademy.org/humanities',
		);
	} elseif ( 'Aviation/Aerospace' === $track ) {
		$video_resources[] = array(
			'label' => 'Khan Academy Physics and Engineering Videos',
			'url'   => 'https://www.khanacademy.org/science/physics',
		);
	} else {
		$video_resources[] = array(
			'label' => 'Khan Academy Grammar and Writing Videos',
			'url'   => 'https://www.khanacademy.org/humanities/grammar',
		);
	}

	$video_html = '<ul>';
	foreach ( $video_resources as $video_resource ) {
		$video_html .= sprintf(
			'<li><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></li>',
			esc_url( $video_resource['url'] ),
			esc_html( $video_resource['label'] )
		);
	}
	$video_html .= '</ul>';

	$build_quiz_questions = function( $title, $phase ) use ( $track, $subject ) {

		$subject_items = array(
			'math'    => array(
				'roadmap' => array(
					array( 'Which sequence best supports math mastery over a semester?', 'Spiral review with cumulative checks', 'Only one end-of-term review' ),
					array( 'What evidence best supports algebraic reasoning growth?', 'Worked solutions with annotated steps', 'Final numeric answers only' ),
				),
				'reading' => array(
					array( 'What should guided notes emphasize in math lessons?', 'Definitions, worked examples, and error analysis', 'Only copied chapter headings' ),
					array( 'Which practice format best builds transfer?', 'Mixed problem sets across standards', 'Single-skill drills only' ),
				),
				'final'   => array(
					array( 'What final evidence best verifies math proficiency?', 'Multi-standard exam plus performance task', 'Attendance logs only' ),
					array( 'What is a valid intervention before reassessment?', 'Targeted reteach on missed standards', 'Reassign identical test with no feedback' ),
				),
			),
			'science' => array(
				'roadmap' => array(
					array( 'What is essential for science course pacing?', 'Lab/analysis checkpoints aligned to standards', 'Reading chapters without checks' ),
					array( 'What evidence best supports scientific reasoning growth?', 'Claim-evidence-reasoning responses', 'Memorized vocabulary list only' ),
				),
				'reading' => array(
					array( 'How should students process science readings?', 'Annotate concepts and connect to phenomena', 'Skim text without note-taking' ),
					array( 'Which activity strengthens science transfer?', 'Data interpretation with written conclusions', 'Only multiple-choice warmups' ),
				),
				'final'   => array(
					array( 'What best verifies semester science mastery?', 'Standards-based exam and investigation product', 'Participation points only' ),
					array( 'What is the best remediation approach in science?', 'Concept-focused reteach with new data sets', 'Repeat old quiz with no instruction' ),
				),
			),
			'ela'     => array(
				'roadmap' => array(
					array( 'What should an ELA semester roadmap include?', 'Reading, writing, and language strands with benchmarks', 'Only novel completion dates' ),
					array( 'What artifact best shows writing growth?', 'Draft-to-final writing with rubric feedback', 'Single final paragraph only' ),
				),
				'reading' => array(
					array( 'What should guided notes capture in ELA?', 'Claims, evidence, and rhetorical moves', 'Only chapter page numbers' ),
					array( 'Which task best supports textual analysis?', 'Constructed response with cited evidence', 'Unscored opinion discussion only' ),
				),
				'final'   => array(
					array( 'What final evidence supports ELA credit award?', 'Proficient writing and reading analysis assessments', 'Attendance and behavior only' ),
					array( 'What is effective writing remediation?', 'Targeted revision cycles with feedback', 'Assign new prompt with no guidance' ),
				),
			),
			'social'  => array(
				'roadmap' => array(
					array( 'What is key in social studies pacing?', 'Chronological/content strands tied to standards', 'Only textbook chapter deadlines' ),
					array( 'What best shows historical thinking growth?', 'Source analysis with claim support', 'Copied definitions only' ),
				),
				'reading' => array(
					array( 'How should students engage social studies text?', 'Annotate causes, effects, and perspectives', 'Read without any written output' ),
					array( 'Which task builds civic/historical reasoning?', 'Document-based question with evidence', 'Only completion-graded worksheets' ),
				),
				'final'   => array(
					array( 'What verifies social studies semester mastery?', 'Exam plus evidence-based performance response', 'Seat time without assessment data' ),
					array( 'Best intervention after weak DBQ performance?', 'Model evidence integration and revise', 'Assign unrelated extra credit' ),
				),
			),
			'aviation' => array(
				'roadmap' => array(
					array( 'What is essential for pacing an aviation pathway course?', 'Standards-aligned milestones with FAA knowledge area checkpoints', 'Only textbook chapter deadlines' ),
					array( 'What evidence best supports aviation knowledge growth?', 'Scenario-based assessments with aeronautical decision-making tasks', 'Final answers only without reasoning' ),
				),
				'reading' => array(
					array( 'How should students process aviation/aerospace readings?', 'Annotate key concepts and connect to real-world flight scenarios', 'Skim text without note-taking' ),
					array( 'Which activity best builds aviation knowledge transfer?', 'Flight planning exercises with weather and navigation analysis', 'Only multiple-choice warmups' ),
				),
				'final'   => array(
					array( 'What best verifies aviation pathway semester mastery?', 'Standards-based exam plus applied aviation project or simulation', 'Attendance logs only' ),
					array( 'What is an effective intervention for aviation coursework?', 'Targeted reteach on FAA knowledge areas with new scenarios', 'Repeat identical quiz without instruction' ),
				),
			),
		);

		$domain = 'ela';
		if ( 'Mathematics' === $track ) {
			$domain = 'math';
		} elseif ( 'Science' === $track ) {
			$domain = 'science';
		} elseif ( 'Social Studies' === $track ) {
			$domain = 'social';
		} elseif ( 'Aviation/Aerospace' === $track ) {
			$domain = 'aviation';
		}

		$phase_questions = $subject_items[ $domain ][ $phase ];

		$question_rows = array(
			array(
				'question' => $phase_questions[0][0],
				'correct'  => $phase_questions[0][1],
				'wrong'    => $phase_questions[0][2],
			),
			array(
				'question' => $phase_questions[1][0],
				'correct'  => $phase_questions[1][1],
				'wrong'    => $phase_questions[1][2],
			),
			array(
				'question' => sprintf( 'What is the transcript credit value for %s semester courses?', $subject ),
				'correct'  => '0.5 credit',
				'wrong'    => '2.0 credits',
			),
			array(
				'question' => 'Which approach best supports district/state audit readiness?',
				'correct'  => 'Maintain graded artifacts, pacing evidence, and teacher feedback records',
				'wrong'    => 'Keep only a final pass/fail list',
			),
			array(
				'question' => 'How are objective lesson quizzes scored in this package?',
				'correct'  => 'Automatically by LifterLMS',
				'wrong'    => 'Only by manual grading in external spreadsheets',
			),
		);

		$questions = array();
		foreach ( $question_rows as $index => $row ) {
			$questions[] = array(
				'title'         => sprintf( '%s Question %d', $title, $index + 1 ),
				'question_type' => 'choice',
				'question'      => $row['question'],
				'points'        => 1,
				'multi_choices' => 'no',
				'choices'       => array(
					array( 'type' => 'choice', 'choice' => $row['correct'], 'correct' => true ),
					array( 'type' => 'choice', 'choice' => $row['wrong'], 'correct' => false ),
				),
			);
		}

		return $questions;
	};

	$quiz_lesson = function( $title, $phase ) use ( $build_quiz_questions, $video_html ) {
		return array(
			'title'                 => $title,
			'quiz_enabled'          => 'yes',
			'require_passing_grade' => 'yes',
			'content'               => '<p>Complete the auto-graded checkpoint quiz to verify mastery before moving forward.</p><h4>Optional Video Support</h4>' . $video_html,
			'quiz'                  => array(
				'title'               => $title . ' Quiz',
				'status'              => 'publish',
				'limit_attempts'      => 'yes',
				'allowed_attempts'    => 3,
				'passing_percent'     => 60,
				'show_correct_answer' => 'yes',
				'questions'           => $build_quiz_questions( $title, $phase ),
			),
		);
	};

	$sections = array(
		array(
			'title'   => 'Semester Roadmap',
			'lessons' => array(
				array(
					'title'   => 'Course Orientation and Success Criteria',
					'content' => sprintf(
						'<p>This half-credit %1$s course is aligned to Florida standards and open educational resource implementation goals.</p><p>Program: %2$s. Grade band: %3$s. Semester: %4$d. Credit: 0.5.</p><p>Florida standards focus: %5$s.</p><p>Core-area designation: %6$s.</p><h4>Grading and Auto-Grading</h4><ul><li>Auto-graded quizzes: 35%%</li><li>Unit assignments and projects: 45%%</li><li>Final assessment: 20%%</li></ul><h4>Materials</h4><ul><li>OpenStax and open-source reference resources</li><li>Digital notebook for guided notes</li><li>Weekly performance check submissions</li></ul>',
						esc_html( $subject ),
						esc_html( $program ),
						esc_html( $grade_band ),
						esc_html( (string) $semester ),
						esc_html( $standards ),
						esc_html( $ncaa_area )
					),
				),
				array(
					'title'   => 'OpenStax Textbook Scope and Sequence',
					'content' => sprintf(
						'<p>Primary open educational resource: OpenStax %1$s (CC BY 4.0).</p><p>Instructor pacing guide should map weekly instruction to this semester scope.</p><p>Attribution: OpenStax, Rice University.</p><h4>Video Resources (When Available)</h4>%2$s',
						esc_html( $book )
						,
						$video_html
					),
				),
				array(
					'title'   => 'Academic Evidence Checklist',
					'content' => '<p>Collect and archive syllabus, pacing calendar, grading policy, assignment rubrics, and evidence of teacher-student interaction.</p><p>This supports district, school, and test-readiness review workflows.</p><h4>Evidence to Retain</h4><ul><li>Teacher announcements and feedback logs</li><li>Timestamped gradebook exports</li><li>Assessment artifacts and intervention records</li></ul>',
				),
				$quiz_lesson( 'Roadmap Mastery Checkpoint', 'roadmap' ),
			),
		),
		array(
			'title'   => 'OpenStax Reading and Practice',
			'lessons' => array(
				array(
					'title'   => 'Unit 1 Reading and Guided Notes',
					'content' => sprintf(
						'<p>Assign OpenStax %1$s chapters for Unit 1 and provide guided notes tied to Florida benchmarks.</p><p>Require evidence of completion before next lesson unlock.</p><h4>Required Deliverables</h4><ul><li>Vocabulary log</li><li>Guided notes</li><li>Exit ticket</li></ul>',
						esc_html( $book )
					),
				),
				array(
					'title'   => 'Unit 2 Reading and Application Tasks',
					'content' => sprintf(
						'<p>Continue semester pacing with Unit 2 chapters from OpenStax %1$s.</p><p>Use discussion, written response, and skill practice tasks aligned to Florida standards.</p><h4>Application Tasks</h4><ul><li>Constructed response prompt</li><li>Skill practice set</li><li>Peer discussion post</li></ul>',
						esc_html( $book )
					),
				),
				array(
					'title'   => 'Unit 3 Mastery Practice',
					'content' => '<p>Use mixed-format practice: short response, problem solving, and standards-based formative checks.</p><p>Flag students below mastery for intervention pathways.</p><h4>Intervention Workflow</h4><ul><li>Assign targeted review</li><li>Schedule reteach conference</li><li>Require reassessment</li></ul>',
				),
				$quiz_lesson( 'Reading and Practice Checkpoint', 'reading' ),
			),
		),
		array(
			'title'   => 'Assessment and Recovery',
			'lessons' => array(
				array(
					'title'   => 'Midterm Checkpoint',
					'content' => '<p>Standards-based midterm checkpoint. Include retake policy and required remediation if proficiency is not met.</p><h4>Auto-Graded Components</h4><ul><li>Objective question bank</li><li>Mastery thresholds</li><li>Immediate feedback release</li></ul>',
				),
				array(
					'title'   => 'Cumulative Performance Task',
					'content' => '<p>Students complete an applied project or written performance task to demonstrate transfer and depth of learning.</p><h4>Rubric Criteria</h4><ul><li>Standards alignment</li><li>Accuracy and reasoning</li><li>Communication and evidence</li></ul>',
				),
				array(
					'title'   => 'Final Exam and Credit Verification',
					'content' => '<p>Final standards-aligned exam with weighted grading policy documented for transcript reporting.</p><p>Successful completion awards 0.5 credit.</p><h4>Completion Requirements</h4><ul><li>Passing overall grade</li><li>All major assessments submitted</li><li>Documented intervention attempts as needed</li></ul>',
				),
				$quiz_lesson( 'Final Readiness Checkpoint', 'final' ),
			),
		),
	);

	// Assign sequential lesson temp IDs and prerequisites for strict progression.
	$lesson_counter = 0;
	$prev_lesson_id = null;
	foreach ( $sections as &$section ) {
		foreach ( $section['lessons'] as &$lesson ) {
			$lesson_counter++;
			$current_id = $lesson_id_base + $lesson_counter;
			$lesson['id'] = $current_id;

			if ( null !== $prev_lesson_id ) {
				$lesson['has_prerequisite'] = 'yes';
				$lesson['prerequisite']     = $prev_lesson_id;
			}

			$prev_lesson_id = $current_id;
		}
		unset( $lesson );
	}
	unset( $section );

	return $sections;
}

/**
 * Build LLMS_Generator course data from compact definitions.
 *
 * @return array
 */
function llms_openstax_get_bulk_generator_payload() {
	$catalog = llms_openstax_course_catalog_definitions();

	$courses = array();

	foreach ( $catalog as $course_index => $item ) {
		$title = sprintf(
			'%1$s - Semester %2$d (Grade %3$d, 0.5 Credit)',
			$item['subject'],
			$item['semester'],
			$item['grade']
		);

		$course_intro = sprintf(
			'<p>Open educational semester course built for Florida standards implementation across core academics and test-prep pathways.</p><p>Program: %1$s.</p><p>Subject: %2$s. Grade: %3$d. Semester: %4$d.</p><p>Designation: %5$s.</p><p>Open resource base: %6$s (CC BY 4.0 attribution where applicable).</p><h4>Course Materials Included</h4><ul><li>Orientation and syllabus expectations</li><li>Unit reading and guided-note workflows</li><li>Auto-graded mastery quizzes</li><li>Performance task and final verification workflow</li><li>Optional video resources linked in lessons</li></ul><h4>Suggested Weekly Cadence</h4><ul><li>Week 1: Orientation and setup</li><li>Weeks 2-6: Unit 1 progression</li><li>Weeks 7-11: Unit 2 progression</li><li>Weeks 12-16: Unit 3 progression</li><li>Weeks 17-18: Final assessment and credit verification</li></ul>',
			esc_html( $item['catalog_group'] ),
			esc_html( $item['subject'] ),
			esc_html( (string) $item['grade'] ),
			esc_html( (string) $item['semester'] ),
			esc_html( $item['ncaa_area'] ),
			esc_html( $item['book'] )
		);

		$categories = array(
			sprintf( 'Florida Grade %d', (int) $item['grade'] ),
			'OpenStax',
			$item['catalog_group'],
		);
		if ( $item['grade'] >= 9 && 'Not Applicable' !== $item['ncaa_area'] && 'Test Prep' !== $item['ncaa_area'] && 'Elective' !== $item['ncaa_area'] ) {
			$categories[] = 'NCAA Core';
		}
		if ( ! empty( $item['pathway_type'] ) ) {
			$categories[] = 'STEM Pathway';
		}

		$courses[] = array(
			'title'             => $title,
			'name'              => sanitize_title( $title ),
			'status'            => 'publish',
			'length'            => '18 Weeks',
			'enrollment_period' => 'no',
			'content'           => $course_intro,
			'categories'        => $categories,
			'tags'              => array(
				'Florida Standards',
				'OpenStax',
				$item['catalog_group'],
				sprintf( 'Semester %d', (int) $item['semester'] ),
				'0.5 Credit',
			),
			'tracks'            => array( $item['course_track'] ),
			'access_plans'      => array(
				array(
					'title'        => 'Free Enrollment',
					'name'         => 'free-enrollment',
					'status'       => 'publish',
					'is_free'      => 'yes',
					'availability' => 'open',
					'price'        => '0',
				),
			),
			'custom'            => array(
				'_llms_credit_value'              => array( '0.5' ),
				'_llms_semester'                  => array( (string) $item['semester'] ),
				'_llms_grade_level'               => array( (string) $item['grade'] ),
				'_llms_grade_band'                => array( $item['grade_band'] ),
				'_llms_state_standard_framework'  => array( implode( '; ', $item['standards'] ) ),
				'_llms_ncaa_core_area'            => array( $item['ncaa_area'] ),
				'_llms_openstax_textbook'         => array( $item['book'] ),
				'_llms_openstax_license'          => array( 'CC BY 4.0' ),
				'_llms_openstax_attribution'      => array( 'OpenStax, Rice University' ),
				'_llms_transcript_credit_hours'   => array( '0.5' ),
				'_llms_autograding_enabled'       => array( 'yes' ),
				'_llms_course_package_version'    => array( '2026.03-turnkey' ),
				'_llms_pathway_type'              => array( ! empty( $item['pathway_type'] ) ? $item['pathway_type'] : 'core' ),
			),
			'sections'          => llms_openstax_get_semester_sections(
				array(
					'book'      => $item['book'],
					'subject'   => $item['subject'],
					'semester'  => $item['semester'],
					'track'     => $item['course_track'],
					'grade_band' => $item['grade_band'],
					'catalog_group' => $item['catalog_group'],
					'ncaa_area' => $item['ncaa_area'],
					'standards' => $item['standards'],
				),
				( $course_index + 1 ) * 1000
			),
		);
	}

	return array(
		'_generator' => 'LifterLMS/BulkCourseGenerator',
		'_version'   => '1.0.0',
		'_source'    => 'OpenStax Florida 6-12 core and EOC/FSA test-prep half-credit package',
		'courses'    => $courses,
	);
}

$payload = llms_openstax_get_bulk_generator_payload();
$courses = (array) $payload['courses'];
$kept    = array();
$skipped = array();

foreach ( $courses as $course ) {
	$slug     = sanitize_title( $course['name'] );
	$existing = get_page_by_path( $slug, OBJECT, 'course' );
	if ( $existing instanceof WP_Post ) {
		$skipped[] = $course['title'];
		continue;
	}
	$kept[] = $course;
}

if ( empty( $kept ) ) {
	WP_CLI::success( 'No courses created. All catalog courses already exist.' );
	if ( $skipped ) {
		WP_CLI::log( sprintf( 'Skipped existing courses: %s', implode( '; ', $skipped ) ) );
	}
	return;
}

$payload['courses'] = $kept;

$generator = new LLMS_Generator( $payload );
$result    = $generator->set_generator( 'LifterLMS/BulkCourseGenerator' );

if ( is_wp_error( $result ) ) {
	WP_CLI::error( $result->get_error_message() );
}

$generator->generate();

if ( $generator->is_error() ) {
	WP_CLI::error( $generator->error->get_error_message() );
}

$generated = $generator->get_generated_content();
$count     = 0;
if ( ! empty( $generated['course'] ) && is_array( $generated['course'] ) ) {
	$count = count( $generated['course'] );
}

WP_CLI::success( sprintf( 'Generated %d OpenStax semester courses.', $count ) );

// Publish all draft sections and lessons created by the generator.
global $wpdb;
$published_sections = (int) $wpdb->query(
	"UPDATE {$wpdb->posts} SET post_status = 'publish' WHERE post_type = 'section' AND post_status = 'draft'"
);
$published_lessons  = (int) $wpdb->query(
	"UPDATE {$wpdb->posts} SET post_status = 'publish' WHERE post_type = 'lesson' AND post_status = 'draft'"
);
if ( $published_sections || $published_lessons ) {
	clean_post_cache( 0 );
	WP_CLI::log( sprintf( 'Published %d sections and %d lessons.', $published_sections, $published_lessons ) );
}

if ( $skipped ) {
	WP_CLI::log( sprintf( 'Skipped existing courses: %s', implode( '; ', $skipped ) ) );
}

$missing_courses = array();
foreach ( $courses as $course ) {
	$slug = sanitize_title( $course['name'] );
	if ( ! get_page_by_path( $slug, OBJECT, 'course' ) ) {
		$missing_courses[] = $course['title'];
	}
}

if ( $missing_courses ) {
	WP_CLI::error(
		sprintf(
			'Course build incomplete. Missing %1$d courses: %2$s',
			count( $missing_courses ),
			implode( '; ', $missing_courses )
		)
	);
}

WP_CLI::success( sprintf( 'Catalog verification complete. All %d semester courses are present.', count( $courses ) ) );
