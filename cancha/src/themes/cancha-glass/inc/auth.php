<?php
/** Voluntary registration and guarded login. WordPress owns passwords, cookies and password reset. */
defined('ABSPATH') || exit;
function cancha_auth_url($page) { return home_url('/' . trim($page, '/') . '/'); }
add_action('init', function () {
    foreach (['ingresar','registro','terminos','activar','reenviar-verificacion'] as $route) {
        add_rewrite_rule('^' . $route . '/?$', 'index.php?cancha_auth=' . $route, 'top');
    }
});
add_filter('query_vars', function ($vars) { $vars[] = 'cancha_auth'; return $vars; });
add_filter('template_include', function ($template) {
    return get_query_var('cancha_auth') ? get_template_directory() . '/auth-page.php' : $template;
});
function cancha_private_route() {
    $route = get_query_var('cancha_auth');
    return (bool) $route || is_page('mi-panel');
}
add_action('template_redirect', function () {
    if (cancha_private_route()) {
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow', true);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['cancha_action'])) {
        $GLOBALS['cancha_form_result'] = cancha_auth_process();
    }
}, 1);
function cancha_attempt_key($type, $identity = '') {
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'); // Deliberately ignore spoofable X-Forwarded-For.
    return 'cancha_rl_' . hash_hmac('sha256', $type . '|' . $address . '|' . strtolower($identity), wp_salt('nonce'));
}
function cancha_throttle($type, $identity = '', $max = 6, $duration = 900) {
    $key = cancha_attempt_key($type, $identity);
    $count = (int) get_transient($key);
    if ($count >= $max) return true;
    set_transient($key, $count + 1, $duration);
    return false;
}
function cancha_send_verify_email($uid) {
    $user = get_userdata($uid);
    if (!$user) return false;
    $token = bin2hex(random_bytes(32));
    update_user_meta($uid, 'cancha_verify_hash', hash_hmac('sha256', $token, wp_salt('secure_auth')));
    update_user_meta($uid, 'cancha_verify_expires', time() + DAY_IN_SECONDS);
    $link = add_query_arg(['uid' => $uid, 'token' => $token], cancha_auth_url('activar'));
    $subject = 'Confirmá tu cuenta de CANCHA';
    $body = "Hola,\n\nConfirmá tu cuenta en CANCHA con este enlace válido durante 24 horas:\n" . $link . "\n\nSi no solicitaste una cuenta, podés ignorar este correo.\n";
    return wp_mail($user->user_email, $subject, $body);
}
add_filter('authenticate', function ($user) {
    if ($user instanceof WP_User && get_user_meta($user->ID, 'cancha_email_verified', true) === 'pending') {
        return new WP_Error('cancha_unverified', 'Confirmá tu correo antes de iniciar sesión.');
    }
    return $user;
}, 45);
function cancha_validate_auth_request($action) {
    if (empty($_POST['cancha_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cancha_nonce'])), 'cancha_' . $action)) {
        return new WP_Error('csrf', 'La sesión del formulario venció. Recargá la página e intentá de nuevo.');
    }
    if (!empty($_POST['website'])) return new WP_Error('spam', 'No se pudo completar la solicitud.');
    if (!is_ssl()) return new WP_Error('https', 'El sitio debe funcionar con HTTPS antes de recibir credenciales.');
    return true;
}
function cancha_auth_process() {
    $action = sanitize_key(wp_unslash($_POST['cancha_action'] ?? ''));
    if (!in_array($action, ['login', 'register', 'resend', 'accept'], true)) return new WP_Error('action', 'Solicitud no válida.');
    $valid = cancha_validate_auth_request($action);
    if (is_wp_error($valid)) return $valid;
    if ($action === 'login') {
        $login = sanitize_text_field(wp_unslash($_POST['login'] ?? ''));
        $password = (string) wp_unslash($_POST['password'] ?? '');
        if (!$login || !$password) return new WP_Error('missing', 'Completá usuario y contraseña.');
        if (cancha_throttle('login-ip', '', 25) || cancha_throttle('login-account', $login, 7)) {
            return new WP_Error('throttle', 'Demasiados intentos. Probá más tarde.');
        }
        // WordPress authenticates passwords and sets secure cookies on SSL connections.
        $user = wp_signon(['user_login' => $login, 'user_password' => $password, 'remember' => !empty($_POST['remember'])], true);
        if (is_wp_error($user)) return new WP_Error('login', 'No fue posible ingresar. Revisá los datos y verificá tu correo.');
        $safe = wp_validate_redirect(esc_url_raw(wp_unslash($_POST['redirect_to'] ?? '')), cancha_route('mi-panel'));
        wp_safe_redirect($safe); exit;
    }
    if ($action === 'register') {
        if (!cancha_terms_ready()) return new WP_Error('register', 'El registro no está habilitado.');
        if (empty($_POST['terms_accept'])) return new WP_Error('terms', 'Debés aceptar los términos y condiciones.');
        if (cancha_throttle('register', '', 5, HOUR_IN_SECONDS)) return new WP_Error('throttle', 'Demasiados intentos de registro. Probá más tarde.');
        $username = sanitize_user(wp_unslash($_POST['username'] ?? ''), true);
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $password = (string) wp_unslash($_POST['password'] ?? '');
        if (!validate_username($username) || strlen($username) < 3 || strlen($username) > 40) return new WP_Error('username', 'El usuario debe contener de 3 a 40 caracteres válidos.');
        if (!is_email($email)) return new WP_Error('email', 'Ingresá un correo válido.');
        if (strlen($password) < 12 || strlen($password) > 256) return new WP_Error('password', 'La contraseña debe tener entre 12 y 256 caracteres.');
        if (username_exists($username) || email_exists($email)) return new WP_Error('exists', 'No se pudo registrar esta cuenta. Probá otro usuario o correo.');
        $uid = wp_insert_user(['user_login' => $username, 'user_email' => $email, 'user_pass' => $password, 'role' => 'subscriber']);
        if (is_wp_error($uid)) return new WP_Error('register', 'No se pudo crear la cuenta.');
        $s = cancha_core_settings();
        update_user_meta($uid, 'cancha_email_verified', 'pending');
        update_user_meta($uid, 'cancha_terms_version', $s['terms_version']);
        update_user_meta($uid, 'cancha_terms_sha256', cancha_terms_fingerprint());
        update_user_meta($uid, 'cancha_terms_accepted_utc', gmdate('Y-m-d H:i:s'));
        update_user_meta($uid, 'cancha_terms_source', 'registro-web');
        if (!cancha_send_verify_email($uid)) return new WP_Error('mail', 'La cuenta quedó pendiente, pero el correo no pudo enviarse. Configurá el correo del servidor y utilizá «Reenviar verificación».');
        return 'Te enviamos un enlace de verificación por correo. Revisá también spam.';
    }
    if ($action === 'resend') {
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!is_email($email)) return new WP_Error('email', 'Ingresá un correo válido.');
        if (cancha_throttle('resend-ip', '', 8, HOUR_IN_SECONDS) || cancha_throttle('resend-email', $email, 2, HOUR_IN_SECONDS)) {
            return 'Si la cuenta sigue pendiente, recibirás instrucciones por correo.';
        }
        $user = get_user_by('email', $email);
        if ($user && get_user_meta($user->ID, 'cancha_email_verified', true) === 'pending') cancha_send_verify_email($user->ID);
        return 'Si la cuenta sigue pendiente, recibirás instrucciones por correo.';
    }
    if ($action === 'accept') {
        if (!is_user_logged_in()) return new WP_Error('login', 'Necesitás iniciar sesión.');
        if (empty($_POST['terms_accept']) || !cancha_terms_published()) return new WP_Error('terms', 'No se pudo actualizar el consentimiento.');
        $uid = get_current_user_id(); $s = cancha_core_settings();
        update_user_meta($uid, 'cancha_terms_version', $s['terms_version']);
        update_user_meta($uid, 'cancha_terms_sha256', cancha_terms_fingerprint());
        update_user_meta($uid, 'cancha_terms_accepted_utc', gmdate('Y-m-d H:i:s'));
        update_user_meta($uid, 'cancha_terms_source', 'panel-usuario');
        return 'Aceptación actualizada correctamente.';
    }
    return null;
}
function cancha_auth_activate_token() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return 'Confirmación pendiente.';
    $uid = absint($_POST['uid'] ?? 0);
    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    if (empty($_POST['cancha_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cancha_nonce'])), 'cancha_activate_' . $uid)) return 'Formulario caducado.';
    if (cancha_throttle('activate', '', 30)) return 'Demasiadas solicitudes. Probá más tarde.';
    if (!$uid || !preg_match('/^[a-f0-9]{64}$/', $token)) return 'Enlace de confirmación inválido.';
    $hash = get_user_meta($uid, 'cancha_verify_hash', true);
    $expires = (int) get_user_meta($uid, 'cancha_verify_expires', true);
    $provided = hash_hmac('sha256', $token, wp_salt('secure_auth'));
    if (!$hash || $expires < time() || !hash_equals($hash, $provided) || get_user_meta($uid, 'cancha_email_verified', true) !== 'pending') {
        return 'El enlace ya fue usado o caducó. Podés solicitar otro.';
    }
    update_user_meta($uid, 'cancha_email_verified', 'yes');
    delete_user_meta($uid, 'cancha_verify_hash');
    delete_user_meta($uid, 'cancha_verify_expires');
    return '¡Cuenta verificada! Ya podés ingresar.';
}
function cancha_current_user_can_predict() {
    if (!is_user_logged_in() || !cancha_terms_published()) return false;
    $uid = get_current_user_id();
    if (get_user_meta($uid, 'cancha_email_verified', true) === 'pending') return false;
    return get_user_meta($uid, 'cancha_terms_sha256', true) === cancha_terms_fingerprint()
        && get_user_meta($uid, 'cancha_terms_version', true) === cancha_core_settings()['terms_version'];
}
// Ensure new users cannot bypass CANCHA's explicit consent through native WordPress public registration.
// No forzar option_users_can_register: WordPress debe conservar su checkbox.
// Redirigir el registro nativo al formulario con consentimiento, sin aceptar POST nativo.
add_action('login_form_register', function () {
    if (!is_admin()) { wp_safe_redirect(cancha_auth_url('registro')); exit; }
}, 0);
add_filter('register_url', function ($url) { return cancha_auth_url('registro'); });
function cancha_auth_result() { return $GLOBALS['cancha_form_result'] ?? null; }
function cancha_login_html($redirect_to = '') {
    if (is_user_logged_in()) return '<div class="glass auth-card"><h1>¡Ya estás en CANCHA!</h1><p><a class="button primary" href="' . esc_url(cancha_route('mi-panel')) . '">Ir a Mi panel</a></p></div>';
    ob_start(); ?>
    <section class="glass auth-card"><div class="eyebrow">CANCHA · TU CUENTA</div><h1>Bienvenido de nuevo</h1><p class="muted">Ingresá para participar en predicciones y acceder a tu panel.</p>
    <?php cancha_form_notice(); echo cancha_google_notice(); ?>
    <?php if (!is_ssl()): ?><p class="auth-alert" role="alert">No se aceptan contraseñas hasta configurar HTTPS.</p><?php else: ?>
    <form class="auth-form" method="post" action="<?php echo esc_url(cancha_auth_url('ingresar')); ?>">
    <?php wp_nonce_field('cancha_login', 'cancha_nonce'); ?>
    <input type="hidden" name="cancha_action" value="login"><input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to ?: cancha_route('mi-panel')); ?>">
    <?php cancha_honeypot(); ?><label>Usuario<input type="text" name="login" autocomplete="username" required maxlength="120"></label>
    <label>Contraseña<input type="password" name="password" autocomplete="current-password" required></label>
    <label class="auth-check"><input type="checkbox" name="remember" value="1"> Mantener la sesión iniciada</label>
    <button type="submit" class="button primary">Ingresar a CANCHA</button></form><?php echo cancha_google_button('login'); ?><?php endif; ?>
    <p><a href="<?php echo esc_url(wp_lostpassword_url()); ?>">¿Olvidaste tu contraseña?</a></p>
    <div class="auth-foot">¿Aún no tenés cuenta? <a href="<?php echo esc_url(cancha_auth_url('registro')); ?>">Registrate</a></div>
    </section><?php return ob_get_clean();
}
function cancha_register_html() {
    if (is_user_logged_in()) return '<div class="glass auth-card"><p>Ya tenés una sesión iniciada.</p><a href="' . esc_url(cancha_route('mi-panel')) . '">Mi panel</a></div>';
    ob_start(); ?>
    <section class="glass auth-card"><div class="eyebrow">FORMÁ PARTE DE CANCHA</div><h1>Creá tu cuenta</h1><p class="muted">Registrarte es gratuito y opcional. Para realizar predicciones necesitarás una cuenta verificada.</p>
    <?php cancha_form_notice(); echo cancha_google_notice(); ?>
    <?php if (!cancha_terms_ready()): ?><?php if(current_user_can('manage_options')): ?><div class="cancha-help">Para habilitar cuentas, marcá el registro y elegí una página publicada de términos con contenido definitivo. <a href="<?php echo esc_url(admin_url('admin.php?page=cancha-settings')); ?>">Ir a configuración</a>.</div><?php endif; ?><div class="module-empty">El registro todavía no está disponible. Podés seguir explorando el sitio sin registrarte.</div>
    <?php elseif (!is_ssl()): ?><p class="auth-alert" role="alert">El registro requiere una conexión segura HTTPS.</p>
    <?php else: ?>
    <form class="auth-form" method="post" action="<?php echo esc_url(cancha_auth_url('registro')); ?>">
    <?php wp_nonce_field('cancha_register', 'cancha_nonce'); ?><input type="hidden" name="cancha_action" value="register"><?php cancha_honeypot(); ?>
    <label>Nombre de usuario<input name="username" autocomplete="username" required minlength="3" maxlength="40"></label>
    <label>Correo electrónico<input type="email" name="email" autocomplete="email" required></label>
    <label>Contraseña segura<input type="password" name="password" autocomplete="new-password" required minlength="12" maxlength="256"><small>Al menos 12 caracteres. Evitá contraseñas reutilizadas.</small></label>
    <label class="auth-check"><input type="checkbox" name="terms_accept" required value="1"><span>Leí y acepto los <a href="<?php echo esc_url(cancha_terms_url()); ?>" target="_blank" rel="noopener noreferrer">términos y condiciones</a> (versión <?php echo esc_html(cancha_core_settings()['terms_version']); ?>) <?php if(get_privacy_policy_url()): ?>y conozco la <a href="<?php echo esc_url(get_privacy_policy_url()); ?>" target="_blank" rel="noopener noreferrer">política de privacidad</a><?php endif; ?>.</span></label>
    <button type="submit" class="button primary">Crear mi cuenta</button></form>
    <div class="auth-divider">o registrate de forma sencilla</div><?php echo cancha_google_button('register'); ?>
    <?php endif; ?><div class="auth-foot">¿Ya tenés cuenta? <a href="<?php echo esc_url(cancha_auth_url('ingresar')); ?>">Ingresá</a></div></section>
    <?php return ob_get_clean();
}
function cancha_honeypot() { echo '<div class="auth-honey" aria-hidden="true"><label>No completar<input tabindex="-1" autocomplete="off" name="website"></label></div>'; }
function cancha_form_notice() {
    $result = cancha_auth_result();
    if ($result instanceof WP_Error) echo '<p class="auth-alert" role="alert">' . esc_html($result->get_error_message()) . '</p>';
    elseif (is_string($result)) echo '<p class="auth-success" role="status">' . esc_html($result) . '</p>';
}
function cancha_terms_html() {
    $s = cancha_core_settings();
    $document = cancha_terms_document();
    return '<article class="glass content-panel terms-panel"><div class="eyebrow">INFORMACIÓN LEGAL</div><h1>Términos y condiciones</h1><p class="muted">Versión: ' . esc_html($s['terms_version']) . '</p>' . ($document ? '<div class="entry-content">' . wpautop(wp_kses_post($document)) . '</div>' : '<p>Los términos están pendientes de publicación por parte del administrador.</p>') . '</article>';
}
function cancha_resend_html() {
    if (is_user_logged_in()) return '';
    ob_start(); ?>
    <section class="glass auth-card"><h1>Reenviar verificación</h1><p class="muted">Si tu cuenta sigue pendiente, recibirás otro enlace.</p>
    <?php cancha_form_notice(); ?>
    <?php if (is_ssl()): ?><form class="auth-form" method="post" action="<?php echo esc_url(cancha_auth_url('reenviar-verificacion')); ?>">
    <?php wp_nonce_field('cancha_resend', 'cancha_nonce'); ?><input type="hidden" name="cancha_action" value="resend"><?php cancha_honeypot(); ?>
    <label>Correo de la cuenta<input type="email" name="email" autocomplete="email" required></label><button class="button primary">Enviar instrucciones</button></form><?php endif; ?>
    </section><?php return ob_get_clean();
}
function cancha_terms_accept_form() {
    if (!is_user_logged_in() || !cancha_terms_published()) return '';
    $s = cancha_core_settings();
    $uid = get_current_user_id();
    $accepted = get_user_meta($uid, 'cancha_terms_sha256', true);
    if ($accepted === cancha_terms_fingerprint() && get_user_meta($uid, 'cancha_terms_version', true) === $s['terms_version']) return '<p class="muted">Términos vigentes aceptados (versión ' . esc_html($s['terms_version']) . ').</p>';
    ob_start(); ?>
    <div class="module-empty"><p>Se han actualizado los términos de CANCHA. Revisalos antes de participar.</p>
    <?php cancha_form_notice(); ?><form method="post" action="<?php echo esc_url(cancha_route('mi-panel')); ?>">
    <?php wp_nonce_field('cancha_accept', 'cancha_nonce'); ?><input type="hidden" name="cancha_action" value="accept"><?php cancha_honeypot(); ?>
    <label class="auth-check"><input type="checkbox" name="terms_accept" required value="1"> Acepto los <a href="<?php echo esc_url(cancha_terms_url()); ?>">términos actualizados</a></label>
    <button type="submit" class="button primary">Aceptar términos</button></form></div>
    <?php return ob_get_clean();
}
function cancha_my_panel_html() {
    if (!is_user_logged_in()) return '<div class="glass content-panel"><h1>Mi panel</h1><p>Necesitás ingresar para ver tu actividad y participar en predicciones.</p><a class="button primary" href="' . esc_url(add_query_arg('redirect_to', cancha_route('mi-panel'), cancha_auth_url('ingresar'))) . '">Ingresar</a></div>';
    $user = wp_get_current_user();
    ob_start(); ?>
    <section class="glass content-panel"><div class="eyebrow">MI CANCHA</div><h1>Hola, <?php echo esc_html($user->display_name ?: $user->user_login); ?></h1>
    <div class="user-profile-line"><?php echo get_avatar($user->ID, 64); ?><div><strong><?php echo esc_html($user->user_login); ?></strong><small><?php echo esc_html($user->user_email); ?></small></div><a class="button ghost" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Cerrar sesión</a></div>
    <?php echo cancha_terms_accept_form(); ?>
    <?php if (cancha_google_configured()): ?><div class="google-link-area"><h3>Mi acceso con Google</h3><?php echo get_user_meta($user->ID,'cancha_google_sub',true) ? '<p>Google ya está vinculado a esta cuenta.</p>' : cancha_google_button('link'); ?></div><?php endif; ?>
    <div class="dashboard-grid"><section class="glass feature-card"><span class="eyebrow">MIS PREDICCIONES</span><h2>Historial</h2><?php cancha_render_module('usuario_predicciones', 'Tus pronósticos se mostrarán cuando instalemos el motor de predicciones.'); ?></section>
    <section class="glass feature-card"><span class="eyebrow">PUNTOS</span><h2>Mi posición</h2><?php cancha_render_module('usuario_ranking', 'Tu puntaje aparecerá cuando conectemos GamiPress.'); ?></section>
    <section class="glass feature-card"><span class="eyebrow">ACTIVIDAD</span><h2>Notificaciones</h2><?php cancha_render_module('usuario_avisos', 'Las notificaciones se incorporarán con el módulo correspondiente.'); ?></section></div>
    <p><a href="<?php echo esc_url(admin_url('profile.php')); ?>">Editar mi perfil de WordPress</a></p></section>
    <?php return ob_get_clean();
}
add_shortcode('cancha_login', function ($a) { $a = shortcode_atts(['redirect_to' => ''], $a); return cancha_login_html($a['redirect_to']); });
add_shortcode('cancha_registro', 'cancha_register_html');
add_shortcode('cancha_terminos', 'cancha_terms_html');
add_shortcode('cancha_mi_panel', 'cancha_my_panel_html');
/** Surface CANCHA's registration metadata in WordPress' built-in personal-data export. */
add_filter('wp_privacy_personal_data_exporters', function ($exporters) {
    $exporters['cancha-registration'] = ['exporter_friendly_name' => 'CANCHA · Registro y consentimiento', 'callback' => 'cancha_export_personal_data'];
    return $exporters;
});
function cancha_export_personal_data($email_address, $page = 1) {
    if ($page > 1) return ['data' => [], 'done' => true];
    $user = get_user_by('email', $email_address);
    if (!$user) return ['data' => [], 'done' => true];
    $items = [];
    foreach ([
        'cancha_email_verified' => 'Estado de verificación',
        'cancha_terms_version' => 'Versión de términos aceptados',
        'cancha_terms_accepted_utc' => 'Fecha UTC de aceptación',
        'cancha_terms_source' => 'Origen de aceptación',
        'cancha_google_sub' => 'Cuenta vinculada con Google (ID interno)',
    ] as $key => $label) {
        $value = get_user_meta($user->ID, $key, true);
        if ($value !== '') $items[] = ['name' => $label, 'value' => (string) $value];
    }
    if (!$items) return ['data' => [], 'done' => true];
    return ['data' => [[
        'group_id' => 'cancha-registration', 'group_label' => 'CANCHA · Registro',
        'item_id' => 'cancha-user-' . $user->ID, 'data' => $items,
    ]], 'done' => true];
}
