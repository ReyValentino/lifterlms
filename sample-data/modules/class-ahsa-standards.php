<?php
/**
 * AHSA Florida Standards Alignment System.
 *
 * Provides structured standard-to-content mapping:
 * - Standards taxonomy with framework, code, and description
 * - Linkable to courses, sections, lessons, and assessments
 * - Admin interface for standards management
 * - Frontend display of aligned standards
 * - Import/export of standards mappings
 *
 * @package AHSA/Modules
 */

defined( 'ABSPATH' ) || exit;

/**
 * AHSA_Standards class.
 */
class AHSA_Standards {

	/**
	 * Singleton instance.
	 *
	 * @var AHSA_Standards|null
	 */
	private static $instance = null;

	/**
	 * DB version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Standard frameworks supported.
	 *
	 * @var array
	 */
	const FRAMEWORKS = array(
		'best_ela'          => 'B.E.S.T. ELA',
		'best_math'         => 'B.E.S.T. Mathematics',
		'ngsss_science'     => 'NGSSS Science',
		'fl_social_studies' => 'Florida Social Studies',
		'fl_cte'            => 'Florida CTE',
		'faa_knowledge'     => 'FAA Knowledge Areas',
		'ncaa_eligibility'  => 'NCAA Eligibility',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return AHSA_Standards
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
		add_action( 'add_meta_boxes', array( $this, 'add_metaboxes' ) );
		add_action( 'save_post', array( $this, 'save_alignment_metabox' ), 10, 2 );
		add_shortcode( 'ahsa_standards_alignment', array( $this, 'render_alignment_shortcode' ) );
	}

	// -------------------------------------------------------------------------
	// Database Schema
	// -------------------------------------------------------------------------

	/**
	 * Create tables if needed.
	 */
	public function maybe_create_tables() {
		$installed = get_option( 'ahsa_standards_db_version', '' );
		if ( self::DB_VERSION === $installed ) {
			return;
		}
		$this->create_tables();
		update_option( 'ahsa_standards_db_version', self::DB_VERSION );
	}

	/**
	 * Create the standards tables.
	 */
	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$standards_table  = $wpdb->prefix . 'ahsa_standards';
		$alignment_table  = $wpdb->prefix . 'ahsa_standards_alignment';

