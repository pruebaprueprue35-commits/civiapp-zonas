<?php
/** CANCHA Glass bootstrap. */
defined('ABSPATH') || exit;
define('CANCHA_VERSION', '1.4.1');
foreach (['core', 'sponsors', 'auth', 'api', 'pwa', 'google', 'ui', 'news', 'install', 'ligas', 'matchday'] as $part) {
    require_once get_template_directory() . '/inc/' . $part . '.php';
}
add_action('after_setup_theme', function () {
    load_theme_textdomain('cancha', get_template_directory() . '/languages');
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo', ['height' => 88, 'width' => 280, 'flex-height' => true, 'flex-width' => true]);
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('responsive-embeds');
    add_theme_support('align-wide');
    register_nav_menus(['primary' => 'Navegación principal', 'footer' => 'Navegación del pie']);
});
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('cancha-glass', get_template_directory_uri() . '/assets/css/theme.css', [], CANCHA_VERSION);
    wp_enqueue_script('cancha-glass', get_template_directory_uri() . '/assets/js/theme.js', [], CANCHA_VERSION, true);
    wp_enqueue_style('cancha-ligas', get_template_directory_uri() . '/assets/css/ligas.css', ['cancha-glass'], CANCHA_VERSION);
    wp_enqueue_script('cancha-ligas', get_template_directory_uri() . '/assets/js/ligas.js', [], CANCHA_VERSION, true);
    wp_enqueue_style('cancha-v141', get_template_directory_uri() . '/assets/css/cancha-141.css', ['cancha-ligas'], CANCHA_VERSION);
    $pwa = cancha_pwa_enabled();
    wp_add_inline_script('cancha-glass', 'window.canchaUI = ' . wp_json_encode([
        'pwa' => $pwa,
        'sw' => esc_url_raw(home_url('/cancha-sw.js')),
        'scope' => wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/',
        'installText' => 'Para instalar CANCHA en iPhone, usá Compartir → Agregar a inicio.',
        'defaultTheme' => cancha_ui_setting('default_color_mode','light'),
    ]) . ';', 'before');
});
function cancha_route($slug) {
    $page = get_page_by_path($slug);
    return $page ? get_permalink($page) : home_url('/' . trim($slug, '/') . '/');
}
function cancha_render_module($module, $message = '') {
    $name = 'cancha_render_' . sanitize_key($module);
    if (has_action($name)) {
        do_action($name);
        return;
    }
    echo '<div class="module-empty"><span class="module-dot" aria-hidden="true"></span>' . esc_html($message ?: 'Esta sección estará disponible al conectar su complemento.') . '</div>';
}
foreach (['campeonatos', 'proximos_partidos', 'futbol_oficial', 'calendario', 'predicciones', 'ranking', 'noticias', 'actividad'] as $module) {
    if (!shortcode_exists('cancha_' . $module)) add_shortcode('cancha_' . $module, static function () use ($module) {
        ob_start(); cancha_render_module($module); return ob_get_clean();
    });
}
add_filter('body_class', function ($classes) {
    if (is_user_logged_in()) $classes[] = 'cancha-authenticated';
    return $classes;
});

// La barra de herramientas de WordPress es opcional para suscriptores sin permisos editoriales.
add_filter('show_admin_bar', function ($show) {
    $s = cancha_core_settings();
    return !empty($s['hide_subscriber_admin_bar']) && is_user_logged_in() && !current_user_can('edit_posts') ? false : $show;
}, 40);

// Search only CANCHA public profiles and editorial posts (never private records).
add_action('pre_get_posts',function($q){if(!is_admin() && $q->is_main_query() && $q->is_search()){ $q->set('post_type',['post','cancha_campeonato','cancha_equipo','cancha_jugador','cancha_staff']);$q->set('post_status','publish');$q->set('posts_per_page',16); }});

// Front-end toolbar control. WordPress dashboard remains available to authorized staff.
add_filter('show_admin_bar',function($show){
 if(is_admin())return $show;
 $mode=cancha_ui_setting('hide_frontend_toolbar','all');
 if($mode==='all')return false;
 if($mode==='subscribers' && is_user_logged_in() && !current_user_can('edit_posts'))return false;
 return $show;
},9999);
