<?php
/**
 * Enforce strict progression rules on all existing AHSA courses.
 *
 * This script retroactively sets:
 * - Sequential lesson prerequisites within each course
 * - require_passing_grade = 'yes' on all quiz-enabled lessons
 * - passing_percent = 60 on all quizzes (D-level threshold)
 * - Global default passing percentage option
 *
 * Usage:
 *   wp eval-file sample-data/setup-progression-rules.php
 *
 * Safe to run multiple times (idempotent).
 *
 * @package AHSA/Setup
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "This script must be executed with WP-CLI.\n";
	return;
}

$default_passing_percent = 60;

// Set global option if not already set.
if ( false === get_option( 'ahsa_global_passing_percent' ) ) {
	update_option( 'ahsa_global_passing_percent', $default_passing_percent );
	WP_CLI::log( sprintf( 'Set global passing percentage to %d%%.', $default_passing_percent ) );
}

// Get all published courses.
$courses = get_posts( array(
	'post_type'      => 'course',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
) );

if ( empty( $courses ) ) {
	WP_CLI::warning( 'No courses found.' );
	return;
}

$total_courses       = count( $courses );
$lessons_updated     = 0;
$quizzes_updated     = 0;
$prereqs_set         = 0;

foreach ( $courses as $course_id ) {
	// Get all sections in order.
	$sections = get_posts( array(
		'post_type'      => 'section',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'meta_value_num',
		'meta_key'       => '_llms_order',
		'order'          => 'ASC',
		'meta_query'     => array(
			array(
				'key'   => '_llms_parent_course',
				'value' => $course_id,
			),
		),
	) );

	$prev_lesson_id = null;

	foreach ( $sections as $section_id ) {
		// Get all lessons in this section in order.
		$lessons = get_posts( array(
			'post_type'      => 'lesson',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_llms_order',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'   => '_llms_parent_section',
					'value' => $section_id,
				),
			),
		) );

		foreach ( $lessons as $lesson_id ) {
			$lesson = new LLMS_Lesson( $lesson_id );

			// Set prerequisite to previous lesson if not already set.
			if ( null !== $prev_lesson_id ) {
				$current_prereq = $lesson->get( 'prerequisite' );
				if ( ! $current_prereq || (int) $current_prereq !== $prev_lesson_id ) {
					$lesson->set( 'has_prerequisite', 'yes' );
					$lesson->set( 'prerequisite', $prev_lesson_id );
					$prereqs_set++;
				}
			}

			// Set require_passing_grade on quiz-enabled lessons.
			$quiz_enabled = $lesson->get( 'quiz_enabled' );
			if ( 'yes' === $quiz_enabled ) {
				$current_rpg = $lesson->get( 'require_passing_grade' );
				if ( 'yes' !== $current_rpg ) {
					$lesson->set( 'require_passing_grade', 'yes' );
					$lessons_updated++;
				}

				// Update quiz passing percent.
				$quiz_id = $lesson->get( 'quiz' );
				if ( $quiz_id ) {
					$quiz = llms_get_post( $quiz_id );
					if ( $quiz && is_a( $quiz, 'LLMS_Quiz' ) ) {
						$current_percent = (float) $quiz->get( 'passing_percent' );
						if ( $current_percent !== (float) $default_passing_percent ) {
							$quiz->set( 'passing_percent', $default_passing_percent );
							$quizzes_updated++;
						}
					}
				}
			}

			$prev_lesson_id = $lesson_id;
		}
	}
}

WP_CLI::success(
	sprintf(
		'Progression rules enforced across %d courses: %d prerequisites set, %d quiz lessons require passing grade, %d quizzes updated to %d%% minimum.',
		$total_courses,
		$prereqs_set,
		$lessons_updated,
		$quizzes_updated,
		$default_passing_percent
	)
);
