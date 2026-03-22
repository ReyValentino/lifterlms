<?php
/**
 * Set up parent monitoring tables, role, and dashboard page.
 *
 * Usage:
 *   wp eval-file sample-data/setup-parent-monitoring.php
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

// Load the module class directly for table creation.
require_once __DIR__ . '/modules/class-ahsa-parent-monitor.php';

// Create/update database tables.
$monitor = AHSA_Parent_Monitor::instance();
$monitor->create_tables();
WP_CLI::log( 'Parent monitoring database tables created/verified.' );

// Register parent role.
$monitor->register_parent_role();
WP_CLI::log( 'Parent role registered.' );

// Create the parent dashboard page.
$slug     = 'parent-dashboard';
$existing = get_page_by_path( $slug, OBJECT, 'page' );

if ( $existing instanceof WP_Post ) {
	WP_CLI::log( 'Parent dashboard page already exists.' );
} else {
	$page_id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Parent Dashboard',
		'post_name'    => $slug,
		'post_content' => '[ahsa_parent_dashboard]',
	) );

	if ( is_wp_error( $page_id ) ) {
		WP_CLI::error( 'Failed to create parent dashboard page: ' . $page_id->get_error_message() );
	} else {
		WP_CLI::log( sprintf( 'Parent dashboard page created (ID: %d).', $page_id ) );
	}
}

// Set default note creator roles.
if ( false === get_option( 'ahsa_note_creator_roles' ) ) {
	update_option( 'ahsa_note_creator_roles', array( 'administrator', 'lms_manager', 'instructor' ) );
	WP_CLI::log( 'Default note creator roles set.' );
}

WP_CLI::success( 'Parent monitoring setup complete.' );
