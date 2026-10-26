<?php
/**
 * Pruebas de la auditoría externa de CiviApp Zonas (H08, H09, H15, H26) en un WordPress real.
 * Uso: php tests/test-auditoria-zonas.php /ruta/a/wordpress /ruta/paquete_v1 /ruta/paquete_v2   (sitio de prueba limpio)
 */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8099'; $_SERVER['REQUEST_URI'] = '/';
require rtrim( $argv[1] ?? '', '/' ) . '/wp-load.php';
$v1 = rtrim( $argv[2] ?? '', '/' ); $v2 = rtrim( $argv[3] ?? '', '/' );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );
$GLOBALS['ok'] = 0; $GLOBALS['fail'] = array();
function t( $c, $l ) { if ( $c ) { $GLOBALS['ok']++; echo "  ✓ $l\n"; } else { $GLOBALS['fail'][] = $l; echo "  ✗ $l\n"; } }
function hasta_terminar() { for ( $i = 0; $i < 60; $i++ ) { delete_option( 'rtz_lock' ); $j = RTZ_Importer::tick( 30 ); if ( ! $j || ! in_array( $j['estado'], array( 'pendiente', 'importando' ), true ) ) { return $j; } } return RTZ_Importer::job(); }
global $wpdb; $p = $wpdb->prefix;
if ( ! is_plugin_active( 'rt-zonas/rt-zonas.php' ) ) { activate_plugin( 'rt-zonas/rt-zonas.php' ); }
RTZ_Schema::install(); $t = RTZ_Schema::tables();
$wpdb->query( "UPDATE {$p}rt_provincias SET georef_id='90' WHERE nombre='Tucumán'" );
$tuc = (int) $wpdb->get_var( "SELECT id FROM {$p}rt_provincias WHERE georef_id='90'" );
$lid = RTTerritorios_Catalogo::add_localidad( $tuc, 0, 'Ciudad Grande' ); $wpdb->update( "{$p}rt_localidades", array( 'georef_id' => '900001' ), array( 'id' => $lid ) );

echo "H08 · Activación de zonas: se confirma entera o no se confirma\n";
RTZ_Importer::start( array( 'tipo' => 'dir', 'valor' => $v1 ), RTZ_Package::read_local_manifest( $v1 ), 'prueba' ); $j = hasta_terminar();
t( 'terminado' === $j['estado'], 'versión 1 cargada' );
$activas_v1 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['zonas']} WHERE activo=1" );
$v1id = (int) $wpdb->get_var( "SELECT version_id FROM {$t['zonas']} WHERE activo=1 LIMIT 1" );
// Falla inyectada: la sentencia que activa los municipios nuevos es rechazada por la base.
$romper = function( $q ) { return ( false !== strpos( $q, "UPDATE {$GLOBALS['wpdb']->prefix}rtz_municipios SET activo=1" ) ) ? 'SELECT * FROM tabla_que_no_existe_rtz' : $q; };
add_filter( 'query', $romper );
RTZ_Importer::start( array( 'tipo' => 'dir', 'valor' => $v2 ), RTZ_Package::read_local_manifest( $v2 ), 'prueba' ); $j = hasta_terminar();
remove_filter( 'query', $romper );
t( 'con_errores' === $j['estado'] && false !== strpos( wp_json_encode( $j['errores'], JSON_UNESCAPED_UNICODE ), 'versión anterior sigue activa' ), 'una sentencia fallida del medio hace fallar la parte (no se ignora)' );
t( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['zonas']} WHERE activo=1" ) === $activas_v1 && (int) $wpdb->get_var( "SELECT COUNT(DISTINCT version_id) FROM {$t['zonas']} WHERE activo=1" ) === 1 && (int) $wpdb->get_var( "SELECT version_id FROM {$t['zonas']} WHERE activo=1 LIMIT 1" ) === $v1id, 'la versión anterior sigue activa entera: nada a medias' );
t( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT municipio_georef FROM {$t['municipios']} WHERE activo=1 GROUP BY municipio_georef HAVING COUNT(*)<>1) x" ), 'ningún municipio queda con dos versiones activas' );
RTZ_Importer::retry_failed(); $j = hasta_terminar();
t( 'terminado' === $j['estado'] && (int) $wpdb->get_var( "SELECT COUNT(DISTINCT version_id) FROM {$t['zonas']} WHERE activo=1" ) === 1 && (int) $wpdb->get_var( "SELECT version_id FROM {$t['zonas']} WHERE activo=1 LIMIT 1" ) !== $v1id, 'sin la falla, Reintentar activa la versión nueva completa' );

