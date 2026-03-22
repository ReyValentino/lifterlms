<?php
/**
 * Build a complete American High School Academy style site structure.
 *
 * Usage:
 *   wp eval-file sample-data/build-ahsa-site.php
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "This script must be executed with WP-CLI.\n";
	return;
}

/**
 * Create or update a page by slug.
 *
 * @param string $slug Slug.
 * @param string $title Title.
 * @param string $content HTML content.
 * @return int
 */
function llms_ahsa_upsert_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	$data     = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $content,
	);

	if ( $existing instanceof WP_Post ) {
		$data['ID'] = $existing->ID;
		$page_id    = wp_update_post( $data, true );
	} else {
		$page_id = wp_insert_post( $data, true );
	}

	if ( is_wp_error( $page_id ) ) {
		WP_CLI::error( sprintf( 'Could not create/update page %s: %s', $slug, $page_id->get_error_message() ) );
	}

	return (int) $page_id;
}

$pages = array(
	'home' => array(
		'title'   => 'American High School Academy',
		'content' => '<h2>Accredited Online High School Pathways</h2><p>Welcome to your complete online academy for grades 9-12.</p><p><strong>Network:</strong> This school deployment is part of the American High School Academy network at <a href="https://americanhighschoolacademy.com" target="_blank" rel="noopener noreferrer">americanhighschoolacademy.com</a>.</p><ul><li>College prep and NCAA aligned pathways</li><li>Semester-based credit model</li><li>Flexible pacing with teacher support</li></ul><p><a href="/high-school-courses/">Explore Courses</a> | <a href="/enroll-now/">Enroll Now</a></p>',
	),
	'about-ahsa' => array(
		'title'   => 'About the Academy',
		'content' => '<h2>About American High School Academy</h2><p>We provide student-centered online learning designed for graduation, college, and career readiness.</p><p>This implementation is operated as part of the American High School Academy organization at <a href="https://americanhighschoolacademy.com" target="_blank" rel="noopener noreferrer">americanhighschoolacademy.com</a>.</p>',
	),
	'academy-network' => array(
		'title'   => 'Academy Network',
		'content' => '<h2>Academy Network Affiliation</h2><p>This site is an AHSA course delivery environment affiliated with <a href="https://americanhighschoolacademy.com" target="_blank" rel="noopener noreferrer">americanhighschoolacademy.com</a>.</p><p>Use this page to publish legal notices, accreditation language, and parent-organization references required by your operations team.</p>',
	),
	'admissions' => array(
		'title'   => 'Admissions',
		'content' => '<h2>Admissions</h2><p>Start your enrollment process with transcript review, pathway planning, and pacing guidance.</p>',
	),
	'academics' => array(
		'title'   => 'Academics',
		'content' => '<h2>Academics</h2><p>Standards-based curriculum with semester scheduling and progress monitoring.</p>',
	),
	'high-school-courses' => array(
		'title'   => 'High School Courses',
		'content' => '<h2>High School Course Catalog</h2><p>Browse grade 9-12 semester courses, including English, Math, Science, and Social Studies.</p><p>All core courses in this deployment are prebuilt inside LifterLMS.</p><p>Purchase and enrollment are handled by LifterLMS access plans at the course level.</p>',
	),
	'middle-school-courses' => array(
		'title'   => 'Middle School Courses',
		'content' => '<h2>Middle School Course Catalog</h2><p>Browse grade 6-8 semester core courses in English, Mathematics, Science, and Social Studies.</p><p>These courses are built with open educational resources and aligned to Florida standards pathways.</p>',
	),
	'florida-eoc-fsa-prep' => array(
		'title'   => 'Florida EOC and FSA Prep',
		'content' => '<h2>Florida EOC and FSA Test Prep</h2><p>Targeted prep courses support EOC and FSA/B.E.S.T. readiness for Algebra 1, Geometry, Biology, Civics, Grade 8 ELA, and Grade 8 Mathematics.</p><p>Each prep course includes standards checkpoints, auto-graded quizzes, and cumulative practice workflows.</p>',
	),
	'aviation-aerospace-courses' => array(
		'title'   => 'Aviation, Aerospace and Drone Pathway',
		'content' => '<h2>AHSA STEM Aviation / Aerospace / Drone Pathway</h2><p>Explore our comprehensive aviation STEM pathway featuring courses in aviation foundations, aerospace science, UAS/drone operations, FAA Part 107 prep, private pilot ground school, flight theory, aerospace engineering, and a capstone project.</p><p>Courses are elective by default and may be classified as core for magnet STEM students. All courses include full rubrics, Florida standards alignment, and strict progression enforcement.</p><h4>Pathway Courses</h4><ul><li>Aviation Foundations</li><li>Aerospace Science</li><li>UAS / Drone Fundamentals</li><li>FAA Part 107 Prep</li><li>Private Pilot Ground School</li><li>Aviation Careers</li><li>Flight Theory, Weather and Navigation</li><li>Aerospace Engineering</li><li>Drone Operations and Data Analysis</li><li>Aviation Aerospace Capstone</li></ul>',
	),
	'enroll-now' => array(
		'title'   => 'Enroll Now',
		'content' => '<h2>Enrollment and Checkout</h2><p>Use the LifterLMS catalog to select a course and complete enrollment.</p><ol><li>Open the course catalog</li><li>Select your semester course</li><li>Choose an access plan and continue to checkout</li></ol><p><a href="/shop/">Open Course Catalog</a> | <a href="/checkout/">Go to Checkout</a> | <a href="/my-account/">Student Account</a></p>',
	),
	'ncaa-eligibility' => array(
		'title'   => 'NCAA Eligibility',
		'content' => '<h2>NCAA Eligibility Support</h2><p>Course documentation includes standards, grading artifacts, and completion verification workflows for review processes.</p>',
	),
	'student-support' => array(
		'title'   => 'Student Support',
		'content' => '<h2>Student Support</h2><p>Academic advising, intervention pathways, and progress coaching are integrated into every course flow.</p>',
	),
	'tuition-and-fees' => array(
		'title'   => 'Tuition and Fees',
		'content' => '<h2>Tuition and Fees</h2><p>Publish district-approved tuition and fee schedules here.</p>',
	),
	'contact' => array(
		'title'   => 'Contact',
		'content' => '<h2>Contact Us</h2><p>Provide registrar, counseling, and technical support contact information.</p>',
	),
	'news' => array(
		'title'   => 'News',
		'content' => '<h2>Academy News</h2><p>Publish announcements, calendar updates, and student success highlights.</p>',
	),
	'parent-dashboard' => array(
		'title'   => 'Parent Dashboard',
		'content' => '[ahsa_parent_dashboard]',
	),
	'student-dashboard' => array(
		'title'   => 'Student Dashboard',
		'content' => '[ahsa_student_dashboard]',
	),
	'student-transcript' => array(
		'title'   => 'My Transcript',
		'content' => '[ahsa_transcript]',
	),
);

