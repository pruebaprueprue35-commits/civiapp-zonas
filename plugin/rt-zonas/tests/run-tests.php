<?php
/**
 * Pruebas de integración en un WordPress real (probadas con SQLite; en producción, MySQL/MariaDB).
 *
 * Uso: php tests/run-tests.php /ruta/a/wordpress /ruta/paquete_v1 /ruta/paquete_v2
 *   paquete_v1 y paquete_v2: carpetas con manifest.json + partes, generadas por civiapp-zonas
 *   (v2 = misma fuente con un cambio, para probar el reemplazo de versiones).
 * No usar en un sitio en producción: crea y modifica datos de zonas.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$rtz_wp_path = rtrim( $argv[1] ?? '', '/' );
$v1  = rtrim( $argv[2] ?? '', '/' );
$v2  = rtrim( $argv[3] ?? '', '/' );
$_SERVER['HTTP_HOST']   = '127.0.0.1:8099';
$_SERVER['REQUEST_URI'] = '/';
require $rtz_wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );

$GLOBALS['rtz_ok']   = 0;
$GLOBALS['rtz_fail'] = array();
function check( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['rtz_ok']++;
		echo "  ✓ {$label}\n";
	} else {
		$GLOBALS['rtz_fail'][] = $label;
		echo "  ✗ {$label}\n";
	}
}
function run_until_done( $max = 50 ) {
	for ( $i = 0; $i < $max; $i++ ) {
		delete_option( 'rtz_lock' );
		$j = RTZ_Importer::tick( 30 );
		if ( ! $j || ! in_array( $j['estado'], array( 'pendiente', 'importando' ), true ) ) {
			return $j;
		}
	}
	return RTZ_Importer::job();
}
function count_active( $t, $where = '1=1' ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE activo=1 AND {$where}" );
}

global $wpdb;
$p = $wpdb->prefix;

echo "1. Preparar Ubicaciones RT (simula el importador GeoRef de Core)\n";
$wpdb->query( "UPDATE {$p}rt_provincias SET georef_id='90' WHERE nombre='Tucumán'" );
$wpdb->query( "UPDATE {$p}rt_provincias SET georef_id='06' WHERE nombre='Buenos Aires'" );
$tuc = (int) $wpdb->get_var( "SELECT id FROM {$p}rt_provincias WHERE georef_id='90'" );
$lid_ciudad = RTTerritorios_Catalogo::add_localidad( $tuc, 0, 'Ciudad Grande' );
$wpdb->update( "{$p}rt_localidades", array( 'georef_id' => '900001' ), array( 'id' => $lid_ciudad ) );
$lid_pueblo = RTTerritorios_Catalogo::add_localidad( $tuc, 0, 'Pueblo Chico' ); // sin código: vínculo por nombre
check( $lid_ciudad > 0 && $lid_pueblo > 0, 'municipios de prueba creados en Core' );

echo "2. Activación con paquete incluido: se carga solo\n";
deactivate_plugins( 'rt-zonas/rt-zonas.php', true );
$plugin_dir = WP_PLUGIN_DIR . '/rt-zonas/datos/';
foreach ( glob( $plugin_dir . '*' ) as $f ) {
	unlink( $f );
}
foreach ( glob( $v1 . '/*' ) as $f ) {
	if ( preg_match( '/(manifest\.json|\.ndjson\.gz)$/', $f ) ) {
		copy( $f, $plugin_dir . basename( $f ) );
	}
}
delete_option( 'rtz_job' );
$r = activate_plugin( 'rt-zonas/rt-zonas.php' );
check( ! is_wp_error( $r ), 'plugin activado' );
check( RTZ_Schema::ready(), 'tablas creadas' );
$job = RTZ_Importer::job();
check( $job && 'pendiente' === $job['estado'], 'trabajo de importación creado al activar' );
check( (bool) wp_next_scheduled( 'rtz_tick' ), 'tanda programada en WP-Cron' );
$job = run_until_done();
check( 'terminado' === $job['estado'], 'importación terminada: ' . $job['estado'] );
$man1 = json_decode( file_get_contents( $v1 . '/manifest.json' ), true );
$t    = RTZ_Schema::tables();
check( count_active( $t['zonas'] ) === (int) $man1['totales']['zonas'], 'zonas activas = zonas del paquete (' . $man1['totales']['zonas'] . ')' );
check( count_active( $t['municipios'] ) === (int) $man1['totales']['municipios'], 'municipios activos = municipios del paquete' );
check( 'activa' === $wpdb->get_var( "SELECT estado FROM {$t['versiones']} WHERE version='" . esc_sql( $man1['version'] ) . "'" ), 'versión marcada activa' );
check( ! wp_next_scheduled( 'rtz_tick' ), 'sin tandas pendientes al terminar' );

echo "3. Vínculo con Ubicaciones RT\n";
check( count_active( $t['zonas'], "localidad_id={$lid_ciudad}" ) > 1, 'Ciudad Grande vinculada por código oficial' );
check( count_active( $t['zonas'], "localidad_id={$lid_pueblo}" ) === 1, 'Pueblo Chico vinculado por nombre único' );
check( count_active( $t['municipios'], "municipio_georef='900003' AND localidad_id=0" ) === 1, 'Comuna sin Core queda sin vincular (no inventa)' );
$lid_comuna = RTTerritorios_Catalogo::add_localidad( $tuc, 0, 'Comuna Sin Radios' );
$wpdb->update( "{$p}rt_localidades", array( 'georef_id' => '900003' ), array( 'id' => $lid_comuna ) );
check( RTZ_Core_Link::relink_pending() >= 1, 'Vincular ahora: toma municipios agregados después a Core' );
check( count_active( $t['zonas'], "localidad_id={$lid_comuna}" ) === 1, 'Comuna vinculada tras importar Core' );

echo "4. Ubicar puntos\n";
$ox = -65.20; $oy = -26.80; $s = 0.0015;
$r  = rtz_ubicar( $oy + 5.5 * $s, $ox + 2.5 * $s );
check( 'ok' === $r['estado'] && '900001' === $r['zona']['municipio_georef'], 'punto dentro de Ciudad Grande → ok' );
check( isset( $r['sector']['id'] ) && preg_match( '/^C\d{3}F\d{3}$/', $r['sector']['id'] ), 'sector de 200 m calculado: ' . ( $r['sector']['id'] ?? '' ) );
$r2 = rtz_ubicar( $oy + 5.5 * $s + 0.0001, $ox + 2.5 * $s + 0.0001 );
check( $r2['sector']['id'] === $r['sector']['id'], 'puntos a 15 m caen en el mismo sector' );
$r3 = rtz_ubicar( $oy + 5.5 * $s, $ox + 2.5 * $s, $lid_ciudad );
check( 'ok' === $r3['estado'], 'con municipio esperado correcto → ok' );
$r4 = rtz_ubicar( $oy + 5.5 * $s, $ox + 2.5 * $s, $lid_pueblo );
check( 'fuera_de_municipio' === $r4['estado'], 'con municipio equivocado → fuera_de_municipio' );
check( 'sin_zona' === rtz_ubicar( -40.0, -70.0 )['estado'], 'punto sin zonas → sin_zona' );
check( 'punto_invalido' === rtz_ubicar( 0, 0 )['estado'], 'GPS 0,0 → punto_invalido' );
check( 'punto_invalido' === rtz_ubicar( -65.2, -26.8 )['estado'], 'latitud/longitud invertidas → punto_invalido' );
$hits = 0;
for ( $i = 0; $i < 12; $i++ ) {
	for ( $j = 0; $j < 12; $j++ ) {
		$u = rtz_ubicar( $oy + ( $j + 0.5 ) * $s, $ox + ( $i + 0.5 ) * $s );
		$hits += ( 'ok' === $u['estado'] ) ? 1 : 0;
	}
}
check( 144 === $hits, "los 144 centros de radios de Ciudad Grande caen en una sola zona ({$hits})" );
$zonas_ciudad = rtz_zonas_de_municipio( $lid_ciudad );
check( count( $zonas_ciudad ) > 1 && is_array( rtz_zona_geometria( $zonas_ciudad[0]['id'] ) ), 'zonas y geometría por municipio' );

echo "5. Renombrar zona y conservar el nombre en la próxima versión\n";
$z0 = $zonas_ciudad[0];
$wpdb->replace( $t['nombres'], array( 'uid' => $z0['uid'], 'nombre' => 'Barrio Las Palmeras', 'usuario' => 1, 'actualizado' => current_time( 'mysql', true ) ) );
$wpdb->update( $t['zonas'], array( 'nombre' => 'Barrio Las Palmeras' ), array( 'uid' => $z0['uid'], 'activo' => 1 ) );

echo "6. Nueva versión remota (GitHub simulado) reemplaza sin borrar\n";
add_filter( 'rtz_permitir_http', '__return_true' );
add_filter( 'http_request_host_is_external', '__return_true' );
$port = 8080; // WordPress solo permite puertos 80, 443 y 8080 en pedidos salientes
$srv  = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $v2 ), array( array( 'pipe', 'r' ), array( 'file', '/dev/null', 'w' ), array( 'file', '/dev/null', 'w' ) ), $pipes );
usleep( 600000 );
foreach ( glob( $plugin_dir . '*' ) as $f ) {
	unlink( $f ); // sin paquete incluido: se usa la URL
}
RTZ_Settings::save( array( 'manifest_url' => '' ) );
update_option( 'rtz_settings', array_merge( RTZ_Settings::get(), array( 'manifest_url' => 'http://127.0.0.1:' . $port . '/manifest.json' ) ) );
$job = RTZ_Importer::start_auto( 'prueba' );
check( is_array( $job ) && 'pendiente' === $job['estado'], 'versión remota detectada' . ( is_wp_error( $job ) ? ': ' . $job->get_error_message() : '' ) );
$job = run_until_done();
$man2 = json_decode( file_get_contents( $v2 . '/manifest.json' ), true );
check( 'terminado' === $job['estado'], 'versión remota importada: ' . $job['estado'] . ' ' . wp_json_encode( $job['errores'] ) );
check( count_active( $t['zonas'] ) === (int) $man2['totales']['zonas'], 'zonas activas = zonas de la versión nueva' );
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['zonas']}" );
check( $total === (int) $man1['totales']['zonas'] + (int) $man2['totales']['zonas'], 'las zonas viejas se conservan inactivas (nada se borró)' );
check( 'reemplazada' === $wpdb->get_var( "SELECT estado FROM {$t['versiones']} WHERE version='" . esc_sql( $man1['version'] ) . "'" ), 'versión anterior marcada reemplazada' );
check( 'Barrio Las Palmeras' === $wpdb->get_var( $wpdb->prepare( "SELECT nombre FROM {$t['zonas']} WHERE uid=%s AND activo=1", $z0['uid'] ) ), 'el nombre puesto a mano se conserva en la versión nueva' );
$old = rtz_zona( $z0['id'] );
check( $old && false === $old['activa'], 'una zona vieja sigue consultable por id (para reclamos ya ubicados)' );
check( null === RTZ_Importer::start_auto( 'prueba' ), 'sin cambios: no reimporta la misma versión' );

echo "7. Parte dañada: no activa nada a medias y permite reintentar\n";
$bad = sys_get_temp_dir() . '/rtz_bad_' . getmypid();
@mkdir( $bad );
foreach ( glob( $v2 . '/*' ) as $f ) {
	copy( $f, $bad . '/' . basename( $f ) );
}
$mb = json_decode( file_get_contents( $bad . '/manifest.json' ), true );
$mb['version'] = 'danada-1';
file_put_contents( $bad . '/manifest.json', wp_json_encode( $mb ) );
$first = $bad . '/' . $mb['provincias'][0]['partes'][0]['archivo'];
$data  = file_get_contents( $first );
$data[ strlen( $data ) - 6 ] = chr( ord( $data[ strlen( $data ) - 6 ] ) ^ 0xFF );
file_put_contents( $first, $data );
$before = count_active( $t['zonas'] );
$m   = RTZ_Package::read_local_manifest( $bad );
$job = RTZ_Importer::start( array( 'tipo' => 'dir', 'valor' => $bad ), $m, 'prueba' );
$job = run_until_done( 10 );
check( 'con_errores' === $job['estado'], 'la importación termina con errores, sin colgarse' );
check( false !== strpos( wp_json_encode( $job['errores'], JSON_UNESCAPED_UNICODE ), 'no coincide con el manifiesto' ), 'el error explica que el archivo está dañado' );
check( count_active( $t['zonas'] ) === $before, 'las zonas activas no cambiaron por la parte dañada' );
file_put_contents( $first, file_get_contents( $v2 . '/' . basename( $first ) ) );
RTZ_Importer::retry_failed();
$job = run_until_done();
check( 'terminado' === $job['estado'], 'al reparar el archivo, Reintentar completa la importación' );

echo "8. Candado: dos tandas a la vez no se pisan\n";
$filas_antes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['zonas']}" );
add_option( 'rtz_lock', time() + 300, '', 'no' );
$j = RTZ_Importer::start( array( 'tipo' => 'dir', 'valor' => $v1 ), RTZ_Package::read_local_manifest( $v1 ), 'prueba' );
$j = RTZ_Importer::tick( 5 );
check( 0 === count( $j['hechas'] ), 'con el candado tomado, la segunda tanda no hace nada' );
update_option( 'rtz_lock', time() - 10 );
$j = RTZ_Importer::tick( 30 );
check( count( $j['hechas'] ) > 0, 'un candado vencido se recupera solo' );
run_until_done();
check( $old && 'ok' === rtz_ubicar( $oy + 5.5 * $s, $ox + 2.5 * $s )['estado'], 'volver a una versión anterior funciona' );
$reactivada = rtz_zona( $z0['id'] );
check( $reactivada && true === $reactivada['activa'], 'al volver a la versión anterior se reactivan las mismas zonas (mismo id)' );
check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['zonas']}" ) === $filas_antes, 'reactivar no duplica filas' );
check( 'Barrio Las Palmeras' === $reactivada['nombre'], 'el nombre a mano también se aplica a la versión reactivada' );

echo "9. Subida manual de ZIP\n";
$zips = glob( $v1 . '/civiapp-zonas-*.zip' );
if ( $zips ) {
	$dir = RTZ_Package::extract_zip( $zips[0] );
	check( ! is_wp_error( $dir ) && file_exists( $dir . 'manifest.json' ), 'ZIP extraído solo con los archivos del manifiesto' );
	check( file_exists( dirname( $dir ) . '/.htaccess' ), 'carpeta de paquetes protegida' );
	$evil = sys_get_temp_dir() . '/rtz_evil_' . getmypid() . '.zip';
	$z    = new ZipArchive();
	$z->open( $evil, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$z->addFromString( 'manifest.json', wp_json_encode( array( 'schema' => RTZ_SCHEMA, 'version' => 'x', 'provincias' => array( array( 'georef_id' => '90', 'partes' => array( array( 'archivo' => '../../wp-config.php', 'sha256' => str_repeat( 'a', 64 ), 'bytes' => 10 ) ) ) ) ) ) );
	$z->close();
	check( is_wp_error( RTZ_Package::extract_zip( $evil ) ), 'ZIP con ruta maliciosa rechazado' );
} else {
	check( false, 'falta el ZIP de prueba' );
}

echo "10. Validación del manifiesto\n";
check( is_wp_error( RTZ_Package::validate_manifest( '{"schema":"otro/9"}' ) ), 'esquema desconocido rechazado' );
check( is_wp_error( RTZ_Package::validate_manifest( 'no es json' ) ), 'JSON inválido rechazado' );
remove_all_filters( 'rtz_permitir_http' );
check( is_wp_error( RTZ_Package::fetch_remote_manifest( 'http://example.com/manifest.json' ) ), 'URL sin https rechazada' );

proc_terminate( $srv );
echo "\nResultado: {$GLOBALS['rtz_ok']} pruebas correctas, " . count( $GLOBALS['rtz_fail'] ) . " fallidas\n";
foreach ( $GLOBALS['rtz_fail'] as $f ) {
	echo "  FALLÓ: {$f}\n";
}
exit( $GLOBALS['rtz_fail'] ? 1 : 0 );
