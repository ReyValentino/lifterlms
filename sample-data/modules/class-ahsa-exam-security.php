<?php
/**
 * AHSA Safe Exam Browser (SEB) Security Layer.
 *
 * Provides exam protection via Safe Exam Browser integration:
 * - Per-quiz SEB enforcement toggle
 * - SEB config key and exam key validation
 * - Protected exam launch flow (blocks non-SEB browsers)
 * - Per-attempt validation and logging
 * - Comprehensive exam event logging:
 *   - start/end, failed validation, focus loss, disconnects,
 *   - abnormal exits, copy/paste attempts, concurrent sessions
 * - Risk level assignment
 * - Admin review tools and log viewer
 *
 * @package AHSA/Modules
 */

defined( 'ABSPATH' ) || exit;

/**
 * AHSA_Exam_Security class.
 */
class AHSA_Exam_Security {

	/**
	 * Singleton instance.
	 *
	 * @var AHSA_Exam_Security|null
	 */
	private static $instance = null;

	/**
	 * DB version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Risk level thresholds.
	 *
	 * @var array
	 */
	const RISK_LEVELS = array(
		'low'      => 0,
		'moderate' => 3,
		'high'     => 6,
		'critical' => 10,
	);

	/**
	 * Valid event types.
	 *
	 * @var array
	 */
	const EVENT_TYPES = array(
		'exam_start',
		'exam_end',
		'validation_failed',
		'focus_loss',
		'focus_return',
		'disconnect',
		'reconnect',
		'abnormal_exit',
		'copy_attempt',
		'paste_attempt',
		'screenshot_attempt',
		'concurrent_session',
		'seb_key_mismatch',
		'browser_blocked',
		'attempt_submitted',
		'time_expired',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return AHSA_Exam_Security
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
		add_action( 'admin_menu', array( $this, 'add_admin_menus' ), 30 );

		// Quiz metabox for SEB settings.
		add_action( 'add_meta_boxes', array( $this, 'add_quiz_metabox' ) );
		add_action( 'save_post_llms_quiz', array( $this, 'save_quiz_metabox' ), 10, 2 );

		// Intercept quiz launch for SEB validation.
		add_action( 'llms_quiz_attempt_new', array( $this, 'validate_exam_start' ), 5, 3 );

		// Log exam completion and end session.
		add_action( 'llms_quiz_attempt_graded', array( $this, 'handle_exam_end' ), 10, 3 );

		// REST API endpoint for client-side event logging.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Inject SEB enforcement JavaScript on quiz pages.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_exam_scripts' ) );

		// Admin AJAX for log review.
		add_action( 'wp_ajax_ahsa_get_exam_logs', array( $this, 'ajax_get_exam_logs' ) );
		add_action( 'wp_ajax_ahsa_update_risk_level', array( $this, 'ajax_update_risk_level' ) );
	}

	// -------------------------------------------------------------------------
	// Database Schema
	// -------------------------------------------------------------------------

	/**
	 * Create tables if needed.
	 */
	public function maybe_create_tables() {
		$installed = get_option( 'ahsa_exam_security_db_version', '' );
		if ( self::DB_VERSION === $installed ) {
			return;
		}
		$this->create_tables();
		update_option( 'ahsa_exam_security_db_version', self::DB_VERSION );
	}

	/**
	 * Create the exam security tables.
	 */
	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$logs_table     = $wpdb->prefix . 'ahsa_exam_logs';
		$sessions_table = $wpdb->prefix . 'ahsa_exam_sessions';

