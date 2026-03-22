<?php
/**
 * AHSA Progression Enforcement Module.
 *
 * Enforces strict sequential progression across all courses:
 * - Lessons must be completed in order
 * - Quiz lessons require a passing grade before advancement
 * - Configurable passing threshold (60-69%, default 60%)
 * - Admin settings at global, per-course, and per-quiz level
 * - Student-facing messages when progression is blocked
 *
 * @package AHSA/Modules
 */

defined( 'ABSPATH' ) || exit;

/**
 * AHSA_Progression_Enforcement class.
 */
class AHSA_Progression_Enforcement {

	/**
	 * Singleton instance.
	 *
	 * @var AHSA_Progression_Enforcement|null
	 */
	private static $instance = null;

	/**
	 * Default minimum passing percentage.
	 *
	 * @var int
	 */
	const DEFAULT_PASSING_PERCENT = 60;

	/**
	 * Minimum allowed passing percentage.
	 *
	 * @var int
	 */
	const MIN_PASSING_PERCENT = 60;

	/**
	 * Maximum allowed passing percentage.
	 *
	 * @var int
	 */
	const MAX_PASSING_PERCENT = 69;

	/**
	 * Get singleton instance.
	 *
	 * @return AHSA_Progression_Enforcement
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Admin settings.
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 30 );

		// Course-level metabox.
		add_action( 'add_meta_boxes', array( $this, 'add_course_metabox' ) );
		add_action( 'save_post_course', array( $this, 'save_course_metabox' ), 10, 2 );

		// Quiz-level passing override metabox.
		add_action( 'add_meta_boxes', array( $this, 'add_quiz_metabox' ) );
		add_action( 'save_post_llms_quiz', array( $this, 'save_quiz_metabox' ), 10, 2 );

		// Enforce passing threshold on quiz completion.
		add_filter( 'llms_quiz_passing_percent', array( $this, 'filter_quiz_passing_percent' ), 10, 2 );

		// Override lesson availability check for strict sequential enforcement.
		add_filter( 'llms_is_lesson_complete', array( $this, 'filter_lesson_complete' ), 10, 4 );

		// Student-facing progression block notices.
		add_action( 'lifterlms_single_lesson_before_summary', array( $this, 'show_progression_notice' ) );

		// Block quiz start if prerequisite lesson not completed/passed.
		add_action( 'llms_quiz_attempt_new', array( $this, 'check_quiz_prerequisites' ), 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Admin Settings
	// -------------------------------------------------------------------------

	/**
	 * Register global settings.
	 */
	public function register_settings() {
		register_setting( 'ahsa_progression', 'ahsa_global_passing_percent', array(
			'type'              => 'integer',
			'default'           => self::DEFAULT_PASSING_PERCENT,
			'sanitize_callback' => array( $this, 'sanitize_passing_percent' ),
		) );
	}

	/**
	 * Sanitize passing percentage to enforced range.
	 *
	 * @param mixed $value Input value.
	 * @return int
	 */
	public function sanitize_passing_percent( $value ) {
		$value = absint( $value );
		return max( self::MIN_PASSING_PERCENT, min( self::MAX_PASSING_PERCENT, $value ) );
	}

