<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wti_settings' );
delete_option( 'wti_last_result' );

$timestamp = wp_next_scheduled( 'wti_scheduled_sync' );

if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'wti_scheduled_sync' );
}

