<?php
/** CANCHA Core settings and dashboard. No generic execution of user-provided code. */
defined('ABSPATH') || exit;
function cancha_core_defaults() {
    return [
        'registration_enabled' => 0,
        'google_client_id' => '',
        'google_secret_encrypted' => '',
        'terms_version' => '1.0',
        'terms_text' => '',
        'terms_page_id' => 0,
        'hide_subscriber_admin_bar' => 1,
        'official_league_id' => 4406, // compatibilidad con ajuste previo
        'official_comp_ids' => ['liga_profesional'=>4406, 'primera_nacional'=>0, 'libertadores'=>0, 'sudamericana'=>0],
        'official_season' => '2026',
        'api_provider' => 'none',
        'api_key_encrypted' => '',
        'api_timeout' => 10,
        'timezone' => 'America/Argentina/Buenos_Aires',
        'pwa_enabled' => 1,
        'sponsor_name' => '', // Compatible with v0.2: frontend fallback for legacy single sponsor.
        'sponsor_url' => '',
        'sponsor_logo' => 0,
    ];
}
function cancha_core_settings() {
    $value = get_option('cancha_core_settings', []);
    return wp_parse_args(is_array($value) ? $value : [], cancha_core_defaults());
}
/** Encrypt stored provider credential with WordPress installation salts; never expose raw key in HTML. */
function cancha_encrypt_secret($plain) {
    if (!function_exists('openssl_encrypt') || !function_exists('random_bytes')) return '';
    $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $cipher === false ? '' : base64_encode($iv . $tag . $cipher);
}
function cancha_decrypt_secret($encoded) {
    if (!$encoded || !function_exists('openssl_decrypt')) return '';
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}
function cancha_api_key() {
    // Intended only for server-side providers; never add to localized frontend scripts.
    if (defined('CANCHA_API_KEY') && CANCHA_API_KEY !== '') return CANCHA_API_KEY;
    return cancha_decrypt_secret(cancha_core_settings()['api_key_encrypted']);
}
function cancha_terms_page_id() {
    $id = absint(cancha_core_settings()['terms_page_id']);
    return $id && get_post_status($id) === 'publish' && get_post_type($id) === 'page' ? $id : 0;
}
function cancha_terms_url() {
    $id = cancha_terms_page_id();
    return $id ? get_permalink($id) : cancha_auth_url('terminos');
}
function cancha_terms_document() {
    $id = cancha_terms_page_id();
    $text = $id ? get_post_field('post_content', $id) : cancha_core_settings()['terms_text'];
    // Evita recursión al seleccionar una página que contiene el shortcode [cancha_terminos].
    if ($id && has_shortcode((string)$text, 'cancha_terminos')) return '';
    return (string)$text;
}
function cancha_terms_fingerprint() { return hash('sha256', cancha_terms_document()); }
function cancha_terms_published() {
    $s = cancha_core_settings();
    // Registrarse exige condiciones explícitas y publicadas; la política de privacidad
    // se recomienda y advierte por separado para que un ajuste de WP no cause bloqueos silenciosos.
    return strlen(trim(wp_strip_all_tags(cancha_terms_document()))) >= 80 && trim($s['terms_version']) !== '';
}
function cancha_terms_ready() {
    return (bool) get_option('users_can_register') && cancha_terms_published();
}

