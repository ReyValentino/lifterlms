<?php
/**
 * AHSA Student Dashboard — personalised student view with enrolled courses,
 * progress bars, GPA overview, upcoming deadlines, and transcript link.
 *
 * Shortcode: [ahsa_student_dashboard]
 *
 * @package AHSA
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

class AHSA_Student_Dashboard {

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'ahsa_student_dashboard', array( $this, 'render' ) );
	}

	/**
	 * Shortcode handler.
	 */
	public function render( $atts ) {
		$student_id = get_current_user_id();
		if ( ! $student_id ) {
			return '<p>' . esc_html__( 'Please log in to view your dashboard.', 'lifterlms' ) . '</p>';
		}

		if ( ! class_exists( 'LLMS_Student' ) ) {
			return '<p>' . esc_html__( 'LifterLMS is required.', 'lifterlms' ) . '</p>';
		}

		$user    = get_userdata( $student_id );
		$student = new \LLMS_Student( $student_id );

		// Gather enrolled courses.
		$course_ids = $this->get_enrolled_course_ids( $student_id );

		// Calculate overview stats.
		$completed   = 0;
		$in_progress = 0;
		$total_credits_earned = 0;
		$gpa_courses = array();

		foreach ( $course_ids as $cid ) {
			$is_complete = $student->is_complete( $cid, 'course' );
			$credit      = (float) get_post_meta( $cid, '_llms_credit_value', true ) ?: 0.5;

			if ( $is_complete ) {
				++$completed;
				$total_credits_earned += $credit;

				$grade_pct = '';
				if ( function_exists( 'llms' ) && method_exists( llms()->grades(), 'get_grade' ) ) {
					$grade_pct = llms()->grades()->get_grade( $cid, $student );
				}

				if ( is_numeric( $grade_pct ) && class_exists( 'AHSA_Transcript' ) ) {
					$obj               = new \stdClass();
					$obj->credit_hours = $credit;
					$obj->letter_grade = AHSA_Transcript::instance()->percent_to_letter( $grade_pct );
					$obj->gpa_points   = AHSA_Transcript::instance()->letter_to_gpa( $obj->letter_grade );
					$gpa_courses[]     = $obj;
				}
			} else {
				++$in_progress;
			}
		}

		$gpa_data = array( 'gpa' => 0 );
		if ( class_exists( 'AHSA_Transcript' ) && ! empty( $gpa_courses ) ) {
			$gpa_data = AHSA_Transcript::instance()->calculate_gpa( $gpa_courses );
		}

		ob_start();
		?>
		<div class="ahsa-student-dashboard">
			<h2><?php printf( esc_html__( 'Welcome, %s', 'lifterlms' ), esc_html( $user->first_name ?: $user->display_name ) ); ?></h2>

			<!-- Stats Row -->
			<div class="ahsa-dashboard-stats">
				<div class="ahsa-dashboard-stat">
					<div class="ahsa-dashboard-stat__value"><?php echo esc_html( count( $course_ids ) ); ?></div>
					<div class="ahsa-dashboard-stat__label"><?php esc_html_e( 'Enrolled', 'lifterlms' ); ?></div>
				</div>
				<div class="ahsa-dashboard-stat">
					<div class="ahsa-dashboard-stat__value"><?php echo esc_html( $completed ); ?></div>
					<div class="ahsa-dashboard-stat__label"><?php esc_html_e( 'Completed', 'lifterlms' ); ?></div>
				</div>
				<div class="ahsa-dashboard-stat">
					<div class="ahsa-dashboard-stat__value"><?php echo esc_html( $in_progress ); ?></div>
					<div class="ahsa-dashboard-stat__label"><?php esc_html_e( 'In Progress', 'lifterlms' ); ?></div>
				</div>
				<div class="ahsa-dashboard-stat">
					<div class="ahsa-dashboard-stat__value"><?php echo esc_html( number_format( $total_credits_earned, 1 ) ); ?></div>
					<div class="ahsa-dashboard-stat__label"><?php esc_html_e( 'Credits Earned', 'lifterlms' ); ?></div>
				</div>
				<div class="ahsa-dashboard-stat">
					<div class="ahsa-dashboard-stat__value"><?php echo esc_html( number_format( $gpa_data['gpa'], 2 ) ); ?></div>
					<div class="ahsa-dashboard-stat__label"><?php esc_html_e( 'Cumulative GPA', 'lifterlms' ); ?></div>
				</div>
			</div>

			<!-- In-Progress Courses -->
			<?php if ( $in_progress > 0 ) : ?>
			<h3 class="ahsa-text-navy"><?php esc_html_e( 'Courses In Progress', 'lifterlms' ); ?></h3>
			<?php
			foreach ( $course_ids as $cid ) :
				if ( $student->is_complete( $cid, 'course' ) ) {
					continue;
				}
				$progress  = $student->get_progress( $cid, 'course' );
				$title     = get_the_title( $cid );
				$grade_pct = '';
				if ( function_exists( 'llms' ) && method_exists( llms()->grades(), 'get_grade' ) ) {
					$grade_pct = llms()->grades()->get_grade( $cid, $student );
				}
				$progress_class = $progress >= 70 ? 'ahsa-progress-fill--on-track' : ( $progress >= 40 ? 'ahsa-progress-fill--behind' : 'ahsa-progress-fill--at-risk' );
			?>
			<div class="ahsa-course-card">
				<div class="ahsa-course-card__header">
					<span class="ahsa-course-card__title"><?php echo esc_html( $title ); ?></span>
					<span class="ahsa-badge ahsa-badge--warning"><?php esc_html_e( 'In Progress', 'lifterlms' ); ?></span>
				</div>
				<div class="ahsa-progress-track">
					<div class="ahsa-progress-fill <?php echo esc_attr( $progress_class ); ?>" style="width:<?php echo esc_attr( min( 100, $progress ) ); ?>%;">
						<?php echo esc_html( round( $progress ) . '%' ); ?>
					</div>
				</div>
				<?php if ( is_numeric( $grade_pct ) ) : ?>
					<p class="ahsa-text-muted" style="margin:6px 0 0;font-size:13px;">
						<?php printf( esc_html__( 'Current Grade: %s%%', 'lifterlms' ), esc_html( round( $grade_pct, 1 ) ) ); ?>
					</p>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
			<?php endif; ?>

			<!-- Completed Courses -->
			<?php if ( $completed > 0 ) : ?>
			<h3 class="ahsa-text-navy"><?php esc_html_e( 'Completed Courses', 'lifterlms' ); ?></h3>
			<table class="ahsa-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Course', 'lifterlms' ); ?></th>
						<th class="text-center"><?php esc_html_e( 'Credit', 'lifterlms' ); ?></th>
						<th class="text-center"><?php esc_html_e( 'Grade', 'lifterlms' ); ?></th>
						<th class="text-center"><?php esc_html_e( 'GPA', 'lifterlms' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $course_ids as $cid ) :
					if ( ! $student->is_complete( $cid, 'course' ) ) {
						continue;
					}
					$credit    = (float) get_post_meta( $cid, '_llms_credit_value', true ) ?: 0.5;
					$grade_pct = '';
					if ( function_exists( 'llms' ) && method_exists( llms()->grades(), 'get_grade' ) ) {
						$grade_pct = llms()->grades()->get_grade( $cid, $student );
					}
					$letter = '';
					$gpa_pt = '—';
					if ( is_numeric( $grade_pct ) && class_exists( 'AHSA_Transcript' ) ) {
						$letter = AHSA_Transcript::instance()->percent_to_letter( $grade_pct );
						$pts    = AHSA_Transcript::instance()->letter_to_gpa( $letter );
						$gpa_pt = is_numeric( $pts ) ? number_format( $pts, 2 ) : '—';
					}
				?>
				<tr>
					<td><?php echo esc_html( get_the_title( $cid ) ); ?></td>
					<td class="text-center"><?php echo esc_html( number_format( $credit, 2 ) ); ?></td>
					<td class="text-center"><?php echo esc_html( $letter ?: '—' ); ?></td>
					<td class="text-center"><?php echo esc_html( $gpa_pt ); ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<!-- Quick Links -->
			<div class="ahsa-dashboard-links" style="margin-top:24px;">
				<?php
				$transcript_page = get_page_by_path( 'student-transcript' );
				if ( $transcript_page ) :
				?>
					<a href="<?php echo esc_url( get_permalink( $transcript_page ) ); ?>" class="button"><?php esc_html_e( 'View Full Transcript', 'lifterlms' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get all enrolled course IDs for a student.
	 */
	private function get_enrolled_course_ids( $student_id ) {
		$student = new \LLMS_Student( $student_id );
		$ids     = array();

		if ( method_exists( $student, 'get_enrollments' ) ) {
			$enrollments = $student->get_enrollments( 'course', array( 'per_page' => 1000, 'status' => 'any' ) );
			if ( ! empty( $enrollments['results'] ) ) {
				$ids = $enrollments['results'];
			}
		}

		// Fallback: direct query.
		if ( empty( $ids ) ) {
			global $wpdb;
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'course' AND p.post_status = 'publish'
				 AND pm.meta_key = %s AND pm.meta_value != ''",
				'_llms_student_' . absint( $student_id ) . '_status'
			) );
		}

		return array_map( 'absint', $ids );
	}
}
