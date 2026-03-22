<?php
/**
 * AHSA Transcript Generator — 4-year high school transcript with transfer courses,
 * 4.0 GPA scale, editable fields, school info, and student demographics.
 *
 * @package AHSA
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

class AHSA_Transcript {

	/** @var self|null */
	private static $instance = null;

	/** Grade → quality points on a 4.0 scale. */
	const GPA_SCALE = array(
		'A+'  => 4.0,
		'A'   => 4.0,
		'A-'  => 3.7,
		'B+'  => 3.3,
		'B'   => 3.0,
		'B-'  => 2.7,
		'C+'  => 2.3,
		'C'   => 2.0,
		'C-'  => 1.7,
		'D+'  => 1.3,
		'D'   => 1.0,
		'D-'  => 0.7,
		'F'   => 0.0,
		'P'   => null, // pass — excluded from GPA
		'W'   => null, // withdrawn — excluded from GPA
		'TR'  => null, // transfer (use transfer_gpa_points)
		'I'   => null, // incomplete
	);

	/** Percentage → letter grade boundaries. */
	const GRADE_BOUNDARIES = array(
		97  => 'A+',
		93  => 'A',
		90  => 'A-',
		87  => 'B+',
		83  => 'B',
		80  => 'B-',
		77  => 'C+',
		73  => 'C',
		70  => 'C-',
		67  => 'D+',
		63  => 'D',
		60  => 'D-',
		0   => 'F',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'create_tables' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 30 );
		add_shortcode( 'ahsa_transcript', array( $this, 'shortcode_transcript' ) );
		add_action( 'admin_post_ahsa_save_transcript', array( $this, 'handle_save_transcript' ) );
		add_action( 'admin_post_ahsa_add_transfer_course', array( $this, 'handle_add_transfer' ) );
		add_action( 'admin_post_ahsa_delete_transfer_course', array( $this, 'handle_delete_transfer' ) );
		add_action( 'admin_post_ahsa_save_school_info', array( $this, 'handle_save_school_info' ) );
		add_action( 'admin_post_ahsa_save_student_info', array( $this, 'handle_save_student_info' ) );
	}

	/* =========================================================
	   Database
	   ========================================================= */

	public function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$transfer_table = $wpdb->prefix . 'ahsa_transfer_courses';
		$sql_transfer   = "CREATE TABLE IF NOT EXISTS {$transfer_table} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			student_user_id BIGINT UNSIGNED NOT NULL,
			school_name     VARCHAR(255)    NOT NULL DEFAULT '',
			school_city     VARCHAR(255)    NOT NULL DEFAULT '',
			school_state    VARCHAR(10)     NOT NULL DEFAULT '',
			course_name     VARCHAR(255)    NOT NULL,
			course_code     VARCHAR(50)     NOT NULL DEFAULT '',
			academic_year   SMALLINT        NOT NULL DEFAULT 0,
			grade_level     TINYINT         NOT NULL DEFAULT 9,
			semester        TINYINT         NOT NULL DEFAULT 1,
			credit_hours    DECIMAL(3,2)    NOT NULL DEFAULT 0.50,
			letter_grade    VARCHAR(5)      NOT NULL DEFAULT '',
			gpa_points      DECIMAL(3,2)    DEFAULT NULL,
			course_type     VARCHAR(50)     NOT NULL DEFAULT 'core',
			subject_area    VARCHAR(100)    NOT NULL DEFAULT '',
			ncaa_approved   TINYINT(1)      NOT NULL DEFAULT 0,
			notes           TEXT            NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_student (student_user_id),
			KEY idx_year_grade (academic_year, grade_level)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_transfer );
	}

	/* =========================================================
	   GPA calculation helpers
	   ========================================================= */

	/**
	 * Convert a numeric percentage to a letter grade.
	 */
	public function percent_to_letter( $percent ) {
		if ( ! is_numeric( $percent ) ) {
			return '';
		}
		$percent = (float) $percent;
		foreach ( self::GRADE_BOUNDARIES as $threshold => $letter ) {
			if ( $percent >= $threshold ) {
				return $letter;
			}
		}
		return 'F';
	}

	/**
	 * Get GPA quality points for a letter grade.
	 *
	 * @return float|null  Null if excluded from GPA.
	 */
	public function letter_to_gpa( $letter ) {
		$letter = strtoupper( trim( $letter ) );
		return isset( self::GPA_SCALE[ $letter ] ) ? self::GPA_SCALE[ $letter ] : null;
	}

	/**
	 * Calculate cumulative or per-year GPA on 4.0 scale.
	 *
	 * @param array $courses Array of objects with credit_hours, letter_grade, gpa_points.
	 * @return array { gpa, total_credits, quality_points, courses_counted }
	 */
	public function calculate_gpa( $courses ) {
		$total_credits   = 0;
		$quality_points  = 0;
		$counted         = 0;

		foreach ( $courses as $c ) {
			$credits = (float) $c->credit_hours;
			$points  = null;

			// Transfer courses may have explicit gpa_points.
			if ( isset( $c->gpa_points ) && is_numeric( $c->gpa_points ) ) {
				$points = (float) $c->gpa_points;
			} else {
				$points = $this->letter_to_gpa( $c->letter_grade );
			}

			if ( null === $points || $credits <= 0 ) {
				continue;
			}

			$quality_points += $points * $credits;
			$total_credits  += $credits;
			++$counted;
		}

		return array(
			'gpa'             => $total_credits > 0 ? round( $quality_points / $total_credits, 4 ) : 0,
			'total_credits'   => $total_credits,
			'quality_points'  => round( $quality_points, 4 ),
			'courses_counted' => $counted,
		);
	}

	/* =========================================================
	   Transfer course CRUD
	   ========================================================= */

	public function add_transfer_course( $data ) {
		global $wpdb;
		return $wpdb->insert(
			$wpdb->prefix . 'ahsa_transfer_courses',
			array(
				'student_user_id' => absint( $data['student_user_id'] ),
				'school_name'     => sanitize_text_field( $data['school_name'] ?? '' ),
				'school_city'     => sanitize_text_field( $data['school_city'] ?? '' ),
				'school_state'    => sanitize_text_field( $data['school_state'] ?? '' ),
				'course_name'     => sanitize_text_field( $data['course_name'] ),
				'course_code'     => sanitize_text_field( $data['course_code'] ?? '' ),
				'academic_year'   => absint( $data['academic_year'] ?? 0 ),
				'grade_level'     => absint( $data['grade_level'] ?? 9 ),
				'semester'        => absint( $data['semester'] ?? 1 ),
				'credit_hours'    => floatval( $data['credit_hours'] ?? 0.5 ),
				'letter_grade'    => sanitize_text_field( $data['letter_grade'] ?? '' ),
				'gpa_points'      => isset( $data['gpa_points'] ) && '' !== $data['gpa_points'] ? floatval( $data['gpa_points'] ) : null,
				'course_type'     => sanitize_text_field( $data['course_type'] ?? 'core' ),
				'subject_area'    => sanitize_text_field( $data['subject_area'] ?? '' ),
				'ncaa_approved'   => ! empty( $data['ncaa_approved'] ) ? 1 : 0,
				'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%f', '%s', '%s', '%d', '%s' )
		);
	}

	public function get_transfer_courses( $student_id, $year = null, $grade = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ahsa_transfer_courses';
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE student_user_id = %d", absint( $student_id ) );
		if ( $year ) {
			$sql .= $wpdb->prepare( ' AND academic_year = %d', absint( $year ) );
		}
		if ( $grade ) {
			$sql .= $wpdb->prepare( ' AND grade_level = %d', absint( $grade ) );
		}
		$sql .= ' ORDER BY academic_year ASC, semester ASC, course_name ASC';
		return $wpdb->get_results( $sql );
	}

	public function delete_transfer_course( $id, $student_id ) {
		global $wpdb;
		return $wpdb->delete(
			$wpdb->prefix . 'ahsa_transfer_courses',
			array( 'id' => absint( $id ), 'student_user_id' => absint( $student_id ) ),
			array( '%d', '%d' )
		);
	}

	/* =========================================================
	   AHSA courses for a student (from LifterLMS enrollment)
	   ========================================================= */

	/**
	 * Get all AHSA courses for a student, structured by year/semester.
	 *
	 * @param int $student_id
	 * @return array Flat list of course objects.
	 */
	public function get_ahsa_courses( $student_id ) {
		if ( ! class_exists( 'LLMS_Student' ) ) {
			return array();
		}

		$student  = new \LLMS_Student( $student_id );
		$courses  = array();
		$enrolled = get_posts( array(
			'post_type'      => 'course',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_llms_student_' . $student_id . '_status',
					'compare' => 'EXISTS',
				),
			),
		) );

		// Fallback: use LLMS enrollment query.
		if ( empty( $enrolled ) && method_exists( $student, 'get_enrollments' ) ) {
			$enrollments = $student->get_enrollments( 'course', array( 'per_page' => 1000, 'status' => 'any' ) );
			if ( ! empty( $enrollments['results'] ) ) {
				$enrolled = $enrollments['results'];
			}
		}

		foreach ( $enrolled as $course_id ) {
			$grade_pct = '';
			if ( function_exists( 'llms' ) && method_exists( llms()->grades(), 'get_grade' ) ) {
				$grade_pct = llms()->grades()->get_grade( $course_id, $student );
			}

			$letter   = $this->percent_to_letter( $grade_pct );
			$credit   = get_post_meta( $course_id, '_llms_credit_value', true );
			$semester = get_post_meta( $course_id, '_llms_semester', true );
			$grade_lv = get_post_meta( $course_id, '_llms_grade_level', true );
			$track    = get_post_meta( $course_id, '_llms_state_standard_framework', true );
			$ncaa     = get_post_meta( $course_id, '_llms_ncaa_core_area', true );
			$pathway  = get_post_meta( $course_id, '_llms_pathway_type', true );
			$complete = $student->is_complete( $course_id, 'course' );

			// Determine academic year from enrollment or grade level.
			$enrollment_date = $student->get_enrollment_date( $course_id );
			$acad_year       = $enrollment_date ? (int) gmdate( 'Y', strtotime( $enrollment_date ) ) : (int) gmdate( 'Y' );
			// If enrolled in fall (Aug–Dec), academic year is that year; spring = year-1.
			$enroll_month = $enrollment_date ? (int) gmdate( 'n', strtotime( $enrollment_date ) ) : 9;
			if ( $enroll_month < 7 ) {
				--$acad_year;
			}

			$c                = new \stdClass();
			$c->source        = 'ahsa';
			$c->course_id     = $course_id;
			$c->course_name   = get_the_title( $course_id );
			$c->course_code   = '';
			$c->academic_year = $acad_year;
			$c->grade_level   = $grade_lv ? (int) $grade_lv : 9;
			$c->semester      = $semester ? (int) $semester : 1;
			$c->credit_hours  = $credit ? (float) $credit : 0.5;
			$c->letter_grade  = $complete ? $letter : 'IP'; // IP = in progress
			$c->gpa_points    = $complete ? $this->letter_to_gpa( $letter ) : null;
			$c->percent_grade = $grade_pct;
			$c->course_type   = $pathway ?: 'core';
			$c->subject_area  = $track ?: '';
			$c->ncaa_approved = ! empty( $ncaa ) && 'Elective' !== $ncaa ? 1 : 0;
			$c->school_name   = $this->get_school_field( 'school_name' );
			$c->is_complete   = $complete;
			$c->notes         = '';

			$courses[] = $c;
		}

		return $courses;
	}

	/**
	 * Combine AHSA + transfer courses into a unified 4-year structure.
	 *
	 * @return array  Keyed by grade level (9, 10, 11, 12), each containing semesters 1 & 2.
	 */
	public function build_transcript_data( $student_id ) {
		$ahsa_courses    = $this->get_ahsa_courses( $student_id );
		$transfer_courses = $this->get_transfer_courses( $student_id );

		// Normalize transfer courses to objects.
		$all_courses = $ahsa_courses;
		foreach ( $transfer_courses as $tc ) {
			$c                = new \stdClass();
			$c->source        = 'transfer';
			$c->transfer_id   = $tc->id;
			$c->course_name   = $tc->course_name;
			$c->course_code   = $tc->course_code;
			$c->academic_year = (int) $tc->academic_year;
			$c->grade_level   = (int) $tc->grade_level;
			$c->semester      = (int) $tc->semester;
			$c->credit_hours  = (float) $tc->credit_hours;
			$c->letter_grade  = $tc->letter_grade;
			$c->gpa_points    = is_numeric( $tc->gpa_points ) ? (float) $tc->gpa_points : $this->letter_to_gpa( $tc->letter_grade );
			$c->course_type   = $tc->course_type;
			$c->subject_area  = $tc->subject_area;
			$c->ncaa_approved = (int) $tc->ncaa_approved;
			$c->school_name   = $tc->school_name ?: 'Transfer';
			$c->is_complete   = true;
			$c->notes         = $tc->notes;
			$all_courses[]    = $c;
		}

		// Organize by grade level → semester.
		$transcript = array();
		foreach ( array( 9, 10, 11, 12 ) as $gl ) {
			$transcript[ $gl ] = array( 1 => array(), 2 => array() );
		}
		foreach ( $all_courses as $c ) {
			$gl  = max( 9, min( 12, $c->grade_level ) );
			$sem = max( 1, min( 2, $c->semester ) );
			$transcript[ $gl ][ $sem ][] = $c;
		}

		// Sort each semester by course name.
		foreach ( $transcript as $gl => &$semesters ) {
			foreach ( $semesters as $sem => &$courses ) {
				usort( $courses, function ( $a, $b ) {
					return strcmp( $a->course_name, $b->course_name );
				} );
			}
		}

		return $transcript;
	}

	/* =========================================================
	   School info (wp_options)
	   ========================================================= */

	public function get_school_field( $key, $default = '' ) {
		$info = get_option( 'ahsa_school_info', array() );
		return isset( $info[ $key ] ) ? $info[ $key ] : $default;
	}

	public function save_school_info( $data ) {
		$fields = array(
			'school_name', 'school_address', 'school_city', 'school_state',
			'school_zip', 'school_phone', 'school_fax', 'school_website',
			'school_email', 'school_ceeb_code', 'school_accreditation',
			'principal_name', 'principal_title', 'counselor_name', 'counselor_title',
			'grading_scale_note', 'transcript_footer_note',
		);
		$clean = array();
		foreach ( $fields as $f ) {
			$clean[ $f ] = isset( $data[ $f ] ) ? sanitize_text_field( $data[ $f ] ) : '';
		}
		update_option( 'ahsa_school_info', $clean );
	}

	/* =========================================================
	   Student info (user meta)
	   ========================================================= */

	public function get_student_field( $student_id, $key, $default = '' ) {
		return get_user_meta( $student_id, '_ahsa_transcript_' . $key, true ) ?: $default;
	}

	public function save_student_info( $student_id, $data ) {
		$fields = array(
			'student_id_number', 'date_of_birth', 'gender',
			'expected_graduation', 'enrollment_date', 'withdrawal_date',
			'parent_guardian', 'address', 'city', 'state', 'zip',
			'phone', 'email', 'class_rank', 'class_size',
			'sat_score', 'act_score', 'community_service_hours',
		);
		foreach ( $fields as $f ) {
			$value = isset( $data[ $f ] ) ? sanitize_text_field( $data[ $f ] ) : '';
			update_user_meta( $student_id, '_ahsa_transcript_' . $f, $value );
		}
	}

	/* =========================================================
	   Admin menu
	   ========================================================= */

	public function admin_menu() {
		add_submenu_page(
			'lifterlms',
			__( 'AHSA Transcripts', 'lifterlms' ),
			__( 'Transcripts', 'lifterlms' ),
			'manage_options',
			'ahsa-transcripts',
			array( $this, 'admin_page' )
		);
	}

	public function admin_page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'transcripts';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AHSA Transcript Manager', 'lifterlms' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="?page=ahsa-transcripts&tab=transcripts" class="nav-tab <?php echo 'transcripts' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Generate Transcript', 'lifterlms' ); ?></a>
				<a href="?page=ahsa-transcripts&tab=transfers" class="nav-tab <?php echo 'transfers' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Transfer Courses', 'lifterlms' ); ?></a>
				<a href="?page=ahsa-transcripts&tab=school" class="nav-tab <?php echo 'school' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'School Info', 'lifterlms' ); ?></a>
				<a href="?page=ahsa-transcripts&tab=student" class="nav-tab <?php echo 'student' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Student Info', 'lifterlms' ); ?></a>
			</nav>
			<div class="tab-content" style="margin-top:20px;">
			<?php
			switch ( $tab ) {
				case 'transfers':
					$this->render_transfers_tab();
					break;
				case 'school':
					$this->render_school_tab();
					break;
				case 'student':
					$this->render_student_tab();
					break;
				default:
					$this->render_transcript_tab();
			}
			?>
			</div>
		</div>
		<?php
	}

	/* =========================================================
	   Tab: Generate Transcript
	   ========================================================= */

	private function render_transcript_tab() {
		$student_id = isset( $_GET['student_id'] ) ? absint( $_GET['student_id'] ) : 0;

		// Student selector.
		$students = get_users( array( 'role__in' => array( 'student', 'subscriber' ), 'number' => 500, 'orderby' => 'display_name' ) );
		?>
		<form method="get">
			<input type="hidden" name="page" value="ahsa-transcripts">
			<input type="hidden" name="tab" value="transcripts">
			<label for="student_id"><strong><?php esc_html_e( 'Select Student:', 'lifterlms' ); ?></strong></label>
			<select name="student_id" id="student_id">
				<option value=""><?php esc_html_e( '— Choose Student —', 'lifterlms' ); ?></option>
				<?php foreach ( $students as $u ) : ?>
					<option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $student_id, $u->ID ); ?>>
						<?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Generate', 'lifterlms' ); ?></button>
			<?php if ( $student_id ) : ?>
				<a href="?page=ahsa-transcripts&tab=transcripts&student_id=<?php echo esc_attr( $student_id ); ?>&print=1" class="button" target="_blank"><?php esc_html_e( 'Print View', 'lifterlms' ); ?></a>
			<?php endif; ?>
		</form>

		<?php
		if ( ! $student_id ) {
			return;
		}

		$print = ! empty( $_GET['print'] );
		$this->render_transcript_html( $student_id, true, $print );
	}

	/* =========================================================
	   Tab: Transfer Courses
	   ========================================================= */

	private function render_transfers_tab() {
		$student_id = isset( $_GET['student_id'] ) ? absint( $_GET['student_id'] ) : 0;
		$students   = get_users( array( 'role__in' => array( 'student', 'subscriber' ), 'number' => 500, 'orderby' => 'display_name' ) );
		?>
		<form method="get">
			<input type="hidden" name="page" value="ahsa-transcripts">
			<input type="hidden" name="tab" value="transfers">
			<label><strong><?php esc_html_e( 'Student:', 'lifterlms' ); ?></strong></label>
			<select name="student_id">
				<option value=""><?php esc_html_e( '— Choose Student —', 'lifterlms' ); ?></option>
				<?php foreach ( $students as $u ) : ?>
					<option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $student_id, $u->ID ); ?>>
						<?php echo esc_html( $u->display_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Load', 'lifterlms' ); ?></button>
		</form>

		<?php if ( ! $student_id ) { return; } ?>

		<h3><?php esc_html_e( 'Add Transfer Course', 'lifterlms' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ahsa_add_transfer_course' ); ?>
			<input type="hidden" name="action" value="ahsa_add_transfer_course">
			<input type="hidden" name="student_user_id" value="<?php echo esc_attr( $student_id ); ?>">

			<table class="form-table">
				<tr><th><?php esc_html_e( 'Previous School Name', 'lifterlms' ); ?></th>
					<td><input type="text" name="school_name" class="regular-text" required></td></tr>
				<tr><th><?php esc_html_e( 'School City / State', 'lifterlms' ); ?></th>
					<td>
						<input type="text" name="school_city" placeholder="City" style="width:200px;">
						<input type="text" name="school_state" placeholder="FL" maxlength="10" style="width:60px;">
					</td></tr>
				<tr><th><?php esc_html_e( 'Course Name', 'lifterlms' ); ?></th>
					<td><input type="text" name="course_name" class="regular-text" required></td></tr>
				<tr><th><?php esc_html_e( 'Course Code', 'lifterlms' ); ?></th>
					<td><input type="text" name="course_code" placeholder="e.g. ENG1001" style="width:150px;"></td></tr>
				<tr><th><?php esc_html_e( 'Academic Year', 'lifterlms' ); ?></th>
					<td><input type="number" name="academic_year" min="2015" max="2040" value="<?php echo esc_attr( gmdate( 'Y' ) - 1 ); ?>" style="width:100px;"></td></tr>
				<tr><th><?php esc_html_e( 'Grade Level', 'lifterlms' ); ?></th>
					<td><select name="grade_level">
						<option value="9"><?php esc_html_e( '9th (Freshman)', 'lifterlms' ); ?></option>
						<option value="10"><?php esc_html_e( '10th (Sophomore)', 'lifterlms' ); ?></option>
						<option value="11"><?php esc_html_e( '11th (Junior)', 'lifterlms' ); ?></option>
						<option value="12"><?php esc_html_e( '12th (Senior)', 'lifterlms' ); ?></option>
					</select></td></tr>
				<tr><th><?php esc_html_e( 'Semester', 'lifterlms' ); ?></th>
					<td><select name="semester">
						<option value="1"><?php esc_html_e( '1 (Fall)', 'lifterlms' ); ?></option>
						<option value="2"><?php esc_html_e( '2 (Spring)', 'lifterlms' ); ?></option>
					</select></td></tr>
				<tr><th><?php esc_html_e( 'Credit Hours', 'lifterlms' ); ?></th>
					<td><input type="number" name="credit_hours" min="0" max="5" step="0.25" value="0.50" style="width:80px;"></td></tr>
				<tr><th><?php esc_html_e( 'Letter Grade', 'lifterlms' ); ?></th>
					<td><select name="letter_grade">
						<?php foreach ( array_keys( self::GPA_SCALE ) as $lg ) : ?>
							<option value="<?php echo esc_attr( $lg ); ?>"><?php echo esc_html( $lg ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th><?php esc_html_e( 'GPA Points Override', 'lifterlms' ); ?></th>
					<td><input type="number" name="gpa_points" min="0" max="5" step="0.01" placeholder="Auto-calculated" style="width:100px;">
					<span class="description"><?php esc_html_e( 'Leave blank to use standard 4.0 scale.', 'lifterlms' ); ?></span></td></tr>
				<tr><th><?php esc_html_e( 'Subject Area', 'lifterlms' ); ?></th>
					<td><select name="subject_area">
						<option value="English"><?php esc_html_e( 'English', 'lifterlms' ); ?></option>
						<option value="Mathematics"><?php esc_html_e( 'Mathematics', 'lifterlms' ); ?></option>
						<option value="Science"><?php esc_html_e( 'Science', 'lifterlms' ); ?></option>
						<option value="Social Studies"><?php esc_html_e( 'Social Studies', 'lifterlms' ); ?></option>
						<option value="World Languages"><?php esc_html_e( 'World Languages', 'lifterlms' ); ?></option>
						<option value="Elective"><?php esc_html_e( 'Elective', 'lifterlms' ); ?></option>
						<option value="CTE"><?php esc_html_e( 'CTE / Vocational', 'lifterlms' ); ?></option>
						<option value="Physical Education"><?php esc_html_e( 'Physical Education', 'lifterlms' ); ?></option>
						<option value="Fine Arts"><?php esc_html_e( 'Fine Arts', 'lifterlms' ); ?></option>
					</select></td></tr>
				<tr><th><?php esc_html_e( 'Course Type', 'lifterlms' ); ?></th>
					<td><select name="course_type">
						<option value="core"><?php esc_html_e( 'Core', 'lifterlms' ); ?></option>
						<option value="elective"><?php esc_html_e( 'Elective', 'lifterlms' ); ?></option>
						<option value="honors"><?php esc_html_e( 'Honors', 'lifterlms' ); ?></option>
						<option value="ap"><?php esc_html_e( 'AP', 'lifterlms' ); ?></option>
						<option value="dual_enrollment"><?php esc_html_e( 'Dual Enrollment', 'lifterlms' ); ?></option>
					</select></td></tr>
				<tr><th><?php esc_html_e( 'NCAA Approved', 'lifterlms' ); ?></th>
					<td><label><input type="checkbox" name="ncaa_approved" value="1"> <?php esc_html_e( 'Yes', 'lifterlms' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Notes', 'lifterlms' ); ?></th>
					<td><textarea name="notes" rows="2" class="large-text"></textarea></td></tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add Transfer Course', 'lifterlms' ); ?></button></p>
		</form>

		<h3><?php esc_html_e( 'Existing Transfer Courses', 'lifterlms' ); ?></h3>
		<?php
		$transfers = $this->get_transfer_courses( $student_id );
		if ( empty( $transfers ) ) {
			echo '<p>' . esc_html__( 'No transfer courses recorded.', 'lifterlms' ) . '</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Course', 'lifterlms' ); ?></th>
				<th><?php esc_html_e( 'School', 'lifterlms' ); ?></th>
				<th><?php esc_html_e( 'Year', 'lifterlms' ); ?></th>
				<th><?php esc_html_e( 'Gr.', 'lifterlms' ); ?></th>
				<th><?php esc_html_e( 'Sem', 'lifterlms' ); ?></th>
				<th><?php esc_html_e( 'Credit', 'lifterlms' ); ?></th>
				<th><?php esc_html_e( 'Grade', 'lifterlms' ); ?></th>
				<th><?php esc_html_e( 'GPA', 'lifterlms' ); ?></th>
				<th><?php esc_html_e( 'Action', 'lifterlms' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $transfers as $tc ) : ?>
				<tr>
					<td><?php echo esc_html( $tc->course_name ); ?><br><small><?php echo esc_html( $tc->course_code ); ?></small></td>
					<td><?php echo esc_html( $tc->school_name ); ?></td>
					<td><?php echo esc_html( $tc->academic_year . '–' . ( $tc->academic_year + 1 ) ); ?></td>
					<td><?php echo esc_html( $tc->grade_level ); ?></td>
					<td><?php echo esc_html( $tc->semester ); ?></td>
					<td><?php echo esc_html( $tc->credit_hours ); ?></td>
					<td><?php echo esc_html( $tc->letter_grade ); ?></td>
					<td><?php echo is_numeric( $tc->gpa_points ) ? esc_html( number_format( $tc->gpa_points, 2 ) ) : '—'; ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<?php wp_nonce_field( 'ahsa_delete_transfer_course' ); ?>
							<input type="hidden" name="action" value="ahsa_delete_transfer_course">
							<input type="hidden" name="transfer_id" value="<?php echo esc_attr( $tc->id ); ?>">
							<input type="hidden" name="student_user_id" value="<?php echo esc_attr( $student_id ); ?>">
							<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Delete this transfer course?', 'lifterlms' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'lifterlms' ); ?>
							</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* =========================================================
	   Tab: School Info
	   ========================================================= */

	private function render_school_tab() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ahsa_save_school_info' ); ?>
			<input type="hidden" name="action" value="ahsa_save_school_info">

			<h3><?php esc_html_e( 'School Information', 'lifterlms' ); ?></h3>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'School Name', 'lifterlms' ); ?></th>
					<td><input type="text" name="school_name" value="<?php echo esc_attr( $this->get_school_field( 'school_name', 'American High School Academy' ) ); ?>" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Address', 'lifterlms' ); ?></th>
					<td><input type="text" name="school_address" value="<?php echo esc_attr( $this->get_school_field( 'school_address' ) ); ?>" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'City', 'lifterlms' ); ?></th>
					<td><input type="text" name="school_city" value="<?php echo esc_attr( $this->get_school_field( 'school_city' ) ); ?>" style="width:200px;"></td></tr>
				<tr><th><?php esc_html_e( 'State', 'lifterlms' ); ?></th>
					<td><input type="text" name="school_state" value="<?php echo esc_attr( $this->get_school_field( 'school_state', 'FL' ) ); ?>" maxlength="10" style="width:60px;"></td></tr>
				<tr><th><?php esc_html_e( 'ZIP Code', 'lifterlms' ); ?></th>
					<td><input type="text" name="school_zip" value="<?php echo esc_attr( $this->get_school_field( 'school_zip' ) ); ?>" style="width:100px;"></td></tr>
				<tr><th><?php esc_html_e( 'Phone', 'lifterlms' ); ?></th>
					<td><input type="text" name="school_phone" value="<?php echo esc_attr( $this->get_school_field( 'school_phone' ) ); ?>" style="width:200px;"></td></tr>
				<tr><th><?php esc_html_e( 'Fax', 'lifterlms' ); ?></th>
					<td><input type="text" name="school_fax" value="<?php echo esc_attr( $this->get_school_field( 'school_fax' ) ); ?>" style="width:200px;"></td></tr>
				<tr><th><?php esc_html_e( 'Website', 'lifterlms' ); ?></th>
					<td><input type="url" name="school_website" value="<?php echo esc_attr( $this->get_school_field( 'school_website' ) ); ?>" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Email', 'lifterlms' ); ?></th>
					<td><input type="email" name="school_email" value="<?php echo esc_attr( $this->get_school_field( 'school_email' ) ); ?>" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'CEEB / School Code', 'lifterlms' ); ?></th>
					<td><input type="text" name="school_ceeb_code" value="<?php echo esc_attr( $this->get_school_field( 'school_ceeb_code' ) ); ?>" style="width:150px;"></td></tr>
				<tr><th><?php esc_html_e( 'Accreditation', 'lifterlms' ); ?></th>
					<td><input type="text" name="school_accreditation" value="<?php echo esc_attr( $this->get_school_field( 'school_accreditation' ) ); ?>" class="regular-text" placeholder="e.g. Cognia / SACS CASI"></td></tr>
			</table>

			<h3><?php esc_html_e( 'School Personnel', 'lifterlms' ); ?></h3>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Principal Name', 'lifterlms' ); ?></th>
					<td><input type="text" name="principal_name" value="<?php echo esc_attr( $this->get_school_field( 'principal_name' ) ); ?>" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Principal Title', 'lifterlms' ); ?></th>
					<td><input type="text" name="principal_title" value="<?php echo esc_attr( $this->get_school_field( 'principal_title', 'Principal' ) ); ?>" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Counselor Name', 'lifterlms' ); ?></th>
					<td><input type="text" name="counselor_name" value="<?php echo esc_attr( $this->get_school_field( 'counselor_name' ) ); ?>" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Counselor Title', 'lifterlms' ); ?></th>
					<td><input type="text" name="counselor_title" value="<?php echo esc_attr( $this->get_school_field( 'counselor_title', 'Guidance Counselor' ) ); ?>" class="regular-text"></td></tr>
			</table>

			<h3><?php esc_html_e( 'Transcript Notes', 'lifterlms' ); ?></h3>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Grading Scale Note', 'lifterlms' ); ?></th>
					<td><textarea name="grading_scale_note" rows="3" class="large-text"><?php echo esc_textarea( $this->get_school_field( 'grading_scale_note', 'A=90-100 (4.0)  B=80-89 (3.0)  C=70-79 (2.0)  D=60-69 (1.0)  F=0-59 (0.0)' ) ); ?></textarea></td></tr>
				<tr><th><?php esc_html_e( 'Footer Note', 'lifterlms' ); ?></th>
					<td><textarea name="transcript_footer_note" rows="3" class="large-text"><?php echo esc_textarea( $this->get_school_field( 'transcript_footer_note', 'This is an official transcript of the American High School Academy. All courses are aligned with Florida state standards.' ) ); ?></textarea></td></tr>
			</table>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save School Info', 'lifterlms' ); ?></button></p>
		</form>
		<?php
	}

	/* =========================================================
	   Tab: Student Info
	   ========================================================= */

	private function render_student_tab() {
		$student_id = isset( $_GET['student_id'] ) ? absint( $_GET['student_id'] ) : 0;
		$students   = get_users( array( 'role__in' => array( 'student', 'subscriber' ), 'number' => 500, 'orderby' => 'display_name' ) );
		?>
		<form method="get">
			<input type="hidden" name="page" value="ahsa-transcripts">
			<input type="hidden" name="tab" value="student">
			<label><strong><?php esc_html_e( 'Student:', 'lifterlms' ); ?></strong></label>
			<select name="student_id">
				<option value=""><?php esc_html_e( '— Choose Student —', 'lifterlms' ); ?></option>
				<?php foreach ( $students as $u ) : ?>
					<option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $student_id, $u->ID ); ?>>
						<?php echo esc_html( $u->display_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Load', 'lifterlms' ); ?></button>
		</form>

		<?php if ( ! $student_id ) { return; }
		$user = get_userdata( $student_id );
		if ( ! $user ) { return; }
		?>

		<h3><?php printf( esc_html__( 'Student Info: %s', 'lifterlms' ), esc_html( $user->display_name ) ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ahsa_save_student_info' ); ?>
			<input type="hidden" name="action" value="ahsa_save_student_info">
			<input type="hidden" name="student_user_id" value="<?php echo esc_attr( $student_id ); ?>">

			<table class="form-table">
				<tr><th><?php esc_html_e( 'Student ID Number', 'lifterlms' ); ?></th>
					<td><input type="text" name="student_id_number" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'student_id_number' ) ); ?>" style="width:200px;"></td></tr>
				<tr><th><?php esc_html_e( 'Date of Birth', 'lifterlms' ); ?></th>
					<td><input type="date" name="date_of_birth" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'date_of_birth' ) ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Gender', 'lifterlms' ); ?></th>
					<td><select name="gender">
						<option value=""><?php esc_html_e( '—', 'lifterlms' ); ?></option>
						<?php foreach ( array( 'Male', 'Female', 'Non-Binary', 'Prefer Not to Say' ) as $g ) :
							$v = $this->get_student_field( $student_id, 'gender' ); ?>
							<option value="<?php echo esc_attr( $g ); ?>" <?php selected( $v, $g ); ?>><?php echo esc_html( $g ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th><?php esc_html_e( 'Parent / Guardian', 'lifterlms' ); ?></th>
					<td><input type="text" name="parent_guardian" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'parent_guardian' ) ); ?>" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Address', 'lifterlms' ); ?></th>
					<td><input type="text" name="address" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'address' ) ); ?>" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'City / State / ZIP', 'lifterlms' ); ?></th>
					<td>
						<input type="text" name="city" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'city' ) ); ?>" placeholder="City" style="width:160px;">
						<input type="text" name="state" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'state' ) ); ?>" placeholder="FL" style="width:60px;">
						<input type="text" name="zip" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'zip' ) ); ?>" placeholder="ZIP" style="width:80px;">
					</td></tr>
				<tr><th><?php esc_html_e( 'Phone', 'lifterlms' ); ?></th>
					<td><input type="tel" name="phone" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'phone' ) ); ?>" style="width:200px;"></td></tr>
				<tr><th><?php esc_html_e( 'Email', 'lifterlms' ); ?></th>
					<td><input type="email" name="email" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'email', $user->user_email ) ); ?>" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Expected Graduation', 'lifterlms' ); ?></th>
					<td><input type="text" name="expected_graduation" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'expected_graduation' ) ); ?>" placeholder="June 2027" style="width:150px;"></td></tr>
				<tr><th><?php esc_html_e( 'Enrollment Date', 'lifterlms' ); ?></th>
					<td><input type="date" name="enrollment_date" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'enrollment_date' ) ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Withdrawal Date', 'lifterlms' ); ?></th>
					<td><input type="date" name="withdrawal_date" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'withdrawal_date' ) ); ?>">
					<span class="description"><?php esc_html_e( 'Leave blank if currently enrolled.', 'lifterlms' ); ?></span></td></tr>
				<tr><th><?php esc_html_e( 'Class Rank / Size', 'lifterlms' ); ?></th>
					<td>
						<input type="text" name="class_rank" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'class_rank' ) ); ?>" placeholder="Rank" style="width:80px;">
						<span> / </span>
						<input type="text" name="class_size" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'class_size' ) ); ?>" placeholder="Total" style="width:80px;">
					</td></tr>
				<tr><th><?php esc_html_e( 'SAT Score', 'lifterlms' ); ?></th>
					<td><input type="text" name="sat_score" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'sat_score' ) ); ?>" placeholder="e.g. 1200" style="width:120px;"></td></tr>
				<tr><th><?php esc_html_e( 'ACT Score', 'lifterlms' ); ?></th>
					<td><input type="text" name="act_score" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'act_score' ) ); ?>" placeholder="e.g. 28" style="width:80px;"></td></tr>
				<tr><th><?php esc_html_e( 'Community Service Hours', 'lifterlms' ); ?></th>
					<td><input type="text" name="community_service_hours" value="<?php echo esc_attr( $this->get_student_field( $student_id, 'community_service_hours' ) ); ?>" placeholder="0" style="width:80px;"></td></tr>
			</table>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Student Info', 'lifterlms' ); ?></button></p>
		</form>
		<?php
	}

	/* =========================================================
	   Transcript HTML renderer (admin + frontend shortcode)
	   ========================================================= */

	/**
	 * Render the full 4-year transcript.
	 *
	 * @param int  $student_id
	 * @param bool $is_admin   Whether this is the admin context.
	 * @param bool $print      Whether to render in print-friendly mode.
	 */
	public function render_transcript_html( $student_id, $is_admin = false, $print = false ) {
		$user = get_userdata( $student_id );
		if ( ! $user ) {
			echo '<p>' . esc_html__( 'Student not found.', 'lifterlms' ) . '</p>';
			return;
		}

		$transcript = $this->build_transcript_data( $student_id );

		// Flatten all courses for cumulative GPA.
		$all_courses = array();
		foreach ( $transcript as $gl => $semesters ) {
			foreach ( $semesters as $courses ) {
				$all_courses = array_merge( $all_courses, $courses );
			}
		}
		$cumulative = $this->calculate_gpa( $all_courses );

		$grade_labels = array(
			9  => __( 'Freshman (Grade 9)', 'lifterlms' ),
			10 => __( 'Sophomore (Grade 10)', 'lifterlms' ),
			11 => __( 'Junior (Grade 11)', 'lifterlms' ),
			12 => __( 'Senior (Grade 12)', 'lifterlms' ),
		);

		$print_class = $print ? ' ahsa-transcript--print' : '';
		?>
		<div class="ahsa-transcript<?php echo esc_attr( $print_class ); ?>">

			<?php if ( $print ) : ?>
			<style>
				@media print {
					body * { visibility: hidden; }
					.ahsa-transcript, .ahsa-transcript * { visibility: visible; }
					.ahsa-transcript { position: absolute; left: 0; top: 0; width: 100%; }
					.no-print { display: none !important; }
				}
				.ahsa-transcript--print { font-family: 'Times New Roman', serif; font-size: 11pt; color: #000; max-width: 800px; margin: 0 auto; }
				.ahsa-transcript--print .ahsa-table th { background: #333 !important; -webkit-print-color-adjust: exact; }
			</style>
			<?php endif; ?>

			<!-- School Header -->
			<div class="ahsa-transcript__header">
				<h2 class="ahsa-text-navy"><?php echo esc_html( $this->get_school_field( 'school_name', 'American High School Academy' ) ); ?></h2>
				<p>
					<?php
					$parts = array_filter( array(
						$this->get_school_field( 'school_address' ),
						$this->get_school_field( 'school_city' ),
						$this->get_school_field( 'school_state' ),
						$this->get_school_field( 'school_zip' ),
					) );
					echo esc_html( implode( ', ', $parts ) );
					?>
				</p>
				<?php
				$phone = $this->get_school_field( 'school_phone' );
				$fax   = $this->get_school_field( 'school_fax' );
				$web   = $this->get_school_field( 'school_website' );
				if ( $phone || $fax || $web ) :
				?>
				<p>
					<?php if ( $phone ) : ?><?php printf( esc_html__( 'Phone: %s', 'lifterlms' ), esc_html( $phone ) ); ?> &nbsp; <?php endif; ?>
					<?php if ( $fax ) : ?><?php printf( esc_html__( 'Fax: %s', 'lifterlms' ), esc_html( $fax ) ); ?> &nbsp; <?php endif; ?>
					<?php if ( $web ) : ?><?php echo esc_html( $web ); ?><?php endif; ?>
				</p>
				<?php endif; ?>
				<?php
				$ceeb  = $this->get_school_field( 'school_ceeb_code' );
				$accred = $this->get_school_field( 'school_accreditation' );
				if ( $ceeb || $accred ) :
				?>
				<p>
					<?php if ( $ceeb ) : ?><?php printf( esc_html__( 'CEEB Code: %s', 'lifterlms' ), esc_html( $ceeb ) ); ?> &nbsp; <?php endif; ?>
					<?php if ( $accred ) : ?><?php printf( esc_html__( 'Accreditation: %s', 'lifterlms' ), esc_html( $accred ) ); ?><?php endif; ?>
				</p>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Official Academic Transcript', 'lifterlms' ); ?></h3>
			</div>

			<!-- Student Information Section -->
			<div class="ahsa-transcript__student-info">
				<table class="ahsa-table">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Student Name:', 'lifterlms' ); ?></strong> <?php echo esc_html( $user->display_name ); ?></td>
							<td><strong><?php esc_html_e( 'Student ID:', 'lifterlms' ); ?></strong> <?php echo esc_html( $this->get_student_field( $student_id, 'student_id_number', $student_id ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Date of Birth:', 'lifterlms' ); ?></strong> <?php echo esc_html( $this->get_student_field( $student_id, 'date_of_birth', '—' ) ); ?></td>
							<td><strong><?php esc_html_e( 'Gender:', 'lifterlms' ); ?></strong> <?php echo esc_html( $this->get_student_field( $student_id, 'gender', '—' ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Parent/Guardian:', 'lifterlms' ); ?></strong> <?php echo esc_html( $this->get_student_field( $student_id, 'parent_guardian', '—' ) ); ?></td>
							<td><strong><?php esc_html_e( 'Expected Graduation:', 'lifterlms' ); ?></strong> <?php echo esc_html( $this->get_student_field( $student_id, 'expected_graduation', '—' ) ); ?></td>
						</tr>
						<tr>
							<td colspan="2">
								<strong><?php esc_html_e( 'Address:', 'lifterlms' ); ?></strong>
								<?php
								$addr_parts = array_filter( array(
									$this->get_student_field( $student_id, 'address' ),
									$this->get_student_field( $student_id, 'city' ),
									$this->get_student_field( $student_id, 'state' ),
									$this->get_student_field( $student_id, 'zip' ),
								) );
								echo esc_html( $addr_parts ? implode( ', ', $addr_parts ) : '—' );
								?>
							</td>
						</tr>
						<?php
						$sat_score  = $this->get_student_field( $student_id, 'sat_score' );
						$act_score  = $this->get_student_field( $student_id, 'act_score' );
						$rank       = $this->get_student_field( $student_id, 'class_rank' );
						$size       = $this->get_student_field( $student_id, 'class_size' );
						$service_hr = $this->get_student_field( $student_id, 'community_service_hours' );
						if ( $sat_score || $act_score || $rank ) :
						?>
						<tr>
							<td>
								<?php if ( $sat_score ) : ?><strong><?php esc_html_e( 'SAT:', 'lifterlms' ); ?></strong> <?php echo esc_html( $sat_score ); ?> &nbsp; <?php endif; ?>
								<?php if ( $act_score ) : ?><strong><?php esc_html_e( 'ACT:', 'lifterlms' ); ?></strong> <?php echo esc_html( $act_score ); ?><?php endif; ?>
							</td>
							<td>
								<?php if ( $rank && $size ) : ?><strong><?php esc_html_e( 'Class Rank:', 'lifterlms' ); ?></strong> <?php echo esc_html( $rank . ' / ' . $size ); ?> &nbsp; <?php endif; ?>
								<?php if ( $service_hr ) : ?><strong><?php esc_html_e( 'Service Hours:', 'lifterlms' ); ?></strong> <?php echo esc_html( $service_hr ); ?><?php endif; ?>
							</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- 4-Year Academic Record -->
			<?php foreach ( array( 9, 10, 11, 12 ) as $gl ) :
				$sem1 = $transcript[ $gl ][1];
				$sem2 = $transcript[ $gl ][2];
				$year_courses = array_merge( $sem1, $sem2 );
				$year_gpa     = $this->calculate_gpa( $year_courses );

				// Determine academic year label from courses if available.
				$first_course = ! empty( $year_courses ) ? $year_courses[0] : null;
				$year_label   = $first_course && ! empty( $first_course->academic_year )
					? $first_course->academic_year . '–' . ( $first_course->academic_year + 1 )
					: '—';
			?>
			<div class="ahsa-transcript__year">
				<h4 class="ahsa-text-navy"><?php echo esc_html( $grade_labels[ $gl ] ); ?> — <?php echo esc_html( $year_label ); ?></h4>

				<?php foreach ( array( 1 => __( 'Semester 1 (Fall)', 'lifterlms' ), 2 => __( 'Semester 2 (Spring)', 'lifterlms' ) ) as $sem_num => $sem_label ) :
					$sem_courses = $transcript[ $gl ][ $sem_num ];
				?>
				<h5><?php echo esc_html( $sem_label ); ?></h5>

				<?php if ( empty( $sem_courses ) ) : ?>
					<p class="ahsa-text-muted"><em><?php esc_html_e( 'No courses recorded.', 'lifterlms' ); ?></em></p>
				<?php else : ?>
					<table class="ahsa-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Course', 'lifterlms' ); ?></th>
								<th><?php esc_html_e( 'Code', 'lifterlms' ); ?></th>
								<th class="text-center"><?php esc_html_e( 'Credit', 'lifterlms' ); ?></th>
								<th class="text-center"><?php esc_html_e( 'Grade', 'lifterlms' ); ?></th>
								<th class="text-center"><?php esc_html_e( 'GPA Pts', 'lifterlms' ); ?></th>
								<th><?php esc_html_e( 'Source', 'lifterlms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $sem_courses as $c ) :
								$source_label = 'transfer' === $c->source
									? esc_html( $c->school_name ) . ' <span class="ahsa-badge ahsa-badge--warning">TR</span>'
									: esc_html__( 'AHSA', 'lifterlms' );
								$gpa_display  = is_numeric( $c->gpa_points ) ? number_format( $c->gpa_points, 2 ) : '—';
								$grade_class  = '';
								if ( 'IP' === $c->letter_grade ) {
									$grade_class = ' class="ahsa-text-warning"';
								} elseif ( 'F' === $c->letter_grade ) {
									$grade_class = ' class="ahsa-text-danger"';
								}
							?>
							<tr>
								<td><?php echo esc_html( $c->course_name ); ?></td>
								<td><?php echo esc_html( $c->course_code ); ?></td>
								<td class="text-center"><?php echo esc_html( number_format( $c->credit_hours, 2 ) ); ?></td>
								<td class="text-center"><span<?php echo $grade_class; ?>><?php echo esc_html( $c->letter_grade ); ?></span></td>
								<td class="text-center"><?php echo esc_html( $gpa_display ); ?></td>
								<td><?php echo wp_kses( $source_label, array( 'span' => array( 'class' => true ) ) ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php endforeach; ?>

				<!-- Year Summary -->
				<?php if ( ! empty( $year_courses ) ) : ?>
				<div class="ahsa-transcript__year-summary">
					<table class="ahsa-table">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Year Credits:', 'lifterlms' ); ?></strong> <?php echo esc_html( number_format( $year_gpa['total_credits'], 2 ) ); ?></td>
								<td><strong><?php esc_html_e( 'Year Quality Points:', 'lifterlms' ); ?></strong> <?php echo esc_html( number_format( $year_gpa['quality_points'], 2 ) ); ?></td>
								<td><strong><?php esc_html_e( 'Year GPA:', 'lifterlms' ); ?></strong> <?php echo esc_html( number_format( $year_gpa['gpa'], 4 ) ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>

			<!-- Cumulative Summary -->
			<div class="ahsa-transcript__cumulative">
				<h4 class="ahsa-text-navy"><?php esc_html_e( 'Cumulative Summary', 'lifterlms' ); ?></h4>
				<table class="ahsa-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Total Credits Earned', 'lifterlms' ); ?></th>
							<th><?php esc_html_e( 'Total Quality Points', 'lifterlms' ); ?></th>
							<th><?php esc_html_e( 'Cumulative GPA (4.0 Scale)', 'lifterlms' ); ?></th>
							<th><?php esc_html_e( 'Courses Calculated', 'lifterlms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td class="text-center"><strong><?php echo esc_html( number_format( $cumulative['total_credits'], 2 ) ); ?></strong></td>
							<td class="text-center"><strong><?php echo esc_html( number_format( $cumulative['quality_points'], 2 ) ); ?></strong></td>
							<td class="text-center"><strong><?php echo esc_html( number_format( $cumulative['gpa'], 4 ) ); ?></strong></td>
							<td class="text-center"><?php echo esc_html( $cumulative['courses_counted'] ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Grading Scale -->
			<div class="ahsa-transcript__grading-scale">
				<h4><?php esc_html_e( 'Grading Scale', 'lifterlms' ); ?></h4>
				<table class="ahsa-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Letter', 'lifterlms' ); ?></th>
							<th><?php esc_html_e( 'Percentage', 'lifterlms' ); ?></th>
							<th><?php esc_html_e( 'Quality Points', 'lifterlms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr><td>A (A+/A/A-)</td><td>90–100</td><td>4.0 / 4.0 / 3.7</td></tr>
						<tr><td>B (B+/B/B-)</td><td>80–89</td><td>3.3 / 3.0 / 2.7</td></tr>
						<tr><td>C (C+/C/C-)</td><td>70–79</td><td>2.3 / 2.0 / 1.7</td></tr>
						<tr><td>D (D+/D/D-)</td><td>60–69</td><td>1.3 / 1.0 / 0.7</td></tr>
						<tr><td>F</td><td>0–59</td><td>0.0</td></tr>
					</tbody>
				</table>
				<?php
				$scale_note = $this->get_school_field( 'grading_scale_note' );
				if ( $scale_note ) :
				?>
					<p><em><?php echo esc_html( $scale_note ); ?></em></p>
				<?php endif; ?>
			</div>

			<!-- Signature / Footer -->
			<div class="ahsa-transcript__footer">
				<?php
				$footer_note = $this->get_school_field( 'transcript_footer_note' );
				if ( $footer_note ) :
				?>
					<p><em><?php echo esc_html( $footer_note ); ?></em></p>
				<?php endif; ?>

				<table class="ahsa-transcript__signatures">
					<tr>
						<td>
							<div class="ahsa-transcript__sig-line">___________________________________</div>
							<div>
								<?php
								$princ_name  = $this->get_school_field( 'principal_name' );
								$princ_title = $this->get_school_field( 'principal_title', 'Principal' );
								echo esc_html( $princ_name ? "$princ_name, $princ_title" : $princ_title );
								?>
							</div>
						</td>
						<td>
							<div class="ahsa-transcript__sig-line">___________________________________</div>
							<div>
								<?php
								$coun_name  = $this->get_school_field( 'counselor_name' );
								$coun_title = $this->get_school_field( 'counselor_title', 'Guidance Counselor' );
								echo esc_html( $coun_name ? "$coun_name, $coun_title" : $coun_title );
								?>
							</div>
						</td>
						<td>
							<div class="ahsa-transcript__sig-line">___________________________________</div>
							<div><?php esc_html_e( 'Date', 'lifterlms' ); ?>: <?php echo esc_html( wp_date( 'F j, Y' ) ); ?></div>
						</td>
					</tr>
				</table>

				<p class="ahsa-text-muted" style="font-size:10px;margin-top:24px;">
					<?php printf( esc_html__( 'Transcript generated on %s. This document is not official unless signed and sealed.', 'lifterlms' ), esc_html( wp_date( 'F j, Y \a\t g:i A' ) ) ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/* =========================================================
	   Frontend shortcode
	   ========================================================= */

	public function shortcode_transcript( $atts ) {
		$atts = shortcode_atts( array(
			'student_id' => get_current_user_id(),
		), $atts, 'ahsa_transcript' );

		$student_id = absint( $atts['student_id'] );

		// Students can only view their own transcript.
		if ( ! current_user_can( 'manage_options' ) && (int) $student_id !== get_current_user_id() ) {
			return '<p>' . esc_html__( 'You do not have permission to view this transcript.', 'lifterlms' ) . '</p>';
		}

		if ( ! $student_id || ! get_userdata( $student_id ) ) {
			return '<p>' . esc_html__( 'Please log in to view your transcript.', 'lifterlms' ) . '</p>';
		}

		ob_start();
		$this->render_transcript_html( $student_id, false, false );
		return ob_get_clean();
	}

	/* =========================================================
	   POST handlers
	   ========================================================= */

	public function handle_add_transfer() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ahsa_add_transfer_course' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'lifterlms' ) );
		}
		$this->add_transfer_course( $_POST );
		$student_id = absint( $_POST['student_user_id'] ?? 0 );
		wp_safe_redirect( admin_url( "admin.php?page=ahsa-transcripts&tab=transfers&student_id={$student_id}&added=1" ) );
		exit;
	}

	public function handle_delete_transfer() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ahsa_delete_transfer_course' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'lifterlms' ) );
		}
		$transfer_id = absint( $_POST['transfer_id'] ?? 0 );
		$student_id  = absint( $_POST['student_user_id'] ?? 0 );
		$this->delete_transfer_course( $transfer_id, $student_id );
		wp_safe_redirect( admin_url( "admin.php?page=ahsa-transcripts&tab=transfers&student_id={$student_id}&deleted=1" ) );
		exit;
	}

	public function handle_save_school_info() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ahsa_save_school_info' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'lifterlms' ) );
		}
		$this->save_school_info( $_POST );
		wp_safe_redirect( admin_url( 'admin.php?page=ahsa-transcripts&tab=school&saved=1' ) );
		exit;
	}

	public function handle_save_student_info() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ahsa_save_student_info' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'lifterlms' ) );
		}
		$student_id = absint( $_POST['student_user_id'] ?? 0 );
		$this->save_student_info( $student_id, $_POST );
		wp_safe_redirect( admin_url( "admin.php?page=ahsa-transcripts&tab=student&student_id={$student_id}&saved=1" ) );
		exit;
	}

	public function handle_save_transcript() {
		// Reserved for future inline edits.
		wp_die( esc_html__( 'Not implemented.', 'lifterlms' ) );
	}
}
