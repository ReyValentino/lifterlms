<?php
/**
 * Bulk SEB Configuration — apply SEB settings across all course quizzes.
 *
 * Usage:
 *   wp eval-file sample-data/setup-bulk-seb.php
 *   wp eval-file sample-data/setup-bulk-seb.php -- --disable
 *
 * @package AHSA
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "This script must be executed with WP-CLI.\n";
	return;
}

$disable = in_array( '--disable', $GLOBALS['argv'] ?? array(), true );

// Find all quizzes.
$quizzes = get_posts( array(
	'post_type'      => 'llms_quiz',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
) );

$count = count( $quizzes );
if ( 0 === $count ) {
	WP_CLI::warning( 'No quizzes found.' );
	return;
}

$action = $disable ? 'Disabling' : 'Enabling';
WP_CLI::log( sprintf( '%s SEB requirement on %d quizzes...', $action, $count ) );

$updated = 0;
foreach ( $quizzes as $quiz_id ) {
	if ( $disable ) {
		update_post_meta( $quiz_id, '_ahsa_seb_required', 'no' );
	} else {
		update_post_meta( $quiz_id, '_ahsa_seb_required', 'yes' );

		// Only set default keys if not already configured.
		$existing_config = get_post_meta( $quiz_id, '_ahsa_seb_config_key', true );
		if ( empty( $existing_config ) ) {
			update_post_meta( $quiz_id, '_ahsa_seb_config_key', '' );
			update_post_meta( $quiz_id, '_ahsa_seb_exam_key', '' );
		}
	}
	++$updated;
}

WP_CLI::success( sprintf( 'SEB %s on %d quizzes.', $disable ? 'disabled' : 'enabled', $updated ) );
