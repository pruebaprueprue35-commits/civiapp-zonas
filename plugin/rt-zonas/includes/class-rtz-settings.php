<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RTZ_Settings {
	const OPTION = 'rtz_settings';

	public static function defaults() {
		return array(
			'manifest_url'      => '',
			'auto_actualizar'   => 1,
			'provincias'        => array(), // vacío = todas
			'partes_por_tanda'  => 3,
			'borrar_al_quitar'  => 0,
		);
	}

	public static function get( $key = null ) {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $s;
		}
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	public static function save( array $in ) {
		$s = self::get();
		if ( isset( $in['manifest_url'] ) ) {
			$url = trim( (string) $in['manifest_url'] );
			$s['manifest_url'] = ( '' === $url ) ? '' : esc_url_raw( $url, array( 'https' ) );
		}
		if ( isset( $in['auto_actualizar'] ) ) {
			$s['auto_actualizar'] = $in['auto_actualizar'] ? 1 : 0;
		}
		if ( isset( $in['provincias'] ) ) {
			$p = is_array( $in['provincias'] ) ? $in['provincias'] : explode( ',', (string) $in['provincias'] );
			$p = array_values( array_unique( array_filter( array_map( function ( $v ) {
				$v = preg_replace( '/\D/', '', (string) $v );
				return '' === $v ? '' : str_pad( substr( $v, 0, 2 ), 2, '0', STR_PAD_LEFT );
			}, $p ) ) ) );
			sort( $p );
			$s['provincias'] = $p;
		}
		if ( isset( $in['partes_por_tanda'] ) ) {
			$s['partes_por_tanda'] = max( 1, min( 20, absint( $in['partes_por_tanda'] ) ) );
		}
		if ( isset( $in['borrar_al_quitar'] ) ) {
			$s['borrar_al_quitar'] = $in['borrar_al_quitar'] ? 1 : 0;
		}
		update_option( self::OPTION, $s, false );
		return $s;
	}

	/** URL del manifiesto: constante de wp-config, luego ajuste del escritorio, luego la incluida en el plugin. */
	public static function manifest_url() {
		if ( defined( 'RTZ_MANIFEST_URL' ) && RTZ_MANIFEST_URL ) {
			return (string) RTZ_MANIFEST_URL;
		}
		$u = (string) self::get( 'manifest_url' );
		return $u ? $u : (string) RTZ_DEFAULT_MANIFEST_URL;
	}

	/** Paquete incluido dentro del plugin (carpeta datos/), si existe. */
	public static function bundled_dir() {
		$dir = RTZ_DIR . 'datos/';
		return file_exists( $dir . 'manifest.json' ) ? $dir : '';
	}
}
