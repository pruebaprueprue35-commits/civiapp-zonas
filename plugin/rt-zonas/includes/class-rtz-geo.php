<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Geometría mínima: punto en polígono (con huecos) y sector de 200 m. Sin servicios externos. */
final class RTZ_Geo {
	const SECTOR_M = 200;

	/** Polígonos de un GeoJSON Polygon/MultiPolygon como lista de listas de anillos. */
	public static function polygons( $geom ) {
		if ( is_string( $geom ) ) {
			$geom = json_decode( $geom, true );
		}
		if ( ! is_array( $geom ) || empty( $geom['type'] ) || empty( $geom['coordinates'] ) ) {
			return array();
		}
		if ( 'Polygon' === $geom['type'] ) {
			return array( $geom['coordinates'] );
		}
		if ( 'MultiPolygon' === $geom['type'] ) {
			return $geom['coordinates'];
		}
		return array();
	}

	/** Regla par-impar sobre todos los anillos: correcta con huecos sin importar orientación. */
	public static function point_in_rings( $x, $y, $rings ) {
		$inside = false;
		foreach ( $rings as $ring ) {
			$n = count( $ring );
			for ( $i = 0, $j = $n - 1; $i < $n; $j = $i++ ) {
				$xi = (float) $ring[ $i ][0];
				$yi = (float) $ring[ $i ][1];
				$xj = (float) $ring[ $j ][0];
				$yj = (float) $ring[ $j ][1];
				if ( ( $yi > $y ) !== ( $yj > $y ) ) {
					$xint = ( $xj - $xi ) * ( $y - $yi ) / ( $yj - $yi ) + $xi;
					if ( $x < $xint ) {
						$inside = ! $inside;
					}
				}
			}
		}
		return $inside;
	}

	public static function contains( $geom, $lng, $lat ) {
		foreach ( self::polygons( $geom ) as $rings ) {
			if ( self::point_in_rings( (float) $lng, (float) $lat, $rings ) ) {
				return true;
			}
		}
		return false;
	}

	public static function valid_point( $lat, $lng ) {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return false;
		}
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( 0.0 === $lat && 0.0 === $lng ) {
			return false; // el 0,0 del GPS no es un lugar de Argentina
		}
		// Argentina continental, Tierra del Fuego y Malvinas. Fuera de esta caja es un error de GPS
		// o latitud/longitud invertidas (en el país siempre lat > -56 y lng < -53).
		return $lat >= -56 && $lat <= -21 && $lng >= -74 && $lng <= -53;
	}

	/**
	 * Sector de unos 200 m dentro del municipio: cuadrícula fija con origen en la esquina sudoeste
	 * del municipio, en metros (equirectangular local). Mismo punto => mismo sector siempre.
	 */
	public static function sector( $lat, $lng, $min_lat, $min_lng ) {
		$lat0 = (float) $min_lat;
		$kx   = 111320.0 * cos( deg2rad( $lat0 ) );
		$ky   = 110540.0;
		$x    = ( (float) $lng - (float) $min_lng ) * $kx;
		$y    = ( (float) $lat - $lat0 ) * $ky;
		$col  = (int) floor( $x / self::SECTOR_M );
		$fila = (int) floor( $y / self::SECTOR_M );
		return array(
			'id'   => sprintf( 'C%03dF%03d', max( 0, $col ), max( 0, $fila ) ),
			'col'  => $col,
			'fila' => $fila,
			'm'    => self::SECTOR_M,
		);
	}
}
