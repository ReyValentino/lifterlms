<?php
/**
 * Export OpenStax Florida catalog compliance report as CSV.
 *
 * Usage:
 *   wp eval-file sample-data/export-openstax-compliance-report.php
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "This script must be executed with WP-CLI.\n";
	return;
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

$upload_dir = wp_upload_dir();
if ( empty( $upload_dir['basedir'] ) || ! is_dir( $upload_dir['basedir'] ) ) {
	WP_CLI::error( 'Unable to locate uploads directory.' );
}

$report_dir = trailingslashit( $upload_dir['basedir'] ) . 'openstax-reports';
if ( ! is_dir( $report_dir ) ) {
	wp_mkdir_p( $report_dir );
}

$timestamp  = gmdate( 'Ymd-His' );
$reportfile = trailingslashit( $report_dir ) . "openstax-compliance-{$timestamp}.csv";
$fp         = fopen( $reportfile, 'w' );
if ( ! $fp ) {
	WP_CLI::error( 'Could not create report file.' );
}

fputcsv(
	$fp,
	array(
		'course_title',
		'course_slug',
		'course_exists',
		'sections_count',
		'lessons_count',
		'quiz_lessons_count',
		'free_access_plans_count',
		'missing_required_meta_keys',
		'pathway_type',
		'progression_enforced',
		'passing_percent',
		'rubric_assigned',
		'standards_aligned_count',
		'seb_required',
		'status',
	)
);

$catalog      = llms_openstax_expected_catalog_entries();
$total        = count( $catalog );
$pass_count   = 0;
$fail_count   = 0;

foreach ( $catalog as $entry ) {
	$title = sprintf(
		'%1$s - Semester %2$d (Grade %3$d, 0.5 Credit)',
		$entry['subject'],
		$entry['semester'],
		$entry['grade']
	);
	$slug  = sanitize_title( $title );
	$post  = get_page_by_path( $slug, OBJECT, 'course' );

	$exists            = $post instanceof WP_Post;
	$sections_count    = 0;
	$lessons_count     = 0;
	$quiz_lessons_count= 0;
	$free_plans_count  = 0;
	$missing_meta      = array();
	$pathway_type      = '';
	$progression_enforced = '';
	$passing_percent   = '';
	$rubric_assigned   = '';
	$standards_count   = 0;
	$seb_required      = '';

	if ( $exists ) {
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

		$plans_query = new WP_Query(
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

		$sections_count     = (int) $sections_query->post_count;
		$lessons_count      = (int) $lessons_query->post_count;
		$quiz_lessons_count = (int) $quiz_lessons_query->post_count;
		$free_plans_count   = (int) $plans_query->post_count;

		foreach ( $required_meta as $meta_key ) {
			$meta_value = get_post_meta( $post->ID, $meta_key, true );
			if ( '' === (string) $meta_value ) {
				$missing_meta[] = $meta_key;
			}
		}

		// Pathway type (core / elective).
		$pathway_type = get_post_meta( $post->ID, '_llms_pathway_type', true );
		if ( '' === $pathway_type ) {
			$pathway_type = 'core';
		}

		// Progression enforcement.
		$progression_enforced = get_post_meta( $post->ID, '_ahsa_progression_enforced', true );
		$progression_enforced = ( 'yes' === $progression_enforced || '' === $progression_enforced ) ? 'yes' : 'no';

		// Passing percent.
		$passing_percent = get_post_meta( $post->ID, '_ahsa_passing_percent', true );
		if ( '' === $passing_percent ) {
			$passing_percent = get_option( 'ahsa_global_passing_percent', '60' );
		}

		// Rubric assignment (stored in ahsa_rubrics table, not post meta).
		global $wpdb;
		$rubric_table    = $wpdb->prefix . 'ahsa_rubrics';
		$rubric_id       = '';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rubric_table ) ) === $rubric_table ) {
			$rubric_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$rubric_table} WHERE course_id = %d AND is_template = 0 LIMIT 1",
				$post->ID
			) );
		}
		$rubric_assigned = ! empty( $rubric_id ) ? 'yes' : 'no';

		// Standards alignment count.
		$alignment_table  = $wpdb->prefix . 'ahsa_standards_alignment';
		$standards_count  = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $alignment_table ) ) === $alignment_table ) {
			$standards_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$alignment_table} WHERE post_id = %d AND post_type = 'course'",
				$post->ID
			) );
		}

		// SEB exam security requirement (stored on quizzes, not courses).
		$seb_required = 'no';
		if ( $quiz_lessons_count > 0 ) {
			$seb_quiz_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm.meta_value
				 WHERE pm.meta_key = '_llms_quiz'
				   AND pm2.meta_key = '_ahsa_seb_required'
				   AND pm2.meta_value = 'yes'
				   AND pm.post_id IN (
				     SELECT post_id FROM {$wpdb->postmeta}
				     WHERE meta_key = '_llms_parent_course' AND meta_value = %d
				   )",
				$post->ID
			) );
			$seb_required = $seb_quiz_count > 0 ? 'yes' : 'no';
		}
	}

	$pass = $exists
		&& $sections_count >= 3
		&& $lessons_count >= 12
		&& $quiz_lessons_count >= 3
		&& $free_plans_count >= 1
		&& empty( $missing_meta )
		&& 'yes' === $rubric_assigned
		&& $standards_count > 0;

	if ( $pass ) {
		++$pass_count;
	} else {
		++$fail_count;
	}

	fputcsv(
		$fp,
		array(
			$title,
			$slug,
			$exists ? 'yes' : 'no',
			$sections_count,
			$lessons_count,
			$quiz_lessons_count,
			$free_plans_count,
			implode( '|', $missing_meta ),
			$pathway_type,
			$progression_enforced,
			$passing_percent,
			$rubric_assigned,
			$standards_count,
			$seb_required,
			$pass ? 'PASS' : 'FAIL',
		)
	);
}

fclose( $fp );

WP_CLI::success( sprintf( 'Compliance report created: %s', $reportfile ) );
WP_CLI::log( sprintf( 'Summary: total=%d pass=%d fail=%d', $total, $pass_count, $fail_count ) );
if ( $fail_count > 0 ) {
	WP_CLI::warning( 'One or more courses failed compliance checks. Review the CSV report.' );
}
