<?php
/**
 * AHSA Branding — stylesheet enqueue.
 *
 * Loaded by ahsa-core.php MU-plugin.
 * Enqueues the shared branding CSS on both the public front-end
 * and on relevant wp-admin pages.
 *
 * @package AHSA
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AHSA_Branding
 */
class AHSA_Branding {

	/** @var string Stylesheet handle. */
	const HANDLE = 'ahsa-branding';

	/** @var self|null */
	private static $instance = null;

	/**
	 * Return singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — register hooks.
	 */
	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login' ) );
	}

	/**
	 * Resolve the CSS file URL.
	 *
	 * Works whether the file lives inside the plugin tree or elsewhere.
	 *
	 * @return string
	 */
	private function css_url() {
		$file = dirname( __DIR__ ) . '/assets/ahsa-branding.css';
		if ( file_exists( $file ) ) {
			return plugins_url( 'assets/ahsa-branding.css', __DIR__ . '/placeholder' );
		}
		// Fallback: try relative to the lifterlms plugin directory.
		return plugins_url( 'sample-data/assets/ahsa-branding.css', dirname( __DIR__ ) . '/lifterlms.php' );
	}

	/**
	 * CSS version string (file modification time).
	 *
	 * @return string
	 */
	private function css_version() {
		$file = dirname( __DIR__ ) . '/assets/ahsa-branding.css';
		return file_exists( $file ) ? (string) filemtime( $file ) : '2.0.0';
	}

	/**
	 * Enqueue on front-end pages that render AHSA shortcodes.
	 */
	public function enqueue_frontend() {
		wp_register_style( self::HANDLE, $this->css_url(), array(), $this->css_version() );

		// Always enqueue — the stylesheet is small and caches well.
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * Enqueue on admin pages that belong to AHSA modules.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin( $hook_suffix ) {
		// Load on any LifterLMS or AHSA admin page and on post editors.
		$dominated = array( 'post.php', 'post-new.php', 'edit.php' );
		$dominated_screens = array( 'ahsa-rubrics', 'ahsa-standards', 'ahsa-exam-security' );

		$load = in_array( $hook_suffix, $dominated, true );

		if ( ! $load ) {
			foreach ( $dominated_screens as $slug ) {
				if ( false !== strpos( $hook_suffix, $slug ) ) {
					$load = true;
					break;
				}
			}
		}

		// Also load on any lifterlms page.
		if ( ! $load && false !== strpos( $hook_suffix, 'lifterlms' ) ) {
			$load = true;
		}

		if ( $load ) {
			wp_enqueue_style( self::HANDLE, $this->css_url(), array(), $this->css_version() );
		}
	}

	/**
	 * Enqueue on the login page for AHSA-branded login screen.
	 */
	public function enqueue_login() {
		wp_enqueue_style( self::HANDLE, $this->css_url(), array(), $this->css_version() );
	}
}
