<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pantalla "Zonas": estado de la carga, revisión en mapa, prueba de ubicación y ajustes. */
final class RTZ_Admin {
	const SLUG = 'rt-zonas';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_rtz_avanzar', array( __CLASS__, 'ajax_advance' ) );
		foreach ( array( 'buscar', 'reintentar', 'vincular', 'ajustes', 'subir', 'renombrar' ) as $a ) {
			add_action( 'admin_post_rtz_' . $a, array( __CLASS__, 'post_' . $a ) );
		}
	}

	public static function menu() {
		global $admin_page_hooks;
		if ( isset( $admin_page_hooks['rt-ubicaciones'] ) ) {
			add_submenu_page( 'rt-ubicaciones', 'Zonas', 'Zonas', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
		} else {
			add_menu_page( 'Zonas', 'Zonas', 'manage_options', self::SLUG, array( __CLASS__, 'page' ), 'dashicons-location', 56 );
		}
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'rtz-admin', RTZ_URL . 'assets/admin.css', array(), RTZ_VERSION );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'estado'; // phpcs:ignore
		$deps = array();
		if ( 'revisar' === $tab && ! empty( $_GET['municipio'] ) ) { // phpcs:ignore
			wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
			wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );
			$deps[] = 'leaflet';
		}
		wp_enqueue_script( 'rtz-admin', RTZ_URL . 'assets/admin.js', $deps, RTZ_VERSION, true );
		wp_localize_script( 'rtz-admin', 'RTZ', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'rtz_avanzar' ),
			'rest'  => esc_url_raw( rest_url( RTZ_Rest::NS . '/zonas' ) ),
		) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permiso.', 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $msg, $tipo = 'ok', $tab = 'estado', $extra = array() ) {
		$url = add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab, 'rtz_msg' => rawurlencode( $msg ), 'rtz_tipo' => $tipo ), $extra ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public static function ajax_advance() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Sin permiso.', 403 );
		}
		check_ajax_referer( 'rtz_avanzar', 'nonce' );
		$job = RTZ_Importer::tick( 15 );
		wp_send_json_success( self::progress( $job ) );
	}

	public static function progress( $job ) {
		if ( ! $job ) {
			return array( 'estado' => 'sin_trabajo', 'porcentaje' => 0 );
		}
		$total = count( $job['cola'] );
		$hecho = count( $job['hechas'] );
		$err   = $job['errores'] ? end( $job['errores'] ) : null;
		return array(
			'estado'     => $job['estado'],
			'version'    => $job['version'],
			'partes'     => $total,
			'hechas'     => $hecho,
			'porcentaje' => $total ? (int) floor( 100 * $hecho / $total ) : 0,
			'zonas'      => (int) $job['zonas'],
			'municipios' => (int) $job['municipios'],
			'error'      => $err ? $err['archivo'] . ': ' . $err['mensaje'] : '',
		);
	}

	public static function post_buscar() {
		self::guard( 'rtz_buscar' );
		$r = RTZ_Importer::start_auto( 'manual' );
		if ( is_wp_error( $r ) ) {
			self::back( $r->get_error_message(), 'error' );
		}
		if ( null === $r ) {
			$msg = RTZ_Settings::manifest_url() || RTZ_Settings::bundled_dir() ? 'Ya tenés la última versión de zonas.' : 'Falta configurar de dónde descargar las zonas (Ajustes) o subir un paquete.';
			self::back( $msg, RTZ_Settings::manifest_url() ? 'ok' : 'aviso' );
		}
		self::back( 'Importación iniciada. Podés dejar esta pantalla abierta para que avance más rápido.' );
	}

	public static function post_reintentar() {
		self::guard( 'rtz_reintentar' );
		RTZ_Importer::retry_failed();
		self::back( 'Se reintentan las partes que fallaron.' );
	}

	public static function post_vincular() {
		self::guard( 'rtz_vincular' );
		$n = RTZ_Core_Link::relink_pending();
		self::back( sprintf( '%d municipios vinculados con Ubicaciones RT.', $n ) );
	}

	public static function post_ajustes() {
		self::guard( 'rtz_ajustes' );
		$in = wp_unslash( $_POST ); // phpcs:ignore
		RTZ_Settings::save( array(
			'manifest_url'     => (string) ( $in['manifest_url'] ?? '' ),
			'auto_actualizar'  => ! empty( $in['auto_actualizar'] ),
			'provincias'       => (array) ( $in['provincias'] ?? array() ),
			'partes_por_tanda' => (int) ( $in['partes_por_tanda'] ?? 3 ),
		) );
		self::back( 'Ajustes guardados.', 'ok', 'ajustes' );
	}

	public static function post_subir() {
		self::guard( 'rtz_subir' );
		if ( empty( $_FILES['paquete']['tmp_name'] ) || ! is_uploaded_file( $_FILES['paquete']['tmp_name'] ) ) { // phpcs:ignore
			self::back( 'Elegí el archivo ZIP del paquete de zonas.', 'error', 'ajustes' );
		}
		$name = sanitize_file_name( (string) $_FILES['paquete']['name'] ); // phpcs:ignore
		if ( '.zip' !== strtolower( substr( $name, -4 ) ) ) {
			self::back( 'El paquete debe ser un archivo .zip.', 'error', 'ajustes' );
		}
		$dir = RTZ_Package::extract_zip( $_FILES['paquete']['tmp_name'] ); // phpcs:ignore
		if ( is_wp_error( $dir ) ) {
			self::back( $dir->get_error_message(), 'error', 'ajustes' );
		}
		$m = RTZ_Package::read_local_manifest( $dir );
		if ( is_wp_error( $m ) ) {
			self::back( $m->get_error_message(), 'error', 'ajustes' );
		}
		$r = RTZ_Importer::start( array( 'tipo' => 'dir', 'valor' => $dir ), $m, 'subida' );
		if ( is_wp_error( $r ) ) {
			self::back( $r->get_error_message(), 'error', 'ajustes' );
		}
		self::back( 'Paquete ' . $m['version'] . ' recibido. La importación ya empezó.' );
	}

	public static function post_renombrar() {
		self::guard( 'rtz_renombrar' );
		global $wpdb;
		$t      = RTZ_Schema::tables();
		$uid    = preg_replace( '/[^0-9a-f]/', '', (string) ( $_POST['uid'] ?? '' ) ); // phpcs:ignore
		$nombre = trim( sanitize_text_field( wp_unslash( (string) ( $_POST['nombre'] ?? '' ) ) ) ); // phpcs:ignore
		$muni   = preg_replace( '/[^0-9]/', '', (string) ( $_POST['municipio'] ?? '' ) ); // phpcs:ignore
		if ( 16 !== strlen( $uid ) ) {
			self::back( 'Zona inválida.', 'error', 'revisar', array( 'municipio' => $muni ) );
		}
		if ( '' === $nombre ) {
			$wpdb->delete( $t['nombres'], array( 'uid' => $uid ) );
			self::back( 'Se restableció el nombre automático; se aplica en la próxima versión.', 'ok', 'revisar', array( 'municipio' => $muni ) );
		}
		$nombre = substr( $nombre, 0, 160 );
		$wpdb->replace( $t['nombres'], array( 'uid' => $uid, 'nombre' => $nombre, 'usuario' => get_current_user_id(), 'actualizado' => current_time( 'mysql', true ) ) );
		$wpdb->update( $t['zonas'], array( 'nombre' => $nombre ), array( 'uid' => $uid, 'activo' => 1 ) );
		self::back( 'Nombre guardado. Se conserva en futuras versiones mientras la zona no cambie.', 'ok', 'revisar', array( 'municipio' => $muni ) );
	}

	private static function form_button( $action, $label, $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rtz-inline">';
		wp_nonce_field( 'rtz_' . $action );
		echo '<input type="hidden" name="action" value="rtz_' . esc_attr( $action ) . '"><button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'estado'; // phpcs:ignore
		$tabs = array( 'estado' => 'Estado', 'revisar' => 'Revisar zonas', 'probar' => 'Probar ubicación', 'ajustes' => 'Ajustes' );
		echo '<div class="wrap rtz"><h1>Zonas de municipios</h1>';
		if ( ! empty( $_GET['rtz_msg'] ) ) { // phpcs:ignore
			$tipo = sanitize_key( $_GET['rtz_tipo'] ?? 'ok' ); // phpcs:ignore
			$cls  = 'error' === $tipo ? 'notice-error' : ( 'aviso' === $tipo ? 'notice-warning' : 'notice-success' );
			echo '<div class="notice ' . esc_attr( $cls ) . '"><p>' . esc_html( rawurldecode( wp_unslash( (string) $_GET['rtz_msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $l ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $k ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</nav>';
		if ( ! RTZ_Schema::ready() ) {
			echo '<div class="notice notice-error"><p>Las tablas de zonas no están listas. Desactivá y activá el plugin.</p></div></div>';
			return;
		}
		$fn = 'tab_' . ( isset( $tabs[ $tab ] ) ? $tab : 'estado' );
		self::$fn();
		echo '</div>';
	}

	private static function tab_estado() {
		global $wpdb;
		$t   = RTZ_Schema::tables();
		$job = RTZ_Importer::job();
		$p   = self::progress( $job );
		$act = $wpdb->get_row( "SELECT * FROM {$t['versiones']} WHERE estado IN ('activa','parcial') ORDER BY id DESC LIMIT 1", ARRAY_A );

		echo '<div class="rtz-cards">';
		echo '<div class="rtz-card"><h2>Versión cargada</h2>';
		if ( $act ) {
			$tot = json_decode( (string) $act['totales'], true );
			echo '<p class="rtz-big">' . esc_html( $act['version'] ) . '</p>';
			echo '<p>' . esc_html( 'activa' === $act['estado'] ? 'Completa' : 'Parcial: faltan partes' ) . ' · ' . esc_html( (string) $act['activada'] ) . ' UTC</p>';
			if ( is_array( $tot ) ) {
				echo '<p>' . esc_html( number_format_i18n( (int) ( $tot['municipios'] ?? 0 ) ) ) . ' municipios · ' . esc_html( number_format_i18n( (int) ( $tot['zonas'] ?? 0 ) ) ) . ' zonas en el paquete</p>';
			}
		} else {
			echo '<p class="rtz-big">Sin zonas todavía</p>';
		}
		echo '</div>';

		echo '<div class="rtz-card" id="rtz-progreso" data-estado="' . esc_attr( $p['estado'] ) . '"><h2>Carga</h2>';
		echo '<div class="rtz-bar"><span style="width:' . (int) $p['porcentaje'] . '%"></span></div>';
		echo '<p class="rtz-prog-text">' . esc_html( self::progress_text( $p ) ) . '</p>';
		$ultimo = get_option( 'rtz_ultimo_error' );
		$err    = $p['error'] ?? '';
		if ( ! $err && is_array( $ultimo ) && ! empty( $ultimo['mensaje'] ) ) {
			$err = 'Última búsqueda (' . $ultimo['fecha'] . '): ' . $ultimo['mensaje'];
		}
		echo '<p class="rtz-prog-error">' . esc_html( $err ) . '</p>';
		echo '<p class="description">Fuente: ' . esc_html( RTZ_Settings::bundled_dir() ? 'paquete incluido en el plugin' : ( RTZ_Settings::manifest_url() ? RTZ_Settings::manifest_url() : 'sin configurar' ) ) . '</p>';
		self::form_button( 'buscar', 'Buscar e importar zonas', 'button button-primary' );
		if ( $job && ! empty( $job['errores'] ) ) {
			self::form_button( 'reintentar', 'Reintentar lo que falló' );
		}
		echo '</div>';

		$sin = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['municipios']} WHERE activo=1 AND localidad_id=0" );
		echo '<div class="rtz-card"><h2>Vínculo con Ubicaciones RT</h2>';
		if ( RTZ_Core_Link::core_available() ) {
			$txt = $sin ? sprintf( _n( '%d municipio con zonas todavía no está en Ubicaciones RT.', '%d municipios con zonas todavía no están en Ubicaciones RT.', $sin, 'rt-zonas' ), $sin ) . ' Importá el catálogo de Argentina en Core y tocá Vincular.' : 'Todos los municipios con zonas están vinculados.';
			echo '<p>' . esc_html( $txt ) . '</p>';
			self::form_button( 'vincular', 'Vincular ahora' );
		} else {
			echo '<p>No se encontró RT Platform Core. Las zonas funcionan igual con el código oficial de cada municipio.</p>';
		}
		echo '</div></div>';

		$rows = $wpdb->get_results( "SELECT provincia_georef, COUNT(*) municipios, SUM(zonas) zonas, SUM(zonas_revisar) revisar, SUM(CASE WHEN localidad_id>0 THEN 1 ELSE 0 END) vinculados, SUM(COALESCE(poblacion,0)) poblacion FROM {$t['municipios']} WHERE activo=1 GROUP BY provincia_georef ORDER BY provincia_georef", ARRAY_A );
		echo '<h2>Por provincia</h2><div class="rtz-table-wrap"><table class="widefat striped"><thead><tr><th>Provincia</th><th>Municipios</th><th>Zonas</th><th>Para revisar</th><th>Vinculados</th><th>Población</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">Todavía no hay zonas cargadas.</td></tr>';
		}
		foreach ( (array) $rows as $r ) {
			$nombre = self::provincia_nombre( $r['provincia_georef'] );
			$url    = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'revisar', 'provincia' => $r['provincia_georef'] ), admin_url( 'admin.php' ) );
			echo '<tr><td>' . esc_html( $nombre ) . '</td><td>' . (int) $r['municipios'] . '</td><td>' . (int) $r['zonas'] . '</td><td>' . (int) $r['revisar'] . '</td><td>' . (int) $r['vinculados'] . '</td><td>' . esc_html( number_format_i18n( (int) $r['poblacion'] ) ) . '</td><td><a class="button" href="' . esc_url( $url ) . '">Revisar</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function progress_text( $p ) {
		switch ( $p['estado'] ) {
			case 'sin_trabajo':
				return 'No hay importaciones en curso.';
			case 'pendiente':
			case 'importando':
				return sprintf( 'Importando versión %s: %d de %d partes (%d%%), %s zonas.', $p['version'], $p['hechas'], $p['partes'], $p['porcentaje'], number_format_i18n( $p['zonas'] ) );
			case 'terminado':
				return sprintf( 'Versión %s cargada: %s municipios y %s zonas.', $p['version'], number_format_i18n( $p['municipios'] ), number_format_i18n( $p['zonas'] ) );
			default:
				return sprintf( 'Versión %s cargada con errores: %d de %d partes. Podés reintentar.', $p['version'], $p['hechas'], $p['partes'] );
		}
	}

	private static function provincia_nombre( $georef ) {
		global $wpdb;
		if ( RTZ_Core_Link::core_available() ) {
			$n = $wpdb->get_var( $wpdb->prepare( "SELECT nombre FROM {$wpdb->prefix}rt_provincias WHERE georef_id=%s", $georef ) );
			if ( $n ) {
				return $n . ' (' . $georef . ')';
			}
		}
		return 'Provincia ' . $georef;
	}

	private static function tab_revisar() {
		global $wpdb;
		$t    = RTZ_Schema::tables();
		$prov = preg_replace( '/[^0-9]/', '', (string) ( $_GET['provincia'] ?? '' ) ); // phpcs:ignore
		$muni = preg_replace( '/[^0-9A-Za-z]/', '', (string) ( $_GET['municipio'] ?? '' ) ); // phpcs:ignore
		if ( $muni ) {
			$m = rtz_municipio( $muni );
			if ( ! $m ) {
				echo '<p>Municipio sin zonas activas.</p>';
				return;
			}
			$back = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'revisar', 'provincia' => $m['provincia_georef'] ), admin_url( 'admin.php' ) );
			echo '<p><a href="' . esc_url( $back ) . '">← Volver a la provincia</a></p>';
			echo '<h2>' . esc_html( $m['nombre'] ) . ' <small>(' . esc_html( $m['municipio_georef'] ) . ')</small></h2>';
			echo '<p>' . (int) $m['zonas'] . ' zonas · ' . esc_html( null === $m['poblacion'] ? 'población sin dato' : number_format_i18n( (int) $m['poblacion'] ) . ' habitantes' ) . ' · ' . (int) $m['radios'] . ' radios censales</p>';
			echo '<div id="rtz-mapa" data-municipio="' . esc_attr( $muni ) . '"></div>';
			echo '<p class="description">Mapa © colaboradores de OpenStreetMap. Las zonas en naranja tienen algo para revisar.</p>';
			$zonas = rtz_zonas_de_municipio( $muni, 'georef' );
			echo '<div class="rtz-table-wrap"><table class="widefat striped"><thead><tr><th>Código</th><th>Nombre</th><th>Población</th><th>Para revisar</th><th>Renombrar</th></tr></thead><tbody>';
			foreach ( $zonas as $z ) {
				echo '<tr><td>' . esc_html( $z['codigo'] ) . '</td><td>' . esc_html( $z['nombre'] ) . '</td><td>' . esc_html( null === $z['poblacion'] ? '—' : number_format_i18n( $z['poblacion'] ) ) . '</td><td>' . esc_html( implode( ', ', array_map( array( __CLASS__, 'flag_text' ), $z['marcas'] ) ) ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rtz-rename">';
				wp_nonce_field( 'rtz_renombrar' );
				echo '<input type="hidden" name="action" value="rtz_renombrar"><input type="hidden" name="uid" value="' . esc_attr( $z['uid'] ) . '"><input type="hidden" name="municipio" value="' . esc_attr( $muni ) . '">';
				echo '<input type="text" name="nombre" value="' . esc_attr( $z['nombre'] ) . '" maxlength="160" aria-label="Nombre de la zona"> <button class="button">Guardar</button></form></td></tr>';
			}
			echo '</tbody></table></div>';
			return;
		}
		$provs = $wpdb->get_col( "SELECT DISTINCT provincia_georef FROM {$t['municipios']} WHERE activo=1 ORDER BY provincia_georef" );
		echo '<form method="get" class="rtz-filter"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="revisar"><label>Provincia <select name="provincia" onchange="this.form.submit()"><option value="">Elegir…</option>';
		foreach ( (array) $provs as $p ) {
			echo '<option value="' . esc_attr( $p ) . '"' . selected( $prov, $p, false ) . '>' . esc_html( self::provincia_nombre( $p ) ) . '</option>';
		}
		echo '</select></label></form>';
		if ( ! $prov ) {
			return;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['municipios']} WHERE activo=1 AND provincia_georef=%s ORDER BY zonas_revisar DESC, nombre", $prov ), ARRAY_A );
		echo '<div class="rtz-table-wrap"><table class="widefat striped"><thead><tr><th>Municipio</th><th>Zonas</th><th>Para revisar</th><th>Población</th><th>Vinculado</th><th></th></tr></thead><tbody>';
		foreach ( (array) $rows as $r ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'revisar', 'municipio' => $r['municipio_georef'] ), admin_url( 'admin.php' ) );
			echo '<tr><td>' . esc_html( $r['nombre'] ) . '</td><td>' . (int) $r['zonas'] . '</td><td>' . (int) $r['zonas_revisar'] . '</td><td>' . esc_html( null === $r['poblacion'] ? '—' : number_format_i18n( (int) $r['poblacion'] ) ) . '</td><td>' . ( $r['localidad_id'] ? 'Sí' : 'No' ) . '</td><td><a class="button" href="' . esc_url( $url ) . '">Ver mapa</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function flag_text( $f ) {
		$map = array(
			'no_contigua'            => 'tiene partes separadas',
			'poco_compacta'          => 'forma muy alargada',
			'poblacion_baja'         => 'poca población',
			'poblacion_alta'         => 'mucha población',
			'geometria_sin_disolver' => 'borde sin unificar',
			'sin_radios'             => 'sin datos censales',
		);
		return isset( $map[ $f ] ) ? $map[ $f ] : $f;
	}

	private static function tab_probar() {
		$lat = isset( $_GET['lat'] ) ? (string) wp_unslash( $_GET['lat'] ) : ''; // phpcs:ignore
		$lng = isset( $_GET['lng'] ) ? (string) wp_unslash( $_GET['lng'] ) : ''; // phpcs:ignore
		echo '<form method="get" class="rtz-filter"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="probar">';
		echo '<label>Latitud <input name="lat" inputmode="decimal" value="' . esc_attr( $lat ) . '" placeholder="-26.7700"></label> ';
		echo '<label>Longitud <input name="lng" inputmode="decimal" value="' . esc_attr( $lng ) . '" placeholder="-65.2100"></label> ';
		echo '<button class="button button-primary">Ubicar</button></form>';
		if ( '' === $lat || '' === $lng ) {
			echo '<p class="description">Pegá las coordenadas de un punto (por ejemplo, de Google Maps) para ver en qué zona y sector cae.</p>';
			return;
		}
		$r = rtz_ubicar( $lat, $lng );
		$textos = array(
			'ok'                 => 'El punto está en la zona indicada.',
			'sin_zona'           => 'El punto no cae en ninguna zona cargada.',
			'ambiguo'            => 'El punto cae en el borde de dos zonas: queda para revisión.',
			'fuera_de_municipio' => 'El punto está en otro municipio.',
			'punto_invalido'     => 'Las coordenadas no son válidas para Argentina (¿latitud y longitud invertidas?).',
		);
		echo '<div class="rtz-card"><p><strong>' . esc_html( $textos[ $r['estado'] ] ?? $r['estado'] ) . '</strong></p>';
		if ( ! empty( $r['zona'] ) ) {
			$m = rtz_municipio( $r['zona']['municipio_georef'] );
			echo '<p>' . esc_html( $r['zona']['nombre'] . ' (' . $r['zona']['codigo'] . ') · ' . ( $m ? $m['nombre'] : $r['zona']['municipio_georef'] ) ) . '</p>';
			if ( ! empty( $r['sector'] ) ) {
				echo '<p>Sector ' . esc_html( $r['sector']['id'] ) . ' (cuadrícula de 200 m)</p>';
			}
		}
		echo '</div>';
	}

	private static function tab_ajustes() {
		$s = RTZ_Settings::get();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rtz-card">';
		wp_nonce_field( 'rtz_ajustes' );
		echo '<input type="hidden" name="action" value="rtz_ajustes"><h2>De dónde se descargan las zonas</h2>';
		$fixed = defined( 'RTZ_MANIFEST_URL' ) && RTZ_MANIFEST_URL;
		echo '<p><label>Dirección del manifiesto (publicación de GitHub)<br><input type="url" class="large-text" name="manifest_url" value="' . esc_attr( $s['manifest_url'] ) . '" placeholder="https://github.com/USUARIO/civiapp-zonas/releases/latest/download/manifest.json"' . ( $fixed ? ' disabled' : '' ) . '></label></p>';
		if ( $fixed ) {
			echo '<p class="description">Fijada en wp-config.php (RTZ_MANIFEST_URL).</p>';
		}
		echo '<p><label><input type="checkbox" name="auto_actualizar" value="1"' . checked( $s['auto_actualizar'], 1, false ) . '> Buscar versiones nuevas todos los días e importarlas solas</label></p>';
		echo '<p><label>Provincias a importar (códigos separados por coma; vacío = todas)<br><input type="text" name="provincias" value="' . esc_attr( implode( ',', (array) $s['provincias'] ) ) . '" placeholder="90,06"></label></p>';
		echo '<p><label>Partes por tanda <input type="number" min="1" max="20" name="partes_por_tanda" value="' . (int) $s['partes_por_tanda'] . '"></label> <span class="description">Bajalo si el hosting corta por tiempo.</span></p>';
		echo '<p><button class="button button-primary">Guardar</button></p></form>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rtz-card">';
		wp_nonce_field( 'rtz_subir' );
		echo '<input type="hidden" name="action" value="rtz_subir"><h2>Subir un paquete a mano</h2><p>Si no hay conexión con GitHub, subí el archivo <code>civiapp-zonas-VERSIÓN.zip</code>.</p>';
		echo '<p><input type="file" name="paquete" accept=".zip,application/zip"> <button class="button">Subir e importar</button></p></form>';
	}
}
