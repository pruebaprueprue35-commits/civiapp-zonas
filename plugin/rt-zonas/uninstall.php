<?php
/**
 * Al desinstalar NO se borra nada: los reclamos ubicados citan zonas y versiones (constancia histórica).
 * Solo se quitan las tareas programadas y el estado temporal de la importación en curso.
 * Si alguna vez hubiera que borrar las tablas, se hace a mano y con respaldo previo, nunca desde el plugin.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
wp_clear_scheduled_hook( 'rtz_tick' );
wp_clear_scheduled_hook( 'rtz_check' );
delete_option( 'rtz_job' );  // estado de una importación en curso (se vuelve a crear al activar)
delete_option( 'rtz_lock' ); // candado temporal
