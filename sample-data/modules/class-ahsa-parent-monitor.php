<?php
/**
 * AHSA Parent Progress Monitoring Module.
 *
 * Provides:
 * - Parent-student relationship linking
 * - Read-only parent dashboard for viewing student progress
 * - Teacher notes system (internal and parent-visible)
 * - Admin controls for linking and note management
 *
 * @package AHSA/Modules
 */

defined( 'ABSPATH' ) || exit;

/**
 * AHSA_Parent_Monitor class.
 */
class AHSA_Parent_Monitor {

	/**
	 * Singleton instance.
	 *
	 * @var AHSA_Parent_Monitor|null
	 */
	private static $instance = null;

	/**
	 * DB version for schema migrations.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Get singleton instance.
	 *
	 * @return AHSA_Parent_Monitor
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
		// DB setup on activation.
		add_action( 'init', array( $this, 'maybe_create_tables' ) );
		add_action( 'init', array( $this, 'register_parent_role' ) );

		// Admin menus.
		add_action( 'admin_menu', array( $this, 'add_admin_menus' ), 30 );

		// Admin AJAX handlers.
		add_action( 'wp_ajax_ahsa_link_parent_student', array( $this, 'ajax_link_parent_student' ) );
		add_action( 'wp_ajax_ahsa_unlink_parent_student', array( $this, 'ajax_unlink_parent_student' ) );
		add_action( 'wp_ajax_ahsa_save_teacher_note', array( $this, 'ajax_save_teacher_note' ) );
		add_action( 'wp_ajax_ahsa_delete_teacher_note', array( $this, 'ajax_delete_teacher_note' ) );

		// Parent dashboard shortcode.
		add_shortcode( 'ahsa_parent_dashboard', array( $this, 'render_parent_dashboard_shortcode' ) );

		// Register the parent dashboard page on site build.
		add_action( 'ahsa_after_site_build', array( $this, 'create_parent_dashboard_page' ) );
	}

	// -------------------------------------------------------------------------
	// Database Schema
	// -------------------------------------------------------------------------

	/**
	 * Create custom tables if needed.
	 */
	public function maybe_create_tables() {
		$installed_version = get_option( 'ahsa_parent_monitor_db_version', '' );
		if ( self::DB_VERSION === $installed_version ) {
			return;
		}
		$this->create_tables();
		update_option( 'ahsa_parent_monitor_db_version', self::DB_VERSION );
	}

	/**
	 * Create the custom database tables.
	 */
	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$relationships_table = $wpdb->prefix . 'ahsa_parent_student';
		$notes_table         = $wpdb->prefix . 'ahsa_teacher_notes';