$page_ids = array();
foreach ( $pages as $slug => $page ) {
	$page_ids[ $slug ] = llms_ahsa_upsert_page( $slug, $page['title'], $page['content'] );
}

if ( class_exists( 'LLMS_Install' ) && method_exists( 'LLMS_Install', 'create_pages' ) ) {
	LLMS_Install::create_pages();
}

$courses_url  = function_exists( 'llms_get_page_url' ) ? llms_get_page_url( 'courses' ) : home_url( '/courses/' );
$checkout_url = function_exists( 'llms_get_page_url' ) ? llms_get_page_url( 'checkout' ) : home_url( '/purchase/' );
$account_url  = function_exists( 'llms_get_page_url' ) ? llms_get_page_url( 'myaccount' ) : home_url( '/dashboard/' );

$page_ids['high-school-courses'] = llms_ahsa_upsert_page(
	'high-school-courses',
	'High School Courses',
	'<h2>High School Course Catalog</h2><p>Browse grade 9-12 semester courses, including English, Math, Science, and Social Studies.</p><p>All core courses in this deployment are prebuilt inside LifterLMS.</p><p>Purchase and enrollment are handled by LifterLMS access plans at the course level.</p><p><a href="' . esc_url( $courses_url ) . '">Open Full Catalog</a></p>'
);

$page_ids['enroll-now'] = llms_ahsa_upsert_page(
	'enroll-now',
	'Enroll Now',
	'<h2>Enrollment and Checkout</h2><p>Use the LifterLMS catalog to select a course and complete enrollment.</p><ol><li>Open the course catalog</li><li>Select your semester course</li><li>Choose an access plan and continue to checkout</li></ol><p><a href="' . esc_url( $courses_url ) . '">Open Course Catalog</a> | <a href="' . esc_url( $checkout_url ) . '">Go to Checkout</a> | <a href="' . esc_url( $account_url ) . '">Student Account</a></p>'
);

update_option( 'blogname', 'American High School Academy' );
update_option( 'blogdescription', 'Part of americanhighschoolacademy.com: online high school courses for grades 9-12' );

// Seed school info for transcript header.
if ( false === get_option( 'ahsa_school_info' ) ) {
	update_option( 'ahsa_school_info', array(
		'name'    => 'American High School Academy',
		'address' => '',
		'phone'   => '',
		'ceeb'    => '',
	) );
}

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $page_ids['home'] );
update_option( 'page_for_posts', $page_ids['news'] );
update_option( 'permalink_structure', '/%postname%/' );

$menu_name = 'AHSA Primary Navigation';
$menu      = wp_get_nav_menu_object( $menu_name );
$menu_id   = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( $menu_name );

$menu_items = array(
	'home',
	'about-ahsa',
	'academy-network',
	'admissions',
	'academics',
	'high-school-courses',
	'middle-school-courses',
	'florida-eoc-fsa-prep',
	'aviation-aerospace-courses',
	'enroll-now',
	'ncaa-eligibility',
	'student-support',
	'parent-dashboard',
	'student-dashboard',
	'student-transcript',
	'tuition-and-fees',
	'contact',
);

$existing_items = wp_get_nav_menu_items( $menu_id );
$existing_map   = array();
if ( $existing_items ) {
	foreach ( $existing_items as $item ) {
		$existing_map[ (int) $item->object_id ] = (int) $item->ID;
	}
}

foreach ( $menu_items as $position => $slug ) {
	$page_id = $page_ids[ $slug ];
	$args    = array(
		'menu-item-object-id' => $page_id,
		'menu-item-object'    => 'page',
		'menu-item-type'      => 'post_type',
		'menu-item-status'    => 'publish',
		'menu-item-position'  => $position + 1,
	);

	if ( isset( $existing_map[ $page_id ] ) ) {
		$args['menu-item-db-id'] = $existing_map[ $page_id ];
	}

	wp_update_nav_menu_item( $menu_id, 0, $args );
}

$locations = get_theme_mod( 'nav_menu_locations', array() );
if ( is_array( $locations ) ) {
	$locations['primary'] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $locations );
}

WP_CLI::success( 'AHSA site structure build complete: pages, settings, and primary navigation configured.' );
