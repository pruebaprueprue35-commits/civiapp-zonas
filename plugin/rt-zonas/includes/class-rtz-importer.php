<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importación por tandas, reanudable y segura:
 * - Cada paquete es una versión nueva; las partes se cargan como inactivas.
 * - Cada parte trae municipios completos: al terminarla se activan esos municipios en una
 *   transacción (las zonas viejas del mismo municipio pasan a inactivas, no se borran).
 * - Avanza con WP-Cron cada minuto y también desde la pantalla (sin depender del tráfico del sitio).
 * - Un candado evita dos importaciones simultáneas; un reintento no duplica (clave versión+uid).
 */
final class RTZ_Importer {
	const JOB      = 'rtz_job';
	const LOCK     = 'rtz_lock';
	const TICK     = 'rtz_tick';
	const CHECK    = 'rtz_check';
	const LOCK_TTL = 300;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::TICK, array( __CLASS__, 'cron_tick' ) );
		add_action( self::CHECK, array( __CLASS__, 'check_updates' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedules' ) );
	}

	public static function schedules( $s ) {
		$s['rtz_minuto'] = array( 'interval' => 60, 'display' => 'Cada minuto (zonas)' );
		return $s;
	}

	public static function on_activate() {
		RTZ_Schema::install();
		if ( ! wp_next_scheduled( self::CHECK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::CHECK );
		}
		// Carga sola: paquete incluido en el plugin o, si no, la última publicación configurada.
		self::start_auto( 'activacion' );
	}

	public static function on_deactivate() {
		wp_clear_scheduled_hook( self::TICK );
		wp_clear_scheduled_hook( self::CHECK );
	}

	public static function ensure_schedules() {
		$job = self::job();
		if ( $job && in_array( $job['estado'], array( 'pendiente', 'importando' ), true ) && ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_event( time() + 30, 'rtz_minuto', self::TICK );
		}
		if ( ! wp_next_scheduled( self::CHECK ) ) {
			wp_schedule_event( time() + 600, 'daily', self::CHECK );
		}
	}

	public static function job() {
		$j = get_option( self::JOB );
		return is_array( $j ) ? $j : null;
	}

	private static function save_job( $job ) {
		$job['actualizado'] = gmdate( 'c' );
		update_option( self::JOB, $job, false );
	}

	/** Busca algo nuevo para importar (incluido en el plugin, o publicado). */
	public static function start_auto( $motivo = 'auto' ) {
		$job = self::job();
		if ( $job && in_array( $job['estado'], array( 'pendiente', 'importando' ), true ) ) {
			return $job;
		}
		$dir = RTZ_Settings::bundled_dir();
		if ( $dir ) {
			$m = RTZ_Package::read_local_manifest( $dir );
			if ( ! is_wp_error( $m ) && ! self::version_loaded( $m['version'] ) ) {
				return self::start( array( 'tipo' => 'dir', 'valor' => $dir ), $m, $motivo );
			}
		}
		$url = RTZ_Settings::manifest_url();
		if ( $url ) {
			$m = RTZ_Package::fetch_remote_manifest( $url );
			if ( is_wp_error( $m ) ) {
				update_option( 'rtz_ultimo_error', array( 'fecha' => gmdate( 'c' ), 'mensaje' => $m->get_error_message() ), false );
				return $m;
			}
			delete_option( 'rtz_ultimo_error' );
			if ( ! self::version_loaded( $m['version'] ) ) {
				return self::start( array( 'tipo' => 'url', 'valor' => $url ), $m, $motivo );
			}
		}
		return null;
	}

	public static function check_updates() {
		if ( RTZ_Settings::get( 'auto_actualizar' ) ) {
			self::start_auto( 'actualizacion_diaria' );
		}
		RTZ_Core_Link::relink_pending();
	}

	public static function version_loaded( $version ) {
		global $wpdb;
		$t = RTZ_Schema::tables();
		$estado = $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$t['versiones']} WHERE version=%s", $version ) );
		return in_array( $estado, array( 'activa', 'parcial' ), true );
	}

	/** Crea (o retoma) la versión y arma la cola de partes. */
	public static function start( $source, $manifest, $motivo = 'manual' ) {
		global $wpdb;
		if ( is_wp_error( $manifest ) || ! is_array( $manifest ) ) {
			return is_wp_error( $manifest ) ? $manifest : new WP_Error( 'rtz_manifest', 'Manifiesto inválido.' );
		}
		if ( ! RTZ_Schema::ready() ) {
			RTZ_Schema::install();
		}
		$t     = RTZ_Schema::tables();
		$provs = (array) RTZ_Settings::get( 'provincias' );
		$queue = array();
		foreach ( $manifest['provincias'] as $p ) {
			if ( $provs && ! in_array( $p['georef_id'], $provs, true ) ) {
				continue;
			}
			foreach ( $p['partes'] as $part ) {
				$queue[] = array(
					'provincia' => $p['georef_id'],
					'archivo'   => $part['archivo'],
					'sha256'    => $part['sha256'],
					'bytes'     => (int) $part['bytes'],
				);
			}
		}
		if ( ! $queue ) {
			return new WP_Error( 'rtz_vacio', 'No hay partes para las provincias elegidas.' );
		}
		$vid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['versiones']} WHERE version=%s", $manifest['version'] ) );
		if ( ! $vid ) {
			$ok = $wpdb->insert( $t['versiones'], array(
				'version'         => $manifest['version'],
				'esquema'         => RTZ_SCHEMA,
				'origen'          => substr( 'dir' === $source['tipo'] ? 'paquete local' : $source['valor'], 0, 255 ),
				'manifest_sha256' => $manifest['_sha256'],
				'generado'        => substr( (string) ( $manifest['generado_utc'] ?? '' ), 0, 40 ),
				'atribucion'      => (string) ( $manifest['atribucion'] ?? '' ),
				'totales'         => wp_json_encode( $manifest['totales'] ?? array() ),
				'estado'          => 'importando',
				'creada'          => current_time( 'mysql', true ),
			) );
			if ( ! $ok ) {
				return new WP_Error( 'rtz_db', 'No se pudo registrar la versión: ' . $wpdb->last_error );
			}
			$vid = (int) $wpdb->insert_id;
		} else {
			$wpdb->update( $t['versiones'], array( 'estado' => 'importando' ), array( 'id' => $vid ) );
		}
		$m = $manifest;
		unset( $m['provincias'] ); // la cola ya tiene lo necesario; el manifiesto liviano queda en el trabajo
		$job = array(
			'version'    => $manifest['version'],
			'version_id' => $vid,
			'fuente'     => $source,
			'manifest'   => array( 'base_url' => $m['base_url'] ?? '', 'version' => $m['version'] ),
			'cola'       => $queue,
			'hechas'     => array(),
			'errores'    => array(),
			'intentos'   => array(),
			'estado'     => 'pendiente',
			'motivo'     => $motivo,
			'iniciado'   => gmdate( 'c' ),
			'zonas'      => 0,
			'municipios' => 0,
		);
		self::save_job( $job );
		if ( ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_event( time() + 5, 'rtz_minuto', self::TICK );
		}
		return $job;
	}

	public static function cron_tick() {
		self::tick( 25 );
	}

	/** Procesa partes hasta agotar el tiempo o la tanda. Devuelve el trabajo actualizado. */
	public static function tick( $seconds = 20 ) {
		$job = self::job();
		if ( ! $job || ! in_array( $job['estado'], array( 'pendiente', 'importando' ), true ) ) {
			wp_clear_scheduled_hook( self::TICK );
			return $job;
		}
		if ( ! self::lock() ) {
			return $job;
		}
		$start = microtime( true );
		$max   = (int) RTZ_Settings::get( 'partes_por_tanda' );
		$done  = 0;
		try {
			$job['estado'] = 'importando';
			while ( $done < $max && ( microtime( true ) - $start ) < $seconds ) {
				$next = self::next_part( $job );
				if ( null === $next ) {
					break;
				}
				$res = self::import_part( $job, $job['cola'][ $next ] );
				$file = $job['cola'][ $next ]['archivo'];
				if ( is_wp_error( $res ) ) {
					$job['intentos'][ $file ] = (int) ( $job['intentos'][ $file ] ?? 0 ) + 1;
					$job['errores'][]         = array( 'archivo' => $file, 'mensaje' => $res->get_error_message(), 'fecha' => gmdate( 'c' ) );
					$job['errores']           = array_slice( $job['errores'], -20 );
					self::save_job( $job );
					break; // se reintenta en la próxima tanda
				}
				$job['hechas'][]    = $file;
				$job['zonas']      += $res['zonas'];
				$job['municipios'] += $res['municipios'];
				$done++;
				self::save_job( $job );
			}
			if ( null === self::next_part( $job ) ) {
				$job = self::finish( $job );
			}
			self::save_job( $job );
		} finally {
			self::unlock();
		}
		return $job;
	}

	private static function next_part( $job ) {
		foreach ( $job['cola'] as $i => $p ) {
			if ( in_array( $p['archivo'], $job['hechas'], true ) ) {
				continue;
			}
			if ( (int) ( $job['intentos'][ $p['archivo'] ] ?? 0 ) >= 5 ) {
				continue; // tras 5 fallos se deja de intentar; queda en errores
			}
			return $i;
		}
		return null;
	}

	/** Carga una parte en la versión nueva y activa sus municipios en una transacción. */
	public static function import_part( $job, $part ) {
		global $wpdb;
		$t   = RTZ_Schema::tables();
		$vid = (int) $job['version_id'];
		$got = RTZ_Package::obtain_part( $job['fuente'], $job['manifest'], $part );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		$path    = $got['path'];
		$munis   = array();
		$zonas   = 0;
		$now     = current_time( 'mysql', true );
		$current = null;
		$renames = self::renames();
		$wpdb->query( 'START TRANSACTION' );
		$skip    = array();
		$res = RTZ_Package::each_record( $path, function ( $r ) use ( $wpdb, $t, $vid, $now, &$munis, &$zonas, &$current, &$skip, $renames, $part ) {
			if ( 'municipio' === $r['tipo'] ) {
				$mg = self::clean_id( $r['municipio_georef'] ?? '' );
				if ( '' === $mg || substr( $mg, 0, 2 ) !== $part['provincia'] && ( $r['provincia_georef'] ?? '' ) !== $part['provincia'] ) {
					return new WP_Error( 'rtz_muni', 'Municipio inválido en ' . $part['archivo'] );
				}
				$current = $mg;
				$b       = self::bbox( $r['bbox'] ?? array() );
				$row     = array(
					'version_id'       => $vid,
					'municipio_georef' => $mg,
					'localidad_id'     => RTZ_Core_Link::localidad_for( $mg, (string) ( $r['provincia_georef'] ?? '' ), (string) ( $r['nombre'] ?? '' ) ),
					'provincia_georef' => self::clean_id( $r['provincia_georef'] ?? $part['provincia'] ),
					'nombre'           => sanitize_text_field( (string) ( $r['nombre'] ?? '' ) ),
					'poblacion'        => isset( $r['poblacion'] ) && null !== $r['poblacion'] ? (int) $r['poblacion'] : null,
					'radios'           => (int) ( $r['radios_count'] ?? 0 ),
					'zonas'            => (int) ( $r['zonas_count'] ?? 0 ),
					'zonas_revisar'    => (int) ( $r['zonas_para_revisar'] ?? 0 ),
					'flags'            => self::flags( $r['flags'] ?? array() ),
					'min_lng'          => $b[0],
					'min_lat'          => $b[1],
					'max_lng'          => $b[2],
					'max_lat'          => $b[3],
					'activo'           => 0,
				);
				// Si esta versión ya tiene el municipio (cada parte se guarda completa o no se guarda),
				// se reutilizan sus filas: los ids de zona no cambian y los reclamos ya ubicados siguen valiendo.
				$existente = $wpdb->get_row( $wpdb->prepare( "SELECT id, localidad_id FROM {$t['municipios']} WHERE version_id=%d AND municipio_georef=%s", $vid, $mg ), ARRAY_A );
				if ( $existente ) {
					$skip[ $mg ]  = true;
					$munis[ $mg ] = (int) $existente['localidad_id'];
					return true;
				}
				if ( false === $wpdb->insert( $t['municipios'], $row ) ) {
					return new WP_Error( 'rtz_db', 'Error guardando municipio: ' . $wpdb->last_error );
				}
				$munis[ $mg ] = $row['localidad_id'];
				return true;
			}
			if ( 'zona' !== $r['tipo'] ) {
				return true; // tipos futuros: se ignoran sin romper
			}
			$mg = self::clean_id( $r['municipio_georef'] ?? '' );
			if ( $mg !== $current ) {
				return new WP_Error( 'rtz_orden', 'Zona fuera de su municipio en ' . $part['archivo'] );
			}
			if ( ! empty( $skip[ $mg ] ) ) {
				return true; // ya guardada en esta versión
			}
			$uid = (string) ( $r['uid'] ?? '' );
			if ( ! preg_match( '/^[0-9a-f]{16}$/', $uid ) ) {
				return new WP_Error( 'rtz_uid', 'Zona sin identificador válido.' );
			}
			$geom = $r['geometry'] ?? null;
			if ( ! is_array( $geom ) || ! in_array( $geom['type'] ?? '', array( 'Polygon', 'MultiPolygon' ), true ) ) {
				return new WP_Error( 'rtz_geom', 'Zona ' . $uid . ' sin polígono.' );
			}
			$b = self::bbox( $r['bbox'] ?? array() );
			$c = $r['centroide'] ?? array( 0, 0 );
			$nombre = isset( $renames[ $uid ] ) ? $renames[ $uid ] : (string) ( $r['nombre'] ?? '' );
			$ok = $wpdb->insert( $t['zonas'], array(
				'version_id'       => $vid,
				'uid'              => $uid,
				'municipio_georef' => $mg,
				'localidad_id'     => (int) $munis[ $mg ],
				'provincia_georef' => self::clean_id( $r['provincia_georef'] ?? $part['provincia'] ),
				'codigo'           => substr( sanitize_text_field( (string) ( $r['codigo'] ?? '' ) ), 0, 8 ),
				'nombre'           => substr( sanitize_text_field( $nombre ), 0, 160 ),
				'direccion'        => substr( sanitize_text_field( (string) ( $r['direccion'] ?? '' ) ), 0, 20 ),
				'lugar'            => substr( sanitize_text_field( (string) ( $r['lugar'] ?? '' ) ), 0, 80 ),
				'poblacion'        => isset( $r['poblacion'] ) && null !== $r['poblacion'] ? (int) $r['poblacion'] : null,
				'radios_count'     => (int) ( $r['radios_count'] ?? 0 ),
				'area_km2'         => (float) ( $r['area_km2'] ?? 0 ),
				'centro_lng'       => (float) ( $c[0] ?? 0 ),
				'centro_lat'       => (float) ( $c[1] ?? 0 ),
				'min_lng'          => $b[0],
				'min_lat'          => $b[1],
				'max_lng'          => $b[2],
				'max_lat'          => $b[3],
				'flags'            => self::flags( $r['flags'] ?? array() ),
				'calidad'          => substr( (string) wp_json_encode( $r['calidad'] ?? array() ), 0, 255 ),
				'activo'           => 0,
				'creado'           => $now,
			) );
			if ( ! $ok ) {
				return new WP_Error( 'rtz_db', 'Error guardando zona: ' . $wpdb->last_error );
			}
			$zid = (int) $wpdb->insert_id;
			$ok  = $wpdb->insert( $t['geom'], array(
				'zona_id' => $zid,
				'geojson' => wp_json_encode( $geom ),
				'radios'  => wp_json_encode( array_values( array_map( 'strval', (array) ( $r['radios'] ?? array() ) ) ) ),
			) );
			if ( ! $ok ) {
				return new WP_Error( 'rtz_db', 'Error guardando geometría: ' . $wpdb->last_error );
			}
			$zonas++;
			return true;
		} );
		if ( $got['temporal'] ) {
			@unlink( $path ); // phpcs:ignore
		}
		if ( is_wp_error( $res ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $res;
		}
		// Activación atómica por municipio: lo nuevo se ve, lo viejo queda guardado e inactivo.
		foreach ( array_keys( $munis ) as $mg ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t['zonas']} SET activo=0 WHERE municipio_georef=%s AND version_id<>%d AND activo=1", $mg, $vid ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t['municipios']} SET activo=0 WHERE municipio_georef=%s AND version_id<>%d AND activo=1", $mg, $vid ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t['zonas']} SET activo=1 WHERE municipio_georef=%s AND version_id=%d", $mg, $vid ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t['municipios']} SET activo=1 WHERE municipio_georef=%s AND version_id=%d", $mg, $vid ) );
		}
		// Nombres puestos a mano: valen para la zona activa con el mismo uid (mismos radios).
		foreach ( $renames as $uid => $nombre ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t['zonas']} SET nombre=%s WHERE uid=%s AND version_id=%d AND activo=1", $nombre, $uid, $vid ) );
		}
		if ( $wpdb->last_error ) {
			$err = $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'rtz_db', 'Error activando zonas: ' . $err );
		}
		$wpdb->query( 'COMMIT' );
		wp_cache_delete( 'rtz_activas', 'rtz' );
		return array( 'zonas' => $zonas, 'municipios' => count( $munis ) );
	}

	private static function finish( $job ) {
		global $wpdb;
		$t      = RTZ_Schema::tables();
		$fallas = count( $job['cola'] ) - count( $job['hechas'] );
		$estado = $fallas ? 'parcial' : 'activa';
		$wpdb->update( $t['versiones'], array( 'estado' => $estado, 'activada' => current_time( 'mysql', true ) ), array( 'id' => (int) $job['version_id'] ) );
		// Versiones anteriores sin municipios activos quedan como reemplazadas.
		$prev = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t['versiones']} WHERE estado IN ('activa','parcial') AND id<>%d", (int) $job['version_id'] ) );
		foreach ( (array) $prev as $pid ) {
			$vivos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['municipios']} WHERE version_id=%d AND activo=1", (int) $pid ) );
			if ( ! $vivos ) {
				$wpdb->update( $t['versiones'], array( 'estado' => 'reemplazada' ), array( 'id' => (int) $pid ) );
			}
		}
		$job['estado']    = $fallas ? 'con_errores' : 'terminado';
		$job['terminado'] = gmdate( 'c' );
		wp_clear_scheduled_hook( self::TICK );
		do_action( 'rtz_version_activada', (int) $job['version_id'], $job['version'], $estado );
		return $job;
	}

	/** Reintenta las partes que fallaron (por ejemplo, tras un corte de conexión). */
	public static function retry_failed() {
		$job = self::job();
		if ( ! $job ) {
			return null;
		}
		$job['intentos'] = array();
		$job['estado']   = 'pendiente';
		self::save_job( $job );
		if ( ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_event( time() + 5, 'rtz_minuto', self::TICK );
		}
		return $job;
	}

	private static function renames() {
		global $wpdb;
		$t   = RTZ_Schema::tables();
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT uid, nombre FROM {$t['nombres']}", ARRAY_A ) as $r ) {
			$out[ $r['uid'] ] = $r['nombre'];
		}
		return $out;
	}

	private static function lock() {
		$now = time();
		if ( add_option( self::LOCK, $now + self::LOCK_TTL, '', 'no' ) ) {
			return true;
		}
		$exp = (int) get_option( self::LOCK );
		if ( $exp < $now ) {
			// Candado vencido (proceso cortado): se toma solo si nadie lo renovó en el medio.
			global $wpdb;
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", (string) ( $now + self::LOCK_TTL ), self::LOCK, (string) $exp ) );
			wp_cache_delete( self::LOCK, 'options' );
			return 1 === (int) $n;
		}
		return false;
	}

	private static function unlock() {
		delete_option( self::LOCK );
	}

	private static function clean_id( $v ) {
		return substr( preg_replace( '/[^0-9A-Za-z_-]/', '', (string) $v ), 0, 20 );
	}

	private static function bbox( $b ) {
		$b = array_values( (array) $b );
		return array( (float) ( $b[0] ?? 0 ), (float) ( $b[1] ?? 0 ), (float) ( $b[2] ?? 0 ), (float) ( $b[3] ?? 0 ) );
	}

	private static function flags( $f ) {
		$f = array_filter( array_map( function ( $x ) {
			return preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $x ) );
		}, (array) $f ) );
		return substr( implode( ',', $f ), 0, 255 );
	}
}
