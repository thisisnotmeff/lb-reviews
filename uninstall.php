<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Data is removed only if "Delete data" is checked in the settings.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lbr_settings = get_option( 'lbr_settings', array() );

wp_clear_scheduled_hook( 'lbr_google_refresh' );
delete_transient( 'lbr_google_live' );
delete_transient( 'lbr_google_lock' );

if ( empty( $lbr_settings['delete_on_uninstall'] ) ) {
	return;
}

$lbr_ids = get_posts(
	array(
		'post_type'      => 'lbr_review',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $lbr_ids as $lbr_id ) {
	wp_delete_post( $lbr_id, true );
}

delete_option( 'lbr_settings' );
delete_option( 'lbr_google_status' );
delete_option( 'lbr_platform_stats' );
$lbr_ver = (int) get_option( 'lbr_cache_ver', 1 );
delete_transient( 'lbr_stored_' . $lbr_ver );
delete_option( 'lbr_cache_ver' );
