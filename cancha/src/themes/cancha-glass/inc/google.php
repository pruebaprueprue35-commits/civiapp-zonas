<?php
/** CANCHA native Google OpenID Connect (authorization code + PKCE + bound state). */
defined('ABSPATH') || exit;
require_once __DIR__ . '/google-verify.php';
function cancha_google_id() {
    if (defined('CANCHA_GOOGLE_CLIENT_ID') && CANCHA_GOOGLE_CLIENT_ID) return (string) CANCHA_GOOGLE_CLIENT_ID;
    return (string) (cancha_core_settings()['google_client_id'] ?? '');
}
function cancha_google_secret() {
    if (defined('CANCHA_GOOGLE_CLIENT_SECRET') && CANCHA_GOOGLE_CLIENT_SECRET) return (string) CANCHA_GOOGLE_CLIENT_SECRET;
    return cancha_decrypt_secret(cancha_core_settings()['google_secret_encrypted'] ?? '');
}
function cancha_google_configured() { return is_ssl() && cancha_google_id() && cancha_google_secret(); }
function cancha_google_redirect_uri() { return home_url('/cancha-google/'); }
add_action('init', static function () { add_rewrite_rule('^cancha-google/?$', 'index.php?cancha_google=1', 'top'); });
add_filter('query_vars', static function($vars) { $vars[]='cancha_google'; return $vars; });
function cancha_google_error($message, $route='ingresar') {
    $token=bin2hex(random_bytes(12));
    set_transient('cancha_google_error_'.$token, sanitize_text_field($message), 4*MINUTE_IN_SECONDS);
    wp_safe_redirect(add_query_arg('google_notice',$token,cancha_auth_url($route))); exit;
}
function cancha_google_notice() {
    $token=sanitize_text_field(wp_unslash($_GET['google_notice']??''));
    if(!preg_match('/^[a-f0-9]{24}$/',$token))return '';
    $key='cancha_google_error_'.$token;$message=get_transient($key);delete_transient($key);
    return $message ? '<p role="alert" class="auth-alert">'.esc_html($message).'</p>' : '';
}
function cancha_google_button($intent='login') {
    if (!cancha_google_configured()) return '';
    if ($intent==='register' && !cancha_terms_ready()) return '';
    ob_start(); ?>
    <form class="cancha-google-form" method="post" action="<?php echo esc_url(cancha_google_redirect_uri()); ?>">
      <?php wp_nonce_field('cancha_google_begin','cancha_google_nonce'); ?>
      <input type="hidden" name="cancha_google_action" value="begin"><input type="hidden" name="intent" value="<?php echo esc_attr($intent); ?>">
      <?php if ($intent==='register'): ?><label class="auth-check"><input name="google_terms_accept" type="checkbox" required value="1"><span>Leí y acepto los <a href="<?php echo esc_url(cancha_terms_url()); ?>" target="_blank" rel="noopener noreferrer">términos y condiciones</a> <?php if(get_privacy_policy_url()): ?>y conozco la <a href="<?php echo esc_url(get_privacy_policy_url()); ?>" target="_blank" rel="noopener noreferrer">política de privacidad</a><?php endif; ?>.</span></label><?php endif; ?>
      <button class="google-button" type="submit"><span class="google-logo" aria-hidden="true">G</span><?php echo $intent==='link' ? 'Vincular mi cuenta de Google' : ($intent==='register' ? 'Registrarme con Google' : 'Ingresar con Google'); ?></button>
    </form><?php return ob_get_clean();
}
function cancha_google_start() {
    if (!cancha_google_configured()) cancha_google_error('Configurá Google en CANCHA y activá HTTPS.');
    if (!isset($_POST['cancha_google_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cancha_google_nonce'])),'cancha_google_begin')) cancha_google_error('Sesión caducada: volvé a intentarlo.');
    if (cancha_throttle('google-start','',12,15*MINUTE_IN_SECONDS)) cancha_google_error('Demasiados intentos. Volvé a probar más tarde.');
    $intent=sanitize_key(wp_unslash($_POST['intent']??'login'));
    if (!in_array($intent,['register','login','link'],true)) cancha_google_error('Acción incorrecta.');
    if ($intent==='register' && (!cancha_terms_ready() || empty($_POST['google_terms_accept']))) cancha_google_error('Antes de registrarte con Google debés aceptar los términos publicados.','registro');
    if ($intent==='link' && !is_user_logged_in()) cancha_google_error('Primero ingresá a tu cuenta.');
    $state=bin2hex(random_bytes(24));$browser=bin2hex(random_bytes(24));$nonce=bin2hex(random_bytes(24));$verifier=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    set_transient('cancha_oidc_'.$state,[
        'browser'=>hash('sha256',$browser), 'nonce'=>$nonce,'verifier'=>$verifier,'intent'=>$intent,
        'uid'=>$intent==='link'?get_current_user_id():0,'terms_version'=>cancha_core_settings()['terms_version'],
        'terms_sha'=>cancha_terms_fingerprint(),
    ],10*MINUTE_IN_SECONDS);
    setcookie('cancha_google_browser',$browser,['expires'=>time()+600,'path'=>wp_parse_url(home_url('/'),PHP_URL_PATH)?:'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    $params=[
        'client_id'=>cancha_google_id(),'redirect_uri'=>cancha_google_redirect_uri(), 'response_type'=>'code',
        'scope'=>'openid email profile','state'=>$state,'nonce'=>$nonce,
        'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256',
        'prompt'=>'select_account',
    ];
    wp_redirect(add_query_arg($params,'https://accounts.google.com/o/oauth2/v2/auth'),302,'CANCHA');exit;
}
function cancha_google_callback() {
    nocache_headers();
    $state=sanitize_text_field(wp_unslash($_GET['state']??''));
    if(!preg_match('/^[a-f0-9]{48}$/',$state)) cancha_google_error('La autorización de Google no pudo validarse.');
    $key='cancha_oidc_'.$state;$data=get_transient($key);delete_transient($key);
    $browser=(string)($_COOKIE['cancha_google_browser']??'');
    setcookie('cancha_google_browser','',['expires'=>time()-3600,'path'=>wp_parse_url(home_url('/'),PHP_URL_PATH)?:'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    if (!is_array($data) || !hash_equals($data['browser'],hash('sha256',$browser))) cancha_google_error('La sesión de Google venció o no coincide con este navegador.');
    if(isset($_GET['error'])) cancha_google_error('El ingreso con Google fue cancelado.');
    $code=(string)wp_unslash($_GET['code']??'');
    if (!$code || strlen($code)>4096) cancha_google_error('Google no devolvió un código válido.');
    $response=wp_safe_remote_post('https://oauth2.googleapis.com/token',[
        'timeout'=>15, 'redirection'=>0,
        'body'=>['code'=>$code,'client_id'=>cancha_google_id(),'client_secret'=>cancha_google_secret(),
            'redirect_uri'=>cancha_google_redirect_uri(),'grant_type'=>'authorization_code','code_verifier'=>$data['verifier']],
    ]);
    if(is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) cancha_google_error('No se pudo verificar el acceso con Google.');
    $tokens=json_decode(wp_remote_retrieve_body($response),true);
    if(!is_array($tokens) || empty($tokens['id_token'])) cancha_google_error('Google no devolvió una identidad verificable.');
    // Validate the RSA signature locally against Google-issued, cached X.509 certificates.
    $claims=cancha_google_verify_jwt((string)$tokens['id_token'],(string)$data['nonce'],cancha_google_id());
    if(is_wp_error($claims)) cancha_google_error('No se pudo verificar de forma segura tu identidad de Google.');
    $sub=(string)$claims['sub'];$email=sanitize_email($claims['email']);$intent=$data['intent'];
    $found=get_users(['meta_key'=>'cancha_google_sub','meta_value'=>$sub,'number'=>2,'fields'=>'ID']);
    if(count($found)>1) cancha_google_error('Hay una asociación duplicada. Contactá al administrador.');
    if($intent==='link') {
        $uid=absint($data['uid']);
        if(!is_user_logged_in() || get_current_user_id()!==$uid) cancha_google_error('Necesitás iniciar sesión nuevamente.');
        $account=get_userdata($uid);
        if (!$account || !hash_equals(strtolower($account->user_email),strtolower($email)) || ($found && (int)$found[0]!==$uid)) cancha_google_error('La cuenta de Google debe tener el mismo correo y no estar vinculada a otro usuario.');
        update_user_meta($uid,'cancha_google_sub',$sub);wp_safe_redirect(cancha_route('mi-panel'));exit;
    }
    if ($found) {
        $uid=(int)$found[0];
        $user=get_userdata($uid);
        if(!$user || strtolower($user->user_email)!==strtolower($email)) cancha_google_error('La asociación de Google necesita revisión.');
    } else {
        if($intent!=='register') cancha_google_error('No existe una cuenta de Google vinculada. Elegí «Registrarme con Google» y aceptá los términos.','registro');
        if(!cancha_terms_ready() || $data['terms_version']!==cancha_core_settings()['terms_version'] || $data['terms_sha']!==cancha_terms_fingerprint()) cancha_google_error('Los términos cambiaron. Revisalos nuevamente.','registro');
        if(get_user_by('email',$email)) cancha_google_error('Ya existe una cuenta con ese correo. Ingresá con contraseña y vinculá Google desde Mi panel.');
        $username=sanitize_user('cancha_'.substr($sub,-12),true);
        if(username_exists($username)) $username=sanitize_user('cancha_'.substr(bin2hex(random_bytes(8)),0,12),true);
        $uid=wp_insert_user(['user_login'=>$username,'user_email'=>$email,'user_pass'=>wp_generate_password(48,true,true),'role'=>'subscriber','display_name'=>sanitize_text_field($claims['name']??$username)]);
        if(is_wp_error($uid)) cancha_google_error('No se pudo crear la cuenta.');
        update_user_meta($uid,'cancha_google_sub',$sub);
        update_user_meta($uid,'cancha_email_verified','yes');
        update_user_meta($uid,'cancha_terms_version',$data['terms_version']);
        update_user_meta($uid,'cancha_terms_sha256',$data['terms_sha']);
        update_user_meta($uid,'cancha_terms_accepted_utc',gmdate('Y-m-d H:i:s'));
        update_user_meta($uid,'cancha_terms_source','google-oauth-consent');
    }
    if (get_user_meta($uid,'cancha_email_verified',true)==='pending') cancha_google_error('Verificá primero tu cuenta de CANCHA por correo.');
    wp_set_current_user($uid);wp_set_auth_cookie($uid,true,is_ssl());do_action('wp_login',get_userdata($uid)->user_login,get_userdata($uid));
    wp_safe_redirect(cancha_route('mi-panel'));exit;
}
add_action('template_redirect', static function(){
    if(!get_query_var('cancha_google'))return;
    if(!is_ssl())cancha_google_error('Google requiere HTTPS.');
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && ($_POST['cancha_google_action']??'')==='begin')cancha_google_start();
    if(($_SERVER['REQUEST_METHOD']??'')==='GET')cancha_google_callback();
    cancha_google_error('Solicitud no permitida.');
},0);
