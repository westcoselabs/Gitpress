<?php
/**
 * Plugin Name: GitPress
 * Plugin URI: https://westcoselabs.com/gitpress
 * Description: Render GitHub-hosted HTML, Markdown, text, or code inside Divi (and any WordPress theme) with server-side output, caching, and webhook invalidation.
 * Version: 1.2.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: WestCose Labs
 * Author URI: https://westcoselabs.com
 * License: GPL-2.0-or-later
 * Text Domain: gitpress
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DGS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DGS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DGS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'DGS_VERSION', '1.2.3' );
define( 'DGS_DEFAULT_CACHE_TTL', 3600 );
define( 'DGS_CACHE_PREFIX', 'dgs_github_content_' );
define( 'DGS_CACHE_INDEX_OPTION', 'dgs_cache_index' );

require_once DGS_PLUGIN_DIR . 'includes/class-github-api.php';
require_once DGS_PLUGIN_DIR . 'includes/class-cache-handler.php';
require_once DGS_PLUGIN_DIR . 'includes/class-shortcode-handler.php';
require_once DGS_PLUGIN_DIR . 'includes/class-webhook-handler.php';
require_once DGS_PLUGIN_DIR . 'includes/class-page-shortcode-manager.php';
require_once DGS_PLUGIN_DIR . 'admin/class-settings-page.php';

class GitPress {

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'init' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_init', array( 'DGS_Settings_Page', 'register_settings' ) );
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		DGS_Shortcode_Handler::init();
		DGS_Webhook_Handler::init();
		DGS_Page_Shortcode_Manager::init();

		wp_register_style(
			'dgs-frontend',
			DGS_PLUGIN_URL . 'assets/style.css',
			array(),
			DGS_VERSION
		);

		load_plugin_textdomain( 'gitpress', false, dirname( DGS_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Add admin settings screen.
	 *
	 * @return void
	 */
	public static function add_admin_menu() {
		add_menu_page(
			__( 'GitPress', 'gitpress' ),
			__( 'GitPress', 'gitpress' ),
			'manage_options',
			'gitpress',
			array( 'DGS_Settings_Page', 'render_page' ),
			'dashicons-admin-links',
			81
		);
	}

	/**
	 * Seed plugin options on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		add_option( 'dgs_cache_ttl', DGS_DEFAULT_CACHE_TTL, '', 'no' );
		add_option( DGS_CACHE_INDEX_OPTION, array(), '', 'no' );
	}

	/**
	 * Deactivation hook placeholder.
	 *
	 * @return void
	 */
	public static function deactivate() {
	}
}

register_activation_hook( __FILE__, array( 'GitPress', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GitPress', 'deactivate' ) );

GitPress::boot();
