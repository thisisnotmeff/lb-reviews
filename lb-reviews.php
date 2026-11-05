<?php
/**
 * Plugin Name:       LB Reviews – Google & Trustpilot
 * Description:       Shows Google and Trustpilot reviews in a responsive grid (shortcode + WPBakery element). Live Google reviews via Places API, full archive via Google Takeout JSON / CSV import, Trustpilot via CSV import or TrustBox.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Update URI:        https://github.com/thisisnotmeff/lb-reviews
 * Author:            Lucilla Bellini Portraits
 * Text Domain:       lb-reviews
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'LBR_VERSION', '1.2.0' );
define( 'LBR_FILE', __FILE__ );
define( 'LBR_DIR', plugin_dir_path( __FILE__ ) );
define( 'LBR_URL', plugin_dir_url( __FILE__ ) );
// GitHub repository used for updates (owner/repo).
if ( ! defined( 'LBR_GITHUB_REPO' ) ) {
	define( 'LBR_GITHUB_REPO', 'thisisnotmeff/lb-reviews' );
}

require_once LBR_DIR . 'includes/class-lbr-settings.php';
require_once LBR_DIR . 'includes/class-lbr-post-type.php';
require_once LBR_DIR . 'includes/class-lbr-repository.php';
require_once LBR_DIR . 'includes/class-lbr-google.php';
require_once LBR_DIR . 'includes/class-lbr-importer.php';
require_once LBR_DIR . 'includes/class-lbr-render.php';
require_once LBR_DIR . 'includes/class-lbr-schema.php';
require_once LBR_DIR . 'includes/class-lbr-wpbakery.php';
require_once LBR_DIR . 'includes/class-lbr-admin.php';
require_once LBR_DIR . 'includes/class-lbr-updater.php';

register_activation_hook( __FILE__, array( 'LBR_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LBR_Plugin', 'deactivate' ) );

/**
 * Bootstrap.
 */
final class LBR_Plugin {

	public static function init() {
		load_plugin_textdomain( 'lb-reviews', false, dirname( plugin_basename( LBR_FILE ) ) . '/languages' );

		LBR_Post_Type::init();
		LBR_Repository::init();
		LBR_Google::init();
		LBR_Render::init();
		LBR_Schema::init();
		LBR_WPBakery::init();
		LBR_Updater::init();

		if ( is_admin() ) {
			LBR_Admin::init();
			LBR_Importer::init();
		}
	}

	public static function activate() {
		LBR_Post_Type::register();
		flush_rewrite_rules();
		LBR_Google::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( LBR_Google::CRON_HOOK );
	}
}

add_action( 'plugins_loaded', array( 'LBR_Plugin', 'init' ) );