		$sql = "CREATE TABLE {$relationships_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_user_id bigint(20) unsigned NOT NULL,
			student_user_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY parent_student (parent_user_id, student_user_id),
			KEY student_user_id (student_user_id)
		) {$charset_collate};

		CREATE TABLE {$notes_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_user_id bigint(20) unsigned NOT NULL,
			author_user_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned DEFAULT NULL,
			note_content longtext NOT NULL,
			parent_visible tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY student_user_id (student_user_id),
			KEY author_user_id (author_user_id),
			KEY course_id (course_id),
			KEY parent_visible (parent_visible)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// -------------------------------------------------------------------------
	// Roles / Capabilities
	// -------------------------------------------------------------------------

	/**
	 * Register the parent role if it doesn't exist.
	 */
	public function register_parent_role() {
		if ( get_role( 'llms_parent' ) ) {
			return;
		}

		add_role( 'llms_parent', __( 'Parent', 'lifterlms' ), array(
			'read' => true,
		) );
	}

	// -------------------------------------------------------------------------
	// Parent-Student Relationships
	// -------------------------------------------------------------------------

	/**
	 * Link a parent to a student.
	 *
	 * @param int $parent_id  Parent user ID.
	 * @param int $student_id Student user ID.
	 * @param int $created_by Admin user ID who created the link.
	 * @return int|false Insert ID or false on failure.
	 */
	public function link_parent_student( $parent_id, $student_id, $created_by = 0 ) {
		global $wpdb;

		$parent_id  = absint( $parent_id );
		$student_id = absint( $student_id );
		$created_by = absint( $created_by );

		if ( ! $parent_id || ! $student_id ) {
			return false;
		}

		if ( ! get_userdata( $parent_id ) || ! get_userdata( $student_id ) ) {
			return false;
		}

		// Prevent self-linking.
		if ( $parent_id === $student_id ) {
			return false;
		}

		$table = $wpdb->prefix . 'ahsa_parent_student';

		// Check if already linked.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE parent_user_id = %d AND student_user_id = %d",
				$parent_id,
				$student_id
			)
		);

		if ( $exists ) {
			return (int) $exists;
		}

		$result = $wpdb->insert(
			$table,
			array(
				'parent_user_id'  => $parent_id,
				'student_user_id' => $student_id,
				'created_by'      => $created_by,
			),
			array( '%d', '%d', '%d' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Unlink a parent from a student.
	 *
	 * @param int $parent_id  Parent user ID.
	 * @param int $student_id Student user ID.
	 * @return bool
	 */
	public function unlink_parent_student( $parent_id, $student_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ahsa_parent_student';

		return (bool) $wpdb->delete(
			$table,
			array(
				'parent_user_id'  => absint( $parent_id ),
				'student_user_id' => absint( $student_id ),
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Get students linked to a parent.
	 *
	 * @param int $parent_id Parent user ID.
	 * @return int[] Array of student user IDs.
	 */
	public function get_students_for_parent( $parent_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ahsa_parent_student';

		return array_map( 'absint', $wpdb->get_col(
			$wpdb->prepare(
				"SELECT student_user_id FROM {$table} WHERE parent_user_id = %d ORDER BY created_at ASC",
				absint( $parent_id )
			)
		) );
	}

	/**
	 * Get parents linked to a student.
	 *
	 * @param int $student_id Student user ID.
	 * @return int[] Array of parent user IDs.
	 */
	public function get_parents_for_student( $student_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ahsa_parent_student';

		return array_map( 'absint', $wpdb->get_col(
			$wpdb->prepare(
				"SELECT parent_user_id FROM {$table} WHERE student_user_id = %d ORDER BY created_at ASC",
				absint( $student_id )
			)
		) );
	}

	/**
	 * Check if a parent is linked to a specific student.
	 *
	 * @param int $parent_id  Parent user ID.
	 * @param int $student_id Student user ID.
	 * @return bool
	 */
	public function is_linked( $parent_id, $student_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ahsa_parent_student';

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE parent_user_id = %d AND student_user_id = %d",
				absint( $parent_id ),
				absint( $student_id )
			)
		);
	}

	// -------------------------------------------------------------------------
	// Teacher Notes
	// -------------------------------------------------------------------------

	/**
	 * Create a teacher note.
	 *
	 * @param array $data Note data.
	 * @return int|false Insert ID or false.
	 */
	public function create_note( $data ) {
		global $wpdb;

		$defaults = array(
			'student_user_id' => 0,
			'author_user_id'  => 0,
			'course_id'       => null,
			'note_content'    => '',
			'parent_visible'  => 0,
		);

		$data = wp_parse_args( $data, $defaults );

		if ( ! $data['student_user_id'] || ! $data['author_user_id'] || empty( $data['note_content'] ) ) {
			return false;
		}

		$table = $wpdb->prefix . 'ahsa_teacher_notes';

		$result = $wpdb->insert(
			$table,
			array(
				'student_user_id' => absint( $data['student_user_id'] ),
				'author_user_id'  => absint( $data['author_user_id'] ),
				'course_id'       => $data['course_id'] ? absint( $data['course_id'] ) : null,
				'note_content'    => sanitize_textarea_field( $data['note_content'] ),
				'parent_visible'  => absint( $data['parent_visible'] ) ? 1 : 0,
			),
			array( '%d', '%d', '%d', '%s', '%d' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update a teacher note.
	 *
	 * @param int   $note_id Note ID.
	 * @param array $data    Fields to update.
	 * @return bool
	 */
	public function update_note( $note_id, $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ahsa_teacher_notes';

		$update = array();
		$format = array();

		if ( isset( $data['note_content'] ) ) {
			$update['note_content'] = sanitize_textarea_field( $data['note_content'] );
			$format[]               = '%s';
		}

		if ( isset( $data['parent_visible'] ) ) {
			$update['parent_visible'] = absint( $data['parent_visible'] ) ? 1 : 0;
			$format[]                 = '%d';
		}

		if ( isset( $data['course_id'] ) ) {
			$update['course_id'] = $data['course_id'] ? absint( $data['course_id'] ) : null;
			$format[]            = '%d';
		}

		if ( empty( $update ) ) {
			return false;
		}

		return (bool) $wpdb->update(
			$table,
			$update,
			array( 'id' => absint( $note_id ) ),
			$format,
			array( '%d' )
		);
	}

	/**
	 * Delete a teacher note.
	 *
	 * @param int $note_id Note ID.
	 * @return bool
	 */
	public function delete_note( $note_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ahsa_teacher_notes';

		return (bool) $wpdb->delete(
			$table,
			array( 'id' => absint( $note_id ) ),
			array( '%d' )
		);
	}

	/**
	 * Get a single note by ID.
	 *
	 * @param int $note_id Note ID.
	 * @return object|null
	 */
	public function get_note( $note_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ahsa_teacher_notes';

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $note_id ) )
		);
	}

	/**
	 * Get notes for a student.
	 *
	 * @param int  $student_id     Student user ID.
	 * @param bool $parent_visible_only If true, only return parent-visible notes.
	 * @param int  $course_id      Optional course filter.
	 * @return array
	 */
	public function get_notes_for_student( $student_id, $parent_visible_only = false, $course_id = 0 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ahsa_teacher_notes';

		$where = $wpdb->prepare( "student_user_id = %d", absint( $student_id ) );

		if ( $parent_visible_only ) {
			$where .= ' AND parent_visible = 1';
		}

		if ( $course_id ) {
			$where .= $wpdb->prepare( ' AND course_id = %d', absint( $course_id ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built with prepare() above.
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC" );
	}

	// -------------------------------------------------------------------------
	// Admin Menus
	// -------------------------------------------------------------------------

	/**
	 * Add admin menu pages.
	 */
	public function add_admin_menus() {
		add_submenu_page(
			'lifterlms',
			__( 'Parent Monitoring', 'lifterlms' ),
			__( 'Parent Monitor', 'lifterlms' ),
			'manage_lifterlms',
			'ahsa-parent-monitor',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the main admin page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_lifterlms' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lifterlms' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'relationships';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Parent Progress Monitoring', 'lifterlms' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-parent-monitor&tab=relationships' ) ); ?>"
					class="nav-tab <?php echo 'relationships' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Parent-Student Links', 'lifterlms' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-parent-monitor&tab=notes' ) ); ?>"
					class="nav-tab <?php echo 'notes' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Teacher Notes', 'lifterlms' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-parent-monitor&tab=settings' ) ); ?>"
					class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'lifterlms' ); ?>
				</a>
			</nav>

			<div class="tab-content ahsa-admin-tab-content">
				<?php
				switch ( $tab ) {
					case 'notes':
						$this->render_notes_tab();
						break;
					case 'settings':
						$this->render_settings_tab();
						break;
					default:
						$this->render_relationships_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the relationships management tab.
	 */
	private function render_relationships_tab() {
		// Handle form submission.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ahsa_link_action'] ) ) {
			check_admin_referer( 'ahsa_link_parent_student' );

			$parent_id  = isset( $_POST['parent_user_id'] ) ? absint( $_POST['parent_user_id'] ) : 0;
			$student_id = isset( $_POST['student_user_id'] ) ? absint( $_POST['student_user_id'] ) : 0;
			$action     = sanitize_key( $_POST['ahsa_link_action'] );

			if ( 'link' === $action && $parent_id && $student_id ) {
				$result = $this->link_parent_student( $parent_id, $student_id, get_current_user_id() );
				if ( $result ) {
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Parent linked to student successfully.', 'lifterlms' ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to link parent to student. They may already be linked or IDs are invalid.', 'lifterlms' ) . '</p></div>';
				}
			} elseif ( 'unlink' === $action && $parent_id && $student_id ) {
				$this->unlink_parent_student( $parent_id, $student_id );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Parent unlinked from student.', 'lifterlms' ) . '</p></div>';
			}
		}

		// Get all relationships.
		global $wpdb;
		$table = $wpdb->prefix . 'ahsa_parent_student';
		$links = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200" );

		// Get parent users for the dropdown.
		$parents = get_users( array(
			'role__in' => array( 'llms_parent' ),
			'orderby'  => 'display_name',
			'number'   => 200,
		) );

		// Get student users for the dropdown.
		$students = get_users( array(
			'role__in' => array( 'student' ),
			'orderby'  => 'display_name',
			'number'   => 500,
		) );
		?>

		<h2><?php esc_html_e( 'Link Parent to Student', 'lifterlms' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'ahsa_link_parent_student' ); ?>
			<input type="hidden" name="ahsa_link_action" value="link">
			<table class="form-table">
				<tr>
					<th><label for="parent_user_id"><?php esc_html_e( 'Parent Account', 'lifterlms' ); ?></label></th>
					<td>
						<select name="parent_user_id" id="parent_user_id" required>
							<option value=""><?php esc_html_e( '— Select Parent —', 'lifterlms' ); ?></option>
							<?php foreach ( $parents as $parent ) : ?>
								<option value="<?php echo esc_attr( $parent->ID ); ?>">
									<?php echo esc_html( sprintf( '%s (%s)', $parent->display_name, $parent->user_email ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Only users with the Parent role are shown. Assign the Parent role to a user first.', 'lifterlms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="student_user_id"><?php esc_html_e( 'Student Account', 'lifterlms' ); ?></label></th>
					<td>
						<select name="student_user_id" id="student_user_id" required>
							<option value=""><?php esc_html_e( '— Select Student —', 'lifterlms' ); ?></option>
							<?php foreach ( $students as $student ) : ?>
								<option value="<?php echo esc_attr( $student->ID ); ?>">
									<?php echo esc_html( sprintf( '%s (%s)', $student->display_name, $student->user_email ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Link Parent to Student', 'lifterlms' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Existing Links', 'lifterlms' ); ?></h2>
		<?php if ( empty( $links ) ) : ?>
			<p><?php esc_html_e( 'No parent-student links found.', 'lifterlms' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Parent', 'lifterlms' ); ?></th>
						<th><?php esc_html_e( 'Student', 'lifterlms' ); ?></th>
						<th><?php esc_html_e( 'Linked', 'lifterlms' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'lifterlms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $links as $link ) :
						$parent_user  = get_userdata( $link->parent_user_id );
						$student_user = get_userdata( $link->student_user_id );
						if ( ! $parent_user || ! $student_user ) {
							continue;
						}
					?>
					<tr>
						<td><?php echo esc_html( $parent_user->display_name ); ?> <small>(<?php echo esc_html( $parent_user->user_email ); ?>)</small></td>
						<td><?php echo esc_html( $student_user->display_name ); ?> <small>(<?php echo esc_html( $student_user->user_email ); ?>)</small></td>
						<td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $link->created_at ) ) ); ?></td>
						<td>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'ahsa_link_parent_student' ); ?>
								<input type="hidden" name="ahsa_link_action" value="unlink">
								<input type="hidden" name="parent_user_id" value="<?php echo esc_attr( $link->parent_user_id ); ?>">
								<input type="hidden" name="student_user_id" value="<?php echo esc_attr( $link->student_user_id ); ?>">
								<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Remove this parent-student link?', 'lifterlms' ) ); ?>');">
									<?php esc_html_e( 'Unlink', 'lifterlms' ); ?>
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
	 * Render the teacher notes tab.
	 */
	private function render_notes_tab() {
		// Handle note creation.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ahsa_note_action'] ) ) {
			$action = sanitize_key( $_POST['ahsa_note_action'] );

			if ( 'create' === $action ) {
				check_admin_referer( 'ahsa_create_teacher_note' );

				if ( ! $this->can_create_notes() ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to create notes.', 'lifterlms' ) . '</p></div>';
				} else {
					$result = $this->create_note( array(
						'student_user_id' => isset( $_POST['student_user_id'] ) ? absint( $_POST['student_user_id'] ) : 0,
						'author_user_id'  => get_current_user_id(),
						'course_id'       => ! empty( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : null,
						'note_content'    => isset( $_POST['note_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note_content'] ) ) : '',
						'parent_visible'  => isset( $_POST['parent_visible'] ) ? 1 : 0,
					) );

					if ( $result ) {
						echo '<div class="notice notice-success"><p>' . esc_html__( 'Note created successfully.', 'lifterlms' ) . '</p></div>';
					} else {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to create note. Student and content are required.', 'lifterlms' ) . '</p></div>';
					}
				}
			} elseif ( 'delete' === $action ) {
				check_admin_referer( 'ahsa_delete_teacher_note' );

				$note_id = isset( $_POST['note_id'] ) ? absint( $_POST['note_id'] ) : 0;
				$note    = $this->get_note( $note_id );

				if ( $note && ( current_user_can( 'manage_lifterlms' ) || (int) $note->author_user_id === get_current_user_id() ) ) {
					$this->delete_note( $note_id );
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Note deleted.', 'lifterlms' ) . '</p></div>';
				}
			}
		}

		$students = get_users( array(
			'role__in' => array( 'student' ),
			'orderby'  => 'display_name',
			'number'   => 500,
		) );

		$selected_student = isset( $_GET['student_id'] ) ? absint( $_GET['student_id'] ) : 0;
		?>

		<h2><?php esc_html_e( 'Create Teacher Note', 'lifterlms' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'ahsa_create_teacher_note' ); ?>
			<input type="hidden" name="ahsa_note_action" value="create">
			<table class="form-table">
				<tr>
					<th><label for="note_student_user_id"><?php esc_html_e( 'Student', 'lifterlms' ); ?></label></th>
					<td>
						<select name="student_user_id" id="note_student_user_id" required>
							<option value=""><?php esc_html_e( '— Select Student —', 'lifterlms' ); ?></option>
							<?php foreach ( $students as $student ) : ?>
								<option value="<?php echo esc_attr( $student->ID ); ?>" <?php selected( $selected_student, $student->ID ); ?>>
									<?php echo esc_html( sprintf( '%s (%s)', $student->display_name, $student->user_email ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="note_course_id"><?php esc_html_e( 'Related Course (optional)', 'lifterlms' ); ?></label></th>
					<td>
						<?php
						$courses = get_posts( array(
							'post_type'      => 'course',
							'post_status'    => 'publish',
							'posts_per_page' => 200,
							'orderby'        => 'title',
							'order'          => 'ASC',
						) );
						?>
						<select name="course_id" id="note_course_id">
							<option value=""><?php esc_html_e( '— No specific course —', 'lifterlms' ); ?></option>
							<?php foreach ( $courses as $course ) : ?>
								<option value="<?php echo esc_attr( $course->ID ); ?>">
									<?php echo esc_html( $course->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="note_content"><?php esc_html_e( 'Note Content', 'lifterlms' ); ?></label></th>
					<td>
						<textarea name="note_content" id="note_content" rows="5" class="large-text" required></textarea>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Visibility', 'lifterlms' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="parent_visible" value="1">
							<?php esc_html_e( 'Visible to parent on their dashboard', 'lifterlms' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'If unchecked, this note is internal-only (teachers and admins).', 'lifterlms' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Note', 'lifterlms' ) ); ?>
		</form>

		<?php if ( $selected_student ) : ?>
			<h2><?php esc_html_e( 'Notes for Selected Student', 'lifterlms' ); ?></h2>
			<?php $this->render_notes_list( $selected_student, false ); ?>
		<?php else : ?>
			<h2><?php esc_html_e( 'Recent Notes', 'lifterlms' ); ?></h2>
			<?php $this->render_recent_notes(); ?>
		<?php endif;
	}

	/**
	 * Render notes list for a student.
	 *
	 * @param int  $student_id         Student user ID.
	 * @param bool $parent_visible_only Only show parent-visible notes.
	 */
	private function render_notes_list( $student_id, $parent_visible_only = false ) {
		$notes = $this->get_notes_for_student( $student_id, $parent_visible_only );

		if ( empty( $notes ) ) {
			echo '<p>' . esc_html__( 'No notes found for this student.', 'lifterlms' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'lifterlms' ) . '</th>';
		echo '<th>' . esc_html__( 'Author', 'lifterlms' ) . '</th>';
		echo '<th>' . esc_html__( 'Course', 'lifterlms' ) . '</th>';
		echo '<th>' . esc_html__( 'Note', 'lifterlms' ) . '</th>';
		echo '<th>' . esc_html__( 'Visibility', 'lifterlms' ) . '</th>';
		if ( ! $parent_visible_only ) {
			echo '<th>' . esc_html__( 'Actions', 'lifterlms' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $notes as $note ) {
			$author = get_userdata( $note->author_user_id );
			echo '<tr>';
			echo '<td>' . esc_html( wp_date( 'M j, Y g:i a', strtotime( $note->created_at ) ) ) . '</td>';
			echo '<td>' . esc_html( $author ? $author->display_name : __( 'Unknown', 'lifterlms' ) ) . '</td>';
			echo '<td>' . ( $note->course_id ? esc_html( get_the_title( $note->course_id ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $note->note_content ) . '</td>';
			echo '<td>' . ( $note->parent_visible ? esc_html__( 'Parent-visible', 'lifterlms' ) : esc_html__( 'Internal', 'lifterlms' ) ) . '</td>';

			if ( ! $parent_visible_only && ( current_user_can( 'manage_lifterlms' ) || (int) $note->author_user_id === get_current_user_id() ) ) {
				echo '<td>';
				echo '<form method="post" style="display:inline;">';
				wp_nonce_field( 'ahsa_delete_teacher_note' );
				echo '<input type="hidden" name="ahsa_note_action" value="delete">';
				echo '<input type="hidden" name="note_id" value="' . esc_attr( $note->id ) . '">';
				echo '<button type="submit" class="button button-small" onclick="return confirm(\'' . esc_js( __( 'Delete this note?', 'lifterlms' ) ) . '\');">' . esc_html__( 'Delete', 'lifterlms' ) . '</button>';
				echo '</form>';
				echo '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render recent notes (all students).
	 */
	private function render_recent_notes() {
		global $wpdb;

		$table = $wpdb->prefix . 'ahsa_teacher_notes';
		$notes = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 50" );

		if ( empty( $notes ) ) {
			echo '<p>' . esc_html__( 'No notes yet.', 'lifterlms' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'lifterlms' ) . '</th>';
		echo '<th>' . esc_html__( 'Student', 'lifterlms' ) . '</th>';
		echo '<th>' . esc_html__( 'Author', 'lifterlms' ) . '</th>';
		echo '<th>' . esc_html__( 'Course', 'lifterlms' ) . '</th>';
		echo '<th>' . esc_html__( 'Note', 'lifterlms' ) . '</th>';
		echo '<th>' . esc_html__( 'Visibility', 'lifterlms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $notes as $note ) {
			$student_user = get_userdata( $note->student_user_id );
			$author       = get_userdata( $note->author_user_id );
			echo '<tr>';
			echo '<td>' . esc_html( wp_date( 'M j, Y', strtotime( $note->created_at ) ) ) . '</td>';
			echo '<td>' . esc_html( $student_user ? $student_user->display_name : __( 'Unknown', 'lifterlms' ) ) . '</td>';
			echo '<td>' . esc_html( $author ? $author->display_name : __( 'Unknown', 'lifterlms' ) ) . '</td>';
			echo '<td>' . ( $note->course_id ? esc_html( get_the_title( $note->course_id ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( wp_trim_words( $note->note_content, 20 ) ) . '</td>';
			echo '<td>' . ( $note->parent_visible ? esc_html__( 'Parent-visible', 'lifterlms' ) : esc_html__( 'Internal', 'lifterlms' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render admin settings tab.
	 */
	private function render_settings_tab() {
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ahsa_pm_settings_action'] ) ) {
			check_admin_referer( 'ahsa_pm_settings' );

			$roles = isset( $_POST['ahsa_note_creator_roles'] ) ? array_map( 'sanitize_key', (array) $_POST['ahsa_note_creator_roles'] ) : array();
			update_option( 'ahsa_note_creator_roles', $roles );

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'lifterlms' ) . '</p></div>';
		}

		$allowed_roles = get_option( 'ahsa_note_creator_roles', array( 'administrator', 'lms_manager', 'instructor' ) );
		$all_roles     = wp_roles()->get_names();
		?>

		<h2><?php esc_html_e( 'Note Creator Roles', 'lifterlms' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'ahsa_pm_settings' ); ?>
			<input type="hidden" name="ahsa_pm_settings_action" value="save">

			<p><?php esc_html_e( 'Select which roles can create parent-visible teacher notes:', 'lifterlms' ); ?></p>

			<?php foreach ( $all_roles as $role_key => $role_name ) :
				if ( in_array( $role_key, array( 'subscriber', 'student', 'llms_parent' ), true ) ) {
					continue;
				}
			?>
				<label style="display:block;margin-bottom:6px;">
					<input type="checkbox" name="ahsa_note_creator_roles[]" value="<?php echo esc_attr( $role_key ); ?>"
						<?php checked( in_array( $role_key, $allowed_roles, true ) ); ?>>
					<?php echo esc_html( translate_user_role( $role_name ) ); ?>
				</label>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save Settings', 'lifterlms' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Check if the current user can create parent-visible notes.
	 *
	 * @return bool
	 */
	private function can_create_notes() {
		if ( current_user_can( 'manage_lifterlms' ) ) {
			return true;
		}

		$allowed_roles = get_option( 'ahsa_note_creator_roles', array( 'administrator', 'lms_manager', 'instructor' ) );
		$user          = wp_get_current_user();

		return ! empty( array_intersect( $user->roles, $allowed_roles ) );
	}

	// -------------------------------------------------------------------------
	// AJAX Handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Link parent-student.
	 */
	public function ajax_link_parent_student() {
		check_ajax_referer( 'ahsa_parent_monitor', 'nonce' );

		if ( ! current_user_can( 'manage_lifterlms' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lifterlms' ) ), 403 );
		}

		$parent_id  = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;
		$student_id = isset( $_POST['student_id'] ) ? absint( $_POST['student_id'] ) : 0;

		$result = $this->link_parent_student( $parent_id, $student_id, get_current_user_id() );

		if ( $result ) {
			wp_send_json_success( array( 'id' => $result ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to create link.', 'lifterlms' ) ) );
		}
	}

	/**
	 * AJAX: Unlink parent-student.
	 */
	public function ajax_unlink_parent_student() {
		check_ajax_referer( 'ahsa_parent_monitor', 'nonce' );

		if ( ! current_user_can( 'manage_lifterlms' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lifterlms' ) ), 403 );
		}

		$parent_id  = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;
		$student_id = isset( $_POST['student_id'] ) ? absint( $_POST['student_id'] ) : 0;

		$this->unlink_parent_student( $parent_id, $student_id );

		wp_send_json_success();
	}

	/**
	 * AJAX: Save teacher note.
	 */
	public function ajax_save_teacher_note() {
		check_ajax_referer( 'ahsa_parent_monitor', 'nonce' );

		if ( ! $this->can_create_notes() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lifterlms' ) ), 403 );
		}

		$result = $this->create_note( array(
			'student_user_id' => isset( $_POST['student_id'] ) ? absint( $_POST['student_id'] ) : 0,
			'author_user_id'  => get_current_user_id(),
			'course_id'       => ! empty( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : null,
			'note_content'    => isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '',
			'parent_visible'  => isset( $_POST['parent_visible'] ) ? absint( $_POST['parent_visible'] ) : 0,
		) );

		if ( $result ) {
			wp_send_json_success( array( 'id' => $result ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save note.', 'lifterlms' ) ) );
		}
	}

	/**
	 * AJAX: Delete teacher note.
	 */
	public function ajax_delete_teacher_note() {
		check_ajax_referer( 'ahsa_parent_monitor', 'nonce' );

		$note_id = isset( $_POST['note_id'] ) ? absint( $_POST['note_id'] ) : 0;
		$note    = $this->get_note( $note_id );

		if ( ! $note ) {
			wp_send_json_error( array( 'message' => __( 'Note not found.', 'lifterlms' ) ), 404 );
		}

		if ( ! current_user_can( 'manage_lifterlms' ) && (int) $note->author_user_id !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lifterlms' ) ), 403 );
		}

		$this->delete_note( $note_id );

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Parent Dashboard (Frontend)
	// -------------------------------------------------------------------------

	/**
	 * Render the parent dashboard shortcode.
	 *
	 * @return string HTML output.
	 */
	public function render_parent_dashboard_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your parent dashboard.', 'lifterlms' ) . '</p>';
		}

		$user = wp_get_current_user();

		// Allow parents, admins, and lms_managers.
		if ( ! in_array( 'llms_parent', $user->roles, true ) && ! current_user_can( 'manage_lifterlms' ) ) {
			return '<p>' . esc_html__( 'This dashboard is only accessible to parent accounts.', 'lifterlms' ) . '</p>';
		}

		$student_ids = $this->get_students_for_parent( $user->ID );

		if ( empty( $student_ids ) ) {
			return '<div class="ahsa-parent-dashboard"><p>' . esc_html__( 'No students are linked to your account. Please contact the school to have your student linked.', 'lifterlms' ) . '</p></div>';
		}

		ob_start();
		?>
		<div class="ahsa-parent-dashboard">
			<h2><?php esc_html_e( 'Your Students', 'lifterlms' ); ?></h2>

			<?php foreach ( $student_ids as $student_id ) :
				$student_user = get_userdata( $student_id );
				if ( ! $student_user ) {
					continue;
				}

				$student = new LLMS_Student( $student_id );
			?>
			<div class="ahsa-student-card">
				<h3><?php echo esc_html( $student_user->display_name ); ?></h3>

				<?php
				// Get enrolled courses.
				$enrolled_courses = $this->get_enrolled_courses( $student_id );
				?>

				<?php if ( empty( $enrolled_courses ) ) : ?>
					<p><?php esc_html_e( 'This student is not currently enrolled in any courses.', 'lifterlms' ); ?></p>
				<?php else : ?>
					<h4><?php esc_html_e( 'Course Progress', 'lifterlms' ); ?></h4>
					<table class="ahsa-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Course', 'lifterlms' ); ?></th>
								<th class="ahsa-text-center"><?php esc_html_e( 'Progress', 'lifterlms' ); ?></th>
								<th class="ahsa-text-center"><?php esc_html_e( 'Status', 'lifterlms' ); ?></th>
								<th class="ahsa-text-center"><?php esc_html_e( 'Grade', 'lifterlms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $enrolled_courses as $course_id ) :
								$progress = $student->get_progress( $course_id, 'course' );
								$complete = $student->is_complete( $course_id, 'course' );
								$grade    = '';
								if ( function_exists( 'llms' ) && method_exists( llms()->grades(), 'get_grade' ) ) {
									$grade = llms()->grades()->get_grade( $course_id, $student );
								}
								$status = $complete ? __( 'Completed', 'lifterlms' ) : __( 'In Progress', 'lifterlms' );
								$status_color = $complete ? '#28a745' : '#ffc107';
							?>
							<tr>
								<td><?php echo esc_html( get_the_title( $course_id ) ); ?></td>
								<td class="ahsa-text-center">
									<div class="ahsa-progress-track">
										<div class="ahsa-progress-fill" style="width:<?php echo esc_attr( min( 100, $progress ) ); ?>%;">
											<?php echo esc_html( round( $progress ) . '%' ); ?>
										</div>
									</div>
								</td>
								<td class="ahsa-text-center">
									<span class="ahsa-badge ahsa-badge--<?php echo $complete ? 'success' : 'warning'; ?>">
										<?php echo esc_html( $status ); ?>
									</span>
								</td>
								<td class="ahsa-text-center">
									<?php echo is_numeric( $grade ) ? esc_html( round( $grade, 1 ) . '%' ) : '—'; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php
				// Show parent-visible notes.
				$notes = $this->get_notes_for_student( $student_id, true );
				if ( ! empty( $notes ) ) :
				?>
					<h4 class="ahsa-section-heading"><?php esc_html_e( 'Teacher Notes', 'lifterlms' ); ?></h4>
					<?php foreach ( $notes as $note ) :
						$author = get_userdata( $note->author_user_id );
					?>
					<div class="ahsa-note">
						<div class="ahsa-note__meta">
							<strong><?php echo esc_html( $author ? $author->display_name : __( 'Teacher', 'lifterlms' ) ); ?></strong>
							&middot;
							<?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $note->created_at ) ) ); ?>
							<?php if ( $note->course_id ) : ?>
								&middot;
								<?php echo esc_html( get_the_title( $note->course_id ) ); ?>
							<?php endif; ?>
						</div>
						<div><?php echo esc_html( $note->note_content ); ?></div>
					</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get enrolled course IDs for a student.
	 *
	 * @param int $student_id Student user ID.
	 * @return int[] Course post IDs.
	 */
	private function get_enrolled_courses( $student_id ) {
		global $wpdb;

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->prefix}lifterlms_user_postmeta
				WHERE user_id = %d AND meta_key = '_enrollment_trigger'
				AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'course' AND post_status = 'publish')
				ORDER BY post_id ASC",
				absint( $student_id )
			)
		);

		return array_map( 'absint', $results );
	}

	/**
	 * Create the parent dashboard page during site build.
	 */
	public function create_parent_dashboard_page() {
		$slug     = 'parent-dashboard';
		$existing = get_page_by_path( $slug, OBJECT, 'page' );

		if ( $existing instanceof WP_Post ) {
			return;
		}

		wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Parent Dashboard', 'lifterlms' ),
			'post_name'    => $slug,
			'post_content' => '[ahsa_parent_dashboard]',
		) );
	}
}
