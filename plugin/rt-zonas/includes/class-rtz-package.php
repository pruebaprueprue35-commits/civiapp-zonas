<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lectura y verificación del paquete `civiapp-zonas/1`: manifiesto + partes NDJSON comprimidas.
 * Fuentes posibles: URL (publicación en GitHub), carpeta incluida en el plugin o ZIP subido.
 */
final class RTZ_Package {
	const MAX_MANIFEST_BYTES = 2097152;    // 2 MB
	const MAX_PART_BYTES     = 67108864;   // 64 MB comprimido
	const MAX_LINE_BYTES     = 16777216;   // 16 MB por zona
	const PART_NAME          = '/^prov-[0-9]{2}-[0-9]{3}\.ndjson\.gz$/';

	/** Valida estructura y nombres. Devuelve el manifiesto normalizado o WP_Error. */
	public static function validate_manifest( $raw ) {
		if ( strlen( (string) $raw ) > self::MAX_MANIFEST_BYTES ) {
			return new WP_Error( 'rtz_manifest_size', 'El manifiesto es demasiado grande.' );
		}
		$m = json_decode( (string) $raw, true );
		if ( ! is_array( $m ) ) {
			return new WP_Error( 'rtz_manifest_json', 'El manifiesto no es JSON válido.' );
		}
		if ( ( $m['schema'] ?? '' ) !== RTZ_SCHEMA ) {
			return new WP_Error( 'rtz_manifest_schema', 'Versión de paquete no compatible: ' . sanitize_text_field( (string) ( $m['schema'] ?? '' ) ) );
		}
		$version = (string) ( $m['version'] ?? '' );
		if ( ! preg_match( '/^[0-9A-Za-z._-]{1,40}$/', $version ) ) {
			return new WP_Error( 'rtz_manifest_version', 'Versión inválida en el manifiesto.' );
		}
		if ( empty( $m['provincias'] ) || ! is_array( $m['provincias'] ) ) {
			return new WP_Error( 'rtz_manifest_vacio', 'El manifiesto no trae provincias.' );
		}
		foreach ( $m['provincias'] as $p ) {
			if ( ! preg_match( '/^[0-9]{2}$/', (string) ( $p['georef_id'] ?? '' ) ) || empty( $p['partes'] ) || ! is_array( $p['partes'] ) ) {
				return new WP_Error( 'rtz_manifest_provincia', 'Provincia mal formada en el manifiesto.' );
			}
			foreach ( $p['partes'] as $part ) {
				if ( ! preg_match( self::PART_NAME, (string) ( $part['archivo'] ?? '' ) )
					|| ! preg_match( '/^[0-9a-f]{64}$/', (string) ( $part['sha256'] ?? '' ) )
					|| (int) ( $part['bytes'] ?? 0 ) <= 0 || (int) $part['bytes'] > self::MAX_PART_BYTES ) {
					return new WP_Error( 'rtz_manifest_parte', 'Parte mal formada en el manifiesto.' );
				}
			}
		}
		if ( ! empty( $m['base_url'] ) && ! self::url_ok( (string) $m['base_url'] ) ) {
			return new WP_Error( 'rtz_manifest_base', 'La dirección base de las partes debe ser https.' );
		}
		$m['_sha256'] = hash( 'sha256', (string) $raw );
		return $m;
	}

	/** Solo https (http únicamente si un entorno de pruebas lo habilita con el filtro rtz_permitir_http). */
	public static function url_ok( $url ) {
		$url = (string) $url;
		return 0 === strpos( $url, 'https://' ) || ( apply_filters( 'rtz_permitir_http', false ) && 0 === strpos( $url, 'http://' ) );
	}

	/** Descarga el manifiesto remoto. */
	public static function fetch_remote_manifest( $url ) {
		if ( ! self::url_ok( $url ) ) {
			return new WP_Error( 'rtz_url', 'La dirección del manifiesto debe empezar con https://' );
		}
		$res = wp_safe_remote_get( $url, array(
			'timeout'             => 30,
			'redirection'         => 5,
			'limit_response_size' => self::MAX_MANIFEST_BYTES + 1,
			'headers'             => array( 'Accept' => 'application/json' ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return new WP_Error( 'rtz_http', 'El servidor de zonas respondió ' . $code . '.' );
		}
		return self::validate_manifest( wp_remote_retrieve_body( $res ) );
	}

	public static function read_local_manifest( $dir ) {
		$path = trailingslashit( $dir ) . 'manifest.json';
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'rtz_local', 'No se encontró el manifiesto del paquete.' );
		}
		return self::validate_manifest( (string) file_get_contents( $path ) );
	}

	/** Dirección de una parte remota: base_url del manifiesto o la carpeta del manifiesto. */
	public static function part_url( $manifest_url, $manifest, $file ) {
		if ( ! empty( $manifest['base_url'] ) ) {
			return trailingslashit( $manifest['base_url'] ) . $file;
		}
		return preg_replace( '#[^/]+$#', '', $manifest_url ) . $file;
	}