echo "H09 · Desinstalar no borra el catálogo histórico\n";
$antes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['zonas']}" );
update_option( 'rtz_settings', array_merge( (array) get_option( 'rtz_settings', array() ), array( 'borrar_al_quitar' => 1 ) ) ); // opción heredada de 1.0
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'rt-zonas/rt-zonas.php' ); }
include WP_PLUGIN_DIR . '/rt-zonas/uninstall.php';
t( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t['zonas'] ) ) === $t['zonas'] && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['zonas']}" ) === $antes, 'con la opción vieja encendida, las tablas y las zonas siguen' );
t( false === strpos( file_get_contents( WP_PLUGIN_DIR . '/rt-zonas/uninstall.php' ), 'DROP' ), 'el desinstalador no tiene ninguna sentencia DROP' );
$s = RTZ_Settings::save( array( 'borrar_al_quitar' => 1 ) );
t( empty( $s['borrar_al_quitar'] ) || ! isset( RTZ_Settings::defaults()['borrar_al_quitar'] ), 'la opción de borrar ya no se ofrece ni se guarda' );

echo "H15 · La versión del paquete no puede salir de su carpeta\n";
$base = json_decode( file_get_contents( $v1 . '/manifest.json' ), true );
foreach ( array( '..', '.', '.oculta', 'a/b', '', '../x', 'a..b' ) as $mal ) { $m = $base; $m['version'] = $mal; $r = RTZ_Package::validate_manifest( wp_json_encode( $m ) ); t( is_wp_error( $r ), 'versión «' . $mal . '» rechazada' ); }
$m = $base; $m['version'] = 'zonas-2026.10.25-3'; t( ! is_wp_error( RTZ_Package::validate_manifest( wp_json_encode( $m ) ) ), 'versión normal aceptada' );

echo "H26 · Presupuestos de importación y geometría\n";
$zipf = sys_get_temp_dir() . '/rtz-grande-' . getmypid() . '.zip'; $z = new ZipArchive(); $z->open( $zipf, ZipArchive::CREATE | ZipArchive::OVERWRITE ); $z->addFromString( 'manifest.json', str_repeat( ' ', RTZ_Package::MAX_MANIFEST_BYTES + 10 ) ); $z->close();
$r = RTZ_Package::extract_zip( $zipf ); @unlink( $zipf );
t( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'demasiado grande' ), 'un manifiesto gigante dentro del ZIP se rechaza sin leerlo' );
$ok_poly = array( 'type' => 'Polygon', 'coordinates' => array( array( array( -65.2, -26.8 ), array( -65.1, -26.8 ), array( -65.1, -26.7 ), array( -65.2, -26.8 ) ) ) );
t( 4 === RTZ_Package::validar_geometria( $ok_poly ), 'polígono válido aceptado' );
$malos = array(
	'abierto' => array( 'type' => 'Polygon', 'coordinates' => array( array( array( -65.2, -26.8 ), array( -65.1, -26.8 ), array( -65.1, -26.7 ), array( -65.0, -26.6 ) ) ) ),
	'texto' => array( 'type' => 'Polygon', 'coordinates' => array( array( array( 'x', -26.8 ), array( -65.1, -26.8 ), array( -65.1, -26.7 ), array( 'x', -26.8 ) ) ) ),
	'fuera' => array( 'type' => 'Polygon', 'coordinates' => array( array( array( 10, 40 ), array( 11, 40 ), array( 11, 41 ), array( 10, 40 ) ) ) ),
	'corto' => array( 'type' => 'Polygon', 'coordinates' => array( array( array( -65.2, -26.8 ), array( -65.1, -26.8 ), array( -65.2, -26.8 ) ) ) ),
	'punto' => array( 'type' => 'Point', 'coordinates' => array( -65.2, -26.8 ) ),
);
foreach ( $malos as $k => $g ) { t( is_wp_error( RTZ_Package::validar_geometria( $g ) ), "geometría inválida ({$k}) rechazada" ); }
$m = $base; $m['version'] = 'muchas'; $m['provincias'][0]['partes'] = array_fill( 0, RTZ_Package::MAX_PARTES + 1, $base['provincias'][0]['partes'][0] );
t( is_wp_error( RTZ_Package::validate_manifest( wp_json_encode( $m ) ) ), 'un manifiesto con demasiadas partes se rechaza' );

$n = count( $GLOBALS['fail'] );
echo "\nResultado: {$GLOBALS['ok']} correctas, $n fallidas\n";
if ( $n ) { echo '  Fallidas: ' . implode( ' | ', $GLOBALS['fail'] ) . "\n"; }
exit( $n ? 1 : 0 );
