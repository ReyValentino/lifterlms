<?php
/**
 * Export AHSA academic progress summary report.
 *
 * Generates a CSV with per-student academic progress including:
 * - Enrollment counts and completion rates
 * - GPA calculations
 * - Blocked progression details
 * - Transfer credit summary
 * - Standards/rubric compliance readiness
 *
 * Usage:
 *   wp eval-file sample-data/export-academic-progress-report.php
 *
 * @package AHSA/Reports
 * @since   2.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "This script must be executed with WP-CLI.\n";
	return;
}

// Ensure transcript module is available for GPA calculations.
$transcript_file = __DIR__ . '/modules/class-ahsa-transcript.php';
if ( is_readable( $transcript_file ) && ! class_exists( 'AHSA_Transcript' ) ) {
	require_once $transcript_file;
}

// -------------------------------------------------------------------------
// Setup report output
// -------------------------------------------------------------------------
$upload_dir = wp_upload_dir();
if ( empty( $upload_dir['basedir'] ) || ! is_dir( $upload_dir['basedir'] ) ) {
	WP_CLI::error( 'Unable to locate uploads directory.' );
}

$report_dir = trailingslashit( $upload_dir['basedir'] ) . 'openstax-reports';
if ( ! is_dir( $report_dir ) ) {
	wp_mkdir_p( $report_dir );
}

$timestamp = gmdate( 'Ymd-His' );

// -------------------------------------------------------------------------
// Part 1: Student Academic Progress
// -------------------------------------------------------------------------
$student_file = trailingslashit( $report_dir ) . "academic-progress-{$timestamp}.csv";
$fp = fopen( $student_file, 'w' );
if ( ! $fp ) {
	WP_CLI::error( 'Could not create student progress report file.' );
}

fputcsv( $fp, array(
	'student_id',
	'display_name',
	'email',
	'enrolled_courses',
	'completed_courses',
	'in_progress_courses',
	'completion_rate_pct',
	'total_credits_earned',
	'cumulative_gpa',
	'transfer_courses',
	'transfer_credits',
	'total_credits',
	'blocked_courses',
	'blocked_reasons',
	'grade_level',
	'expected_graduation',
	'status',
) );

// Find all students (subscribers + any LifterLMS student role).
$student_users = get_users( array(
	'role__in'    => array( 'subscriber', 'student' ),
	'number'      => 500,
	'orderby'     => 'display_name',
	'order'       => 'ASC',
	'fields'      => 'all',
) );

$total_students  = count( $student_users );
$on_track_count  = 0;
$behind_count    = 0;

$global_passing = (int) get_option( 'ahsa_global_passing_percent', 60 );

foreach ( $student_users as $user ) {
	$student_id = $user->ID;

	// Enrollment data from LifterLMS.
	$enrolled_ids = array();
	if ( function_exists( 'llms_get_enrolled_courses' ) ) {
		$enrolled_ids = llms_get_enrolled_courses( $student_id );
	} elseif ( class_exists( 'LLMS_Student' ) ) {
		$student_obj = new LLMS_Student( $student_id );
		$enrollments = $student_obj->get_enrollments( 'course', array( 'limit' => 500 ) );
		$enrolled_ids = isset( $enrollments['results'] ) ? $enrollments['results'] : array();
	}

	$enrolled_count   = count( $enrolled_ids );
	$completed_count  = 0;
	$in_progress      = 0;
	$credits_earned   = 0.0;
	$blocked_courses  = array();

	foreach ( $enrolled_ids as $course_id ) {
		$progress = 0;
		if ( function_exists( 'llms_get_student_progress' ) ) {
			$progress = (float) llms_get_student_progress( $student_id, $course_id, 'course' );
		} elseif ( class_exists( 'LLMS_Student' ) ) {
			$s = new LLMS_Student( $student_id );
			$progress = (float) $s->get_progress( $course_id, 'course' );
		}

		$credit = (float) get_post_meta( $course_id, '_llms_credit_value', true );

		if ( $progress >= 100 ) {
			++$completed_count;
			$credits_earned += $credit ?: 0.5;
		} else {
			++$in_progress;

			// Check for blocked progression.
			$enforced = get_post_meta( $course_id, '_ahsa_progression_enforced', true );
			if ( 'yes' === $enforced || '' === $enforced ) {
				$pass_pct = get_post_meta( $course_id, '_ahsa_passing_percent', true );
				if ( '' === $pass_pct ) {
					$pass_pct = $global_passing;
				}
				if ( $progress > 0 && $progress < 100 ) {
					$course_title = get_the_title( $course_id );
					$blocked_courses[] = sprintf( '%s (%.0f%% / %d%% required)', $course_title, $progress, $pass_pct );
				}
			}
		}
	}

	$completion_rate = $enrolled_count > 0 ? round( ( $completed_count / $enrolled_count ) * 100, 1 ) : 0;

	// GPA via transcript module.
	$gpa = 0.0;
	if ( class_exists( 'AHSA_Transcript' ) ) {
		$transcript      = AHSA_Transcript::instance();
		$transcript_data = $transcript->build_transcript_data( $student_id );
		$all_courses     = array();
		foreach ( $transcript_data as $courses_by_semester ) {
			foreach ( $courses_by_semester as $courses ) {
				$all_courses = array_merge( $all_courses, $courses );
			}
		}
		$gpa_data = $transcript->calculate_gpa( $all_courses );
		$gpa      = isset( $gpa_data['gpa'] ) ? $gpa_data['gpa'] : 0.0;
	}

	// Transfer course data.
	$transfer_count   = 0;
	$transfer_credits = 0.0;
	global $wpdb;
	$transfer_table = $wpdb->prefix . 'ahsa_transfer_courses';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $transfer_table ) ) === $transfer_table ) {
		$transfer_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(credit_hours), 0) AS credits FROM {$transfer_table} WHERE student_user_id = %d",
			$student_id
		) );
		if ( $transfer_row ) {
			$transfer_count   = (int) $transfer_row->cnt;
			$transfer_credits = (float) $transfer_row->credits;
		}
	}

	$total_credits = $credits_earned + $transfer_credits;

	// Student transcript meta.
	$grade_level          = get_user_meta( $student_id, '_ahsa_transcript_grade_level', true );
	$expected_graduation  = get_user_meta( $student_id, '_ahsa_transcript_expected_graduation', true );

	// Status determination.
	$status = 'on-track';
	if ( ! empty( $blocked_courses ) ) {
		$status = 'blocked';
		++$behind_count;
	} elseif ( $enrolled_count > 0 && $completion_rate < 25 && $in_progress > 0 ) {
		$status = 'behind';
		++$behind_count;
	} else {
		++$on_track_count;
	}

	fputcsv( $fp, array(
		$student_id,
		$user->display_name,
		$user->user_email,
		$enrolled_count,
		$completed_count,
		$in_progress,
		$completion_rate,
		$credits_earned,
		round( $gpa, 2 ),
		$transfer_count,
		$transfer_credits,
		$total_credits,
		count( $blocked_courses ),
		implode( ' | ', $blocked_courses ),
		$grade_level ?: 'N/A',
		$expected_graduation ?: 'N/A',
		$status,
	) );
}

fclose( $fp );

WP_CLI::success( sprintf( 'Academic progress report: %s', $student_file ) );
WP_CLI::log( sprintf( 'Students: %d total, %d on-track, %d behind/blocked', $total_students, $on_track_count, $behind_count ) );

// -------------------------------------------------------------------------
// Part 2: Course Compliance Readiness Summary
// -------------------------------------------------------------------------
$summary_file = trailingslashit( $report_dir ) . "compliance-readiness-{$timestamp}.csv";
$sfp = fopen( $summary_file, 'w' );
if ( ! $sfp ) {
	WP_CLI::error( 'Could not create compliance readiness file.' );
}

fputcsv( $sfp, array(
	'metric',
	'value',
	'status',
) );

// Gather system-wide metrics.
$published_courses = (int) wp_count_posts( 'course' )->publish;
$total_quizzes     = (int) wp_count_posts( 'llms_quiz' )->publish;

// Rubric coverage.
$rubric_count = 0;
$rubric_table = $wpdb->prefix . 'ahsa_rubrics';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rubric_table ) ) === $rubric_table ) {
	$rubric_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT course_id) FROM {$rubric_table} WHERE course_id > 0" );
}
$rubric_pct = $published_courses > 0 ? round( ( $rubric_count / $published_courses ) * 100, 1 ) : 0;

// Standards coverage.
$standards_count = 0;
$alignment_table = $wpdb->prefix . 'ahsa_standards_alignment';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $alignment_table ) ) === $alignment_table ) {
	$standards_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$alignment_table} WHERE post_type = 'course'" );
}
$standards_pct = $published_courses > 0 ? round( ( $standards_count / $published_courses ) * 100, 1 ) : 0;

// SEB coverage.
$seb_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ahsa_seb_required' AND meta_value = 'yes'"
);
$seb_pct = $total_quizzes > 0 ? round( ( $seb_count / $total_quizzes ) * 100, 1 ) : 0;

// Progression enforcement coverage.
$enforced_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
	 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_type = 'course' AND p.post_status = 'publish'
	 WHERE pm.meta_key = '_ahsa_progression_enforced' AND pm.meta_value = 'yes'"
);

// Exam sessions.
$sessions_table = $wpdb->prefix . 'ahsa_exam_sessions';
$total_sessions = 0;
$flagged_sessions = 0;
$unreviewed = 0;
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) ) === $sessions_table ) {
	$total_sessions   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sessions_table}" );
	$flagged_sessions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sessions_table} WHERE risk_level IN ('moderate','high','critical')" );
	$unreviewed       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sessions_table} WHERE risk_level != 'low' AND admin_reviewed = 0" );
}

// Transfer courses.
$transfer_total = 0;
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $transfer_table ) ) === $transfer_table ) {
	$transfer_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$transfer_table}" );
}

// Parent accounts.
$parent_count = 0;
$parent_role = get_role( 'ahsa_parent' );
if ( $parent_role ) {
	$parent_count = (int) ( new WP_User_Query( array( 'role' => 'ahsa_parent', 'count_total' => true, 'number' => 0 ) ) )->get_total();
}

// Write summary rows.
$metrics = array(
	array( 'Published Courses', $published_courses, $published_courses >= 84 ? 'PASS' : 'WARN' ),
	array( 'Total Quizzes', $total_quizzes, $total_quizzes > 0 ? 'PASS' : 'WARN' ),
	array( 'Courses with Rubrics', sprintf( '%d / %d (%s%%)', $rubric_count, $published_courses, $rubric_pct ), $rubric_pct >= 80 ? 'PASS' : 'WARN' ),
	array( 'Courses with Standards', sprintf( '%d / %d (%s%%)', $standards_count, $published_courses, $standards_pct ), $standards_pct >= 80 ? 'PASS' : 'WARN' ),
	array( 'SEB-Protected Quizzes', sprintf( '%d / %d (%s%%)', $seb_count, $total_quizzes, $seb_pct ), 'INFO' ),
	array( 'Progression Enforced', $enforced_count, $enforced_count > 0 ? 'PASS' : 'WARN' ),
	array( 'Global Passing %', $global_passing . '%', ( $global_passing >= 60 && $global_passing <= 69 ) ? 'PASS' : 'WARN' ),
	array( 'Total Students', $total_students, 'INFO' ),
	array( 'Students On-Track', $on_track_count, 'INFO' ),
	array( 'Students Behind/Blocked', $behind_count, $behind_count > 0 ? 'WARN' : 'PASS' ),
	array( 'Parent Accounts', $parent_count, 'INFO' ),
	array( 'Transfer Courses', $transfer_total, 'INFO' ),
	array( 'Exam Sessions Total', $total_sessions, 'INFO' ),
	array( 'Flagged Exam Sessions', $flagged_sessions, $flagged_sessions > 0 ? 'WARN' : 'PASS' ),
	array( 'Unreviewed Flagged Sessions', $unreviewed, $unreviewed > 0 ? 'WARN' : 'PASS' ),
);

foreach ( $metrics as $row ) {
	fputcsv( $sfp, $row );
}

fclose( $sfp );

WP_CLI::success( sprintf( 'Compliance readiness summary: %s', $summary_file ) );
WP_CLI::log( '---' );
WP_CLI::log( 'Reports saved to: ' . $report_dir );
