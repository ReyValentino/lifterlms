<?php
/**
 * Set up exam security tables and default settings.
 *
 * Usage:
 *   wp eval-file sample-data/setup-exam-security.php
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

require_once __DIR__ . '/modules/class-ahsa-exam-security.php';

$security = AHSA_Exam_Security::instance();
$security->create_tables();
WP_CLI::log( 'Exam security tables created/verified.' );

// Set default global SEB setting.
if ( false === get_option( 'ahsa_seb_global_default' ) ) {
	update_option( 'ahsa_seb_global_default', 'no' );
	WP_CLI::log( 'Global SEB default set to: disabled (per-quiz opt-in).' );
} else {
	WP_CLI::log( 'Global SEB default already configured.' );
}

WP_CLI::success( 'Exam security setup complete. Enable SEB per-quiz via the quiz editor or globally via the Exam Security settings page.' );