		$sql = "CREATE TABLE {$standards_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			framework varchar(50) NOT NULL,
			standard_code varchar(100) NOT NULL,
			title varchar(255) NOT NULL,
			description text,
			grade_band varchar(20) DEFAULT NULL,
			subject_area varchar(100) DEFAULT NULL,
			parent_standard_id bigint(20) unsigned DEFAULT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY framework (framework),
			KEY standard_code (standard_code),
			KEY grade_band (grade_band),
			KEY parent_standard_id (parent_standard_id)
		) {$charset_collate};

		CREATE TABLE {$alignment_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			standard_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			post_type varchar(20) NOT NULL,
			alignment_type enum('primary','supporting','assessed') NOT NULL DEFAULT 'primary',
			notes text,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY standard_post (standard_id, post_id),
			KEY post_id (post_id),
			KEY post_type (post_type),
			KEY standard_id (standard_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// -------------------------------------------------------------------------
	// Standards CRUD
	// -------------------------------------------------------------------------

	/**
	 * Create a standard.
	 *
	 * @param array $data Standard data.
	 * @return int|false Standard ID or false.
	 */
	public function create_standard( $data ) {
		global $wpdb;

		$defaults = array(
			'framework'          => '',
			'standard_code'      => '',
			'title'              => '',
			'description'        => '',
			'grade_band'         => null,
			'subject_area'       => null,
			'parent_standard_id' => null,
			'sort_order'         => 0,
		);
		$data = wp_parse_args( $data, $defaults );

		if ( empty( $data['framework'] ) || empty( $data['standard_code'] ) || empty( $data['title'] ) ) {
			return false;
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'ahsa_standards',
			array(
				'framework'          => sanitize_key( $data['framework'] ),
				'standard_code'      => sanitize_text_field( $data['standard_code'] ),
				'title'              => sanitize_text_field( $data['title'] ),
				'description'        => sanitize_textarea_field( $data['description'] ),
				'grade_band'         => $data['grade_band'] ? sanitize_text_field( $data['grade_band'] ) : null,
				'subject_area'       => $data['subject_area'] ? sanitize_text_field( $data['subject_area'] ) : null,
				'parent_standard_id' => $data['parent_standard_id'] ? absint( $data['parent_standard_id'] ) : null,
				'sort_order'         => absint( $data['sort_order'] ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get a standard by ID.
	 *
	 * @param int $standard_id Standard ID.
	 * @return object|null
	 */
	public function get_standard( $standard_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ahsa_standards WHERE id = %d",
				absint( $standard_id )
			)
		);
	}

	/**
	 * Get standards by framework.
	 *
	 * @param string $framework Framework key.
	 * @param string $grade_band Optional grade band filter.
	 * @return array
	 */
	public function get_standards_by_framework( $framework, $grade_band = '' ) {
		global $wpdb;

		$where = $wpdb->prepare( "framework = %s", sanitize_key( $framework ) );
		if ( $grade_band ) {
			$where .= $wpdb->prepare( " AND grade_band = %s", sanitize_text_field( $grade_band ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built with prepare().
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}ahsa_standards WHERE {$where} ORDER BY sort_order ASC, standard_code ASC"
		);
	}

	/**
	 * Get all standards (paginated).
	 *
	 * @param int    $page    Page number.
	 * @param int    $per_page Per page.
	 * @param string $framework Optional framework filter.
	 * @return array
	 */
	public function get_standards( $page = 1, $per_page = 50, $framework = '' ) {
		global $wpdb;

		$offset = ( max( 1, $page ) - 1 ) * $per_page;
		$where  = '1=1';
		if ( $framework ) {
			$where = $wpdb->prepare( "framework = %s", sanitize_key( $framework ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ahsa_standards WHERE {$where} ORDER BY framework ASC, sort_order ASC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Delete a standard and its alignments.
	 *
	 * @param int $standard_id Standard ID.
	 * @return bool
	 */
	public function delete_standard( $standard_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'ahsa_standards_alignment', array( 'standard_id' => absint( $standard_id ) ), array( '%d' ) );
		return (bool) $wpdb->delete( $wpdb->prefix . 'ahsa_standards', array( 'id' => absint( $standard_id ) ), array( '%d' ) );
	}

	// -------------------------------------------------------------------------
	// Alignment CRUD
	// -------------------------------------------------------------------------

	/**
	 * Align a standard to a post (course/section/lesson/quiz).
	 *
	 * @param int    $standard_id Standard ID.
	 * @param int    $post_id     Post ID.
	 * @param string $type        Alignment type (primary, supporting, assessed).
	 * @param string $notes       Optional notes.
	 * @return int|false Alignment ID or false.
	 */
	public function align( $standard_id, $post_id, $type = 'primary', $notes = '' ) {
		global $wpdb;

		$standard_id = absint( $standard_id );
		$post_id     = absint( $post_id );

		if ( ! $standard_id || ! $post_id ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$valid_types = array( 'primary', 'supporting', 'assessed' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			$type = 'primary';
		}

		// Check if alignment already exists.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}ahsa_standards_alignment WHERE standard_id = %d AND post_id = %d",
				$standard_id,
				$post_id
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$wpdb->prefix . 'ahsa_standards_alignment',
				array(
					'alignment_type' => $type,
					'notes'          => sanitize_textarea_field( $notes ),
				),
				array( 'id' => $existing ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return (int) $existing;
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'ahsa_standards_alignment',
			array(
				'standard_id'    => $standard_id,
				'post_id'        => $post_id,
				'post_type'      => $post->post_type,
				'alignment_type' => $type,
				'notes'          => sanitize_textarea_field( $notes ),
				'created_by'     => get_current_user_id(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Remove an alignment.
	 *
	 * @param int $standard_id Standard ID.
	 * @param int $post_id     Post ID.
	 * @return bool
	 */
	public function remove_alignment( $standard_id, $post_id ) {
		global $wpdb;
		return (bool) $wpdb->delete(
			$wpdb->prefix . 'ahsa_standards_alignment',
			array(
				'standard_id' => absint( $standard_id ),
				'post_id'     => absint( $post_id ),
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Get standards aligned to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_aligned_standards( $post_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, a.alignment_type, a.notes as alignment_notes
				 FROM {$wpdb->prefix}ahsa_standards s
				 INNER JOIN {$wpdb->prefix}ahsa_standards_alignment a ON s.id = a.standard_id
				 WHERE a.post_id = %d
				 ORDER BY s.framework ASC, s.sort_order ASC",
				absint( $post_id )
			)
		);
	}

	/**
	 * Get posts aligned to a standard.
	 *
	 * @param int $standard_id Standard ID.
	 * @return array
	 */
	public function get_aligned_posts( $standard_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, p.post_title, p.post_type
				 FROM {$wpdb->prefix}ahsa_standards_alignment a
				 INNER JOIN {$wpdb->posts} p ON a.post_id = p.ID
				 WHERE a.standard_id = %d
				 ORDER BY p.post_type ASC, p.post_title ASC",
				absint( $standard_id )
			)
		);
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
			__( 'Standards Alignment', 'lifterlms' ),
			__( 'Standards', 'lifterlms' ),
			'manage_lifterlms',
			'ahsa-standards',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_lifterlms' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lifterlms' ) );
		}

		// Handle form submissions.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ahsa_std_action'] ) ) {
			check_admin_referer( 'ahsa_standards_manage' );
			$action = sanitize_key( $_POST['ahsa_std_action'] );

			if ( 'create' === $action ) {
				$result = $this->create_standard( array(
					'framework'     => isset( $_POST['framework'] ) ? sanitize_key( $_POST['framework'] ) : '',
					'standard_code' => isset( $_POST['standard_code'] ) ? sanitize_text_field( wp_unslash( $_POST['standard_code'] ) ) : '',
					'title'         => isset( $_POST['std_title'] ) ? sanitize_text_field( wp_unslash( $_POST['std_title'] ) ) : '',
					'description'   => isset( $_POST['std_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['std_description'] ) ) : '',
					'grade_band'    => isset( $_POST['grade_band'] ) ? sanitize_text_field( wp_unslash( $_POST['grade_band'] ) ) : '',
					'subject_area'  => isset( $_POST['subject_area'] ) ? sanitize_text_field( wp_unslash( $_POST['subject_area'] ) ) : '',
				) );
				if ( $result ) {
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Standard created.', 'lifterlms' ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to create standard. Framework, code, and title are required.', 'lifterlms' ) . '</p></div>';
				}
			} elseif ( 'delete' === $action ) {
				$std_id = isset( $_POST['standard_id'] ) ? absint( $_POST['standard_id'] ) : 0;
				if ( $std_id ) {
					$this->delete_standard( $std_id );
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Standard deleted.', 'lifterlms' ) . '</p></div>';
				}
			}
		}

		$filter_framework = isset( $_GET['framework'] ) ? sanitize_key( $_GET['framework'] ) : '';
		$page_num         = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$standards        = $this->get_standards( $page_num, 50, $filter_framework );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Florida Standards Alignment', 'lifterlms' ); ?></h1>

			<h2><?php esc_html_e( 'Add Standard', 'lifterlms' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'ahsa_standards_manage' ); ?>
				<input type="hidden" name="ahsa_std_action" value="create">
				<table class="form-table">
					<tr>
						<th><label for="std_framework"><?php esc_html_e( 'Framework', 'lifterlms' ); ?></label></th>
						<td>
							<select name="framework" id="std_framework" required>
								<option value=""><?php esc_html_e( '— Select —', 'lifterlms' ); ?></option>
								<?php foreach ( self::FRAMEWORKS as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="std_code"><?php esc_html_e( 'Standard Code', 'lifterlms' ); ?></label></th>
						<td><input type="text" name="standard_code" id="std_code" class="regular-text" required placeholder="e.g., MA.912.AR.1.1"></td>
					</tr>
					<tr>
						<th><label for="std_title"><?php esc_html_e( 'Title', 'lifterlms' ); ?></label></th>
						<td><input type="text" name="std_title" id="std_title" class="large-text" required></td>
					</tr>
					<tr>
						<th><label for="std_desc"><?php esc_html_e( 'Description', 'lifterlms' ); ?></label></th>
						<td><textarea name="std_description" id="std_desc" rows="3" class="large-text"></textarea></td>
					</tr>
					<tr>
						<th><label for="std_grade"><?php esc_html_e( 'Grade Band', 'lifterlms' ); ?></label></th>
						<td>
							<select name="grade_band" id="std_grade">
								<option value=""><?php esc_html_e( '— Any —', 'lifterlms' ); ?></option>
								<option value="K-5">K-5</option>
								<option value="6-8">6-8</option>
								<option value="9-12">9-12</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="std_subject"><?php esc_html_e( 'Subject Area', 'lifterlms' ); ?></label></th>
						<td><input type="text" name="subject_area" id="std_subject" class="regular-text" placeholder="e.g., Mathematics, Science"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Add Standard', 'lifterlms' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Standards Library', 'lifterlms' ); ?></h2>

			<form method="get" style="margin-bottom:15px;">
				<input type="hidden" name="page" value="ahsa-standards">
				<label for="filter_framework"><?php esc_html_e( 'Filter by Framework:', 'lifterlms' ); ?></label>
				<select name="framework" id="filter_framework" onchange="this.form.submit();">
					<option value=""><?php esc_html_e( 'All Frameworks', 'lifterlms' ); ?></option>
					<?php foreach ( self::FRAMEWORKS as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter_framework, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>

			<?php if ( empty( $standards ) ) : ?>
				<p><?php esc_html_e( 'No standards found. Add standards above or run the standards setup script.', 'lifterlms' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Code', 'lifterlms' ); ?></th>
							<th><?php esc_html_e( 'Framework', 'lifterlms' ); ?></th>
							<th><?php esc_html_e( 'Title', 'lifterlms' ); ?></th>
							<th><?php esc_html_e( 'Grade Band', 'lifterlms' ); ?></th>
							<th><?php esc_html_e( 'Alignments', 'lifterlms' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'lifterlms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $standards as $std ) :
							$framework_label = isset( self::FRAMEWORKS[ $std->framework ] ) ? self::FRAMEWORKS[ $std->framework ] : $std->framework;
							$alignment_count = $this->count_alignments( $std->id );
						?>
						<tr>
							<td><code><?php echo esc_html( $std->standard_code ); ?></code></td>
							<td><?php echo esc_html( $framework_label ); ?></td>
							<td><?php echo esc_html( $std->title ); ?></td>
							<td><?php echo esc_html( $std->grade_band ?: '—' ); ?></td>
							<td><?php echo esc_html( $alignment_count ); ?></td>
							<td>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'ahsa_standards_manage' ); ?>
									<input type="hidden" name="ahsa_std_action" value="delete">
									<input type="hidden" name="standard_id" value="<?php echo esc_attr( $std->id ); ?>">
									<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Delete this standard and all alignments?', 'lifterlms' ) ); ?>');">
										<?php esc_html_e( 'Delete', 'lifterlms' ); ?>
									</button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Count alignments for a standard.
	 *
	 * @param int $standard_id Standard ID.
	 * @return int
	 */
	private function count_alignments( $standard_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ahsa_standards_alignment WHERE standard_id = %d",
				absint( $standard_id )
			)
		);
	}

	// -------------------------------------------------------------------------
	// Content Metaboxes
	// -------------------------------------------------------------------------

	/**
	 * Add alignment metaboxes to courses, lessons, and quizzes.
	 */
	public function add_metaboxes() {
		$post_types = array( 'course', 'lesson', 'llms_quiz' );
		foreach ( $post_types as $pt ) {
			add_meta_box(
				'ahsa-standards-alignment',
				__( 'Standards Alignment', 'lifterlms' ),
				array( $this, 'render_alignment_metabox' ),
				$pt,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Render alignment metabox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_alignment_metabox( $post ) {
		$aligned = $this->get_aligned_standards( $post->ID );

		wp_nonce_field( 'ahsa_standards_alignment_' . $post->ID, 'ahsa_standards_alignment_nonce' );

		if ( ! empty( $aligned ) ) {
			echo '<table class="widefat" style="margin-bottom:15px;">';
			echo '<thead><tr><th>' . esc_html__( 'Code', 'lifterlms' ) . '</th><th>' . esc_html__( 'Standard', 'lifterlms' ) . '</th><th>' . esc_html__( 'Type', 'lifterlms' ) . '</th><th>' . esc_html__( 'Remove', 'lifterlms' ) . '</th></tr></thead><tbody>';
			foreach ( $aligned as $std ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( $std->standard_code ) . '</code></td>';
				echo '<td>' . esc_html( $std->title ) . '</td>';
				echo '<td>' . esc_html( ucfirst( $std->alignment_type ) ) . '</td>';
				echo '<td><label><input type="checkbox" name="ahsa_remove_standards[]" value="' . esc_attr( $std->id ) . '"> ' . esc_html__( 'Remove', 'lifterlms' ) . '</label></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'No standards aligned yet.', 'lifterlms' ) . '</p>';
		}

		// Add alignment form.
		global $wpdb;
		$all_standards = $wpdb->get_results(
			"SELECT id, standard_code, title, framework FROM {$wpdb->prefix}ahsa_standards ORDER BY framework ASC, sort_order ASC LIMIT 500"
		);

		if ( ! empty( $all_standards ) ) {
			echo '<h4>' . esc_html__( 'Add Standard Alignment', 'lifterlms' ) . '</h4>';
			echo '<select name="ahsa_add_standard_id" style="width:60%;">';
			echo '<option value="">' . esc_html__( '— Select Standard —', 'lifterlms' ) . '</option>';
			$current_fw = '';
			foreach ( $all_standards as $std ) {
				$fw_label = isset( self::FRAMEWORKS[ $std->framework ] ) ? self::FRAMEWORKS[ $std->framework ] : $std->framework;
				if ( $std->framework !== $current_fw ) {
					if ( $current_fw ) {
						echo '</optgroup>';
					}
					echo '<optgroup label="' . esc_attr( $fw_label ) . '">';
					$current_fw = $std->framework;
				}
				echo '<option value="' . esc_attr( $std->id ) . '">' . esc_html( $std->standard_code . ' — ' . $std->title ) . '</option>';
			}
			if ( $current_fw ) {
				echo '</optgroup>';
			}
			echo '</select> ';

			echo '<select name="ahsa_alignment_type" style="width:20%;">';
			echo '<option value="primary">' . esc_html__( 'Primary', 'lifterlms' ) . '</option>';
			echo '<option value="supporting">' . esc_html__( 'Supporting', 'lifterlms' ) . '</option>';
			echo '<option value="assessed">' . esc_html__( 'Assessed', 'lifterlms' ) . '</option>';
			echo '</select>';
		} else {
			echo '<p class="description">' . esc_html__( 'No standards in the library. Add them via the Standards admin page first.', 'lifterlms' ) . '</p>';
		}
	}

	/**
	 * Save alignment metabox.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_alignment_metabox( $post_id, $post ) {
		if ( ! in_array( $post->post_type, array( 'course', 'lesson', 'llms_quiz' ), true ) ) {
			return;
		}

		if ( ! isset( $_POST['ahsa_standards_alignment_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ahsa_standards_alignment_nonce'] ) ), 'ahsa_standards_alignment_' . $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Remove selected standards.
		if ( ! empty( $_POST['ahsa_remove_standards'] ) && is_array( $_POST['ahsa_remove_standards'] ) ) {
			foreach ( $_POST['ahsa_remove_standards'] as $std_id ) {
				$this->remove_alignment( absint( $std_id ), $post_id );
			}
		}

		// Add new alignment.
		if ( ! empty( $_POST['ahsa_add_standard_id'] ) ) {
			$type = isset( $_POST['ahsa_alignment_type'] ) ? sanitize_key( $_POST['ahsa_alignment_type'] ) : 'primary';
			$this->align( absint( $_POST['ahsa_add_standard_id'] ), $post_id, $type );
		}
	}

	// -------------------------------------------------------------------------
	// Frontend Shortcode
	// -------------------------------------------------------------------------

	/**
	 * Render standards alignment display.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_alignment_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'post_id' => 0 ), $atts, 'ahsa_standards_alignment' );
		$post_id = absint( $atts['post_id'] );

		if ( ! $post_id ) {
			global $post;
			if ( $post ) {
				$post_id = $post->ID;
			}
		}

		if ( ! $post_id ) {
			return '';
		}

		$standards = $this->get_aligned_standards( $post_id );
		if ( empty( $standards ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="ahsa-standards-alignment" style="margin:20px 0;">
			<h4 style="color:#003366;"><?php esc_html_e( 'Florida Standards Alignment', 'lifterlms' ); ?></h4>
			<table style="width:100%;border-collapse:collapse;border:1px solid #ddd;">
				<thead>
					<tr style="background:#003366;color:#fff;">
						<th style="padding:8px;text-align:left;"><?php esc_html_e( 'Code', 'lifterlms' ); ?></th>
						<th style="padding:8px;text-align:left;"><?php esc_html_e( 'Standard', 'lifterlms' ); ?></th>
						<th style="padding:8px;text-align:center;"><?php esc_html_e( 'Type', 'lifterlms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $standards as $i => $std ) : ?>
					<tr style="background:<?php echo $i % 2 ? '#f9f9f9' : '#fff'; ?>;">
						<td style="padding:8px;border-bottom:1px solid #eee;"><code><?php echo esc_html( $std->standard_code ); ?></code></td>
						<td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html( $std->title ); ?></td>
						<td style="padding:8px;border-bottom:1px solid #eee;text-align:center;">
							<span style="padding:2px 8px;border-radius:3px;font-size:12px;background:<?php echo 'primary' === $std->alignment_type ? '#003366' : ( 'assessed' === $std->alignment_type ? '#c62828' : '#666' ); ?>;color:#fff;">
								<?php echo esc_html( ucfirst( $std->alignment_type ) ); ?>
							</span>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Bulk Seeding
	// -------------------------------------------------------------------------

	/**
	 * Seed sample Florida standards for all supported frameworks.
	 *
	 * @return int Number of standards created.
	 */
	public function seed_florida_standards() {
		$standards = $this->get_seed_data();
		$count = 0;

		foreach ( $standards as $std ) {
			$result = $this->create_standard( $std );
			if ( $result ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Get seed data for Florida standards.
	 *
	 * @return array
	 */
	private function get_seed_data() {
		$data = array();
		$sort = 0;

		// B.E.S.T. ELA standards (sample).
		$ela = array(
			array( 'ELA.6.R.1.1', 'Cite textual evidence to support analysis', '6-8', 'English Language Arts' ),
			array( 'ELA.7.R.1.2', 'Determine a central idea and analyze its development', '6-8', 'English Language Arts' ),
			array( 'ELA.8.R.1.3', 'Analyze how particular lines of dialogue or incidents propel the action', '6-8', 'English Language Arts' ),
			array( 'ELA.9.R.1.1', 'Analyze how an author develops and refines ideas and claims', '9-12', 'English Language Arts' ),
			array( 'ELA.10.R.1.2', 'Analyze the development of central ideas over the course of a text', '9-12', 'English Language Arts' ),
			array( 'ELA.11.R.1.1', 'Evaluate how authors use specific rhetorical choices to achieve purpose', '9-12', 'English Language Arts' ),
			array( 'ELA.12.R.1.2', 'Synthesize information from multiple texts to develop a coherent understanding', '9-12', 'English Language Arts' ),
		);
		foreach ( $ela as $e ) {
			$sort++;
			$data[] = array( 'framework' => 'best_ela', 'standard_code' => $e[0], 'title' => $e[1], 'grade_band' => $e[2], 'subject_area' => $e[3], 'sort_order' => $sort );
		}

		// B.E.S.T. Math standards (sample).
		$math = array(
			array( 'MA.6.NSO.1.1', 'Extend knowledge of the number system to include integers and rational numbers', '6-8', 'Mathematics' ),
			array( 'MA.7.AR.1.1', 'Apply properties of operations to add and subtract linear expressions with rational coefficients', '6-8', 'Mathematics' ),
			array( 'MA.8.AR.1.1', 'Apply the Laws of Exponents to generate equivalent numerical and algebraic expressions', '6-8', 'Mathematics' ),
			array( 'MA.912.AR.1.1', 'Identify and interpret parts of an equation or expression that represent a quantity', '9-12', 'Mathematics' ),
			array( 'MA.912.AR.2.1', 'Given a one-variable equation, solve and graph on a number line', '9-12', 'Mathematics' ),
			array( 'MA.912.GR.1.1', 'Prove relationships and theorems about lines and angles', '9-12', 'Mathematics' ),
			array( 'MA.912.C.1.1', 'Express limits symbolically using correct mathematical notation', '9-12', 'Mathematics' ),
		);
		foreach ( $math as $m ) {
			$sort++;
			$data[] = array( 'framework' => 'best_math', 'standard_code' => $m[0], 'title' => $m[1], 'grade_band' => $m[2], 'subject_area' => $m[3], 'sort_order' => $sort );
		}

		// NGSSS Science standards (sample).
		$science = array(
			array( 'SC.6.E.6.1', 'Describe and give examples of ways in which Earth surface is built up and torn down', '6-8', 'Science' ),
			array( 'SC.7.L.15.1', 'Recognize that fossil evidence is consistent with the scientific theory of evolution', '6-8', 'Science' ),
			array( 'SC.8.P.8.1', 'Explore the scientific theory of atoms by recognizing that atoms make up everything', '6-8', 'Science' ),
			array( 'SC.912.L.14.1', 'Describe the scientific theory of cells as the fundamental unit of life', '9-12', 'Science' ),
			array( 'SC.912.P.10.1', 'Differentiate among the various forms of energy and recognize that they can be transformed', '9-12', 'Science' ),
			array( 'SC.912.E.5.1', 'Cite evidence of the expansion of the universe using Hubble Law and CMB', '9-12', 'Science' ),
		);
		foreach ( $science as $s ) {
			$sort++;
			$data[] = array( 'framework' => 'ngsss_science', 'standard_code' => $s[0], 'title' => $s[1], 'grade_band' => $s[2], 'subject_area' => $s[3], 'sort_order' => $sort );
		}

		// Florida Social Studies (sample).
		$social = array(
			array( 'SS.6.W.1.1', 'Use timelines to identify chronological order of historical events', '6-8', 'Social Studies' ),
			array( 'SS.7.C.1.1', 'Recognize how Enlightenment ideas influenced founding of the United States', '6-8', 'Social Studies' ),
			array( 'SS.8.A.1.1', 'Provide supporting details for an answer from text, charts, and other sources', '6-8', 'Social Studies' ),
			array( 'SS.912.A.1.1', 'Describe the importance of historiography, which includes how historical knowledge is obtained', '9-12', 'Social Studies' ),
			array( 'SS.912.W.1.1', 'Use timelines to establish cause-and-effect relationships of historical events', '9-12', 'Social Studies' ),
			array( 'SS.912.E.1.1', 'Identify the factors of production and why they are necessary for the production of goods and services', '9-12', 'Social Studies' ),
		);
		foreach ( $social as $ss ) {
			$sort++;
			$data[] = array( 'framework' => 'fl_social_studies', 'standard_code' => $ss[0], 'title' => $ss[1], 'grade_band' => $ss[2], 'subject_area' => $ss[3], 'sort_order' => $sort );
		}

		// Florida CTE (sample for aviation pathway).
		$cte = array(
			array( 'CTE.AV.001', 'Demonstrate understanding of principles of flight and aerodynamics', '9-12', 'Aviation' ),
			array( 'CTE.AV.002', 'Identify components and functions of aircraft systems', '9-12', 'Aviation' ),
			array( 'CTE.AV.003', 'Apply weather theory to flight planning decisions', '9-12', 'Aviation' ),
			array( 'CTE.UAS.001', 'Demonstrate knowledge of UAS regulations and airspace requirements', '9-12', 'Unmanned Aircraft Systems' ),
			array( 'CTE.UAS.002', 'Plan and execute UAS missions following safety protocols', '9-12', 'Unmanned Aircraft Systems' ),
			array( 'CTE.AE.001', 'Apply engineering design processes to aerospace problems', '9-12', 'Aerospace Engineering' ),
			array( 'CTE.AE.002', 'Analyze forces acting on spacecraft and aircraft structures', '9-12', 'Aerospace Engineering' ),
		);
		foreach ( $cte as $c ) {
			$sort++;
			$data[] = array( 'framework' => 'fl_cte', 'standard_code' => $c[0], 'title' => $c[1], 'grade_band' => $c[2], 'subject_area' => $c[3], 'sort_order' => $sort );
		}

		// FAA Knowledge Areas (sample).
		$faa = array(
			array( 'FAA.107.AW', 'Applicable regulations relating to small unmanned aircraft system rating privileges', '9-12', 'FAA Part 107' ),
			array( 'FAA.107.AS', 'Airspace classification, operating requirements, and flight restrictions', '9-12', 'FAA Part 107' ),
			array( 'FAA.107.WX', 'Aviation weather sources and effects on small unmanned aircraft performance', '9-12', 'FAA Part 107' ),
			array( 'FAA.PPL.AER', 'Principles of aerodynamics, the airplane, and engines', '9-12', 'Private Pilot' ),
			array( 'FAA.PPL.NAV', 'Navigation including pilotage, dead reckoning, and radio navigation', '9-12', 'Private Pilot' ),
			array( 'FAA.PPL.WX', 'Weather theory, reports, and forecasts', '9-12', 'Private Pilot' ),
			array( 'FAA.PPL.ADM', 'Aeronautical decision making and safety culture', '9-12', 'Private Pilot' ),
		);
		foreach ( $faa as $f ) {
			$sort++;
			$data[] = array( 'framework' => 'faa_knowledge', 'standard_code' => $f[0], 'title' => $f[1], 'grade_band' => $f[2], 'subject_area' => $f[3], 'sort_order' => $sort );
		}

		return $data;
	}

	/**
	 * Auto-align courses to standards based on their metadata.
	 *
	 * @return int Number of alignments created.
	 */
	public function auto_align_courses() {
		global $wpdb;

		$courses = get_posts( array(
			'post_type'      => 'course',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$count = 0;

		foreach ( $courses as $course_id ) {
			$framework_text = get_post_meta( $course_id, '_llms_state_standard_framework', true );
			if ( empty( $framework_text ) ) {
				continue;
			}

			$frameworks = array_map( 'trim', explode( ';', $framework_text ) );

			foreach ( $frameworks as $fw_text ) {
				// Match framework text to framework keys.
				$fw_key = $this->match_framework_key( $fw_text );
				if ( ! $fw_key ) {
					continue;
				}

				$standards = $this->get_standards_by_framework( $fw_key );
				foreach ( $standards as $std ) {
					$result = $this->align( $std->id, $course_id, 'primary' );
					if ( $result ) {
						$count++;
					}
				}
			}
		}

		return $count;
	}

	/**
	 * Normalize a string for framework matching.
	 *
	 * Lowercases, strips punctuation (except /), collapses whitespace.
	 * Converts "b.e.s.t." → "best" for consistent matching.
	 *
	 * @param string $text Raw text.
	 * @return string Normalized text.
	 */
	private function normalize_framework_text( $text ) {
		$text = strtolower( $text );
		// Normalize "B.E.S.T." → "best" before stripping dots.
		$text = str_replace( 'b.e.s.t.', 'best', $text );
		// Strip remaining punctuation except forward-slash and spaces.
		$text = preg_replace( '/[^a-z0-9\/\s]/', '', $text );
		// Collapse whitespace.
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );
		return $text;
	}

	/**
	 * Match a framework text string to a framework key.
	 *
	 * Uses normalized matching: lowercase, punctuation-stripped, "B.E.S.T." → "best".
	 *
	 * @param string $text Framework text.
	 * @return string|false Framework key or false.
	 */
	private function match_framework_key( $text ) {
		$text = $this->normalize_framework_text( $text );

		$patterns = array(
			'best_math'         => array( 'best math', 'math best', 'math fsa', 'algebra', 'geometry', 'precalculus', 'statistics' ),
			'best_ela'          => array( 'best ela', 'ela best', 'ela fsa', 'fsa/best' ),
			'ngsss_science'     => array( 'ngsss', 'biology', 'chemistry', 'physics', 'earth and space', 'life science', 'physical science' ),
			'fl_social_studies' => array( 'social studies', 'world history', 'us history', 'civics', 'economics', 'government' ),
			'fl_cte'            => array( 'cte', 'career', 'aviation framework', 'aerospace framework', 'uas framework', 'engineering framework', 'capstone framework' ),
			'faa_knowledge'     => array( 'faa', 'part 107', 'private pilot' ),
		);

		foreach ( $patterns as $key => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $text, $needle ) ) {
					return $key;
				}
			}
		}

		return false;
	}
}
