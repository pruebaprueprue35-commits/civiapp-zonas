<?php
/**
 * Al desinstalar NO se borran las zonas: los reclamos ya ubicados las siguen necesitando.
 * Solo se borran si se marcó explícitamente la opción "borrar_al_quitar".
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
$s = (array) get_option( 'rtz_settings', array() );
wp_clear_scheduled_hook( 'rtz_tick' );
wp_clear_scheduled_hook( 'rtz_check' );
delete_option( 'rtz_job' );
delete_option( 'rtz_lock' );
if ( empty( $s['borrar_al_quitar'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'rtz_versiones', 'rtz_municipios', 'rtz_zonas', 'rtz_zona_geom', 'rtz_nombres' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" ); // phpcs:ignore
}
delete_option( 'rtz_settings' );
delete_option( 'rtz_db_version' );