add_action('admin_menu', function () {
    add_menu_page('CANCHA', 'CANCHA', 'manage_options', 'cancha-core', 'cancha_core_dashboard', 'dashicons-shield-alt', 27);
    add_submenu_page('cancha-core', 'Resumen', 'Resumen', 'manage_options', 'cancha-core', 'cancha_core_dashboard');
    add_submenu_page('cancha-core', 'Configuración', 'Configuración', 'manage_options', 'cancha-settings', 'cancha_core_options_screen');
    add_submenu_page('cancha-core', 'Apariencia y menús', 'Apariencia y menús', 'manage_options', 'cancha-ui', 'cancha_ui_screen');
    add_submenu_page('cancha-core', 'Novedades', 'Novedades', 'manage_options', 'cancha-news', 'cancha_news_admin');
    add_submenu_page('cancha-core', 'Shortcodes', 'Shortcodes', 'manage_options', 'cancha-shortcodes', 'cancha_core_shortcodes_screen');
    add_submenu_page('cancha-core', 'Diagnóstico', 'Diagnóstico', 'manage_options', 'cancha-health', 'cancha_core_health_screen');
}, 11);
add_action('admin_init', function () {
    register_setting('cancha_core_group', 'cancha_core_settings', [
        'type' => 'array', 'default' => cancha_core_defaults(), 'sanitize_callback' => 'cancha_core_sanitize',
    ]);
});
function cancha_core_sanitize($input) {
    $previous = cancha_core_settings();
    if (!current_user_can('manage_options') || !is_array($input)) return $previous;
    $out = $previous;
    $out['registration_enabled'] = !empty($input['registration_enabled']) ? 1 : 0;
    $out['terms_version'] = substr(sanitize_text_field($input['terms_version'] ?? ''), 0, 64);
    $out['terms_text'] = wp_kses_post($input['terms_text'] ?? $previous['terms_text']);
    $out['terms_page_id'] = absint($input['terms_page_id'] ?? 0);
    if ($out['terms_page_id'] && (get_post_type($out['terms_page_id']) !== 'page' || get_post_status($out['terms_page_id']) !== 'publish')) {
        $out['terms_page_id'] = 0;
        add_settings_error('cancha_core_settings', 'terms_page', 'Elegí una página de términos publicada.', 'error');
    }
    $out['hide_subscriber_admin_bar'] = !empty($input['hide_subscriber_admin_bar']) ? 1 : 0;
    $out['google_client_id'] = trim(sanitize_text_field($input['google_client_id'] ?? ''));
    if ($out['google_client_id'] && !preg_match('/^[a-zA-Z0-9._-]{15,180}\.apps\.googleusercontent\.com$/', $out['google_client_id'])) { $out['google_client_id'] = ''; add_settings_error('cancha_core_settings','google_id','El ID de cliente Google no es válido.','error'); }
    if (!empty($input['google_delete_secret'])) $out['google_secret_encrypted']='';
    elseif (!empty($input['google_new_secret'])) { $secret=(string)$input['google_new_secret']; if(strlen($secret)<8||strlen($secret)>512||preg_match('/[\x00-\x1F\x7F]/',$secret)) add_settings_error('cancha_core_settings','google_secret','Clave Google no válida.','error'); else { $encrypted=cancha_encrypt_secret($secret); if ($encrypted) $out['google_secret_encrypted']=$encrypted; } }
    if ($out['google_client_id'] !== ($previous['google_client_id'] ?? '')) $out['google_secret_encrypted']= !empty($input['google_new_secret']) ? $out['google_secret_encrypted'] : '';
    $out['official_league_id'] = absint($input['official_league_id'] ?? $previous['official_league_id']);
    $out['official_comp_ids'] = [];
    foreach (['liga_profesional','primera_nacional','libertadores','sudamericana'] as $code) {
        $out['official_comp_ids'][$code] = absint($input['official_comp_ids'][$code] ?? 0);
    }
    $out['official_season'] = preg_match('/^20[0-9]{2}(-20[0-9]{2})?$/', (string)($input['official_season'] ?? '')) ? sanitize_text_field($input['official_season']) : wp_date('Y');
    // Siempre conservar el checkbox marcado tal como fue elegido, aunque falten requisitos legales.
    // El registro se protege por cancha_terms_ready() hasta que estén completos.
    if ($out['registration_enabled']) {
        $candidate = $out['terms_page_id'] ? (string)get_post_field('post_content', $out['terms_page_id']) : $out['terms_text'];
        $privacy_id = absint(get_option('wp_page_for_privacy_policy'));
        if (strlen(trim(wp_strip_all_tags($candidate))) < 80 || has_shortcode($candidate, 'cancha_terminos') || !$out['terms_version']) {
            add_settings_error('cancha_core_settings', 'terms', 'Preferencia guardada. Para registrar usuarios debés publicar y seleccionar una página de términos con contenido real (80 caracteres como mínimo).', 'warning');
        }
    }
    // Reflejar el ajuste en WordPress sin interceptar option_users_can_register,
    // lo que antes provocaba que «Cualquiera puede registrarse» pareciera desmarcado.
    update_option('users_can_register', $out['registration_enabled'] ? 1 : 0);
    $providers = ['none', 'api-football', 'football-data', 'thesportsdb'];
    $provider = sanitize_key($input['api_provider'] ?? 'none');
    $out['api_provider'] = in_array($provider, $providers, true) ? $provider : 'none';
    if ($out['api_provider'] !== $previous['api_provider']) {
        $out['api_key_encrypted'] = '';
        // Los IDs de competiciones no coinciden entre proveedores.
        foreach ($out['official_comp_ids'] as $code => $id) {
            // Conservar IDs explícitamente editados durante este guardado; no reutilizar los antiguos.
            $old_id = absint($previous['official_comp_ids'][$code] ?? 0);
            if ($old_id === $id) $out['official_comp_ids'][$code] = ($code === 'liga_profesional' && $out['api_provider']==='thesportsdb') ? 4406 : 0;
        } // Avoid sending one provider's secret to another.
    }
    $out['api_timeout'] = max(2, min(30, absint($input['api_timeout'] ?? 10)));
    $zone = sanitize_text_field($input['timezone'] ?? 'America/Argentina/Buenos_Aires');
    $out['timezone'] = in_array($zone, timezone_identifiers_list(), true) ? $zone : 'America/Argentina/Buenos_Aires';
    $out['pwa_enabled'] = !empty($input['pwa_enabled']) ? 1 : 0;
    if (!empty($input['api_delete_key'])) $out['api_key_encrypted'] = '';
    elseif (isset($input['api_new_key']) && trim((string) $input['api_new_key']) !== '') {
        $new_key = trim((string) $input['api_new_key']);
        if (strlen($new_key) > 512 || preg_match('/[\x00-\x1F\x7F]/', $new_key)) {
            add_settings_error('cancha_core_settings', 'api_key', 'Clave API no válida.', 'error');
        } else {
            $encrypted = cancha_encrypt_secret($new_key);
            if ($encrypted) $out['api_key_encrypted'] = $encrypted;
            else add_settings_error('cancha_core_settings', 'openssl', 'No se pudo cifrar la clave: OpenSSL no está disponible.', 'error');
        }
    }
    return $out;
}
function cancha_core_admin_header($title, $desc) {
    echo '<div class="wrap cancha-admin"><h1>' . esc_html($title) . '</h1><p class="description">' . esc_html($desc) . '</p>';
    echo '<nav class="cancha-admin-nav"><a href="' . esc_url(admin_url('admin.php?page=cancha-core')) . '">Resumen</a><a href="' . esc_url(admin_url('admin.php?page=cancha-settings')) . '">Configuración</a><a href="' . esc_url(admin_url('edit.php?post_type=cancha_sponsor')) . '">Sponsors</a><a class="nav-competitions" href="' . esc_url(admin_url('edit.php?post_type=cancha_campeonato')) . '">Campeonatos</a><a class="nav-ads" href="' . esc_url(admin_url('edit.php?post_type=cancha_anuncio')) . '">Ads</a><a href="' . esc_url(admin_url('admin.php?page=cancha-ui')) . '">Apariencia</a><a href="' . esc_url(admin_url('admin.php?page=cancha-news')) . '">Novedades</a><a href="' . esc_url(admin_url('admin.php?page=cancha-shortcodes')) . '">Shortcodes</a><a href="' . esc_url(admin_url('admin.php?page=cancha-health')) . '">Diagnóstico</a></nav>';
}
function cancha_core_dashboard() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos.');
    $s = cancha_core_settings(); cancha_core_admin_header('CANCHA Core', 'Centro de control del tema. Solo accesible para administradores.');
    echo '<div class="cancha-admin-cards">';
    $items = [
        ['Apariencia y menús', 'Portada, colores, navegación y opciones', admin_url('admin.php?page=cancha-ui')],
        ['Novedades', 'Publicar noticias y moderar comentarios', admin_url('admin.php?page=cancha-news')],
        ['Guía de torneos', 'Crear planteles, directivas, partidos y campeones', admin_url('admin.php?page=cancha-tournament-guide')],
        ['Sponsors', 'Alta, baja y programación de patrocinadores', admin_url('edit.php?post_type=cancha_sponsor')],
        ['Registro', cancha_terms_ready() ? 'Registro disponible (sujeto a HTTPS)' : 'Registro desactivado o sin términos definitivos', admin_url('admin.php?page=cancha-settings')],
        ['API deportiva', $s['api_provider'] === 'none' ? 'Sin proveedor seleccionado' : 'Proveedor elegido: ' . $s['api_provider'] . '. Consulta pública con caché.', admin_url('admin.php?page=cancha-settings')],
        ['Campeonatos', 'Torneos, equipos, jornadas y resultados (complemento)', admin_url('edit.php?post_type=cancha_campeonato')],
        ['Publicidad · Ads', 'Campañas, ubicaciones y fechas (complemento)', admin_url('edit.php?post_type=cancha_anuncio')],
        ['Shortcodes', 'Insertá secciones y pantallas en tus páginas', admin_url('admin.php?page=cancha-shortcodes')],
        ['Diagnóstico', 'Comprobaciones técnicas y pendientes', admin_url('admin.php?page=cancha-health')],
    ];
    foreach ($items as $item) echo '<a class="cancha-admin-card" href="' . esc_url($item[2]) . '"><strong>' . esc_html($item[0]) . '</strong><span>' . esc_html($item[1]) . '</span></a>';
    echo '</div><div class="notice notice-info inline"><p>Las APIs se configuran aquí; la sincronización deportiva, predicciones y GamiPress se incorporarán mediante complementos. Ningún resultado ni puntaje ficticio se publicará.</p></div></div>';
}
function cancha_core_options_screen() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos.');
    $s = cancha_core_settings(); cancha_core_admin_header('Configuración de CANCHA', 'Cuenta de usuarios, términos, API y configuración general.');
    settings_errors('cancha_core_settings');
    $blockers=[];if(!get_option('users_can_register'))$blockers[]='Habilitá «Permitir registro»';
    if(!cancha_terms_published())$blockers[]='Publicá o seleccioná términos reales de al menos 80 caracteres';
    if(!is_ssl())$blockers[]='Configurá HTTPS correctamente en WordPress y en el servidor';
    if($blockers) echo '<div class="notice notice-warning inline"><p><strong>¿Por qué no puedo registrarme?</strong> '.esc_html(implode(' · ',$blockers)).'. Estos requisitos se comprueban automáticamente.</p></div>';
    else echo '<div class="notice notice-success inline"><p><strong>Registro habilitado.</strong> Verificá el envío real del correo de confirmación con una cuenta de prueba.</p></div>';
    ?>
    <form method="post" action="options.php" autocomplete="off"><?php settings_fields('cancha_core_group'); ?>
      <h2 id="cancha-users">Usuarios y consentimiento</h2><table class="form-table" role="presentation">
      <tr><th scope="row">Registro de usuarios</th><td><label><input type="checkbox" value="1" name="cancha_core_settings[registration_enabled]" <?php checked((bool)get_option('users_can_register')); ?>> Permitir registro voluntario con confirmación de correo</label><p class="description">Este control sincroniza la casilla «Cualquiera puede registrarse» de Ajustes → Generales. Podés habilitarla desde cualquiera de los dos lugares; CANCHA requiere HTTPS y términos publicados con contenido suficiente. Configurá el correo SMTP para confirmar cuentas; seleccioná también una política de privacidad real.</p></td></tr>
      <tr><th scope="row">Barra de administración (sitio público)</th><td><label><input type="checkbox" name="cancha_core_settings[hide_subscriber_admin_bar]" value="1" <?php checked($s['hide_subscriber_admin_bar']); ?>> Ocultar barra superior de WordPress a suscriptores (no afecta a administradores)</label></td></tr>
      <tr><th><label for="terms_version">Versión de los términos</label></th><td><input id="terms_version" name="cancha_core_settings[terms_version]" value="<?php echo esc_attr($s['terms_version']); ?>" maxlength="64"></td></tr>
      <tr><th scope="row">Página de términos y condiciones</th><td><?php wp_dropdown_pages(['name'=>'cancha_core_settings[terms_page_id]','selected'=>$s['terms_page_id'],'show_option_none'=>'— Utilizar texto de respaldo —','option_none_value'=>0,'post_status'=>'publish']); ?><p><a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=page')); ?>">Crear página de términos</a></p><p class="description">Seleccioná una página publicada. Su contenido se usará para el consentimiento y los enlaces. Si elegís una, el editor de abajo será únicamente respaldo. No incluyas [cancha_terminos] en la página elegida.</p></td></tr>
      <tr><th scope="row">Términos y condiciones</th><td><?php wp_editor($s['terms_text'], 'cancha_terms_editor', ['textarea_name' => 'cancha_core_settings[terms_text]', 'textarea_rows' => 12, 'media_buttons' => false]); ?><p class="description">Texto de respaldo si no seleccionaste página. Los cambios de la página elegida requieren nueva aceptación. Se registra versión, huella y fecha UTC.</p></td></tr>
      </table><h2 id="cancha-google">Acceso con Google</h2><table class="form-table" role="presentation">
      <tr><th>ID de cliente OAuth</th><td><input class="regular-text" name="cancha_core_settings[google_client_id]" value="<?php echo esc_attr($s['google_client_id']??''); ?>"><p class="description">Creá credenciales OAuth tipo Aplicación web en Google Cloud. URL de redirección autorizada exacta: <code><?php echo esc_html(cancha_google_redirect_uri()); ?></code>. El registro Google exige aceptación explícita y guarda versión/huella/fecha.</p></td></tr>
      <tr><th>Secreto Google</th><td><input class="regular-text" type="password" name="cancha_core_settings[google_new_secret]" placeholder="<?php echo !empty($s['google_secret_encrypted']) ? 'Secreto guardado (oculto)' : 'Introducir Client Secret'; ?>" autocomplete="new-password"><p><label><input type="checkbox" name="cancha_core_settings[google_delete_secret]" value="1"> Eliminar secreto guardado</label></p><p class="description">Se cifra con OpenSSL y las sales de WordPress. Alternativa más segura en wp-config.php: constantes CANCHA_GOOGLE_CLIENT_ID y CANCHA_GOOGLE_CLIENT_SECRET.</p></td></tr>
      </table><h2>Proveedor deportivo (configuración preliminar)</h2><table class="form-table" role="presentation">
      <tr><th><label for="api_provider">Proveedor</label></th><td><select id="api_provider" name="cancha_core_settings[api_provider]"><?php foreach (['none' => 'Sin configurar','api-football' => 'API-Football','football-data' => 'football-data.org','thesportsdb' => 'TheSportsDB (gratuita, datos parciales)'] as $key => $label) echo '<option value="' . esc_attr($key) . '" ' . selected($s['api_provider'], $key, false) . '>' . esc_html($label) . '</option>'; ?></select><p class="description">Activa consulta bajo demanda con caché en Fútbol oficial si existe cobertura. No actualiza torneos amateur ni predicciones.</p></td></tr>
      <tr><th>Competiciones que se consultarán</th><td>
      <?php foreach(['liga_profesional'=>'Liga Profesional Argentina','primera_nacional'=>'Primera Nacional','libertadores'=>'Copa Libertadores','sudamericana'=>'Copa Sudamericana'] as $code=>$label): ?>
      <p><label><?php echo esc_html($label); ?> · ID del proveedor <input type="number" min="0" name="cancha_core_settings[official_comp_ids][<?php echo esc_attr($code); ?>]" value="<?php echo esc_attr($s['official_comp_ids'][$code] ?? 0); ?>" style="width:105px"></label></p>
      <?php endforeach; ?>
      <label>Temporada <input class="small-text" name="cancha_core_settings[official_season]" value="<?php echo esc_attr($s['official_season']); ?>"></label>
      <p class="description">Usá 0 para desactivar una competición. ID TheSportsDB comprobado: Liga Profesional 4406. Para las otras ligas, consultá el catálogo de tu proveedor y sus temporadas disponibles; los IDs cambian según el servicio. Al cambiar proveedor se reinician los IDs para evitar usar competiciones equivocadas.</p></td></tr>
      <tr><th><label for="cancha_api_new">Clave API nueva</label></th><td><input id="cancha_api_new" type="password" autocomplete="new-password" class="regular-text" name="cancha_core_settings[api_new_key]" value="" placeholder="<?php echo !empty($s['api_key_encrypted']) ? 'Clave guardada (oculta)' : 'Clave opcional'; ?>"><p class="description">Se cifra en la base de datos usando las claves de WordPress, si OpenSSL está disponible. Se prioriza la constante CANCHA_API_KEY de wp-config.php si existe.</p><label><input type="checkbox" name="cancha_core_settings[api_delete_key]" value="1"> Borrar clave almacenada</label></td></tr>
      <tr><th><label for="api_timeout">Tiempo máximo por petición (segundos)</label></th><td><input id="api_timeout" type="number" min="2" max="30" name="cancha_core_settings[api_timeout]" value="<?php echo esc_attr($s['api_timeout']); ?>"></td></tr>
      <tr><th><label for="cancha_timezone">Zona horaria deportiva</label></th><td><select name="cancha_core_settings[timezone]" id="cancha_timezone"><?php foreach (['America/Argentina/Buenos_Aires','UTC','America/Santiago','America/Montevideo','America/Bogota','America/Mexico_City','Europe/Madrid'] as $zone) echo '<option value="' . esc_attr($zone) . '" ' . selected($s['timezone'], $zone, false) . '>' . esc_html($zone) . '</option>'; ?></select></td></tr>
      </table><h2>Webapp</h2><table class="form-table" role="presentation"><tr><th scope="row">PWA instalable</th><td><label><input type="checkbox" name="cancha_core_settings[pwa_enabled]" value="1" <?php checked($s['pwa_enabled']); ?>> Habilitar manifest y caché de archivos visuales</label><p class="description">Requiere HTTPS (salvo localhost). El modo sin conexión es informativo; nunca almacena datos privados ni predicciones.</p></td></tr></table>
      <?php submit_button('Guardar configuración'); ?></form>
      <h2>Probar conexión deportiva</h2><p>Primero guardá el proveedor y al menos una competición. La prueba consume una consulta real y verifica que se reciban datos del formato esperado; no garantiza cobertura completa ni licencia.</p>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('cancha_test_api'); ?><input type="hidden" name="action" value="cancha_test_api"><?php submit_button('Probar API configurada','secondary','submit',false); ?></form>
      <?php $api_notice=get_transient('cancha_api_notice_'.get_current_user_id()); if ($api_notice): delete_transient('cancha_api_notice_'.get_current_user_id()); ?><div class="notice notice-info inline"><p><?php echo esc_html($api_notice); ?></p></div><?php endif; ?>
      </div>
    <?php
}
function cancha_shortcodes_catalog() {
    return [
        '[cancha_login]' => 'Formulario de ingreso de usuarios',
        '[cancha_registro]' => 'Registro con confirmación de correo y consentimiento',
        '[cancha_terminos]' => 'Términos vigentes (página seleccionada o respaldo)',
        '[cancha_futbol_oficial]' => 'Resultados del proveedor seleccionado, con caché (si hay cobertura)',
        '[cancha_sponsors scope="general"]' => 'Sponsors generales activos',
        '[cancha_sponsors scope="torneo:mi-torneo"]' => 'Sponsors de campeonato según su slug',
        '[cancha_plantel equipo="123"]' => 'Plantel de jugadores de un club',
        '[cancha_jugador id="123"]' => 'Ficha y estadísticas de un jugador',
        '[cancha_equipo id="123"]' => 'Ficha y estadísticas de un club',
        '[cancha_goleadores campeonato="123"]' => 'Goleadores y disciplina de un torneo',
        '[cancha_historial campeonato="123"]' => 'Campeones y ediciones anteriores',
        '[cancha_directorio tipo="jugadores"]' => 'Directorio público de futbolistas',
        '[cancha_tabla campeonato="123"]' => 'Tabla calculada de un campeonato (requiere CANCHA Campeonatos)',
        '[cancha_partidos campeonato="123" jornada="1"]' => 'Partidos de una jornada (requiere CANCHA Campeonatos)',
        '[cancha_ads slot="home_mid"]' => 'Anuncios gráficos en zona central (requiere CANCHA Ads)',
        '[cancha_campeonatos]' => 'Directorio de torneos desde CANCHA Campeonatos',
        '[cancha_proximos_partidos]' => 'Partidos próximos desde el complemento',
        '[cancha_calendario]' => 'Agenda deportiva desde los complementos',
        '[cancha_predicciones]' => 'Predicciones desde el complemento (requerirá sesión)',
        '[cancha_ranking]' => 'Ranking pendiente de conexión con GamiPress',
        '[cancha_noticias]' => 'Feed general de novedades de CANCHA mediante entradas de WordPress',
    ];
}
function cancha_core_shortcodes_screen() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos.');
    cancha_core_admin_header('Shortcodes CANCHA', 'Copiá y pegá estos códigos en cualquier bloque Shortcode de WordPress.');
    echo '<table class="widefat striped cancha-shortcodes"><thead><tr><th>Código</th><th>Función</th><th>Estado</th></tr></thead><tbody>';
    foreach (cancha_shortcodes_catalog() as $tag => $description) {
        preg_match('/^\[([a-z0-9_]+)/', $tag, $parsed);
        $slug=$parsed[1]??'';
        $ready=$slug && shortcode_exists($slug);
        // Placeholder shortcodes from the theme do not imply that the corresponding engine exists.
        if(in_array($slug,['cancha_predicciones','cancha_ranking'],true))$ready=false;
        if(in_array($slug,['cancha_campeonatos','cancha_proximos_partidos','cancha_calendario'],true))$ready=post_type_exists('cancha_campeonato');
        echo '<tr><td><code>' . esc_html($tag) . '</code></td><td>' . esc_html($description) . '</td><td>' . ($ready ? 'Disponible' : 'Espera complemento') . '</td></tr>';
    }
    echo '</tbody></table><p>Por seguridad este panel no permite ejecutar PHP, endpoints arbitrarios ni shortcodes de terceros.</p></div>';
}
function cancha_core_health_screen() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos.');
    cancha_core_admin_header('Diagnóstico CANCHA', 'Revisión del entorno y requisitos para habilitar funcionalidades.');
    $checks = [
      ['WordPress 6.4+', version_compare(get_bloginfo('version'), '6.4', '>='), 'Versión detectada: ' . get_bloginfo('version')],
      ['PHP 8.0+', version_compare(PHP_VERSION, '8.0', '>='), 'Versión detectada: ' . PHP_VERSION],
      ['HTTPS activo', is_ssl(), 'Obligatorio para credenciales y PWA en producción.'],
      ['Privacidad publicada', (bool) get_option('wp_page_for_privacy_policy') && get_post_status((int)get_option('wp_page_for_privacy_policy')) === 'publish', 'Configurar Ajustes → Privacidad y publicar la página.'],
      ['Página legal seleccionada o texto alternativo', cancha_terms_published(), 'Página o texto legal publicado con 80 caracteres como mínimo. La privacidad se revisa por separado.'],
      ['Registro WordPress habilitado', (bool)get_option('users_can_register'), 'Activar la casilla en CANCHA → Configuración o Ajustes → Generales.'],
      ['Términos completos y registro habilitado', cancha_terms_ready(), 'La habilitación exige que una página legal tenga contenido real.'],
      ['Google OAuth con firma local', cancha_google_configured() && function_exists('openssl_verify'), 'Se exige cliente OAuth, HTTPS, OpenSSL y certificados públicos de Google.'],
      ['OpenSSL', function_exists('openssl_encrypt'), 'Necesario para cifrar claves de proveedores en la base de datos.'],
      ['Clave API legible', empty(cancha_core_settings()['api_key_encrypted']) || cancha_api_key() !== '', 'Si cambiaron las sales de WordPress, volvé a guardar la clave.'],
      ['Acceso Google', cancha_google_configured(), 'Necesita un cliente OAuth de Google, secreto y HTTPS.'],
      ['CANCHA Campeonatos', post_type_exists('cancha_campeonato'), 'Complemento de torneos, equipos y partidos.'],
      ['CANCHA Ads', post_type_exists('cancha_anuncio'), 'Complemento de campañas publicitarias.'],
      ['Enlaces permanentes', (bool) get_option('permalink_structure'), 'Guardar Ajustes → Enlaces permanentes; se requieren enlaces amigables.'],
    ];
    echo '<table class="widefat striped"><thead><tr><th>Control</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';
    foreach ($checks as $check) echo '<tr><td>' . esc_html($check[0]) . '</td><td>' . ($check[1] ? '✓ OK' : '⚠ Revisar') . '</td><td>' . esc_html($check[2]) . '</td></tr>';
    echo '</tbody></table><p><strong>Pendientes externos:</strong> entrega real de email/SMTP, pruebas en tu hosting, cobertura y licencia de API, conexión GamiPress, motor de predicciones y datos de campeonatos.</p></div>';
}
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, 'cancha') === false) return;
    wp_enqueue_style('cancha-admin', get_template_directory_uri() . '/assets/css/admin.css', [], CANCHA_VERSION);
});

// Diagnóstico manual controlado, no programado, sin mostrar secretos ni URLs arbitrarias.
add_action('admin_post_cancha_test_api',function(){
    if(!current_user_can('manage_options')) wp_die('Sin permisos.',403);
    check_admin_referer('cancha_test_api');
    $s=cancha_core_settings();$league=0;
    foreach((array)($s['official_comp_ids']??[]) as $id){if(absint($id)){$league=absint($id);break;}}
    if(!$league){$message='Definí al menos un ID de competición y guardá la configuración.';}
    elseif($s['api_provider']==='none'){$message='Seleccioná un proveedor y guardá la configuración.';}
    else{
        $cache_key='cancha_official_'.md5($s['api_provider'].'|'.$league.'|'.$s['official_season'].'|past');
        delete_transient($cache_key);
        $result=cancha_official_response($s['api_provider'],$league,$s['official_season'],'past');
        $message=is_wp_error($result)?'Prueba fallida: '.$result->get_error_message():'Conexión realizada. Partidos recibidos: '.count($result).'. Si recibiste 0, revisá liga y temporada.';
    }
    set_transient('cancha_api_notice_'.get_current_user_id(),$message,120);
    wp_safe_redirect(admin_url('admin.php?page=cancha-settings'));exit;
});
