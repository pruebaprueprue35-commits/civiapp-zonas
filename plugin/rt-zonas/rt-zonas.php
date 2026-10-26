<?php
/**
 * Plugin Name: CiviApp Zonas
 * Description: Zonas de todos los municipios de Argentina, generadas desde radios censales 2022. Se descargan y cargan solas, se actualizan por versión y ubican cada punto (reclamo) en su zona y sector.
 * Version: 1.1.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: CiviApp
 * License: GPL-2.0-or-later
 * Text Domain: rt-zonas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'rt_tabla_estado' ) ) {
	/** SHOW TABLE STATUS de una tabla (fila con Engine…), también si MySQL guardó el nombre en minúsculas. */
	function rt_tabla_estado( $name ) {
		global $wpdb;
		$name = (string) $name;
		$r = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $name ) ), ARRAY_A );
		if ( ! $r && strtolower( $name ) !== $name ) { $r = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( strtolower( $name ) ) ), ARRAY_A ); }
		return $r;
	}
}
if ( ! function_exists( 'rt_tabla_como' ) ) {
	/**
	 * Nombre de la tabla si existe, sin distinguir mayúsculas (o null). Con un prefijo con mayúsculas y MySQL configurado con
	 * lower_case_table_names=1 la tabla se guarda en minúsculas: compararla exacto la daba por ausente. Devuelve el nombre
	 * pedido tal cual, así las comparaciones existentes (=== $tabla) siguen funcionando.
	 */
	function rt_tabla_como( $name ) {
		global $wpdb;
		$name = (string) $name;
		$r = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
		if ( ( null === $r || '' === $r ) && strtolower( $name ) !== $name ) { $r = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( strtolower( $name ) ) ) ); }
		return ( is_string( $r ) && 0 === strcasecmp( $r, $name ) ) ? $name : $r;
	}
}

define( 'RTZ_VERSION', '1.1.2' );
define( 'RTZ_FILE', __FILE__ );
define( 'RTZ_DIR', plugin_dir_path( __FILE__ ) );
define( 'RTZ_URL', plugin_dir_url( __FILE__ ) );
define( 'RTZ_SCHEMA', 'civiapp-zonas/1' );

// URL del manifiesto publicado por GitHub (se puede fijar también en wp-config.php con RTZ_MANIFEST_URL).
if ( ! defined( 'RTZ_DEFAULT_MANIFEST_URL' ) ) {
	define( 'RTZ_DEFAULT_MANIFEST_URL', 'https://github.com/pruebaprueprue35-commits/civiapp-zonas/releases/latest/download/manifest.json' );
}

require_once RTZ_DIR . 'includes/class-rtz-schema.php';
require_once RTZ_DIR . 'includes/class-rtz-settings.php';
require_once RTZ_DIR . 'includes/class-rtz-geo.php';
require_once RTZ_DIR . 'includes/class-rtz-package.php';
require_once RTZ_DIR . 'includes/class-rtz-importer.php';
require_once RTZ_DIR . 'includes/class-rtz-core-link.php';
require_once RTZ_DIR . 'includes/api.php';
require_once RTZ_DIR . 'includes/class-rtz-rest.php';
require_once RTZ_DIR . 'includes/class-rtz-paquete.php';

if ( is_admin() ) {
	require_once RTZ_DIR . 'includes/class-rtz-admin.php';
	RTZ_Admin::init();
}

RTZ_Importer::init();
RTZ_Rest::init();
RTZ_Paquete::init();

register_activation_hook( __FILE__, array( 'RTZ_Importer', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'RTZ_Importer', 'on_deactivate' ) );

add_action( 'plugins_loaded', array( 'RTZ_Schema', 'maybe_upgrade' ) );