	/**
	 * Add admin menu page.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'lifterlms',
			__( 'Progression Settings', 'lifterlms' ),
			__( 'Progression', 'lifterlms' ),
			'manage_lifterlms',
			'ahsa-progression',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the admin settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_lifterlms' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lifterlms' ) );
		}

		$global_percent = $this->get_global_passing_percent();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AHSA Progression Enforcement Settings', 'lifterlms' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'ahsa_progression' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="ahsa_global_passing_percent">
								<?php esc_html_e( 'Global Minimum Passing Percentage', 'lifterlms' ); ?>
							</label>
						</th>
						<td>
							<select name="ahsa_global_passing_percent" id="ahsa_global_passing_percent">
								<?php for ( $i = self::MIN_PASSING_PERCENT; $i <= self::MAX_PASSING_PERCENT; $i++ ) : ?>
									<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $global_percent, $i ); ?>>
										<?php echo esc_html( $i . '%' ); ?>
									</option>
								<?php endfor; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Students must earn at least this percentage on quizzes and exams to advance. This is the D-level progression threshold (60%-69%). Per-course and per-quiz overrides are available.', 'lifterlms' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Progression Rules Summary', 'lifterlms' ); ?></h2>
				<ul style="list-style:disc;margin-left:2em;">
					<li><?php esc_html_e( 'All lessons within each section must be completed in order.', 'lifterlms' ); ?></li>
					<li><?php esc_html_e( 'Quiz lessons require a passing grade before the student can advance.', 'lifterlms' ); ?></li>
					<li><?php esc_html_e( 'Sections must be completed sequentially — the next section is locked until the current section is fully passed.', 'lifterlms' ); ?></li>
					<li><?php esc_html_e( 'Course completion requires all sections, lessons, and quizzes to be completed and passed.', 'lifterlms' ); ?></li>
				</ul>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Course-Level Metabox
	// -------------------------------------------------------------------------

	/**
	 * Add course-level progression override metabox.
	 */
	public function add_course_metabox() {
		add_meta_box(
			'ahsa-course-progression',
			__( 'Progression Enforcement', 'lifterlms' ),
			array( $this, 'render_course_metabox' ),
			'course',
			'side',
			'default'
		);
	}

