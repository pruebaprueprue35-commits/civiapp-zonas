<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lectura pública (datos territoriales abiertos, sin datos personales):
 *   GET /wp-json/civiapp-zonas/v1/ubicar?lat=..&lng=..[&localidad=ID]
 *   GET /wp-json/civiapp-zonas/v1/zonas?localidad=ID | ?municipio=GEOREF   (GeoJSON)
 * Con límite de pedidos por IP para evitar abuso.
 */
final class RTZ_Rest {
	const NS = 'civiapp-zonas/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( self::NS, '/ubicar', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'ubicar' ),
			'args'                => array(
				'lat'       => array( 'required' => true ),
				'lng'       => array( 'required' => true ),
				'localidad' => array( 'required' => false ),
			),
		) );
		register_rest_route( self::NS, '/zonas', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'zonas' ),
		) );
	}

	private static function limited() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0';
		$key = 'rtz_rl_' . md5( $ip . '|' . gmdate( 'YmdHi' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= (int) apply_filters( 'rtz_limite_por_minuto', 60 ) ) {
			return true;
		}
		set_transient( $key, $n + 1, 120 );
		return false;
	}

	public static function ubicar( WP_REST_Request $req ) {
		if ( self::limited() ) {
			return new WP_Error( 'rtz_limite', 'Demasiadas consultas. Probá en un minuto.', array( 'status' => 429 ) );
		}
		$res = rtz_ubicar( $req->get_param( 'lat' ), $req->get_param( 'lng' ), absint( $req->get_param( 'localidad' ) ) );
		return rest_ensure_response( $res );
	}

	public static function zonas( WP_REST_Request $req ) {
		if ( self::limited() ) {
			return new WP_Error( 'rtz_limite', 'Demasiadas consultas. Probá en un minuto.', array( 'status' => 429 ) );
		}
		$loc  = absint( $req->get_param( 'localidad' ) );
		$muni = preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'municipio' ) );
		if ( ! $loc && ! $muni ) {
			return new WP_Error( 'rtz_param', 'Indicá localidad o municipio.', array( 'status' => 400 ) );
		}
		$zonas    = $loc ? rtz_zonas_de_municipio( $loc ) : rtz_zonas_de_municipio( $muni, 'georef' );
		$features = array();
		foreach ( $zonas as $z ) {
			$features[] = array(
				'type'       => 'Feature',
				'properties' => $z,
				'geometry'   => rtz_zona_geometria( $z['id'] ),
			);
		}
		$resp = rest_ensure_response( array( 'type' => 'FeatureCollection', 'features' => $features ) );
		$resp->header( 'Cache-Control', 'public, max-age=3600' );
		return $resp;
	}
}
