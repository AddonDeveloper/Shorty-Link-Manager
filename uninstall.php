<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function wpsl_delete_options() {
	delete_option( 'wpsl_settings' );
	delete_option( 'wpsl_link_map' );
	delete_option( 'wpsl_discovered_links' );
}

if ( is_multisite() ) {
	$wpsl_site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $wpsl_site_ids as $wpsl_site_id ) {
		switch_to_blog( $wpsl_site_id );
		wpsl_delete_options();
		restore_current_blog();
	}
} else {
	wpsl_delete_options();
}