		$sql = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			event_type varchar(50) NOT NULL,
			event_data longtext,
			ip_address varchar(45) DEFAULT NULL,
			user_agent text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) {$charset_collate};

		CREATE TABLE {$sessions_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_user_id bigint(20) unsigned NOT NULL,
			quiz_id bigint(20) unsigned NOT NULL,
			attempt_id bigint(20) unsigned DEFAULT NULL,
			seb_validated tinyint(1) NOT NULL DEFAULT 0,
			seb_config_hash varchar(64) DEFAULT NULL,
			risk_score int(11) NOT NULL DEFAULT 0,
			risk_level varchar(20) NOT NULL DEFAULT 'low',
			admin_reviewed tinyint(1) NOT NULL DEFAULT 0,
			admin_notes text,
			started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ended_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY student_user_id (student_user_id),
			KEY quiz_id (quiz_id),
			KEY risk_level (risk_level),
			KEY admin_reviewed (admin_reviewed),
			KEY started_at (started_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// -------------------------------------------------------------------------
	// SEB Configuration
	// -------------------------------------------------------------------------

	/**
	 * Check if a quiz requires SEB.
	 *
	 * @param int $quiz_id Quiz post ID.
	 * @return bool
	 */
	public function quiz_requires_seb( $quiz_id ) {
		return 'yes' === get_post_meta( $quiz_id, '_ahsa_seb_required', true );
	}

	/**
	 * Get SEB config key for a quiz.
	 *
	 * @param int $quiz_id Quiz post ID.
	 * @return string
	 */
	public function get_seb_config_key( $quiz_id ) {
		return (string) get_post_meta( $quiz_id, '_ahsa_seb_config_key', true );
	}

	/**
	 * Get SEB exam key for a quiz.
	 *
	 * @param int $quiz_id Quiz post ID.
	 * @return string
	 */
	public function get_seb_exam_key( $quiz_id ) {
		return (string) get_post_meta( $quiz_id, '_ahsa_seb_exam_key', true );
	}

	/**
	 * Validate SEB request headers against quiz configuration.
	 *
	 * @param int $quiz_id Quiz post ID.
	 * @return bool|string True if valid, error message if not.
	 */
	public function validate_seb_request( $quiz_id ) {
		if ( ! $this->quiz_requires_seb( $quiz_id ) ) {
			return true;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// Check for SEB user agent.
		if ( false === stripos( $user_agent, 'SEB' ) ) {
			return __( 'This exam requires Safe Exam Browser. Please launch the exam through SEB.', 'lifterlms' );
		}

		// Validate config key hash if configured.
		$config_key = $this->get_seb_config_key( $quiz_id );
		if ( $config_key ) {
			$request_hash = isset( $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] ) )
				: '';

			$expected_hash = hash( 'sha256', $config_key . get_permalink( $quiz_id ) );

			if ( ! hash_equals( $expected_hash, $request_hash ) ) {
				return __( 'SEB configuration key validation failed. Ensure you are using the correct SEB configuration.', 'lifterlms' );
			}
		}

		// Validate exam key if configured.
		$exam_key = $this->get_seb_exam_key( $quiz_id );
		if ( $exam_key ) {
			$browser_exam_key = isset( $_SERVER['HTTP_X_SAFEEXAMBROWSER_REQUESTHASH'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SAFEEXAMBROWSER_REQUESTHASH'] ) )
				: '';

			$expected_exam_hash = hash( 'sha256', $exam_key . get_permalink( $quiz_id ) );

			if ( ! hash_equals( $expected_exam_hash, $browser_exam_key ) ) {
				return __( 'SEB exam key validation failed.', 'lifterlms' );
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Session Management
	// -------------------------------------------------------------------------

	/**
	 * Create an exam session.
	 *
	 * @param int  $student_id Student user ID.
	 * @param int  $quiz_id    Quiz post ID.
	 * @param bool $validated  Was SEB validated.
	 * @return int|false Session ID or false.
	 */
	public function create_session( $student_id, $quiz_id, $validated = false ) {
		global $wpdb;

		$result = $wpdb->insert(
			$wpdb->prefix . 'ahsa_exam_sessions',
			array(
				'student_user_id' => absint( $student_id ),
				'quiz_id'         => absint( $quiz_id ),
				'seb_validated'   => $validated ? 1 : 0,
				'seb_config_hash' => $validated ? $this->get_current_seb_hash() : null,
			),
			array( '%d', '%d', '%d', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * End an exam session.
	 *
	 * @param int $session_id Session ID.
	 * @return bool
	 */
	public function end_session( $session_id ) {
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . 'ahsa_exam_sessions',
			array( 'ended_at' => current_time( 'mysql', true ) ),
			array( 'id' => absint( $session_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get the current SEB config hash from request headers.
	 *
	 * @return string|null
	 */
	private function get_current_seb_hash() {
		return isset( $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] ) )
			: null;
	}

	/**
	 * Check for concurrent sessions.
	 *
	 * @param int $student_id Student user ID.
	 * @param int $quiz_id    Quiz post ID.
	 * @return bool True if concurrent session exists.
	 */
	public function has_concurrent_session( $student_id, $quiz_id ) {
		global $wpdb;
		$active = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ahsa_exam_sessions
				 WHERE student_user_id = %d AND quiz_id = %d AND ended_at IS NULL
				 AND started_at > DATE_SUB(NOW(), INTERVAL 4 HOUR)",
				absint( $student_id ),
				absint( $quiz_id )
			)
		);
		return (int) $active > 0;
	}

	// -------------------------------------------------------------------------
	// Event Logging
	// -------------------------------------------------------------------------

	/**
	 * Log an exam event.
	 *
	 * @param int    $session_id Session ID.
	 * @param string $event_type Event type.
	 * @param array  $event_data Additional event data.
	 * @return int|false Log entry ID or false.
	 */
	public function log_event( $session_id, $event_type, $event_data = array() ) {
		global $wpdb;

		if ( ! in_array( $event_type, self::EVENT_TYPES, true ) ) {
			return false;
		}

		$ip = $this->get_client_ip();

		$result = $wpdb->insert(
			$wpdb->prefix . 'ahsa_exam_logs',
			array(
				'session_id' => absint( $session_id ),
				'event_type' => sanitize_key( $event_type ),
				'event_data' => wp_json_encode( $event_data ),
				'ip_address' => $ip ? sanitize_text_field( $ip ) : null,
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : null,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( $result ) {
			// Update risk score based on event type.
			$this->update_risk_score( $session_id, $event_type );
		}

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string|null
	 */
	private function get_client_ip() {
		$headers = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				$ip  = trim( $ips[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return null;
	}

	/**
	 * Update risk score for a session based on event type.
	 *
	 * @param int    $session_id Session ID.
	 * @param string $event_type Event type.
	 */
	private function update_risk_score( $session_id, $event_type ) {
		$risk_points = array(
			'validation_failed'  => 5,
			'focus_loss'         => 1,
			'disconnect'         => 2,
			'abnormal_exit'      => 4,
			'copy_attempt'       => 3,
			'paste_attempt'      => 3,
			'screenshot_attempt' => 4,
			'concurrent_session' => 5,
			'seb_key_mismatch'   => 5,
			'browser_blocked'    => 5,
		);

		if ( ! isset( $risk_points[ $event_type ] ) ) {
			return;
		}

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}ahsa_exam_sessions SET risk_score = risk_score + %d WHERE id = %d",
				$risk_points[ $event_type ],
				absint( $session_id )
			)
		);

		// Recalculate risk level.
		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT risk_score FROM {$wpdb->prefix}ahsa_exam_sessions WHERE id = %d",
				absint( $session_id )
			)
		);

		if ( $session ) {
			$level = 'low';
			foreach ( self::RISK_LEVELS as $lvl => $threshold ) {
				if ( $session->risk_score >= $threshold ) {
					$level = $lvl;
				}
			}

			$wpdb->update(
				$wpdb->prefix . 'ahsa_exam_sessions',
				array( 'risk_level' => $level ),
				array( 'id' => absint( $session_id ) ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Get logs for a session.
	 *
	 * @param int $session_id Session ID.
	 * @return array
	 */
	public function get_session_logs( $session_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ahsa_exam_logs WHERE session_id = %d ORDER BY created_at ASC",
				absint( $session_id )
			)
		);
	}

	// -------------------------------------------------------------------------
	// Quiz Launch Validation
	// -------------------------------------------------------------------------

	/**
	 * Validate exam start — intercept quiz attempt creation.
	 *
	 * @param array        $attempt_data Attempt data.
	 * @param LLMS_Student $student      Student object.
	 * @param LLMS_Quiz    $quiz         Quiz object.
	 */
	public function validate_exam_start( $attempt_data, $student, $quiz ) {
		$quiz_id = $quiz->get( 'id' );

		if ( ! $this->quiz_requires_seb( $quiz_id ) ) {
			return;
		}

		$student_id = $student->get_id();

		// Check for concurrent sessions.
		if ( $this->has_concurrent_session( $student_id, $quiz_id ) ) {
			$session_id = $this->create_session( $student_id, $quiz_id, false );
			if ( $session_id ) {
				$this->log_event( $session_id, 'concurrent_session', array(
					'message' => 'Concurrent exam session detected',
				) );
				$this->end_session( $session_id );
			}
			wp_die(
				esc_html__( 'A concurrent exam session was detected. Only one exam session is allowed at a time. Contact your instructor if this is an error.', 'lifterlms' ),
				esc_html__( 'Concurrent Session Blocked', 'lifterlms' ),
				array( 'response' => 403, 'back_link' => true )
			);
		}

		// Validate SEB.
		$validation = $this->validate_seb_request( $quiz_id );
		$validated  = ( true === $validation );

		$session_id = $this->create_session( $student_id, $quiz_id, $validated );

		if ( ! $validated ) {
			if ( $session_id ) {
				$this->log_event( $session_id, 'browser_blocked', array(
					'message' => is_string( $validation ) ? $validation : 'SEB validation failed',
				) );
				$this->end_session( $session_id );
			}
			wp_die(
				esc_html( is_string( $validation ) ? $validation : __( 'This exam requires Safe Exam Browser.', 'lifterlms' ) ),
				esc_html__( 'Exam Security Block', 'lifterlms' ),
				array( 'response' => 403, 'back_link' => true )
			);
		}

		// Valid SEB session — log start.
		if ( $session_id ) {
			$this->log_event( $session_id, 'exam_start', array(
				'quiz_id' => $quiz_id,
				'seb'     => true,
			) );

			// Store session ID in user meta for client-side logging.
			update_user_meta( $student_id, '_ahsa_current_exam_session', $session_id );
		}
	}

	/**
	 * Handle exam end — log completion and close session.
	 *
	 * Hooked to llms_quiz_attempt_graded.
	 *
	 * @param array        $attempt_data Attempt data.
	 * @param LLMS_Student $student      Student object.
	 * @param LLMS_Quiz    $quiz         Quiz object.
	 */
	public function handle_exam_end( $attempt_data, $student, $quiz ) {
		$student_id = $student->get_id();
		$quiz_id    = $quiz->get( 'id' );

		if ( ! $this->quiz_requires_seb( $quiz_id ) ) {
			return;
		}

		$session_id = get_user_meta( $student_id, '_ahsa_current_exam_session', true );
		if ( ! $session_id ) {
			return;
		}

		$grade = isset( $attempt_data['grade'] ) ? $attempt_data['grade'] : null;

		$this->log_event( (int) $session_id, 'attempt_submitted', array(
			'quiz_id' => $quiz_id,
			'grade'   => $grade,
		) );

		$this->log_event( (int) $session_id, 'exam_end', array(
			'quiz_id' => $quiz_id,
		) );

		$this->end_session( (int) $session_id );
		delete_user_meta( $student_id, '_ahsa_current_exam_session' );
	}

	// -------------------------------------------------------------------------
	// REST API for Client-Side Event Logging
	// -------------------------------------------------------------------------

	/**
	 * Register REST routes.
	 */
	public function register_rest_routes() {
		register_rest_route( 'ahsa/v1', '/exam-event', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_log_event' ),
			'permission_callback' => function() {
				return is_user_logged_in();
			},
			'args'                => array(
				'session_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'event_type' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => function( $param ) {
						return in_array( $param, self::EVENT_TYPES, true );
					},
				),
				'event_data' => array(
					'required' => false,
					'type'     => 'object',
					'default'  => array(),
				),
			),
		) );
	}

	/**
	 * REST callback for logging exam events from client-side JavaScript.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function rest_log_event( $request ) {
		$session_id = $request->get_param( 'session_id' );
		$event_type = $request->get_param( 'event_type' );
		$event_data = $request->get_param( 'event_data' );

		// Verify the session belongs to the current user.
		global $wpdb;
		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT student_user_id FROM {$wpdb->prefix}ahsa_exam_sessions WHERE id = %d",
				$session_id
			)
		);

		if ( ! $session || (int) $session->student_user_id !== get_current_user_id() ) {
			return new WP_REST_Response( array( 'error' => 'Invalid session' ), 403 );
		}

		$log_id = $this->log_event( $session_id, $event_type, is_array( $event_data ) ? $event_data : array() );

		return new WP_REST_Response( array( 'logged' => (bool) $log_id, 'id' => $log_id ), 200 );
	}

	// -------------------------------------------------------------------------
	// Frontend Scripts
	// -------------------------------------------------------------------------

	/**
	 * Enqueue exam security JavaScript on SEB-protected quiz pages.
	 */
	public function maybe_enqueue_exam_scripts() {
		if ( ! is_singular( 'llms_quiz' ) && ! is_singular( 'lesson' ) ) {
			return;
		}

		global $post;
		$quiz_id = 0;

		if ( 'llms_quiz' === get_post_type( $post ) ) {
			$quiz_id = $post->ID;
		} elseif ( 'lesson' === get_post_type( $post ) ) {
			$quiz_id = get_post_meta( $post->ID, '_llms_quiz', true );
		}

		if ( ! $quiz_id || ! $this->quiz_requires_seb( $quiz_id ) ) {
			return;
		}

		$session_id = get_user_meta( get_current_user_id(), '_ahsa_current_exam_session', true );
		if ( ! $session_id ) {
			return;
		}

		// Inline the exam monitoring script.
		wp_add_inline_script( 'jquery', $this->get_exam_monitor_script( $session_id ), 'after' );
	}

	/**
	 * Generate the client-side exam monitoring JavaScript.
	 *
	 * @param int $session_id Exam session ID.
	 * @return string JavaScript code.
	 */
	private function get_exam_monitor_script( $session_id ) {
		$rest_url  = esc_url_raw( rest_url( 'ahsa/v1/exam-event' ) );
		$nonce     = wp_create_nonce( 'wp_rest' );
		$session   = absint( $session_id );

		return <<<JS
(function() {
	'use strict';
	var sessionId = {$session};
	var restUrl = '{$rest_url}';
	var nonce = '{$nonce}';

	function logEvent(type, data) {
		var xhr = new XMLHttpRequest();
		xhr.open('POST', restUrl, true);
		xhr.setRequestHeader('Content-Type', 'application/json');
		xhr.setRequestHeader('X-WP-Nonce', nonce);
		xhr.send(JSON.stringify({
			session_id: sessionId,
			event_type: type,
			event_data: data || {}
		}));
	}

	// Focus loss detection.
	document.addEventListener('visibilitychange', function() {
		if (document.hidden) {
			logEvent('focus_loss', {timestamp: new Date().toISOString()});
		} else {
			logEvent('focus_return', {timestamp: new Date().toISOString()});
		}
	});

	window.addEventListener('blur', function() {
		logEvent('focus_loss', {source: 'window_blur', timestamp: new Date().toISOString()});
	});

	// Copy/paste prevention.
	document.addEventListener('copy', function(e) {
		e.preventDefault();
		logEvent('copy_attempt', {timestamp: new Date().toISOString()});
	});

	document.addEventListener('paste', function(e) {
		e.preventDefault();
		logEvent('paste_attempt', {timestamp: new Date().toISOString()});
	});

	document.addEventListener('cut', function(e) {
		e.preventDefault();
		logEvent('copy_attempt', {source: 'cut', timestamp: new Date().toISOString()});
	});

	// Right-click prevention.
	document.addEventListener('contextmenu', function(e) {
		e.preventDefault();
	});

	// Keyboard shortcut blocking.
	document.addEventListener('keydown', function(e) {
		if ((e.ctrlKey || e.metaKey) && ['c','v','x','a','p','s'].indexOf(e.key.toLowerCase()) !== -1) {
			e.preventDefault();
			logEvent('copy_attempt', {key: e.key, timestamp: new Date().toISOString()});
		}
		if (e.key === 'PrintScreen' || e.key === 'F12') {
			e.preventDefault();
			logEvent('screenshot_attempt', {key: e.key, timestamp: new Date().toISOString()});
		}
	});

	// Disconnect detection.
	window.addEventListener('offline', function() {
		logEvent('disconnect', {timestamp: new Date().toISOString()});
	});

	window.addEventListener('online', function() {
		logEvent('reconnect', {timestamp: new Date().toISOString()});
	});

	// Unload detection (abnormal exit).
	window.addEventListener('beforeunload', function() {
		logEvent('abnormal_exit', {timestamp: new Date().toISOString()});
	});
})();
JS;
	}

	// -------------------------------------------------------------------------
	// Quiz Metabox
	// -------------------------------------------------------------------------

	/**
	 * Add SEB settings metabox to quiz editor.
	 */
	public function add_quiz_metabox() {
		add_meta_box(
			'ahsa-seb-settings',
			__( 'Exam Security (SEB)', 'lifterlms' ),
			array( $this, 'render_quiz_metabox' ),
			'llms_quiz',
			'side',
			'high'
		);
	}

	/**
	 * Render quiz SEB metabox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_quiz_metabox( $post ) {
		$seb_required = get_post_meta( $post->ID, '_ahsa_seb_required', true );
		$config_key   = get_post_meta( $post->ID, '_ahsa_seb_config_key', true );
		$exam_key     = get_post_meta( $post->ID, '_ahsa_seb_exam_key', true );

		wp_nonce_field( 'ahsa_seb_settings_' . $post->ID, 'ahsa_seb_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="ahsa_seb_required" value="yes" <?php checked( $seb_required, 'yes' ); ?>>
				<strong><?php esc_html_e( 'Require Safe Exam Browser', 'lifterlms' ); ?></strong>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'When enabled, students must use SEB to take this quiz. Non-SEB browsers will be blocked.', 'lifterlms' ); ?></p>

		<p>
			<label for="ahsa_seb_config_key"><strong><?php esc_html_e( 'SEB Config Key', 'lifterlms' ); ?></strong></label><br>
			<input type="text" name="ahsa_seb_config_key" id="ahsa_seb_config_key"
				value="<?php echo esc_attr( $config_key ); ?>" class="widefat">
		</p>
		<p class="description"><?php esc_html_e( 'The SEB configuration key from your .seb config file. Used to validate the SEB session.', 'lifterlms' ); ?></p>

		<p>
			<label for="ahsa_seb_exam_key"><strong><?php esc_html_e( 'SEB Exam Key', 'lifterlms' ); ?></strong></label><br>
			<input type="text" name="ahsa_seb_exam_key" id="ahsa_seb_exam_key"
				value="<?php echo esc_attr( $exam_key ); ?>" class="widefat">
		</p>
		<p class="description"><?php esc_html_e( 'Optional browser exam key for additional verification.', 'lifterlms' ); ?></p>
		<?php
	}

	/**
	 * Save quiz SEB metabox.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_quiz_metabox( $post_id, $post ) {
		if ( ! isset( $_POST['ahsa_seb_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ahsa_seb_nonce'] ) ), 'ahsa_seb_settings_' . $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$seb_required = isset( $_POST['ahsa_seb_required'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_ahsa_seb_required', $seb_required );

		if ( isset( $_POST['ahsa_seb_config_key'] ) ) {
			$config_key = sanitize_text_field( wp_unslash( $_POST['ahsa_seb_config_key'] ) );
			update_post_meta( $post_id, '_ahsa_seb_config_key', $config_key );
		}

		if ( isset( $_POST['ahsa_seb_exam_key'] ) ) {
			$exam_key = sanitize_text_field( wp_unslash( $_POST['ahsa_seb_exam_key'] ) );
			update_post_meta( $post_id, '_ahsa_seb_exam_key', $exam_key );
		}
	}

	// -------------------------------------------------------------------------
	// Admin Menus & Review Tools
	// -------------------------------------------------------------------------

	/**
	 * Add admin menus.
	 */
	public function add_admin_menus() {
		add_submenu_page(
			'lifterlms',
			__( 'Exam Security', 'lifterlms' ),
			__( 'Exam Security', 'lifterlms' ),
			'manage_lifterlms',
			'ahsa-exam-security',
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

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'sessions';

		// Handle review form.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ahsa_review_action'] ) ) {
			check_admin_referer( 'ahsa_exam_review' );
			$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
			$notes      = isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '';

			if ( $session_id ) {
				global $wpdb;
				$wpdb->update(
					$wpdb->prefix . 'ahsa_exam_sessions',
					array(
						'admin_reviewed' => 1,
						'admin_notes'    => $notes,
					),
					array( 'id' => $session_id ),
					array( '%d', '%s' ),
					array( '%d' )
				);
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Session reviewed.', 'lifterlms' ) . '</p></div>';
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AHSA Exam Security', 'lifterlms' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-exam-security&tab=sessions' ) ); ?>"
					class="nav-tab <?php echo 'sessions' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Exam Sessions', 'lifterlms' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-exam-security&tab=flagged' ) ); ?>"
					class="nav-tab <?php echo 'flagged' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Flagged Sessions', 'lifterlms' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-exam-security&tab=settings' ) ); ?>"
					class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'lifterlms' ); ?>
				</a>
			</nav>

			<div class="ahsa-admin-tab-content">
				<?php
				switch ( $tab ) {
					case 'flagged':
						$this->render_flagged_tab();
						break;
					case 'settings':
						$this->render_settings_tab();
						break;
					case 'review':
						$this->render_review_tab();
						break;
					default:
						$this->render_sessions_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sessions tab.
	 */
	private function render_sessions_tab() {
		global $wpdb;

		$sessions = $wpdb->get_results(
			"SELECT s.*, u.display_name as student_name, p.post_title as quiz_title
			 FROM {$wpdb->prefix}ahsa_exam_sessions s
			 LEFT JOIN {$wpdb->users} u ON s.student_user_id = u.ID
			 LEFT JOIN {$wpdb->posts} p ON s.quiz_id = p.ID
			 ORDER BY s.started_at DESC LIMIT 100"
		);

		if ( empty( $sessions ) ) {
			echo '<p>' . esc_html__( 'No exam sessions recorded yet.', 'lifterlms' ) . '</p>';
			return;
		}

		$this->render_sessions_table( $sessions );
	}

	/**
	 * Render flagged sessions tab.
	 */
	private function render_flagged_tab() {
		global $wpdb;

		$sessions = $wpdb->get_results(
			"SELECT s.*, u.display_name as student_name, p.post_title as quiz_title
			 FROM {$wpdb->prefix}ahsa_exam_sessions s
			 LEFT JOIN {$wpdb->users} u ON s.student_user_id = u.ID
			 LEFT JOIN {$wpdb->posts} p ON s.quiz_id = p.ID
			 WHERE s.risk_level IN ('moderate','high','critical')
			 ORDER BY s.risk_score DESC, s.started_at DESC LIMIT 100"
		);

		if ( empty( $sessions ) ) {
			echo '<p>' . esc_html__( 'No flagged sessions.', 'lifterlms' ) . '</p>';
			return;
		}

		$this->render_sessions_table( $sessions );
	}

	/**
	 * Render sessions table.
	 *
	 * @param array $sessions Session rows.
	 */
	private function render_sessions_table( $sessions ) {
		?>
		<table class="wp-list-table widefat fixed striped ahsa-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Student', 'lifterlms' ); ?></th>
					<th><?php esc_html_e( 'Quiz', 'lifterlms' ); ?></th>
					<th><?php esc_html_e( 'SEB', 'lifterlms' ); ?></th>
					<th><?php esc_html_e( 'Risk', 'lifterlms' ); ?></th>
					<th><?php esc_html_e( 'Score', 'lifterlms' ); ?></th>
					<th><?php esc_html_e( 'Started', 'lifterlms' ); ?></th>
					<th><?php esc_html_e( 'Reviewed', 'lifterlms' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'lifterlms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sessions as $session ) : ?>
				<tr>
					<td><?php echo esc_html( $session->student_name ?: __( 'Unknown', 'lifterlms' ) ); ?></td>
					<td><?php echo esc_html( $session->quiz_title ?: __( 'Unknown', 'lifterlms' ) ); ?></td>
					<td><?php echo $session->seb_validated ? '&#10003;' : '&#10007;'; ?></td>
					<td>
						<span class="ahsa-badge ahsa-risk-<?php echo esc_attr( $session->risk_level ); ?>">
							<?php echo esc_html( ucfirst( $session->risk_level ) ); ?>
						</span>
					</td>
					<td><?php echo esc_html( $session->risk_score ); ?></td>
					<td><?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $session->started_at ) ) ); ?></td>
					<td><?php echo $session->admin_reviewed ? '&#10003;' : '—'; ?></td>
					<td>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-exam-security&tab=review&session_id=' . $session->id ) ); ?>" class="button button-small">
							<?php esc_html_e( 'Review', 'lifterlms' ); ?>
						</a>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render session review tab.
	 */
	private function render_review_tab() {
		$session_id = isset( $_GET['session_id'] ) ? absint( $_GET['session_id'] ) : 0;
		if ( ! $session_id ) {
			echo '<p>' . esc_html__( 'No session specified.', 'lifterlms' ) . '</p>';
			return;
		}

		global $wpdb;
		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, u.display_name as student_name, u.user_email as student_email, p.post_title as quiz_title
				 FROM {$wpdb->prefix}ahsa_exam_sessions s
				 LEFT JOIN {$wpdb->users} u ON s.student_user_id = u.ID
				 LEFT JOIN {$wpdb->posts} p ON s.quiz_id = p.ID
				 WHERE s.id = %d",
				$session_id
			)
		);

		if ( ! $session ) {
			echo '<p>' . esc_html__( 'Session not found.', 'lifterlms' ) . '</p>';
			return;
		}

		$logs = $this->get_session_logs( $session_id );

		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ahsa-exam-security' ) ); ?>">&larr; <?php esc_html_e( 'Back to Sessions', 'lifterlms' ); ?></a></p>

		<h2><?php esc_html_e( 'Session Review', 'lifterlms' ); ?></h2>

		<table class="form-table">
			<tr><th><?php esc_html_e( 'Student', 'lifterlms' ); ?></th><td><?php echo esc_html( $session->student_name ); ?> (<?php echo esc_html( $session->student_email ); ?>)</td></tr>
			<tr><th><?php esc_html_e( 'Quiz', 'lifterlms' ); ?></th><td><?php echo esc_html( $session->quiz_title ); ?></td></tr>
			<tr><th><?php esc_html_e( 'SEB Validated', 'lifterlms' ); ?></th><td><?php echo $session->seb_validated ? esc_html__( 'Yes', 'lifterlms' ) : esc_html__( 'No', 'lifterlms' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Risk Level', 'lifterlms' ); ?></th><td><span class="ahsa-badge ahsa-risk-<?php echo esc_attr( $session->risk_level ); ?>"><?php echo esc_html( ucfirst( $session->risk_level ) ); ?></span> (score: <?php echo esc_html( $session->risk_score ); ?>)</td></tr>
			<tr><th><?php esc_html_e( 'Started', 'lifterlms' ); ?></th><td><?php echo esc_html( wp_date( 'M j, Y g:i:s a', strtotime( $session->started_at ) ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Ended', 'lifterlms' ); ?></th><td><?php echo $session->ended_at ? esc_html( wp_date( 'M j, Y g:i:s a', strtotime( $session->ended_at ) ) ) : '—'; ?></td></tr>
		</table>

		<h3><?php esc_html_e( 'Event Log', 'lifterlms' ); ?></h3>
		<?php if ( empty( $logs ) ) : ?>
			<p><?php esc_html_e( 'No events logged for this session.', 'lifterlms' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:180px;"><?php esc_html_e( 'Time', 'lifterlms' ); ?></th>
						<th style="width:180px;"><?php esc_html_e( 'Event', 'lifterlms' ); ?></th>
						<th><?php esc_html_e( 'Details', 'lifterlms' ); ?></th>
						<th style="width:120px;"><?php esc_html_e( 'IP', 'lifterlms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $logs as $log ) :
						$data = json_decode( $log->event_data, true );
					?>
					<tr>
						<td><?php echo esc_html( wp_date( 'g:i:s a', strtotime( $log->created_at ) ) ); ?></td>
						<td><code><?php echo esc_html( $log->event_type ); ?></code></td>
						<td><?php echo $data ? esc_html( wp_json_encode( $data ) ) : '—'; ?></td>
						<td><?php echo esc_html( $log->ip_address ?: '—' ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Admin Review', 'lifterlms' ); ?></h3>
		<form method="post">
			<?php wp_nonce_field( 'ahsa_exam_review' ); ?>
			<input type="hidden" name="ahsa_review_action" value="review">
			<input type="hidden" name="session_id" value="<?php echo esc_attr( $session->id ); ?>">
			<p>
				<label for="admin_notes"><strong><?php esc_html_e( 'Review Notes', 'lifterlms' ); ?></strong></label><br>
				<textarea name="admin_notes" id="admin_notes" rows="4" class="large-text"><?php echo esc_textarea( $session->admin_notes ); ?></textarea>
			</p>
			<?php submit_button( __( 'Mark as Reviewed', 'lifterlms' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render settings tab.
	 */
	private function render_settings_tab() {
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ahsa_seb_global_action'] ) ) {
			check_admin_referer( 'ahsa_seb_global_settings' );
			$global_seb = isset( $_POST['ahsa_seb_global_default'] ) ? 'yes' : 'no';
			update_option( 'ahsa_seb_global_default', $global_seb );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'lifterlms' ) . '</p></div>';
		}

		$global_default = get_option( 'ahsa_seb_global_default', 'no' );

		// Stats.
		global $wpdb;
		$total_sessions  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ahsa_exam_sessions" );
		$flagged_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ahsa_exam_sessions WHERE risk_level IN ('moderate','high','critical')" );
		$unreviewed      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ahsa_exam_sessions WHERE risk_level != 'low' AND admin_reviewed = 0" );
		$seb_quizzes     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ahsa_seb_required' AND meta_value = 'yes'" );
		?>

		<h2><?php esc_html_e( 'Global Settings', 'lifterlms' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'ahsa_seb_global_settings' ); ?>
			<input type="hidden" name="ahsa_seb_global_action" value="save">

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Default SEB Requirement', 'lifterlms' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ahsa_seb_global_default" value="yes" <?php checked( $global_default, 'yes' ); ?>>
							<?php esc_html_e( 'Require SEB for all new quizzes by default', 'lifterlms' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Individual quizzes can override this setting.', 'lifterlms' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Security Dashboard', 'lifterlms' ); ?></h2>
		<table class="form-table">
			<tr><th><?php esc_html_e( 'SEB-Protected Quizzes', 'lifterlms' ); ?></th><td><strong><?php echo esc_html( $seb_quizzes ); ?></strong></td></tr>
			<tr><th><?php esc_html_e( 'Total Exam Sessions', 'lifterlms' ); ?></th><td><strong><?php echo esc_html( $total_sessions ); ?></strong></td></tr>
			<tr><th><?php esc_html_e( 'Flagged Sessions', 'lifterlms' ); ?></th><td><strong class="ahsa-risk-critical"><?php echo esc_html( $flagged_count ); ?></strong></td></tr>
			<tr><th><?php esc_html_e( 'Awaiting Review', 'lifterlms' ); ?></th><td><strong class="ahsa-risk-high"><?php echo esc_html( $unreviewed ); ?></strong></td></tr>
		</table>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX Handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Get exam logs for a session.
	 */
	public function ajax_get_exam_logs() {
		check_ajax_referer( 'ahsa_exam_security', 'nonce' );

		if ( ! current_user_can( 'manage_lifterlms' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lifterlms' ) ), 403 );
		}

		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		$logs       = $this->get_session_logs( $session_id );

		wp_send_json_success( array( 'logs' => $logs ) );
	}

	/**
	 * AJAX: Update risk level override.
	 */
	public function ajax_update_risk_level() {
		check_ajax_referer( 'ahsa_exam_security', 'nonce' );

		if ( ! current_user_can( 'manage_lifterlms' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lifterlms' ) ), 403 );
		}

		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		$level      = isset( $_POST['risk_level'] ) ? sanitize_key( $_POST['risk_level'] ) : '';

		if ( ! $session_id || ! array_key_exists( $level, self::RISK_LEVELS ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'lifterlms' ) ) );
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'ahsa_exam_sessions',
			array( 'risk_level' => $level ),
			array( 'id' => $session_id ),
			array( '%s' ),
			array( '%d' )
		);

		wp_send_json_success();
	}
}
