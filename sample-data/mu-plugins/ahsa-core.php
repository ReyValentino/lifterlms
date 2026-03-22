<?php
/**
 * AHSA Core Modules Loader.
 *
 * Must-use plugin that loads custom AHSA modules for:
 * - Progression Enforcement (strict sequential, passing grades, admin settings)
 * - Parent Progress Monitoring (parent-student linking, dashboard, teacher notes)
 *
 * Deployment: Copy this file into wp-content/mu-plugins/ahsa-core.php
 * and copy the modules/ directory alongside it, or adjust the path constant below.
 *
 * @package AHSA/Loader
 *
 * Plugin Name: AHSA Core Modules
 * Description: Progression enforcement, parent monitoring, rubrics, standards, exam security, branding, transcripts, student dashboard, email notifications, and admin dashboard for American High School Academy.
 * Version: 2.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Path to the AHSA modules directory.
 *
 * When deployed as a mu-plugin, modules are expected at:
 *   wp-content/mu-plugins/ahsa-modules/
 *
 * During development inside the LifterLMS plugin repo, they live at:
 *   wp-content/plugins/lifterlms/sample-data/modules/
 */
if ( ! defined( 'AHSA_MODULES_DIR' ) ) {
	// Try LifterLMS plugin repo path first (development).
	$dev_path = WP_PLUGIN_DIR . '/lifterlms/sample-data/modules';
	if ( is_dir( $dev_path ) ) {
		define( 'AHSA_MODULES_DIR', $dev_path );
	} else {
		// Fallback to alongside the mu-plugin.
		define( 'AHSA_MODULES_DIR', WPMU_PLUGIN_DIR . '/ahsa-modules' );
	}
}

/**
 * Boot the AHSA modules after LifterLMS is loaded.
 */
function ahsa_core_modules_init() {
	// Only load if LifterLMS is active.
	if ( ! class_exists( 'LifterLMS' ) ) {
		return;
	}

	$modules_dir = AHSA_MODULES_DIR;

	if ( ! is_dir( $modules_dir ) ) {
		return;
	}

	// Load progression enforcement.
	$progression_file = $modules_dir . '/class-ahsa-progression-enforcement.php';
	if ( is_readable( $progression_file ) ) {
		require_once $progression_file;
		AHSA_Progression_Enforcement::instance();
	}

	// Load parent monitoring.
	$parent_file = $modules_dir . '/class-ahsa-parent-monitor.php';
	if ( is_readable( $parent_file ) ) {
		require_once $parent_file;
		AHSA_Parent_Monitor::instance();
	}

	// Load rubric system.
	$rubrics_file = $modules_dir . '/class-ahsa-rubrics.php';
	if ( is_readable( $rubrics_file ) ) {
		require_once $rubrics_file;
		AHSA_Rubrics::instance();
	}

	// Load standards alignment.
	$standards_file = $modules_dir . '/class-ahsa-standards.php';
	if ( is_readable( $standards_file ) ) {
		require_once $standards_file;
		AHSA_Standards::instance();
	}

	// Load exam security (SEB).
	$exam_file = $modules_dir . '/class-ahsa-exam-security.php';
	if ( is_readable( $exam_file ) ) {
		require_once $exam_file;
		AHSA_Exam_Security::instance();
	}

	// Load branding (CSS enqueue).
	$branding_file = $modules_dir . '/class-ahsa-branding.php';
	if ( is_readable( $branding_file ) ) {
		require_once $branding_file;
		AHSA_Branding::instance();
	}

	// Load transcript generator.
	$transcript_file = $modules_dir . '/class-ahsa-transcript.php';
	if ( is_readable( $transcript_file ) ) {
		require_once $transcript_file;
		AHSA_Transcript::instance();
	}

	// Load student dashboard.
	$student_dash_file = $modules_dir . '/class-ahsa-student-dashboard.php';
	if ( is_readable( $student_dash_file ) ) {
		require_once $student_dash_file;
		AHSA_Student_Dashboard::instance();
	}

	// Load email notifications.
	$email_file = $modules_dir . '/class-ahsa-email-notifications.php';
	if ( is_readable( $email_file ) ) {
		require_once $email_file;
		AHSA_Email_Notifications::instance();
	}

	// Load admin dashboard widget.
	$admin_dash_file = $modules_dir . '/class-ahsa-admin-dashboard.php';
	if ( is_readable( $admin_dash_file ) ) {
		require_once $admin_dash_file;
		AHSA_Admin_Dashboard::instance();
	}
}
add_action( 'plugins_loaded', 'ahsa_core_modules_init', 20 );
