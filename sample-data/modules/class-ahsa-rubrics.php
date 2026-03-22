<?php
/**
 * AHSA Rubric Grading System.
 *
 * Provides structured rubric-based grading for courses and assessments:
 * - Six rubric categories: content mastery, assignments, quizzes/exams,
 *   participation, projects, academic integrity
 * - Course-level rubric templates with configurable weights
 * - Rubric attachment to courses and assessments
 * - Admin interface for rubric management
 * - Grading logic integration with LLMS grade calculations
 *
 * @package AHSA/Modules
 */

defined( 'ABSPATH' ) || exit;

/**
 * AHSA_Rubrics class.
 */
class AHSA_Rubrics {

	/**
	 * Singleton instance.
	 *
	 * @var AHSA_Rubrics|null
	 */
	private static $instance = null;

	/**
	 * DB version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Default rubric categories with weights.
	 *
	 * @var array
	 */
	const DEFAULT_CATEGORIES = array(
		'content_mastery'    => array( 'label' => 'Content Mastery', 'weight' => 30, 'description' => 'Demonstrates understanding of core concepts and standards' ),
		'assignments'        => array( 'label' => 'Assignments', 'weight' => 25, 'description' => 'Timely and accurate completion of coursework and deliverables' ),
		'quizzes_exams'      => array( 'label' => 'Quizzes / Exams', 'weight' => 25, 'description' => 'Performance on auto-graded and teacher-graded assessments' ),
		'participation'      => array( 'label' => 'Participation', 'weight' => 5, 'description' => 'Engagement in discussions, collaborative work, and learning activities' ),
		'projects'           => array( 'label' => 'Projects', 'weight' => 10, 'description' => 'Applied projects, performance tasks, and portfolio artifacts' ),
		'academic_integrity' => array( 'label' => 'Academic Integrity', 'weight' => 5, 'description' => 'Adherence to academic honesty policies and original work standards' ),
	);

	/**
	 * Get singleton instance.
	 *
	 * @return AHSA_Rubrics
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
		add_action( 'init', array( $this, 'maybe_create_tables' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 30 );
		add_action( 'add_meta_boxes', array( $this, 'add_course_metabox' ) );
		add_action( 'save_post_course', array( $this, 'save_course_metabox' ), 10, 2 );
		add_shortcode( 'ahsa_course_rubric', array( $this, 'render_rubric_shortcode' ) );
	}

	// -------------------------------------------------------------------------
	// Database Schema
	// -------------------------------------------------------------------------

	/**
	 * Create tables if needed.
	 */
	public function maybe_create_tables() {
		$installed = get_option( 'ahsa_rubrics_db_version', '' );
		if ( self::DB_VERSION === $installed ) {
			return;
		}
		$this->create_tables();
		update_option( 'ahsa_rubrics_db_version', self::DB_VERSION );
	}

	/**
	 * Create the rubric tables.
	 */
	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$rubrics_table    = $wpdb->prefix . 'ahsa_rubrics';
		$categories_table = $wpdb->prefix . 'ahsa_rubric_categories';