	/**
	 * Deja la parte en un archivo local verificado y devuelve su ruta.
	 * $source: array('tipo' => 'url'|'dir', 'valor' => ...).
	 */
	public static function obtain_part( $source, $manifest, $part ) {
		$file = (string) $part['archivo'];
		if ( ! preg_match( self::PART_NAME, $file ) ) {
			return new WP_Error( 'rtz_nombre', 'Nombre de parte inválido.' );
		}
		if ( 'dir' === $source['tipo'] ) {
			$path = trailingslashit( $source['valor'] ) . $file;
			if ( ! is_readable( $path ) ) {
				return new WP_Error( 'rtz_falta', 'Falta la parte ' . $file . ' en el paquete.' );
			}
			$tmp = false;
		} else {
			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$path = wp_tempnam( $file );
			$url  = self::part_url( $source['valor'], $manifest, $file );
			$res  = wp_safe_remote_get( $url, array(
				'timeout'             => 120,
				'redirection'         => 5,
				'stream'              => true,
				'filename'            => $path,
				'limit_response_size' => self::MAX_PART_BYTES,
			) );
			if ( is_wp_error( $res ) ) {
				@unlink( $path ); // phpcs:ignore
				return $res;
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				@unlink( $path ); // phpcs:ignore
				return new WP_Error( 'rtz_http', 'No se pudo descargar ' . $file . ' (HTTP ' . (int) wp_remote_retrieve_response_code( $res ) . ').' );
			}
			$tmp = true;
		}
		clearstatcache( true, $path );
		if ( (int) filesize( $path ) !== (int) $part['bytes'] || ! hash_equals( (string) $part['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
			if ( $tmp ) {
				@unlink( $path ); // phpcs:ignore
			}
			return new WP_Error( 'rtz_hash', 'La parte ' . $file . ' no coincide con el manifiesto (archivo dañado o alterado).' );
		}
		return array( 'path' => $path, 'temporal' => $tmp );
	}

	/** Itera los registros de una parte comprimida, línea por línea. */
	public static function each_record( $path, callable $cb ) {
		$fh = gzopen( $path, 'rb' );
		if ( ! $fh ) {
			return new WP_Error( 'rtz_gz', 'No se pudo abrir la parte comprimida.' );
		}
		$n = 0;
		while ( ! gzeof( $fh ) ) {
			$line = gzgets( $fh, self::MAX_LINE_BYTES );
			if ( false === $line ) {
				break;
			}
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$rec = json_decode( $line, true );
			if ( ! is_array( $rec ) || empty( $rec['tipo'] ) ) {
				gzclose( $fh );
				return new WP_Error( 'rtz_linea', 'Línea ilegible en la parte (registro ' . ( $n + 1 ) . ').' );
			}
			$n++;
			$r = $cb( $rec );
			if ( is_wp_error( $r ) ) {
				gzclose( $fh );
				return $r;
			}
		}
		gzclose( $fh );
		return $n;
	}

	/**
	 * Extrae un ZIP subido a uploads/rtz-paquetes/<version>/ copiando solo los archivos que nombra
	 * su manifiesto (nunca rutas arbitrarias del ZIP). Devuelve la carpeta.
	 */
	public static function extract_zip( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'rtz_zip', 'El servidor no tiene soporte para ZIP.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'rtz_zip', 'No se pudo abrir el ZIP.' );
		}
		$raw = $zip->getFromName( 'manifest.json' );
		if ( false === $raw ) {
			$zip->close();
			return new WP_Error( 'rtz_zip', 'El ZIP no contiene manifest.json en la raíz.' );
		}
		$m = self::validate_manifest( $raw );
		if ( is_wp_error( $m ) ) {
			$zip->close();
			return $m;
		}
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'rtz-paquetes/' . $m['version'] . '/';
		if ( ! wp_mkdir_p( $dir ) ) {
			$zip->close();
			return new WP_Error( 'rtz_dir', 'No se pudo crear la carpeta del paquete.' );
		}
		self::protect_dir( trailingslashit( $up['basedir'] ) . 'rtz-paquetes/' );
		file_put_contents( $dir . 'manifest.json', $raw );
		foreach ( $m['provincias'] as $p ) {
			foreach ( $p['partes'] as $part ) {
				$stream = $zip->getStream( $part['archivo'] );
				if ( ! $stream ) {
					$zip->close();
					return new WP_Error( 'rtz_zip', 'Falta ' . $part['archivo'] . ' dentro del ZIP.' );
				}
				$out = fopen( $dir . $part['archivo'], 'wb' );
				stream_copy_to_stream( $stream, $out, self::MAX_PART_BYTES + 1 );
				fclose( $out );
				fclose( $stream );
			}
		}
		$zip->close();
		return $dir;
	}

	private static function protect_dir( $dir ) {
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silencio.\n" );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" );
		}
	}
}
