<?php
/**
 * Set up rubric templates and assign to all AHSA courses.
 *
 * Usage:
 *   wp eval-file sample-data/setup-rubrics.php
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

require_once __DIR__ . '/modules/class-ahsa-rubrics.php';

$rubrics = AHSA_Rubrics::instance();
$rubrics->create_tables();
WP_CLI::log( 'Rubric tables created/verified.' );

// Create template for core academic courses.
global $wpdb;
$existing_template = $wpdb->get_var(
	"SELECT id FROM {$wpdb->prefix}ahsa_rubrics WHERE is_template = 1 AND title = 'AHSA Core Academic Rubric' LIMIT 1"
);

if ( ! $existing_template ) {
	$template_id = $rubrics->create_rubric( array(
		'title'       => 'AHSA Core Academic Rubric',
		'description' => 'Standard rubric for all AHSA core academic courses. Categories cover content mastery, assignments, quizzes/exams, participation, projects, and academic integrity.',
		'is_template' => 1,
	) );
	WP_CLI::log( sprintf( 'Core academic rubric template created (ID: %d).', $template_id ) );
} else {
	$template_id = (int) $existing_template;
	WP_CLI::log( 'Core academic rubric template already exists.' );
}

// Create template for aviation/STEM pathway.
$existing_aviation = $wpdb->get_var(
	"SELECT id FROM {$wpdb->prefix}ahsa_rubrics WHERE is_template = 1 AND title = 'AHSA Aviation/STEM Pathway Rubric' LIMIT 1"
);

if ( ! $existing_aviation ) {
	$aviation_template_id = $rubrics->create_rubric( array(
		'title'       => 'AHSA Aviation/STEM Pathway Rubric',
		'description' => 'Rubric for aviation, aerospace, and drone pathway courses with emphasis on applied projects and technical mastery.',
		'is_template' => 1,
		'categories'  => array(
			'content_mastery'    => array( 'label' => 'Technical Knowledge', 'weight' => 25, 'description' => 'Demonstrates understanding of aviation/aerospace concepts and FAA knowledge areas' ),
			'assignments'        => array( 'label' => 'Assignments', 'weight' => 20, 'description' => 'Timely and accurate completion of coursework, flight planning, and technical deliverables' ),
			'quizzes_exams'      => array( 'label' => 'Quizzes / Exams', 'weight' => 25, 'description' => 'Performance on knowledge checks and certification-style assessments' ),
			'participation'      => array( 'label' => 'Professional Conduct', 'weight' => 5, 'description' => 'Aviation safety culture, communication, and professional behaviors' ),
			'projects'           => array( 'label' => 'Applied Projects', 'weight' => 20, 'description' => 'Flight simulations, drone operations, engineering design projects, and capstone work' ),
			'academic_integrity' => array( 'label' => 'Academic Integrity', 'weight' => 5, 'description' => 'Original work, proper attribution, and adherence to academic honesty policies' ),
		),
	) );
	WP_CLI::log( sprintf( 'Aviation/STEM rubric template created (ID: %d).', $aviation_template_id ) );
} else {
	$aviation_template_id = (int) $existing_aviation;
	WP_CLI::log( 'Aviation/STEM rubric template already exists.' );
}

// Assign rubrics to all courses that don't already have one.
$courses = get_posts( array(
	'post_type'      => 'course',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'fields'         => 'ids',
) );

$assigned = 0;
foreach ( $courses as $course_id ) {
	$existing_rubric = $rubrics->get_course_rubric( $course_id );
	if ( $existing_rubric ) {
		continue;
	}

	$pathway = get_post_meta( $course_id, '_llms_pathway_type', true );
	$catalog_group = '';
	$terms = wp_get_post_terms( $course_id, 'course_cat', array( 'fields' => 'names' ) );
	if ( is_array( $terms ) ) {
		foreach ( $terms as $term ) {
			if ( false !== stripos( $term, 'Aviation' ) ) {
				$catalog_group = 'aviation';
				break;
			}
		}
	}

	if ( 'aviation' === $catalog_group || 'elective' === $pathway ) {
		$rubrics->clone_to_course( $aviation_template_id, $course_id );
	} else {
		$rubrics->clone_to_course( $template_id, $course_id );
	}
	$assigned++;
}

WP_CLI::log( sprintf( 'Assigned rubrics to %d courses.', $assigned ) );
WP_CLI::success( 'Rubric setup complete.' );