		$sql = "CREATE TABLE {$rubrics_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL,
			description text,
			course_id bigint(20) unsigned DEFAULT NULL,
			is_template tinyint(1) NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY course_id (course_id),
			KEY is_template (is_template)
		) {$charset_collate};

		CREATE TABLE {$categories_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rubric_id bigint(20) unsigned NOT NULL,
			category_key varchar(50) NOT NULL,
			label varchar(100) NOT NULL,
			weight decimal(5,2) NOT NULL DEFAULT 0,
			description text,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY rubric_id (rubric_id),
			KEY category_key (category_key)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// -------------------------------------------------------------------------
	// Rubric CRUD
	// -------------------------------------------------------------------------

	/**
	 * Create a rubric with default AHSA categories.
	 *
	 * @param array $data Rubric data.
	 * @return int|false Rubric ID or false.
	 */
	public function create_rubric( $data ) {
		global $wpdb;

		$defaults = array(
			'title'       => '',
			'description' => '',
			'course_id'   => null,
			'is_template' => 0,
			'created_by'  => get_current_user_id(),
		);
		$data = wp_parse_args( $data, $defaults );

		if ( empty( $data['title'] ) ) {
			return false;
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'ahsa_rubrics',
			array(
				'title'       => sanitize_text_field( $data['title'] ),
				'description' => sanitize_textarea_field( $data['description'] ),
				'course_id'   => $data['course_id'] ? absint( $data['course_id'] ) : null,
				'is_template' => absint( $data['is_template'] ) ? 1 : 0,
				'created_by'  => absint( $data['created_by'] ),
			),
			array( '%s', '%s', '%d', '%d', '%d' )
		);

		if ( ! $result ) {
			return false;
		}

		$rubric_id = $wpdb->insert_id;

		// Create default categories.
		$categories = isset( $data['categories'] ) ? $data['categories'] : self::DEFAULT_CATEGORIES;
		$sort = 0;
		foreach ( $categories as $key => $cat ) {
			$sort++;
			$wpdb->insert(
				$wpdb->prefix . 'ahsa_rubric_categories',
				array(
					'rubric_id'    => $rubric_id,
					'category_key' => sanitize_key( $key ),
					'label'        => sanitize_text_field( $cat['label'] ),
					'weight'       => floatval( $cat['weight'] ),
					'description'  => isset( $cat['description'] ) ? sanitize_textarea_field( $cat['description'] ) : '',
					'sort_order'   => $sort,
				),
				array( '%d', '%s', '%s', '%f', '%s', '%d' )
			);
		}

		return $rubric_id;
	}

	/**
	 * Get a rubric by ID.
	 *
	 * @param int $rubric_id Rubric ID.
	 * @return object|null
	 */
	public function get_rubric( $rubric_id ) {
		global $wpdb;
		$rubric = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ahsa_rubrics WHERE id = %d",
				absint( $rubric_id )
			)
		);

		if ( $rubric ) {
			$rubric->categories = $this->get_rubric_categories( $rubric_id );
		}

		return $rubric;
	}

	/**
	 * Get rubric categories.
	 *
	 * @param int $rubric_id Rubric ID.
	 * @return array
	 */
	public function get_rubric_categories( $rubric_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ahsa_rubric_categories WHERE rubric_id = %d ORDER BY sort_order ASC",
				absint( $rubric_id )
			)
		);
	}

	/**
	 * Get rubric for a course.
	 *
	 * @param int $course_id Course post ID.
	 * @return object|null
	 */
	public function get_course_rubric( $course_id ) {
		global $wpdb;

		$rubric_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}ahsa_rubrics WHERE course_id = %d ORDER BY id DESC LIMIT 1",
				absint( $course_id )
			)
		);

		return $rubric_id ? $this->get_rubric( $rubric_id ) : null;
	}

	/**
	 * Update rubric category weights.
	 *
	 * @param int   $rubric_id  Rubric ID.
	 * @param array $weights    Array of category_key => weight.
	 * @return bool
	 */
	public function update_category_weights( $rubric_id, $weights ) {
		global $wpdb;

		$total = array_sum( array_map( 'floatval', $weights ) );
		if ( abs( $total - 100 ) > 0.01 ) {
			return false;
		}

		foreach ( $weights as $key => $weight ) {
			$wpdb->update(
				$wpdb->prefix . 'ahsa_rubric_categories',
				array( 'weight' => floatval( $weight ) ),
				array( 'rubric_id' => absint( $rubric_id ), 'category_key' => sanitize_key( $key ) ),
				array( '%f' ),
				array( '%d', '%s' )
			);
		}

		return true;
	}

	/**
	 * Delete a rubric and its categories.
	 *
	 * @param int $rubric_id Rubric ID.
	 * @return bool
	 */
	public function delete_rubric( $rubric_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'ahsa_rubric_categories', array( 'rubric_id' => absint( $rubric_id ) ), array( '%d' ) );
		return (bool) $wpdb->delete( $wpdb->prefix . 'ahsa_rubrics', array( 'id' => absint( $rubric_id ) ), array( '%d' ) );
	}

	/**
	 * Get all rubric templates.
	 *
	 * @return array
	 */
	public function get_templates() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}ahsa_rubrics WHERE is_template = 1 ORDER BY title ASC"
		);
	}

	/**
	 * Clone a rubric template to a course.
	 *
	 * @param int $template_id Template rubric ID.
	 * @param int $course_id   Target course ID.
	 * @return int|false New rubric ID or false.
	 */
	public function clone_to_course( $template_id, $course_id ) {
		$template = $this->get_rubric( $template_id );
		if ( ! $template ) {
			return false;
		}

		$categories = array();
		foreach ( $template->categories as $cat ) {
			$categories[ $cat->category_key ] = array(
				'label'       => $cat->label,
				'weight'      => $cat->weight,
				'description' => $cat->description,
			);
		}

		return $this->create_rubric( array(
			'title'       => $template->title,
			'description' => $template->description,
			'course_id'   => $course_id,
			'is_template' => 0,
			'categories'  => $categories,
		) );
	}

	// -------------------------------------------------------------------------
	// Admin Menu
	// -------------------------------------------------------------------------

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'lifterlms',
			__( 'Rubrics', 'lifterlms' ),
			__( 'Rubrics', 'lifterlms' ),
			'manage_lifterlms',
			'ahsa-rubrics',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_lifterlms' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lifterlms' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

		// Handle form submissions.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ahsa_rubric_action'] ) ) {
			check_admin_referer( 'ahsa_rubric_manage' );
			$form_action = sanitize_key( $_POST['ahsa_rubric_action'] );

			if ( 'create' === $form_action ) {
				$rubric_id = $this->create_rubric( array(
					'title'       => isset( $_POST['rubric_title'] ) ? sanitize_text_field( wp_unslash( $_POST['rubric_title'] ) ) : '',
					'description' => isset( $_POST['rubric_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rubric_description'] ) ) : '',
					'is_template' => isset( $_POST['is_template'] ) ? 1 : 0,
					'course_id'   => ! empty( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : null,
				) );
				if ( $rubric_id ) {
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Rubric created.', 'lifterlms' ) . '</p></div>';
				}
			} elseif ( 'update_weights' === $form_action ) {
				$rubric_id = isset( $_POST['rubric_id'] ) ? absint( $_POST['rubric_id'] ) : 0;
				$weights   = isset( $_POST['weights'] ) ? array_map( 'floatval', (array) $_POST['weights'] ) : array();
				if ( $rubric_id && $this->update_category_weights( $rubric_id, $weights ) ) {
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Weights updated.', 'lifterlms' ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to update weights. Ensure they sum to 100%.', 'lifterlms' ) . '</p></div>';
				}
			} elseif ( 'delete' === $form_action ) {
				$rubric_id = isset( $_POST['rubric_id'] ) ? absint( $_POST['rubric_id'] ) : 0;
				if ( $rubric_id ) {
					$this->delete_rubric( $rubric_id );
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Rubric deleted.', 'lifterlms' ) . '</p></div>';
				}
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AHSA Rubric Management', 'lifterlms' ); ?></h1>

			<?php if ( 'edit' === $action && isset( $_GET['rubric_id'] ) ) : ?>
				<?php $this->render_edit_page( absint( $_GET['rubric_id'] ) ); ?>
			<?php else : ?>
				<?php $this->render_list_page(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render rubric list page.
	 */
	private function render_list_page() {
		global $wpdb;
		$rubrics = $wpdb->get_results(
			"SELECT r.*, COALESCE(p.post_title, '—') as course_title
			 FROM {$wpdb->prefix}ahsa_rubrics r
			 LEFT JOIN {$wpdb->posts} p ON r.course_id = p.ID
			 ORDER BY r.is_template DESC, r.title ASC"
		);
		?>

		<h2><?php esc_html_e( 'Create New Rubric', 'lifterlms' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'ahsa_rubric_manage' ); ?>
			<input type="hidden" name="ahsa_rubric_action" value="create">
			<table class="form-table">
				<tr>
					<th><label for="rubric_title"><?php esc_html_e( 'Title', 'lifterlms' ); ?></label></th>
					<td><input type="text" name="rubric_title" id="rubric_title" class="regular-text" required></td>
				</tr>
				<tr>
					<th><label for="rubric_description"><?php esc_html_e( 'Description', 'lifterlms' ); ?></label></th>
					<td><textarea name="rubric_description" id="rubric_description" rows="3" class="large-text"></textarea></td>
				</tr>
				<tr>
					<th><label for="rubric_course_id"><?php esc_html_e( 'Attach to Course', 'lifterlms' ); ?></label></th>
					<td>
						<select name="course_id" id="rubric_course_id">
							<option value=""><?php esc_html_e( '— Template (no course) —', 'lifterlms' ); ?></option>
							<?php
							$courses = get_posts( array( 'post_type' => 'course', 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
							foreach ( $courses as $course ) :
							?>
								<option value="<?php echo esc_attr( $course->ID ); ?>"><?php echo esc_html( $course->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Template?', 'lifterlms' ); ?></th>
					<td>
						<label><input type="checkbox" name="is_template" value="1"> <?php esc_html_e( 'Save as reusable template', 'lifterlms' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Create Rubric', 'lifterlms' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Existing Rubrics', 'lifterlms' ); ?></h2>
		<?php if ( empty( $rubrics ) ) : ?>
			<p><?php esc_html_e( 'No rubrics found. Create one above or run the rubric setup script.', 'lifterlms' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'lifterlms' ); ?></th>
						<th><?php esc_html_e( 'Type', 'lifterlms' ); ?></th>
						<th><?php esc_html_e( 'Course', 'lifterlms' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'lifterlms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rubrics as $rubric ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $rubric->title ); ?></strong></td>
						<td><?php echo $rubric->is_template ? esc_html__( 'Template', 'lifterlms' ) : esc_html__( 'Course', 'lifterlms' ); ?></td>
						<td><?php echo esc_html( $rubric->course_title ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-rubrics&action=edit&rubric_id=' . $rubric->id ) ); ?>" class="button button-small">
								<?php esc_html_e( 'Edit', 'lifterlms' ); ?>
							</a>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'ahsa_rubric_manage' ); ?>
								<input type="hidden" name="ahsa_rubric_action" value="delete">
								<input type="hidden" name="rubric_id" value="<?php echo esc_attr( $rubric->id ); ?>">
								<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Delete this rubric?', 'lifterlms' ) ); ?>');">
									<?php esc_html_e( 'Delete', 'lifterlms' ); ?>
								</button>
							</form>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
	}

	/**
	 * Render rubric edit page with category weight management.
	 *
	 * @param int $rubric_id Rubric ID.
	 */
	private function render_edit_page( $rubric_id ) {
		$rubric = $this->get_rubric( $rubric_id );
		if ( ! $rubric ) {
			echo '<p>' . esc_html__( 'Rubric not found.', 'lifterlms' ) . '</p>';
			return;
		}
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-rubrics' ) ); ?>">&larr; <?php esc_html_e( 'Back to Rubrics', 'lifterlms' ); ?></a></p>

		<h2><?php echo esc_html( $rubric->title ); ?></h2>
		<?php if ( $rubric->description ) : ?>
			<p><?php echo esc_html( $rubric->description ); ?></p>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'ahsa_rubric_manage' ); ?>
			<input type="hidden" name="ahsa_rubric_action" value="update_weights">
			<input type="hidden" name="rubric_id" value="<?php echo esc_attr( $rubric->id ); ?>">

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Category', 'lifterlms' ); ?></th>
						<th><?php esc_html_e( 'Description', 'lifterlms' ); ?></th>
						<th style="width:120px;"><?php esc_html_e( 'Weight (%)', 'lifterlms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$total_weight = 0;
					foreach ( $rubric->categories as $cat ) :
						$total_weight += $cat->weight;
					?>
					<tr>
						<td><strong><?php echo esc_html( $cat->label ); ?></strong></td>
						<td><?php echo esc_html( $cat->description ); ?></td>
						<td>
							<input type="number" name="weights[<?php echo esc_attr( $cat->category_key ); ?>]"
								value="<?php echo esc_attr( $cat->weight ); ?>"
								min="0" max="100" step="0.5" style="width:80px;">
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="2"><strong><?php esc_html_e( 'Total', 'lifterlms' ); ?></strong></td>
						<td><strong><?php echo esc_html( number_format( $total_weight, 1 ) . '%' ); ?></strong></td>
					</tr>
				</tfoot>
			</table>

			<p class="description"><?php esc_html_e( 'Weights must sum to exactly 100%.', 'lifterlms' ); ?></p>
			<?php submit_button( __( 'Update Weights', 'lifterlms' ) ); ?>
		</form>
		<?php
	}

	// -------------------------------------------------------------------------
	// Course Metabox
	// -------------------------------------------------------------------------

	/**
	 * Add rubric metabox to course editor.
	 */
	public function add_course_metabox() {
		add_meta_box(
			'ahsa-course-rubric',
			__( 'Course Rubric', 'lifterlms' ),
			array( $this, 'render_course_metabox' ),
			'course',
			'side',
			'default'
		);
	}

	/**
	 * Render course rubric metabox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_course_metabox( $post ) {
		$rubric = $this->get_course_rubric( $post->ID );

		wp_nonce_field( 'ahsa_course_rubric_' . $post->ID, 'ahsa_course_rubric_nonce' );

		if ( $rubric ) {
			echo '<p><strong>' . esc_html( $rubric->title ) . '</strong></p>';
			echo '<ul style="margin:0;padding-left:1.5em;">';
			foreach ( $rubric->categories as $cat ) {
				printf(
					'<li>%s: %s%%</li>',
					esc_html( $cat->label ),
					esc_html( number_format( $cat->weight, 0 ) )
				);
			}
			echo '</ul>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ahsa-rubrics&action=edit&rubric_id=' . $rubric->id ) ) . '">' . esc_html__( 'Edit Rubric', 'lifterlms' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'No rubric attached.', 'lifterlms' ) . '</p>';
			$templates = $this->get_templates();
			if ( $templates ) {
				echo '<select name="ahsa_rubric_template_id" style="width:100%;">';
				echo '<option value="">' . esc_html__( '— Select Template —', 'lifterlms' ) . '</option>';
				foreach ( $templates as $tpl ) {
					echo '<option value="' . esc_attr( $tpl->id ) . '">' . esc_html( $tpl->title ) . '</option>';
				}
				echo '</select>';
				echo '<p class="description">' . esc_html__( 'Select a template to attach a rubric when saving.', 'lifterlms' ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html__( 'Create a rubric template first via the Rubrics admin page.', 'lifterlms' ) . '</p>';
			}
		}
	}

	/**
	 * Save course metabox.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_course_metabox( $post_id, $post ) {
		if ( ! isset( $_POST['ahsa_course_rubric_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ahsa_course_rubric_nonce'] ) ), 'ahsa_course_rubric_' . $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['ahsa_rubric_template_id'] ) ) {
			$template_id = absint( $_POST['ahsa_rubric_template_id'] );
			$this->clone_to_course( $template_id, $post_id );
		}
	}

	// -------------------------------------------------------------------------
	// Frontend Shortcode
	// -------------------------------------------------------------------------

	/**
	 * Render rubric display shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_rubric_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'course_id' => 0 ), $atts, 'ahsa_course_rubric' );
		$course_id = absint( $atts['course_id'] );

		if ( ! $course_id ) {
			global $post;
			if ( $post && 'course' === get_post_type( $post ) ) {
				$course_id = $post->ID;
			}
		}

		if ( ! $course_id ) {
			return '';
		}

		$rubric = $this->get_course_rubric( $course_id );
		if ( ! $rubric ) {
			return '<p>' . esc_html__( 'No rubric is assigned to this course.', 'lifterlms' ) . '</p>';
		}

		ob_start();
		?>
		<div class="ahsa-rubric">
			<h3><?php echo esc_html( $rubric->title ); ?></h3>
			<?php if ( $rubric->description ) : ?>
				<p><?php echo esc_html( $rubric->description ); ?></p>
			<?php endif; ?>
			<table class="ahsa-table ahsa-rubric__table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Category', 'lifterlms' ); ?></th>
						<th><?php esc_html_e( 'Description', 'lifterlms' ); ?></th>
						<th class="ahsa-text-center"><?php esc_html_e( 'Weight', 'lifterlms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rubric->categories as $i => $cat ) : ?>
					<tr>
						<td class="ahsa-rubric__category"><?php echo esc_html( $cat->label ); ?></td>
						<td><?php echo esc_html( $cat->description ); ?></td>
						<td class="ahsa-text-center ahsa-rubric__weight"><?php echo esc_html( number_format( $cat->weight, 0 ) . '%' ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}
}
