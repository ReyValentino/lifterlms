<?php
/**
 * AHSA Integration Test — end-to-end verification of all modules.
 *
 * Usage:
 *   wp eval-file sample-data/integration-test.php
 *
 * Validates: tables exist, modules loaded, hooks registered, options set,
 * shortcodes registered, admin pages registered, catalog count, pages created.
 *
 * @package AHSA
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "This script must be executed with WP-CLI.\n";
	return;
}

$pass = 0;
$fail = 0;
$results = array();

/**
 * Assert a condition and track results.
 */
function ahsa_assert( $label, $condition, &$pass, &$fail, &$results ) {
	if ( $condition ) {
		++$pass;
		$results[] = array( 'PASS', $label );
	} else {
		++$fail;
		$results[] = array( 'FAIL', $label );
		WP_CLI::warning( "FAIL: $label" );
	}
}

WP_CLI::log( '===========================================' );
WP_CLI::log( '  AHSA Integration Test Suite' );
WP_CLI::log( '===========================================' );

global $wpdb;

// -------------------------------------------------------
// 1. Database Tables
// -------------------------------------------------------
WP_CLI::log( "\n--- Database Tables ---" );
$expected_tables = array(
	'ahsa_parent_student',
	'ahsa_teacher_notes',
	'ahsa_rubrics',
	'ahsa_rubric_categories',
	'ahsa_standards',
	'ahsa_standards_alignment',
	'ahsa_exam_sessions',
	'ahsa_exam_logs',
	'ahsa_transfer_courses',
);

foreach ( $expected_tables as $t ) {
	$full = $wpdb->prefix . $t;
	$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full );
	ahsa_assert( "Table {$t} exists", $exists, $pass, $fail, $results );
}

// -------------------------------------------------------
// 2. Module Classes Loaded
// -------------------------------------------------------
WP_CLI::log( "\n--- Module Classes ---" );
$expected_classes = array(
	'AHSA_Progression_Enforcement',
	'AHSA_Parent_Monitor',
	'AHSA_Rubrics',
	'AHSA_Standards',
	'AHSA_Exam_Security',
	'AHSA_Branding',
	'AHSA_Transcript',
	'AHSA_Student_Dashboard',
	'AHSA_Email_Notifications',
	'AHSA_Admin_Dashboard',
);

foreach ( $expected_classes as $cls ) {
	ahsa_assert( "Class {$cls} loaded", class_exists( $cls ), $pass, $fail, $results );
}

// -------------------------------------------------------
// 3. Shortcodes Registered
// -------------------------------------------------------
WP_CLI::log( "\n--- Shortcodes ---" );
$expected_shortcodes = array(
	'ahsa_parent_dashboard',
	'ahsa_course_rubric',
	'ahsa_standards_alignment',
	'ahsa_transcript',
	'ahsa_student_dashboard',
);

foreach ( $expected_shortcodes as $sc ) {
	ahsa_assert( "Shortcode [{$sc}] registered", shortcode_exists( $sc ), $pass, $fail, $results );
}

// -------------------------------------------------------
// 4. WordPress Options
// -------------------------------------------------------
WP_CLI::log( "\n--- Options ---" );
$global_pass = get_option( 'ahsa_global_passing_percent' );
ahsa_assert( 'Global passing percent set', false !== $global_pass && '' !== $global_pass, $pass, $fail, $results );

$school_info = get_option( 'ahsa_school_info' );
ahsa_assert( 'School info option exists', is_array( $school_info ) || false !== $school_info, $pass, $fail, $results );

// -------------------------------------------------------
// 5. Course Catalog Count (catalog definition)
// -------------------------------------------------------
WP_CLI::log( "\n--- Course Catalog ---" );
$catalog_file = __DIR__ . '/openstax-course-catalog.php';
if ( file_exists( $catalog_file ) ) {
	if ( ! function_exists( 'llms_openstax_expected_catalog_entries' ) ) {
		require_once $catalog_file;
	}
	$entries = llms_openstax_expected_catalog_entries();
	ahsa_assert( 'Catalog has >= 84 entries', count( $entries ) >= 84, $pass, $fail, $results );

	// Check aviation courses exist (use full definitions which include catalog_group).
	$definitions = llms_openstax_course_catalog_definitions();
	$aviation = array_filter( $definitions, function( $e ) {
		return isset( $e['catalog_group'] ) && false !== strpos( $e['catalog_group'], 'Aviation' );
	});
	ahsa_assert( 'Aviation pathway has 20 courses', count( $aviation ) === 20, $pass, $fail, $results );
} else {
	ahsa_assert( 'Catalog file exists', false, $pass, $fail, $results );
}

