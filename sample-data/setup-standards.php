<?php
/**
 * Set up Florida standards library and auto-align to AHSA courses.
 *
 * Usage:
 *   wp eval-file sample-data/setup-standards.php
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

require_once __DIR__ . '/modules/class-ahsa-standards.php';

$standards = AHSA_Standards::instance();
$standards->create_tables();
WP_CLI::log( 'Standards tables created/verified.' );

// Seed Florida standards if library is empty.
global $wpdb;
$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ahsa_standards" );

if ( 0 === $count ) {
	$seeded = $standards->seed_florida_standards();
	WP_CLI::log( sprintf( 'Seeded %d Florida standards across all frameworks.', $seeded ) );
} else {
	WP_CLI::log( sprintf( 'Standards library already contains %d entries. Skipping seed.', $count ) );
}

// Auto-align courses to standards based on their metadata.
$aligned = $standards->auto_align_courses();
WP_CLI::log( sprintf( 'Created %d course-to-standard alignments.', $aligned ) );

WP_CLI::success( 'Standards alignment setup complete.' );
