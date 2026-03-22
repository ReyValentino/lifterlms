<?php
/**
 * AHSA Email Notifications — automated alerts for parents, students, and staff.
 *
 * Triggers:
 *  - Parent alert: student grade drops below threshold
 *  - Parent alert: new teacher note posted
 *  - Student reminder: upcoming quiz deadline (daily cron)
 *  - Admin alert: SEB flagged session
 *
 * @package AHSA
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

class AHSA_Email_Notifications {

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Grade-drop detection: fire after any quiz grade is recorded.
		add_action( 'llms_quiz_attempt_graded', array( $this, 'check_grade_drop' ), 20, 2 );

		// Teacher note posted → notify parent.
		add_action( 'ahsa_teacher_note_created', array( $this, 'notify_parent_teacher_note' ), 10, 2 );

		// SEB flagged session → notify admin.
		add_action( 'ahsa_exam_session_flagged', array( $this, 'notify_admin_flagged' ), 10, 2 );

		// Daily cron for student reminders.
		add_action( 'ahsa_daily_student_reminders', array( $this, 'send_student_reminders' ) );
		if ( ! wp_next_scheduled( 'ahsa_daily_student_reminders' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 07:00' ), 'daily', 'ahsa_daily_student_reminders' );
		}

		// Admin settings.
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 35 );
	}

	/* =========================================================
	   Settings
	   ========================================================= */

	private function get_option( $key, $default = '' ) {
		$opts = get_option( 'ahsa_email_settings', array() );
		return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
	}

	private function save_options( $data ) {
		$fields = array(
			'grade_drop_enabled',
			'grade_drop_threshold',
			'teacher_note_enabled',
			'student_reminder_enabled',
			'admin_flagged_enabled',
			'from_name',
			'from_email',
		);
		$clean = array();
		foreach ( $fields as $f ) {
			$clean[ $f ] = isset( $data[ $f ] ) ? sanitize_text_field( $data[ $f ] ) : '';
		}
		update_option( 'ahsa_email_settings', $clean );
	}

	/* =========================================================
	   1. Grade-Drop Alert (to parent)
	   ========================================================= */

	/**
	 * After a quiz is graded, check if the student's overall course grade fell
	 * below the configured threshold. If so, email linked parents.
	 *
	 * @param int    $student_id
	 * @param object $attempt
	 */
	public function check_grade_drop( $student_id, $attempt ) {
		if ( 'yes' !== $this->get_option( 'grade_drop_enabled', 'yes' ) ) {
			return;
		}

		$threshold = (float) $this->get_option( 'grade_drop_threshold', '70' );
		if ( ! class_exists( 'LLMS_Student' ) ) {
			return;
		}

		$student   = new \LLMS_Student( $student_id );
		$course_id = 0;

		// Determine course from the quiz.
		if ( is_object( $attempt ) && isset( $attempt->quiz_id ) ) {
			$lesson_id = get_post_meta( $attempt->quiz_id, '_llms_lesson_id', true );
			if ( $lesson_id ) {
				$course_id = (int) get_post_meta( $lesson_id, '_llms_parent_course', true );
			}
		}

		if ( ! $course_id ) {
			return;
		}

		// Get overall grade.
		$grade_pct = null;
		if ( function_exists( 'llms' ) && method_exists( llms()->grades(), 'get_grade' ) ) {
			$grade_pct = llms()->grades()->get_grade( $course_id, $student );
		}

		if ( null === $grade_pct || ! is_numeric( $grade_pct ) || (float) $grade_pct >= $threshold ) {
			return;
		}

		// Find parent(s).
		$parent_ids = $this->get_parent_ids( $student_id );
		if ( empty( $parent_ids ) ) {
			return;
		}

		$student_user = get_userdata( $student_id );
		$course_title = get_the_title( $course_id );

		foreach ( $parent_ids as $pid ) {
			$parent = get_userdata( $pid );
			if ( ! $parent ) {
				continue;
			}

			$subject = sprintf(
				/* translators: 1: student name, 2: course name */
				__( '[AHSA] Grade Alert — %1$s in %2$s', 'lifterlms' ),
				$student_user->display_name,
				$course_title
			);

			$body = sprintf(
				/* translators: 1: parent name, 2: student name, 3: course, 4: grade, 5: threshold */
				__(
					"Dear %1\$s,\n\nThis is an automated notification from American High School Academy.\n\n" .
					"%2\$s's grade in %3\$s has dropped to %4\$s%%, which is below the %5\$s%% threshold.\n\n" .
					"Please log in to the Parent Dashboard to review your student's progress and contact their teacher if you have questions.\n\n" .
					"Best regards,\nAmerican High School Academy",
					'lifterlms'
				),
				$parent->display_name,
				$student_user->display_name,
				$course_title,
				round( $grade_pct, 1 ),
				$threshold
			);

			$this->send( $parent->user_email, $subject, $body );
		}
	}

	/* =========================================================
	   2. Teacher Note Alert (to parent)
	   ========================================================= */

	/**
	 * Notify linked parents when a teacher posts a note.
	 *
	 * @param int   $student_id
	 * @param array $note_data  { author_user_id, note_content, course_id, ... }
	 */
	public function notify_parent_teacher_note( $student_id, $note_data ) {
		if ( 'yes' !== $this->get_option( 'teacher_note_enabled', 'yes' ) ) {
			return;
		}

		$parent_ids = $this->get_parent_ids( $student_id );
		if ( empty( $parent_ids ) ) {
			return;
		}

		$student_user = get_userdata( $student_id );
		$author       = get_userdata( $note_data['author_user_id'] ?? 0 );
		$course_title = ! empty( $note_data['course_id'] ) ? get_the_title( $note_data['course_id'] ) : '';

		foreach ( $parent_ids as $pid ) {
			$parent = get_userdata( $pid );
			if ( ! $parent ) {
				continue;
			}

			$subject = sprintf(
				__( '[AHSA] New Teacher Note for %s', 'lifterlms' ),
				$student_user->display_name
			);

			$body = sprintf(
				__(
					"Dear %1\$s,\n\nA new note has been posted for %2\$s%3\$s.\n\n" .
					"From: %4\$s\n" .
					"Note: %5\$s\n\n" .
					"Log in to the Parent Dashboard for full details.\n\n" .
					"Best regards,\nAmerican High School Academy",
					'lifterlms'
				),
				$parent->display_name,
				$student_user->display_name,
				$course_title ? ' in ' . $course_title : '',
				$author ? $author->display_name : __( 'A teacher', 'lifterlms' ),
				wp_strip_all_tags( $note_data['note_content'] ?? '' )
			);

			$this->send( $parent->user_email, $subject, $body );
		}
	}

	/* =========================================================
	   3. Student Reminders (daily cron)
	   ========================================================= */

	public function send_student_reminders() {
		if ( 'yes' !== $this->get_option( 'student_reminder_enabled', 'yes' ) ) {
			return;
		}

		// Find students with in-progress courses that have low completion.
		$students = get_users( array(
			'role__in' => array( 'student', 'subscriber' ),
			'number'   => 500,
		) );

		if ( ! class_exists( 'LLMS_Student' ) ) {
			return;
		}

		foreach ( $students as $u ) {
			$student = new \LLMS_Student( $u->ID );
			$stale   = array();

			// Check courses for stale progress (enrolled but < 10% progress in last 7 days).
			$enrollments = array();
			if ( method_exists( $student, 'get_enrollments' ) ) {
				$result = $student->get_enrollments( 'course', array( 'per_page' => 100, 'status' => 'enrolled' ) );
				if ( ! empty( $result['results'] ) ) {
					$enrollments = $result['results'];
				}
			}

			foreach ( $enrollments as $cid ) {
				if ( $student->is_complete( $cid, 'course' ) ) {
					continue;
				}
				$progress = $student->get_progress( $cid, 'course' );
				// Only remind if progress is between 1% and 90% (active but not nearly done).
				if ( $progress >= 1 && $progress < 90 ) {
					$stale[] = get_the_title( $cid ) . ' (' . round( $progress ) . '%)';
				}
			}

			if ( empty( $stale ) ) {
				continue;
			}

			// Rate-limit: 1 reminder per student per week.
			$last_sent = get_user_meta( $u->ID, '_ahsa_last_reminder_sent', true );
			if ( $last_sent && ( time() - (int) $last_sent ) < 7 * DAY_IN_SECONDS ) {
				continue;
			}

			$subject = __( '[AHSA] Weekly Course Progress Reminder', 'lifterlms' );
			$body    = sprintf(
				__(
					"Hi %1\$s,\n\nHere's a quick update on your AHSA courses still in progress:\n\n%2\$s\n\n" .
					"Keep up the great work! Log in to continue your coursework.\n\n" .
					"Best regards,\nAmerican High School Academy",
					'lifterlms'
				),
				$u->display_name,
				'• ' . implode( "\n• ", $stale )
			);

			if ( $this->send( $u->user_email, $subject, $body ) ) {
				update_user_meta( $u->ID, '_ahsa_last_reminder_sent', time() );
			}
		}
	}

	/* =========================================================
	   4. Admin Alert — Flagged Exam Session
	   ========================================================= */

	/**
	 * @param int   $session_id
	 * @param array $session_data
	 */
	public function notify_admin_flagged( $session_id, $session_data ) {
		if ( 'yes' !== $this->get_option( 'admin_flagged_enabled', 'yes' ) ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		$student     = get_userdata( $session_data['student_user_id'] ?? 0 );
		$quiz_title  = get_the_title( $session_data['quiz_id'] ?? 0 );

		$subject = sprintf(
			__( '[AHSA] Flagged Exam Session #%d', 'lifterlms' ),
			$session_id
		);

		$body = sprintf(
			__(
				"A flagged exam session requires review.\n\n" .
				"Session ID: %1\$d\n" .
				"Student: %2\$s\n" .
				"Quiz: %3\$s\n" .
				"Risk Level: %4\$s (score: %5\$s)\n\n" .
				"Please review this session in the Exam Security admin panel.",
				'lifterlms'
			),
			$session_id,
			$student ? $student->display_name : '(unknown)',
			$quiz_title,
			$session_data['risk_level'] ?? 'unknown',
			$session_data['risk_score'] ?? '0'
		);

		$this->send( $admin_email, $subject, $body );
	}

	/* =========================================================
	   Mail sender
	   ========================================================= */

	/**
	 * Send an email using wp_mail with AHSA headers.
	 *
	 * @return bool
	 */
	private function send( $to, $subject, $body ) {
		$from_name  = $this->get_option( 'from_name', 'American High School Academy' );
		$from_email = $this->get_option( 'from_email', get_option( 'admin_email' ) );

		$headers = array(
			'From: ' . sanitize_text_field( $from_name ) . ' <' . sanitize_email( $from_email ) . '>',
			'Content-Type: text/plain; charset=UTF-8',
		);

		return wp_mail( $to, $subject, $body, $headers );
	}

	/* =========================================================
	   Helper: parent IDs for a student
	   ========================================================= */

	private function get_parent_ids( $student_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ahsa_parent_student';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT parent_user_id FROM {$table} WHERE student_user_id = %d",
			absint( $student_id )
		) );
	}

	/* =========================================================
	   Admin Settings Page
	   ========================================================= */

	public function admin_menu() {
		add_submenu_page(
			'lifterlms',
			__( 'AHSA Email Notifications', 'lifterlms' ),
			__( 'Email Alerts', 'lifterlms' ),
			'manage_options',
			'ahsa-email-settings',
			array( $this, 'admin_page' )
		);
	}

	public function admin_page() {
		if ( ! empty( $_POST['ahsa_email_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['ahsa_email_nonce'] ), 'ahsa_email_settings' ) ) {
			$this->save_options( $_POST );
			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'lifterlms' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AHSA Email Notifications', 'lifterlms' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'ahsa_email_settings', 'ahsa_email_nonce' ); ?>

				<h3><?php esc_html_e( 'Sender', 'lifterlms' ); ?></h3>
				<table class="form-table">
					<tr><th><?php esc_html_e( 'From Name', 'lifterlms' ); ?></th>
						<td><input type="text" name="from_name" value="<?php echo esc_attr( $this->get_option( 'from_name', 'American High School Academy' ) ); ?>" class="regular-text"></td></tr>
					<tr><th><?php esc_html_e( 'From Email', 'lifterlms' ); ?></th>
						<td><input type="email" name="from_email" value="<?php echo esc_attr( $this->get_option( 'from_email', get_option( 'admin_email' ) ) ); ?>" class="regular-text"></td></tr>
				</table>

				<h3><?php esc_html_e( 'Notification Toggles', 'lifterlms' ); ?></h3>
				<table class="form-table">
					<tr><th><?php esc_html_e( 'Grade Drop Alert (to Parents)', 'lifterlms' ); ?></th>
						<td>
							<label><input type="checkbox" name="grade_drop_enabled" value="yes" <?php checked( $this->get_option( 'grade_drop_enabled', 'yes' ), 'yes' ); ?>> <?php esc_html_e( 'Enabled', 'lifterlms' ); ?></label>
						</td></tr>
					<tr><th><?php esc_html_e( 'Grade Drop Threshold (%)', 'lifterlms' ); ?></th>
						<td><input type="number" name="grade_drop_threshold" min="0" max="100" value="<?php echo esc_attr( $this->get_option( 'grade_drop_threshold', '70' ) ); ?>" style="width:80px;"></td></tr>
					<tr><th><?php esc_html_e( 'Teacher Note Alert (to Parents)', 'lifterlms' ); ?></th>
						<td>
							<label><input type="checkbox" name="teacher_note_enabled" value="yes" <?php checked( $this->get_option( 'teacher_note_enabled', 'yes' ), 'yes' ); ?>> <?php esc_html_e( 'Enabled', 'lifterlms' ); ?></label>
						</td></tr>
					<tr><th><?php esc_html_e( 'Student Progress Reminder (Weekly)', 'lifterlms' ); ?></th>
						<td>
							<label><input type="checkbox" name="student_reminder_enabled" value="yes" <?php checked( $this->get_option( 'student_reminder_enabled', 'yes' ), 'yes' ); ?>> <?php esc_html_e( 'Enabled', 'lifterlms' ); ?></label>
						</td></tr>
					<tr><th><?php esc_html_e( 'Admin Alert — Flagged Exam', 'lifterlms' ); ?></th>
						<td>
							<label><input type="checkbox" name="admin_flagged_enabled" value="yes" <?php checked( $this->get_option( 'admin_flagged_enabled', 'yes' ), 'yes' ); ?>> <?php esc_html_e( 'Enabled', 'lifterlms' ); ?></label>
						</td></tr>
				</table>

				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'lifterlms' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
