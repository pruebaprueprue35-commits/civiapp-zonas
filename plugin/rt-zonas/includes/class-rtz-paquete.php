<?php
/**
 * Proveedor de zonas para el exportador único de CiviApp (RT Platform Core 14+): una fila por zona activa de los municipios
 * del paquete, con polígono, población, barrios que la integran y versión. Los reclamos guardan el id de su zona.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RTZ_Paquete {
	public static function init() {
		add_filter( 'rtpc_paquete_proveedores', array( __CLASS__, 'registrar' ) );
		add_filter( 'rtpc_paquete_atribucion', array( __CLASS__, 'atribucion' ) );
	}

	public static function registrar( $p ) {
		$p[] = array(
			'id' => 'civiapp-zonas', 'modulo' => 'territorio', 'orden' => 2, 'version' => RTZ_VERSION, 'etiqueta' => 'Zonas automáticas',
			'tipos' => array( 'zona' => array( __CLASS__, 'zonas' ) ),
		);
		return $p;
	}

	public static function atribucion( $a ) {
		$a['zonas'] = 'Zonas CiviApp elaboradas con radios censales INDEC Censo 2022 (cartografía Rodriguez y de Grande, CONICET, CC BY-SA), límites de GeoRef (datos.gob.ar) y datos © colaboradores de OpenStreetMap (ODbL).';
		return $a;
	}

	public static function zonas( $ctx, $cursor, $limite ) {
		global $wpdb;
		$t    = RTZ_Schema::tables();
		$mids = class_exists( 'RTPC_Paquete_Territorio' ) ? RTPC_Paquete_Territorio::municipios_del_paquete( $ctx ) : array();
		// Sin municipios definidos (paquete «todos» sin reclamos ni clientes) no se exporta el país entero: solo las zonas vinculadas a Core.
		$w = $wpdb->prepare( 'z.activo=1 AND z.id>%d', absint( $cursor ) ) . ( $mids ? ' AND z.localidad_id IN (' . implode( ',', array_map( 'absint', $mids ) ) . ')' : ' AND z.localidad_id>0' );
		$rows = (array) $wpdb->get_results( "SELECT z.*, g.geojson FROM {$t['zonas']} z JOIN {$t['geom']} g ON g.zona_id=z.id WHERE {$w} ORDER BY z.id LIMIT " . (int) min( 50, $limite ), ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'No se pudieron leer las zonas.' );
		}
		$out  = array();
		$last = absint( $cursor );
		foreach ( $rows as $r ) {
			$last = (int) $r['id'];
			$z    = rtz_zona_publica( $r );
			$b    = rtz_barrios_de_zona( $last );
			$ver  = $wpdb->get_var( $wpdb->prepare( "SELECT version FROM {$t['versiones']} WHERE id=%d", $r['version_id'] ) );
			$out[] = array( 'record_type' => 'zona', 'source_id' => $last, 'client_id' => 0, 'data' => array(
				'id' => $last, 'uid' => $z['uid'], 'codigo' => $z['codigo'], 'nombre' => $z['nombre'], 'lugar' => $z['lugar'],
				'municipio_id' => $z['localidad_id'], 'municipio_georef' => $z['municipio_georef'], 'provincia_georef' => $z['provincia_georef'],
				'poblacion' => $z['poblacion'], 'area_km2' => $z['area_km2'], 'centro' => $z['centro'], 'marcas' => $z['marcas'],
				'barrios_osm' => $b['osm'], 'barrios_rt' => $b['rt'], 'version' => (string) $ver, 'version_id' => $z['version_id'],
				'sector_m' => RTZ_Geo::SECTOR_M, 'geometria' => json_decode( (string) $r['geojson'], true ),
			) );
		}
		return array( 'rows' => $out, 'cursor' => (string) $last, 'done' => count( $rows ) < min( 50, $limite ) );
	}
}