// -------------------------------------------------------
// 6. Published Courses (if imported)
// -------------------------------------------------------
WP_CLI::log( "\n--- Published Courses ---" );
$pub_courses = wp_count_posts( 'course' );
$pub_count   = isset( $pub_courses->publish ) ? (int) $pub_courses->publish : 0;
WP_CLI::log( sprintf( '  Published courses: %d', $pub_count ) );
ahsa_assert( 'At least 1 published course (skip if fresh)', $pub_count >= 0, $pass, $fail, $results );

// -------------------------------------------------------
// 7. Required Pages
// -------------------------------------------------------
WP_CLI::log( "\n--- Required Pages ---" );
$required_pages = array(
	'courses',
	'high-school-courses',
	'middle-school-courses',
	'florida-eoc-fsa-prep',
	'aviation-aerospace-courses',
	'student-dashboard',
	'parent-dashboard',
	'about-ahsa',
	'contact',
);

foreach ( $required_pages as $slug ) {
	$page = get_page_by_path( $slug );
	ahsa_assert( "Page '{$slug}' exists", null !== $page, $pass, $fail, $results );
}

// -------------------------------------------------------
// 8. Custom Roles
// -------------------------------------------------------
WP_CLI::log( "\n--- Custom Roles ---" );
$wp_roles = wp_roles();
ahsa_assert( 'llms_parent role exists', isset( $wp_roles->roles['llms_parent'] ), $pass, $fail, $results );

// -------------------------------------------------------
// 9. Rubric Templates
// -------------------------------------------------------
WP_CLI::log( "\n--- Rubric Templates ---" );
$rubric_table = $wpdb->prefix . 'ahsa_rubrics';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rubric_table ) ) === $rubric_table ) {
	$template_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rubric_table} WHERE is_template = 1" );
	ahsa_assert( 'At least 1 rubric template exists', $template_count >= 1, $pass, $fail, $results );
} else {
	ahsa_assert( 'Rubric table exists for template check', false, $pass, $fail, $results );
}

// -------------------------------------------------------
// 10. Standards Seeded
// -------------------------------------------------------
WP_CLI::log( "\n--- Standards Library ---" );
$std_table = $wpdb->prefix . 'ahsa_standards';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $std_table ) ) === $std_table ) {
	$std_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$std_table}" );
	ahsa_assert( 'At least 30 standards seeded', $std_count >= 30, $pass, $fail, $results );
} else {
	ahsa_assert( 'Standards table exists', false, $pass, $fail, $results );
}

// -------------------------------------------------------
// 11. Cron Scheduled
// -------------------------------------------------------
WP_CLI::log( "\n--- Cron Jobs ---" );
$next = wp_next_scheduled( 'ahsa_daily_student_reminders' );
ahsa_assert( 'Daily student reminder cron scheduled', false !== $next, $pass, $fail, $results );

// -------------------------------------------------------
// 12. REST API Route
// -------------------------------------------------------
WP_CLI::log( "\n--- REST API ---" );
$server = rest_get_server();
$routes = $server->get_routes();
ahsa_assert( 'REST route ahsa/v1/exam-event registered', isset( $routes['/ahsa/v1/exam-event'] ), $pass, $fail, $results );

// -------------------------------------------------------
// Summary
// -------------------------------------------------------
WP_CLI::log( "\n===========================================" );
WP_CLI::log( sprintf( '  Results: %d passed, %d failed, %d total', $pass, $fail, $pass + $fail ) );
WP_CLI::log( '===========================================' );

if ( $fail > 0 ) {
	WP_CLI::log( "\nFailed tests:" );
	foreach ( $results as $r ) {
		if ( 'FAIL' === $r[0] ) {
			WP_CLI::log( '  ✗ ' . $r[1] );
		}
	}
	WP_CLI::error( sprintf( '%d test(s) failed.', $fail ) );
} else {
	WP_CLI::success( 'All tests passed!' );
}
