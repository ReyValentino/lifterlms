<?php
/**
 * Verify AHSA WordPress site readiness.
 *
 * Usage:
 *   wp eval-file sample-data/verify-ahsa-site.php
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "This script must be executed with WP-CLI.\n";
	return;
}

$required_pages = array(
	'home',
	'about-ahsa',
	'academy-network',
	'admissions',
	'academics',
	'high-school-courses',
	'middle-school-courses',
	'florida-eoc-fsa-prep',
	'enroll-now',
	'ncaa-eligibility',
	'student-support',
	'aviation-aerospace-courses',
	'tuition-and-fees',
	'contact',
	'news',
	'parent-dashboard',
	'student-dashboard',
	'student-transcript',
);

$errors = array();

foreach ( $required_pages as $slug ) {
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	if ( ! ( $page instanceof WP_Post ) ) {
		$errors[] = sprintf( 'Missing required page: %s', $slug );
	}
}

$front_id = (int) get_option( 'page_on_front' );
$front    = $front_id ? get_post( $front_id ) : null;
if ( ! ( $front instanceof WP_Post ) || 'home' !== $front->post_name ) {
	$errors[] = 'Front page is not configured to the Home page.';
}

$posts_id = (int) get_option( 'page_for_posts' );
$posts    = $posts_id ? get_post( $posts_id ) : null;
if ( ! ( $posts instanceof WP_Post ) || 'news' !== $posts->post_name ) {
	$errors[] = 'Posts page is not configured to the News page.';
}

$menu = wp_get_nav_menu_object( 'AHSA Primary Navigation' );
if ( ! $menu ) {
	$errors[] = 'Missing AHSA Primary Navigation menu.';
} else {
	$items = wp_get_nav_menu_items( $menu->term_id );
	if ( ! is_array( $items ) || count( $items ) < 8 ) {
		$errors[] = 'Primary navigation has too few items.';
	}
}

$course_count = (int) wp_count_posts( 'course' )->publish;
if ( $course_count < 84 ) {
	$errors[] = sprintf( 'Too few published courses (%d). Expected at least 84.', $course_count );
}

if ( ! function_exists( 'llms_get_page_id' ) || ! function_exists( 'llms_get_page_url' ) ) {
	$errors[] = 'LifterLMS functions are unavailable. Verify plugin activation and load order.';
} else {
	$lms_pages = array(
		'courses'   => 'Course catalog',
		'checkout'  => 'Checkout',
		'myaccount' => 'My account',
	);

	foreach ( $lms_pages as $key => $label ) {
		$page_id = (int) llms_get_page_id( $key );
		if ( $page_id < 1 ) {
			$errors[] = sprintf( '%s page is not configured in LifterLMS.', $label );
			continue;
		}

		$page = get_post( $page_id );
		if ( ! ( $page instanceof WP_Post ) || 'publish' !== $page->post_status ) {
			$errors[] = sprintf( '%s page is missing or not published.', $label );
		}
	}

	$catalog_url  = llms_get_page_url( 'courses' );
	$checkout_url = llms_get_page_url( 'checkout' );
	$account_url  = llms_get_page_url( 'myaccount' );
	foreach ( array( $catalog_url, $checkout_url, $account_url ) as $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			$errors[] = 'One or more required LifterLMS URLs are not resolvable.';
			break;
		}
	}
}

$plan_count_query = new WP_Query(
	array(
		'post_type'              => 'llms_access_plan',
		'post_status'            => 'publish',
		'posts_per_page'         => 1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	)
);

if ( (int) $plan_count_query->post_count < 1 ) {
	$errors[] = 'No published LifterLMS access plans found; course selling/enrollment is not ready.';
}

if ( $errors ) {
	WP_CLI::error( "AHSA site verification failed:\n- " . implode( "\n- ", $errors ) );
}

WP_CLI::success( sprintf( 'AHSA site verification passed. Published courses: %d.', $course_count ) );
