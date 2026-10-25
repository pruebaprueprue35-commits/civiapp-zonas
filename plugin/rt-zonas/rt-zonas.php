<?php
/**
 * Plugin Name: CiviApp Zonas
 * Description: Zonas de todos los municipios de Argentina, generadas desde radios censales 2022. Se descargan y cargan solas, se actualizan por versión y ubican cada punto (reclamo) en su zona y sector.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: CiviApp
 * License: GPL-2.0-or-later
 * Text Domain: rt-zonas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RTZ_VERSION', '1.0.1' );
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

if ( is_admin() ) {
	require_once RTZ_DIR . 'includes/class-rtz-admin.php';
	RTZ_Admin::init();
}

RTZ_Importer::init();
RTZ_Rest::init();

register_activation_hook( __FILE__, array( 'RTZ_Importer', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'RTZ_Importer', 'on_deactivate' ) );

add_action( 'plugins_loaded', array( 'RTZ_Schema', 'maybe_upgrade' ) );
