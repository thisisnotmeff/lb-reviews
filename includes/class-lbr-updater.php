<?php
/**
 * Updates from GitHub Releases.
 *
 * WordPress shows "a new version is available" on the Plugins screen (and can auto-update)
 * when the latest release on GitHub has a higher version than the installed one.
 * Each release must carry the plugin ZIP as an asset named lb-reviews.zip
 * (built automatically by the GitHub Action in .github/workflows/release.yml).
 */

defined( 'ABSPATH' ) || exit;

class LBR_Updater {

	const CACHE_KEY = 'lbr_github_release';
	const SLUG      = 'lb-reviews';
	const ASSET     = 'lb-reviews.zip';

	public static function init() {
		if ( ! self::repo() ) {
			return;
		}
		// WordPress 5.8+: plugins whose "Update URI" header points to github.com are asked here.
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( LBR_FILE ), array( __CLASS__, 'check_link' ) );
		add_action( 'admin_post_lbr_check_updates', array( __CLASS__, 'force_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'checked_notice' ) );
	}

	/**
	 * "owner/repo". Change it with: add_filter( 'lbr_github_repo', fn() => 'owner/repo' );
	 */
	public static function repo() {
		$repo = apply_filters( 'lbr_github_repo', defined( 'LBR_GITHUB_REPO' ) ? LBR_GITHUB_REPO : '' );
		return preg_match( '#^[\w.-]+/[\w.-]+$#', (string) $repo ) ? $repo : '';
	}

	/**
	 * Latest release, cached for 6 hours (1 hour after an error).
	 *
	 * @return array|null version, package, url, notes, published
	 */
	public static function latest( $force = false ) {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'LB-Reviews-Updater',
				),
			)
		);

		$data = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['tag_name'] ) && empty( $body['draft'] ) && empty( $body['prerelease'] ) ) {
				$package = '';
				foreach ( (array) ( isset( $body['assets'] ) ? $body['assets'] : array() ) as $asset ) {
					if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
						$package = $asset['browser_download_url'];
						break;
					}
				}
				if ( $package ) {
					$data = array(
						'version'   => ltrim( $body['tag_name'], 'vV' ),
						'package'   => esc_url_raw( $package ),
						'url'       => isset( $body['html_url'] ) ? esc_url_raw( $body['html_url'] ) : 'https://github.com/' . self::repo(),
						'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
						'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
					);
				}
			}
		}

		set_site_transient( self::CACHE_KEY, $data ? $data : array( 'version' => '' ), $data ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * @param array|false $update      Update data from other filters.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( LBR_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::latest();
		if ( ! $release ) {
			return $update;
		}
		// WordPress compares versions itself: newer → "Update now", otherwise "up to date" (auto-update toggle available).
		return array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => 'https://github.com/' . self::repo(),
			'package'      => $release['package'],
			'requires'     => '6.0',
			'requires_php' => '7.4',
		);
	}

	/**
	 * "View details" popup.
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest();
		$notes   = $release ? $release['notes'] : '';
		$readme  = file_exists( LBR_DIR . 'readme.txt' ) ? file_get_contents( LBR_DIR . 'readme.txt' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions

		return (object) array(
			'name'          => 'LB Reviews – Google & Trustpilot',
			'slug'          => self::SLUG,
			'version'       => $release ? $release['version'] : LBR_VERSION,
			'author'        => 'Lucilla Bellini Portraits',
			'homepage'      => 'https://github.com/' . self::repo(),
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $release ? $release['published'] : '',
			'download_link' => $release ? $release['package'] : '',
			'sections'      => array(
				'changelog'   => $notes ? wpautop( esc_html( $notes ) ) : '<pre>' . esc_html( $readme ) . '</pre>',
				'description' => esc_html__( 'Google and Trustpilot reviews in a grid or slider (shortcode + Uncode / WPBakery element).', 'lb-reviews' ),
			),
		);
	}

	/**
	 * Make sure the extracted folder is called "lb-reviews", whatever the ZIP's top folder is.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( LBR_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'lbr_update_folder', __( 'Could not rename the plugin folder during the update.', 'lb-reviews' ) );
	}

	public static function after_update( $upgrader, $options ) {
		if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	public static function check_link( $links ) {
		if ( current_user_can( 'update_plugins' ) ) {
			$url     = wp_nonce_url( admin_url( 'admin-post.php?action=lbr_check_updates' ), 'lbr_check_updates' );
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'lb-reviews' ) . '</a>';
		}
		return $links;
	}

	public static function force_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'lb-reviews' ) );
		}
		check_admin_referer( 'lbr_check_updates' );
		delete_site_transient( self::CACHE_KEY );
		$release = self::latest( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$state = ! $release ? 'error' : ( version_compare( $release['version'], LBR_VERSION, '>' ) ? 'available' : 'current' );
		wp_safe_redirect( add_query_arg( 'lbr_update', $state, admin_url( 'plugins.php' ) ) );
		exit;
	}

	public static function checked_notice() {
		if ( empty( $_GET['lbr_update'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$state = sanitize_key( $_GET['lbr_update'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$msgs  = array(
			/* translators: %s: version */
			'available' => array( 'warning', sprintf( __( 'LB Reviews: version %s is available. Use “Update now” below.', 'lb-reviews' ), (string) ( self::latest()['version'] ?? '' ) ) ),
			/* translators: %s: version */
			'current'   => array( 'success', sprintf( __( 'LB Reviews is up to date (%s).', 'lb-reviews' ), LBR_VERSION ) ),
			'error'     => array( 'error', __( 'LB Reviews: could not read the latest release from GitHub. Try again later.', 'lb-reviews' ) ),
		);
		if ( isset( $msgs[ $state ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msgs[ $state ][0] ), esc_html( $msgs[ $state ][1] ) );
		}
	}
}
