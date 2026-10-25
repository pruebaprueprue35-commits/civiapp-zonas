<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vínculo con "Ubicaciones RT" (RT Platform Core), dueño del catálogo de municipios.
 * Solo lee: no escribe tablas de Core. Usa el filtro `rtz_core_localidad_por_georef` si Core lo provee;
 * si no, una lectura directa del código oficial (georef_id) o, en último caso, del nombre en la provincia.
 */
final class RTZ_Core_Link {
	private static $cache = array();

	public static function core_available() {
		global $wpdb;
		static $ok = null;
		if ( null === $ok ) {
			$table = $wpdb->prefix . 'rt_localidades';
			$ok    = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		}
		return $ok;
	}

	public static function localidad_for( $municipio_georef, $provincia_georef = '', $nombre = '' ) {
		$key = $municipio_georef . '|' . $provincia_georef . '|' . $nombre;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$id = (int) apply_filters( 'rtz_core_localidad_por_georef', 0, $municipio_georef, $provincia_georef, $nombre );
		if ( ! $id && self::core_available() ) {
			global $wpdb;
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}rt_localidades WHERE georef_id=%s LIMIT 1", $municipio_georef ) );
			if ( ! $id && $nombre && $provincia_georef ) {
				$pid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}rt_provincias WHERE georef_id=%s LIMIT 1", $provincia_georef ) );
				if ( $pid ) {
					$cands = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}rt_localidades WHERE provincia_id=%d AND LOWER(nombre)=LOWER(%s)", $pid, $nombre ) );
					if ( 1 === count( $cands ) ) {
						$id = (int) $cands[0]; // solo si el nombre no es ambiguo
					}
				}
			}
		}
		self::$cache[ $key ] = $id;
		return $id;
	}

	/** Vincula municipios/zonas activas que quedaron sin localidad (por ejemplo, si Core se importó después). */
	public static function relink_pending() {
		global $wpdb;
		$t     = RTZ_Schema::tables();
		$rows  = $wpdb->get_results( "SELECT municipio_georef, provincia_georef, nombre FROM {$t['municipios']} WHERE activo=1 AND localidad_id=0 LIMIT 2000", ARRAY_A );
		$fixed = 0;
		foreach ( (array) $rows as $r ) {
			self::$cache = array();
			$id          = self::localidad_for( $r['municipio_georef'], $r['provincia_georef'], $r['nombre'] );
			if ( $id ) {
				$wpdb->update( $t['municipios'], array( 'localidad_id' => $id ), array( 'municipio_georef' => $r['municipio_georef'], 'activo' => 1 ) );
				$wpdb->update( $t['zonas'], array( 'localidad_id' => $id ), array( 'municipio_georef' => $r['municipio_georef'], 'activo' => 1 ) );
				$fixed++;
			}
		}
		return $fixed;
	}
}
