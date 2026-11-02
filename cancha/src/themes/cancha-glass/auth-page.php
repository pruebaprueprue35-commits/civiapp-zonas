<?php
/** Custom public routes (no pages needed). */
defined('ABSPATH') || exit;
get_header();
$route = get_query_var('cancha_auth');
switch ($route) {
    case 'registro': echo cancha_register_html(); break;
    case 'terminos': echo cancha_terms_html(); break;
    case 'activar':
        echo '<section class="glass auth-card"><div class="eyebrow">VERIFICACIÓN</div><h1>Confirmar tu cuenta</h1>';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            echo '<p role="status">' . esc_html(cancha_auth_activate_token()) . '</p><a class="button primary" href="' . esc_url(cancha_auth_url('ingresar')) . '">Ingresar</a>';
        } else {
            $uid = absint($_GET['uid'] ?? 0);
            $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
            if ($uid && preg_match('/^[a-f0-9]{64}$/', $token)) {
                echo '<p>Confirmá que querés activar esta cuenta en CANCHA.</p><form class="auth-form" method="post" action="' . esc_url(cancha_auth_url('activar')) . '">';
                wp_nonce_field('cancha_activate_' . $uid, 'cancha_nonce');
                echo '<input type="hidden" name="uid" value="' . esc_attr($uid) . '"><input type="hidden" name="token" value="' . esc_attr($token) . '"><button type="submit" class="button primary">Confirmar cuenta</button></form>';
            } else echo '<p>El enlace recibido no es válido.</p>';
        }
        echo '<p><a href="' . esc_url(cancha_auth_url('reenviar-verificacion')) . '">Pedir otro enlace</a></p></section>';
        break;
    case 'reenviar-verificacion': echo cancha_resend_html(); break;
    default: echo cancha_login_html(wp_validate_redirect(esc_url_raw(wp_unslash($_GET['redirect_to'] ?? '')), cancha_route('mi-panel')));
}
get_footer();
