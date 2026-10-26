<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tablas propias. Nada se borra: cada importación es una versión; las zonas viejas quedan inactivas
 * para que los reclamos ya ubicados conserven la zona que tenían.
 */
final class RTZ_Schema {
	const DB_VERSION = '1.1.0';

	public static function tables() {
		global $wpdb;
		return array(
			'versiones'  => $wpdb->prefix . 'rtz_versiones',
			'municipios' => $wpdb->prefix . 'rtz_municipios',
			'zonas'      => $wpdb->prefix . 'rtz_zonas',
			'geom'       => $wpdb->prefix . 'rtz_zona_geom',
			'nombres'    => $wpdb->prefix . 'rtz_nombres',
		);
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = self::tables();

		dbDelta( "CREATE TABLE {$t['versiones']} (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 version varchar(40) NOT NULL,
 esquema varchar(30) NOT NULL,
 origen varchar(255) NOT NULL DEFAULT '',
 manifest_sha256 char(64) NOT NULL DEFAULT '',
 generado varchar(40) NOT NULL DEFAULT '',
 atribucion text,
 totales longtext,
 estado varchar(20) NOT NULL DEFAULT 'importando',
 creada datetime NOT NULL,
 activada datetime DEFAULT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY version (version),
 KEY estado (estado)
) ENGINE=InnoDB {$c};" );

		dbDelta( "CREATE TABLE {$t['municipios']} (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 version_id bigint unsigned NOT NULL,
 municipio_georef varchar(20) NOT NULL,
 localidad_id bigint unsigned NOT NULL DEFAULT 0,
 provincia_georef varchar(4) NOT NULL DEFAULT '',
 nombre varchar(160) NOT NULL DEFAULT '',
 tipo_unidad varchar(16) NOT NULL DEFAULT 'municipio',
 poblacion bigint DEFAULT NULL,
 radios int unsigned NOT NULL DEFAULT 0,
 zonas int unsigned NOT NULL DEFAULT 0,
 zonas_revisar int unsigned NOT NULL DEFAULT 0,
 flags varchar(255) NOT NULL DEFAULT '',
 min_lat decimal(10,7) NOT NULL DEFAULT 0,
 max_lat decimal(10,7) NOT NULL DEFAULT 0,
 min_lng decimal(10,7) NOT NULL DEFAULT 0,
 max_lng decimal(10,7) NOT NULL DEFAULT 0,
 activo tinyint NOT NULL DEFAULT 0,
 PRIMARY KEY  (id),
 UNIQUE KEY version_municipio (version_id,municipio_georef),
 KEY municipio_activo (municipio_georef,activo),
 KEY localidad_activo (localidad_id,activo),
 KEY provincia_activo (provincia_georef,activo)
) ENGINE=InnoDB {$c};" );

		dbDelta( "CREATE TABLE {$t['zonas']} (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 version_id bigint unsigned NOT NULL,
 uid char(16) NOT NULL,
 municipio_georef varchar(20) NOT NULL,
 localidad_id bigint unsigned NOT NULL DEFAULT 0,
 provincia_georef varchar(4) NOT NULL DEFAULT '',
 codigo varchar(8) NOT NULL DEFAULT '',
 nombre varchar(160) NOT NULL DEFAULT '',
 direccion varchar(20) NOT NULL DEFAULT '',
 lugar varchar(80) NOT NULL DEFAULT '',
 barrios text,
 poblacion bigint DEFAULT NULL,
 radios_count int unsigned NOT NULL DEFAULT 0,
 area_km2 decimal(12,3) NOT NULL DEFAULT 0,
 centro_lat decimal(10,7) NOT NULL DEFAULT 0,
 centro_lng decimal(10,7) NOT NULL DEFAULT 0,
 min_lat decimal(10,7) NOT NULL DEFAULT 0,
 max_lat decimal(10,7) NOT NULL DEFAULT 0,
 min_lng decimal(10,7) NOT NULL DEFAULT 0,
 max_lng decimal(10,7) NOT NULL DEFAULT 0,
 flags varchar(255) NOT NULL DEFAULT '',
 calidad varchar(255) NOT NULL DEFAULT '',
 activo tinyint NOT NULL DEFAULT 0,
 creado datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY version_uid (version_id,uid),
 KEY uid (uid),
 KEY activo_lat (activo,min_lat,max_lat),
 KEY municipio_activo (municipio_georef,activo),
 KEY localidad_activo (localidad_id,activo)
) ENGINE=InnoDB {$c};" );

		dbDelta( "CREATE TABLE {$t['geom']} (
 zona_id bigint unsigned NOT NULL,
 geojson longtext NOT NULL,
 radios longtext NOT NULL,
 PRIMARY KEY  (zona_id)
) ENGINE=InnoDB {$c};" );

		dbDelta( "CREATE TABLE {$t['nombres']} (
 uid char(16) NOT NULL,
 nombre varchar(160) NOT NULL,
 usuario bigint unsigned NOT NULL DEFAULT 0,
 actualizado datetime NOT NULL,
 PRIMARY KEY  (uid)
) ENGINE=InnoDB {$c};" );

		if ( self::ready() ) {
			update_option( 'rtz_db_version', self::DB_VERSION, false );
			return true;
		}
		return false;
	}

	public static function ready() {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			if ( rt_tabla_como( $table ) !== $table ) {
				return false;
			}
		}
		return true;
	}

	public static function maybe_upgrade() {
		if ( get_option( 'rtz_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
