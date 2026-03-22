<?php
/**
 * AHSA Admin Dashboard Widget — overview panel on the WP admin home.
 *
 * Shows: enrollment counts, flagged exams, compliance status, GPA distribution,
 * recent teacher notes.
 *
 * @package AHSA
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

class AHSA_Admin_Dashboard {

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	public function register_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'ahsa_overview',
			__( 'AHSA School Overview', 'lifterlms' ),
			array( $this, 'render_widget' )
		);
	}

	public function render_widget() {
		global $wpdb;

		// Course count.
		$course_count = wp_count_posts( 'course' );
		$published    = isset( $course_count->publish ) ? (int) $course_count->publish : 0;

		// Student count.
		$student_count = count_users();
		$students      = 0;
		if ( isset( $student_count['avail_roles']['student'] ) ) {
			$students = (int) $student_count['avail_roles']['student'];
		} elseif ( isset( $student_count['avail_roles']['subscriber'] ) ) {
			$students = (int) $student_count['avail_roles']['subscriber'];
		}

		// Parent count.
		$parents = 0;
		if ( isset( $student_count['avail_roles']['ahsa_parent'] ) ) {
			$parents = (int) $student_count['avail_roles']['ahsa_parent'];
		}

		// Flagged exam sessions.
		$flagged = 0;
		$sessions_table = $wpdb->prefix . 'ahsa_exam_sessions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) ) === $sessions_table ) {
			$flagged = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$sessions_table} WHERE risk_level IN ('moderate','high','critical') AND admin_reviewed = 0"
			);
		}

		// Compliance: courses with rubric + standards.
		$rubric_count = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_ahsa_rubric_id' AND meta_value != '' AND meta_value != '0'"
		);

		$standards_table = $wpdb->prefix . 'ahsa_standards_alignment';
		$aligned_count   = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $standards_table ) ) === $standards_table ) {
			$aligned_count = (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT post_id) FROM {$standards_table} WHERE post_type = 'course'"
			);
		}

		// Transfer courses count.
		$transfer_table = $wpdb->prefix . 'ahsa_transfer_courses';
		$transfers      = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $transfer_table ) ) === $transfer_table ) {
			$transfers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$transfer_table}" );
		}
		?>
		<div class="ahsa-admin-widget">
			<div class="ahsa-exam-stats">
				<div class="ahsa-exam-stat">
					<div class="ahsa-exam-stat__value"><?php echo esc_html( $published ); ?></div>
					<div class="ahsa-exam-stat__label"><?php esc_html_e( 'Courses', 'lifterlms' ); ?></div>
				</div>
				<div class="ahsa-exam-stat">
					<div class="ahsa-exam-stat__value"><?php echo esc_html( $students ); ?></div>
					<div class="ahsa-exam-stat__label"><?php esc_html_e( 'Students', 'lifterlms' ); ?></div>
				</div>
				<div class="ahsa-exam-stat">
					<div class="ahsa-exam-stat__value"><?php echo esc_html( $parents ); ?></div>
					<div class="ahsa-exam-stat__label"><?php esc_html_e( 'Parents', 'lifterlms' ); ?></div>
				</div>
				<div class="ahsa-exam-stat">
					<div class="ahsa-exam-stat__value"><?php echo esc_html( $transfers ); ?></div>
					<div class="ahsa-exam-stat__label"><?php esc_html_e( 'Transfers', 'lifterlms' ); ?></div>
				</div>
			</div>

			<h4><?php esc_html_e( 'Compliance', 'lifterlms' ); ?></h4>
			<ul>
				<li><?php printf( esc_html__( 'Courses with rubrics: %d / %d', 'lifterlms' ), $rubric_count, $published ); ?></li>
				<li><?php printf( esc_html__( 'Courses with standards: %d / %d', 'lifterlms' ), $aligned_count, $published ); ?></li>
			</ul>

			<?php if ( $flagged > 0 ) : ?>
			<h4 class="ahsa-risk-critical"><?php printf( esc_html__( '%d Flagged Exam Sessions', 'lifterlms' ), $flagged ); ?></h4>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-exam-security&tab=flagged' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'Review Flagged Sessions', 'lifterlms' ); ?>
				</a>
			</p>
			<?php else : ?>
			<p class="ahsa-text-success"><?php esc_html_e( 'No flagged exam sessions.', 'lifterlms' ); ?></p>
			<?php endif; ?>

			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-transcripts' ) ); ?>"><?php esc_html_e( 'Transcript Manager', 'lifterlms' ); ?></a> |
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-rubrics' ) ); ?>"><?php esc_html_e( 'Rubrics', 'lifterlms' ); ?></a> |
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-standards' ) ); ?>"><?php esc_html_e( 'Standards', 'lifterlms' ); ?></a> |
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-email-settings' ) ); ?>"><?php esc_html_e( 'Email Alerts', 'lifterlms' ); ?></a>
			</p>
		</div>
		<?php
	}
}