	/**
	 * Render the course-level metabox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_course_metabox( $post ) {
		$course_percent = get_post_meta( $post->ID, '_ahsa_course_passing_percent', true );
		$global_percent = $this->get_global_passing_percent();

		wp_nonce_field( 'ahsa_course_progression_' . $post->ID, 'ahsa_course_progression_nonce' );
		?>
		<p>
			<label for="ahsa_course_passing_percent">
				<strong><?php esc_html_e( 'Passing Percentage Override', 'lifterlms' ); ?></strong>
			</label>
		</p>
		<select name="ahsa_course_passing_percent" id="ahsa_course_passing_percent" style="width:100%;">
			<option value="" <?php selected( $course_percent, '' ); ?>>
				<?php
				printf(
					/* translators: %d: global passing percentage */
					esc_html__( 'Use global default (%d%%)', 'lifterlms' ),
					$global_percent
				);
				?>
			</option>
			<?php for ( $i = self::MIN_PASSING_PERCENT; $i <= self::MAX_PASSING_PERCENT; $i++ ) : ?>
				<option value="<?php echo esc_attr( $i ); ?>" <?php selected( (int) $course_percent, $i ); ?>>
					<?php echo esc_html( $i . '%' ); ?>
				</option>
			<?php endfor; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Override the minimum passing score for all quizzes in this course. Leave blank to use the global setting.', 'lifterlms' ); ?>
		</p>
		<?php
	}

	/**
	 * Save course metabox.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_course_metabox( $post_id, $post ) {
		if ( ! isset( $_POST['ahsa_course_progression_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ahsa_course_progression_nonce'] ) ), 'ahsa_course_progression_' . $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['ahsa_course_passing_percent'] ) ) {
			$value = sanitize_text_field( wp_unslash( $_POST['ahsa_course_passing_percent'] ) );
			if ( '' === $value ) {
				delete_post_meta( $post_id, '_ahsa_course_passing_percent' );
			} else {
				$value = $this->sanitize_passing_percent( $value );
				update_post_meta( $post_id, '_ahsa_course_passing_percent', $value );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Quiz-Level Metabox
	// -------------------------------------------------------------------------

	/**
	 * Add quiz-level passing override metabox.
	 */
	public function add_quiz_metabox() {
		add_meta_box(
			'ahsa-quiz-progression',
			__( 'Passing Score Override', 'lifterlms' ),
			array( $this, 'render_quiz_metabox' ),
			'llms_quiz',
			'side',
			'default'
		);
	}

	/**
	 * Render the quiz-level metabox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_quiz_metabox( $post ) {
		$quiz_percent = get_post_meta( $post->ID, '_ahsa_quiz_passing_percent', true );
		$effective    = $this->get_effective_passing_percent( $post->ID );

		wp_nonce_field( 'ahsa_quiz_progression_' . $post->ID, 'ahsa_quiz_progression_nonce' );
		?>
		<p>
			<label for="ahsa_quiz_passing_percent">
				<strong><?php esc_html_e( 'Override Passing Percentage', 'lifterlms' ); ?></strong>
			</label>
		</p>
		<select name="ahsa_quiz_passing_percent" id="ahsa_quiz_passing_percent" style="width:100%;">
			<option value="" <?php selected( $quiz_percent, '' ); ?>>
				<?php
				printf(
					/* translators: %d: effective passing percentage */
					esc_html__( 'Use course/global default (%d%%)', 'lifterlms' ),
					$effective
				);
				?>
			</option>
			<?php for ( $i = self::MIN_PASSING_PERCENT; $i <= self::MAX_PASSING_PERCENT; $i++ ) : ?>
				<option value="<?php echo esc_attr( $i ); ?>" <?php selected( (int) $quiz_percent, $i ); ?>>
					<?php echo esc_html( $i . '%' ); ?>
				</option>
			<?php endfor; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Override the minimum passing score for this specific quiz. Leave blank to inherit from the course or global setting.', 'lifterlms' ); ?>
		</p>
		<?php
	}

	/**
	 * Save quiz metabox.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_quiz_metabox( $post_id, $post ) {
		if ( ! isset( $_POST['ahsa_quiz_progression_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ahsa_quiz_progression_nonce'] ) ), 'ahsa_quiz_progression_' . $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['ahsa_quiz_passing_percent'] ) ) {
			$value = sanitize_text_field( wp_unslash( $_POST['ahsa_quiz_passing_percent'] ) );
			if ( '' === $value ) {
				delete_post_meta( $post_id, '_ahsa_quiz_passing_percent' );
			} else {
				$value = $this->sanitize_passing_percent( $value );
				update_post_meta( $post_id, '_ahsa_quiz_passing_percent', $value );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Passing Percent Resolution
	// -------------------------------------------------------------------------

	/**
	 * Get the global passing percentage setting.
	 *
	 * @return int
	 */
	public function get_global_passing_percent() {
		$value = get_option( 'ahsa_global_passing_percent', self::DEFAULT_PASSING_PERCENT );
		return max( self::MIN_PASSING_PERCENT, min( self::MAX_PASSING_PERCENT, absint( $value ) ) );
	}

	/**
	 * Get the effective passing percentage for a quiz, resolving the cascade:
	 * quiz override > course override > global default.
	 *
	 * @param int $quiz_id Quiz post ID.
	 * @return int
	 */
	public function get_effective_passing_percent( $quiz_id ) {
		// Quiz-level override.
		$quiz_override = get_post_meta( $quiz_id, '_ahsa_quiz_passing_percent', true );
		if ( '' !== $quiz_override && is_numeric( $quiz_override ) ) {
			return $this->sanitize_passing_percent( $quiz_override );
		}

		// Course-level override via lesson parent.
		$lesson_id = get_post_meta( $quiz_id, '_llms_lesson_id', true );
		if ( $lesson_id ) {
			$course_id = get_post_meta( $lesson_id, '_llms_parent_course', true );
			if ( $course_id ) {
				$course_override = get_post_meta( $course_id, '_ahsa_course_passing_percent', true );
				if ( '' !== $course_override && is_numeric( $course_override ) ) {
					return $this->sanitize_passing_percent( $course_override );
				}
			}
		}

		return $this->get_global_passing_percent();
	}

	/**
	 * Filter the quiz passing percent to use the AHSA cascade.
	 *
	 * If the `llms_quiz_passing_percent` filter doesn't exist in core, this
	 * is a no-op; the setup script directly sets quiz meta instead.
	 *
	 * @param float $percent  Current passing percent.
	 * @param int   $quiz_id  Quiz post ID.
	 * @return float
	 */
	public function filter_quiz_passing_percent( $percent, $quiz_id ) {
		return (float) $this->get_effective_passing_percent( $quiz_id );
	}

	// -------------------------------------------------------------------------
	// Lesson Completion Enforcement
	// -------------------------------------------------------------------------

	/**
	 * Additional enforcement: ensure quiz-bearing lessons are only "complete"
	 * if the student actually earned a passing grade.
	 *
	 * @param bool   $is_complete Current completion status.
	 * @param int    $object_id   Lesson post ID.
	 * @param string $type        Object type.
	 * @param object $student     LLMS_Student instance.
	 * @return bool
	 */
	public function filter_lesson_complete( $is_complete, $object_id, $type, $student ) {
		if ( 'lesson' !== $type || ! $is_complete ) {
			return $is_complete;
		}

		$quiz_enabled = get_post_meta( $object_id, '_llms_quiz_enabled', true );
		if ( 'yes' !== $quiz_enabled ) {
			return $is_complete;
		}

		$quiz_id = get_post_meta( $object_id, '_llms_quiz', true );
		if ( ! $quiz_id ) {
			return $is_complete;
		}

		$min_grade = $this->get_effective_passing_percent( $quiz_id );

		// Check best attempt grade.
		if ( class_exists( 'LLMS_Student_Quizzes' ) ) {
			$student_quizzes = new LLMS_Student_Quizzes( $student->get_id() );
			$best_attempt    = $student_quizzes->get_best_attempt( $quiz_id );
			if ( ! $best_attempt || ! $best_attempt->is_passing() ) {
				return false;
			}
			$grade = $best_attempt->get( 'grade' );
			if ( $grade < $min_grade ) {
				return false;
			}
		}

		return $is_complete;
	}

	// -------------------------------------------------------------------------
	// Student-Facing Notices
	// -------------------------------------------------------------------------

	/**
	 * Show a progression notice on the lesson page when the student is blocked.
	 */
	public function show_progression_notice() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		global $post;
		if ( ! $post || 'lesson' !== get_post_type( $post ) ) {
			return;
		}

		$student = new LLMS_Student( get_current_user_id() );
		$lesson  = new LLMS_Lesson( $post->ID );

		// Check if lesson has a prerequisite.
		if ( $lesson->has_prerequisite() ) {
			$prereq_id = $lesson->get( 'prerequisite' );
			if ( $prereq_id && ! $student->is_complete( $prereq_id, 'lesson' ) ) {
				$prereq_title = get_the_title( $prereq_id );
				$this->render_notice(
					sprintf(
						/* translators: %s: prerequisite lesson title */
						__( 'You must complete "%s" before you can access this lesson.', 'lifterlms' ),
						esc_html( $prereq_title )
					),
					'prerequisite'
				);
				return;
			}

			// Prerequisite exists and is complete — check if it required a passing grade.
			if ( $prereq_id ) {
				$prereq_quiz_enabled = get_post_meta( $prereq_id, '_llms_quiz_enabled', true );
				if ( 'yes' === $prereq_quiz_enabled ) {
					$prereq_quiz_id = get_post_meta( $prereq_id, '_llms_quiz', true );
					if ( $prereq_quiz_id ) {
						$min_grade = $this->get_effective_passing_percent( $prereq_quiz_id );
						if ( class_exists( 'LLMS_Student_Quizzes' ) ) {
							$student_quizzes = new LLMS_Student_Quizzes( $student->get_id() );
							$best_attempt    = $student_quizzes->get_best_attempt( $prereq_quiz_id );
							$passed          = $best_attempt && $best_attempt->is_passing() && $best_attempt->get( 'grade' ) >= $min_grade;
							if ( ! $passed ) {
								$this->render_notice(
									sprintf(
										/* translators: 1: prerequisite lesson title, 2: minimum passing percentage */
										__( 'You must earn at least %2$d%% on the quiz in "%1$s" to unlock this lesson.', 'lifterlms' ),
										esc_html( get_the_title( $prereq_id ) ),
										$min_grade
									),
									'passing_grade'
								);
								return;
							}
						}
					}
				}
			}
		}

		// Check section-level progression: is the previous section fully complete?
		$section_id = get_post_meta( $post->ID, '_llms_parent_section', true );
		$course_id  = get_post_meta( $post->ID, '_llms_parent_course', true );
		if ( $section_id && $course_id ) {
			$section_order = (int) get_post_meta( $section_id, '_llms_order', true );
			if ( $section_order > 1 ) {
				$prev_section = $this->get_section_by_order( $course_id, $section_order - 1 );
				if ( $prev_section && ! $student->is_complete( $prev_section, 'section' ) ) {
					$prev_title = get_the_title( $prev_section );
					$this->render_notice(
						sprintf(
							/* translators: %s: previous section title */
							__( 'You must complete all lessons and pass all quizzes in "%s" before accessing this section.', 'lifterlms' ),
							esc_html( $prev_title )
						),
						'section_locked'
					);
				}
			}
		}
	}

	/**
	 * Render a styled notice block.
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type identifier.
	 */
	private function render_notice( $message, $type ) {
		printf(
			'<div class="llms-notice ahsa-progression-notice ahsa-progression-notice--%1$s"><strong>%2$s</strong> %3$s</div>',
			esc_attr( $type ),
			esc_html__( 'Progression Locked:', 'lifterlms' ),
			wp_kses_post( $message )
		);
	}

	/**
	 * Get a section post ID by its order within a course.
	 *
	 * @param int $course_id    Course post ID.
	 * @param int $section_order Section order number.
	 * @return int|false Section ID or false.
	 */
	private function get_section_by_order( $course_id, $section_order ) {
		$sections = get_posts( array(
			'post_type'      => 'section',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_llms_parent_course',
					'value' => $course_id,
				),
				array(
					'key'   => '_llms_order',
					'value' => $section_order,
					'type'  => 'NUMERIC',
				),
			),
		) );

		return ! empty( $sections ) ? (int) $sections[0] : false;
	}

	/**
	 * Block quiz start if previous lesson/section requirements are not met.
	 *
	 * @param array        $attempt_data Attempt data.
	 * @param LLMS_Student $student      Student object.
	 * @param LLMS_Quiz    $quiz         Quiz object.
	 */
	public function check_quiz_prerequisites( $attempt_data, $student, $quiz ) {
		$lesson_id = $quiz->get( 'lesson_id' );
		if ( ! $lesson_id ) {
			return;
		}

		$lesson = new LLMS_Lesson( $lesson_id );
		if ( $lesson->has_prerequisite() ) {
			$prereq_id = $lesson->get( 'prerequisite' );
			if ( $prereq_id && ! $student->is_complete( $prereq_id, 'lesson' ) ) {
				wp_die(
					esc_html__( 'You must complete the prerequisite lesson before taking this quiz.', 'lifterlms' ),
					esc_html__( 'Prerequisite Required', 'lifterlms' ),
					array( 'response' => 403, 'back_link' => true )
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Admin Review: Blocked Progression Reasons
	// -------------------------------------------------------------------------

	/**
	 * Get blocked progression reasons for a specific student in a course.
	 *
	 * @param int $student_id Student user ID.
	 * @param int $course_id  Course post ID.
	 * @return array Array of blocking reasons.
	 */
	public function get_blocked_reasons( $student_id, $course_id ) {
		$reasons = array();
		$student = new LLMS_Student( $student_id );

		$sections = get_posts( array(
			'post_type'      => 'section',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_llms_order',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'   => '_llms_parent_course',
					'value' => $course_id,
				),
			),
		) );

		foreach ( $sections as $section_id ) {
			$lessons = get_posts( array(
				'post_type'      => 'lesson',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'meta_value_num',
				'meta_key'       => '_llms_order',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'   => '_llms_parent_section',
						'value' => $section_id,
					),
				),
			) );

			foreach ( $lessons as $lesson_id ) {
				if ( $student->is_complete( $lesson_id, 'lesson' ) ) {
					continue;
				}

				$reason = array(
					'section_id'    => $section_id,
					'section_title' => get_the_title( $section_id ),
					'lesson_id'     => $lesson_id,
					'lesson_title'  => get_the_title( $lesson_id ),
					'type'          => 'incomplete_lesson',
				);

				$quiz_enabled = get_post_meta( $lesson_id, '_llms_quiz_enabled', true );
				if ( 'yes' === $quiz_enabled ) {
					$quiz_id = get_post_meta( $lesson_id, '_llms_quiz', true );
					if ( $quiz_id ) {
						$min_grade       = $this->get_effective_passing_percent( $quiz_id );
						$student_quizzes = new LLMS_Student_Quizzes( $student->get_id() );
						$best_attempt    = $student_quizzes->get_best_attempt( $quiz_id );
						if ( $best_attempt ) {
							$grade = $best_attempt->get( 'grade' );
							if ( $grade < $min_grade ) {
								$reason['type']      = 'failed_quiz';
								$reason['grade']     = $grade;
								$reason['min_grade'] = $min_grade;
							}
						} else {
							$reason['type'] = 'quiz_not_attempted';
						}
					}
				}

				$reasons[] = $reason;
			}
		}

		return $reasons;
	}
}
