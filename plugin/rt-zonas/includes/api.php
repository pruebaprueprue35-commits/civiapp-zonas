<?php
/**
 * Funciones públicas para otros plugins (RT Reportes, Ads, Motor 100).
 * Contrato estable: devuelven arrays simples; nunca exponen SQL ni tablas.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ubica un punto en su zona activa.
 *
 * @param float $lat
 * @param float $lng
 * @param int   $localidad_id Municipio esperado (id de Ubicaciones RT). 0 = cualquiera.
 * @return array {estado: ok|sin_zona|ambiguo|fuera_de_municipio|punto_invalido, zona?, sector?, candidatos?}
 *               estado "fuera_de_municipio": el punto cae en una zona de otro municipio (se informa cuál).
 */
function rtz_ubicar( $lat, $lng, $localidad_id = 0 ) {
	global $wpdb;
	if ( ! RTZ_Geo::valid_point( $lat, $lng ) ) {
		return array( 'estado' => 'punto_invalido' );
	}
	$lat = (float) $lat;
	$lng = (float) $lng;
	$t   = RTZ_Schema::tables();
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT z.*, g.geojson FROM {$t['zonas']} z JOIN {$t['geom']} g ON g.zona_id=z.id
		 WHERE z.activo=1 AND z.min_lat<=%f AND z.max_lat>=%f AND z.min_lng<=%f AND z.max_lng>=%f
		 ORDER BY z.id LIMIT 200",
		$lat, $lat, $lng, $lng
	), ARRAY_A );
	$hits = array();
	foreach ( (array) $rows as $r ) {
		if ( RTZ_Geo::contains( $r['geojson'], $lng, $lat ) ) {
			unset( $r['geojson'] );
			$hits[] = $r;
		}
	}
	if ( ! $hits ) {
		return array( 'estado' => 'sin_zona' );
	}
	$propios = $localidad_id ? array_values( array_filter( $hits, function ( $h ) use ( $localidad_id ) {
		return (int) $h['localidad_id'] === (int) $localidad_id;
	} ) ) : $hits;
	if ( $localidad_id && ! $propios ) {
		return array( 'estado' => 'fuera_de_municipio', 'zona' => rtz_zona_publica( $hits[0] ) );
	}
	if ( count( $propios ) > 1 ) {
		return array( 'estado' => 'ambiguo', 'candidatos' => array_map( 'rtz_zona_publica', $propios ) );
	}
	$z      = $propios[0];
	$muni   = rtz_municipio( $z['municipio_georef'] );
	$sector = $muni ? RTZ_Geo::sector( $lat, $lng, $muni['min_lat'], $muni['min_lng'] ) : null;
	return array( 'estado' => 'ok', 'zona' => rtz_zona_publica( $z ), 'sector' => $sector );
}

/**
 * Zonas activas de un municipio.
 *
 * @param int|string $valor Id de Ubicaciones RT (por defecto) o código GeoRef si $por = 'georef'.
 * @param string     $por   'localidad' | 'georef'.
 */
function rtz_zonas_de_municipio( $valor, $por = 'localidad' ) {
	global $wpdb;
	$t = RTZ_Schema::tables();
	if ( 'georef' === $por ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['zonas']} WHERE activo=1 AND municipio_georef=%s ORDER BY codigo", (string) $valor ), ARRAY_A );
	} else {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['zonas']} WHERE activo=1 AND localidad_id=%d ORDER BY codigo", (int) $valor ), ARRAY_A );
	}
	return array_map( 'rtz_zona_publica', (array) $rows );
}

/** Una zona por id interno (aunque ya no esté activa: los reclamos viejos la siguen mostrando). */
function rtz_zona( $id ) {
	global $wpdb;
	$t = RTZ_Schema::tables();
	$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['zonas']} WHERE id=%d", (int) $id ), ARRAY_A );
	return $r ? rtz_zona_publica( $r ) : null;
}

/** Geometría GeoJSON de una zona. */
function rtz_zona_geometria( $id ) {
	global $wpdb;
	$t = RTZ_Schema::tables();
	$g = $wpdb->get_var( $wpdb->prepare( "SELECT geojson FROM {$t['geom']} WHERE zona_id=%d", (int) $id ) );
	return $g ? json_decode( $g, true ) : null;
}

function rtz_municipio( $georef ) {
	global $wpdb;
	$t = RTZ_Schema::tables();
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['municipios']} WHERE activo=1 AND municipio_georef=%s", (string) $georef ), ARRAY_A );
}

/** Forma pública y estable de una zona. */
function rtz_zona_publica( $r ) {
	return array(
		'id'               => (int) $r['id'],
		'uid'              => (string) $r['uid'],
		'codigo'           => (string) $r['codigo'],
		'nombre'           => (string) $r['nombre'],
		'lugar'            => (string) $r['lugar'],
		'municipio_georef' => (string) $r['municipio_georef'],
		'localidad_id'     => (int) $r['localidad_id'],
		'provincia_georef' => (string) $r['provincia_georef'],
		'poblacion'        => null === $r['poblacion'] ? null : (int) $r['poblacion'],
		'area_km2'         => (float) $r['area_km2'],
		'centro'           => array( 'lat' => (float) $r['centro_lat'], 'lng' => (float) $r['centro_lng'] ),
		'activa'           => (bool) (int) $r['activo'],
		'version_id'       => (int) $r['version_id'],
		'marcas'           => '' === (string) $r['flags'] ? array() : explode( ',', (string) $r['flags'] ),
	);
}
