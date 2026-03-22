<?php
/**
 * Verify OpenStax-based Florida 9-12 semester courses in LifterLMS.
 *
 * Usage:
 *   wp eval-file sample-data/verify-openstax-florida.php
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "This script must be executed with WP-CLI.\n";
	return;
}

if ( ! class_exists( 'LLMS_Course' ) ) {
	WP_CLI::error( 'LLMS_Course model is not available. Activate LifterLMS first.' );
}

require_once __DIR__ . '/openstax-course-catalog.php';

$required_meta = array(
	'_llms_credit_value',
	'_llms_semester',
	'_llms_grade_level',
	'_llms_grade_band',
	'_llms_state_standard_framework',
	'_llms_ncaa_core_area',
	'_llms_openstax_textbook',
	'_llms_openstax_license',
	'_llms_openstax_attribution',
	'_llms_transcript_credit_hours',
	'_llms_autograding_enabled',
);

$catalog = llms_openstax_expected_catalog_entries();
$errors  = array();

foreach ( $catalog as $entry ) {
	$title = sprintf(
		'%1$s - Semester %2$d (Grade %3$d, 0.5 Credit)',
		$entry['subject'],
		$entry['semester'],
		$entry['grade']
	);
	$slug  = sanitize_title( $title );
	$post  = get_page_by_path( $slug, OBJECT, 'course' );

	if ( ! ( $post instanceof WP_Post ) ) {
		$errors[] = sprintf( 'Missing course: %s', $title );
		continue;
	}

	$course = new LLMS_Course( $post->ID );

	$sections_query = new WP_Query(
		array(
			'post_type'              => 'section',
			'post_status'            => 'any',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'   => '_llms_parent_course',
					'value' => $post->ID,
				),
			),
		)
	);

	$lessons_query = new WP_Query(
		array(
			'post_type'              => 'lesson',
			'post_status'            => 'any',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'   => '_llms_parent_course',
					'value' => $post->ID,
				),
			),
		)
	);

	$free_plans_query = new WP_Query(
		array(
			'post_type'              => 'llms_access_plan',
			'post_status'            => 'any',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'   => '_llms_product_id',
					'value' => $post->ID,
				),
				array(
					'key'   => '_llms_is_free',
					'value' => 'yes',
				),
			),
		)
	);

	$quiz_lessons_query = new WP_Query(
		array(
			'post_type'              => 'lesson',
			'post_status'            => 'any',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'   => '_llms_parent_course',
					'value' => $post->ID,
				),
				array(
					'key'   => '_llms_quiz_enabled',
					'value' => 'yes',
				),
			),
		)
	);

	$sections_count     = (int) $sections_query->post_count;
	$lessons_count      = (int) $lessons_query->post_count;
	$free_plans_count   = (int) $free_plans_query->post_count;
	$quiz_lessons_count = (int) $quiz_lessons_query->post_count;

	if ( $sections_count < 3 ) {
		$errors[] = sprintf( 'Course has too few sections (%1$d): %2$s', $sections_count, $title );
	}

	if ( $lessons_count < 12 ) {
		$errors[] = sprintf( 'Course has too few lessons (%1$d): %2$s', $lessons_count, $title );
	}

	if ( $free_plans_count < 1 ) {
		$errors[] = sprintf( 'Course is missing a free access plan: %s', $title );
	}

	$quiz_lessons = $quiz_lessons_count;
	if ( $quiz_lessons < 3 ) {
		$errors[] = sprintf( 'Course has too few quiz lessons (%1$d): %2$s', $quiz_lessons, $title );
	}

	foreach ( $required_meta as $meta_key ) {
		$meta_value = get_post_meta( $post->ID, $meta_key, true );
		if ( '' === (string) $meta_value ) {
			$errors[] = sprintf( 'Course is missing required meta %1$s: %2$s', $meta_key, $title );
		}
	}

	// Verify progression rules: quiz lessons must require passing grade.
	if ( $quiz_lessons_count >= 1 ) {
		$quiz_lesson_ids = $quiz_lessons_query->posts;
		foreach ( $quiz_lesson_ids as $ql_id ) {
			$rpg = get_post_meta( $ql_id, '_llms_require_passing_grade', true );
			if ( 'yes' !== $rpg ) {
				$errors[] = sprintf( 'Quiz lesson (ID %d) does not require passing grade: %s', $ql_id, $title );
			}
		}
	}

	// Verify progression rules: quizzes must have passing_percent <= 69.
	if ( $quiz_lessons_count >= 1 ) {
		foreach ( $quiz_lessons_query->posts as $ql_id ) {
			$quiz_id = get_post_meta( $ql_id, '_llms_quiz', true );
			if ( $quiz_id ) {
				$pct = (float) get_post_meta( $quiz_id, '_llms_passing_percent', true );
				if ( $pct < 60 || $pct > 69 ) {
					$errors[] = sprintf( 'Quiz (ID %d) passing percent %.0f%% is outside 60-69%% range: %s', $quiz_id, $pct, $title );
				}
			}
		}
	}
}

if ( $errors ) {
	WP_CLI::error( "Verification failed:\n- " . implode( "\n- ", $errors ) );
}

WP_CLI::success( sprintf( 'Verification successful. All %d courses are complete and functional.', count( $catalog ) ) );
