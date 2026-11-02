<?php
/**
 * Plugin Name: CANCHA Fútbol Oficial
 * Description: Resultados, fixture y posiciones de torneos oficiales en CANCHA mediante API-Football o TheSportsDB. No modifica campeonatos amateur.
 * Version: 1.2.3
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: CANCHA
 * License: GPL-2.0-or-later
 * Text Domain: cancha-oficial
 */
if (!defined('ABSPATH')) exit;
define('COF_VERSION', '1.2.3');
define('COF_PATH', plugin_dir_path(__FILE__));
define('COF_URL', plugin_dir_url(__FILE__));
require_once COF_PATH . 'includes/settings.php';
require_once COF_PATH . 'includes/providers.php';
require_once COF_PATH . 'includes/calendar.php';
require_once COF_PATH . 'includes/render.php';

add_action('admin_menu', 'cof_register_menu', 45);
add_action('admin_init', 'cof_settings_register');
add_action('admin_post_cof_probe', 'cof_admin_probe');
add_action('admin_post_cof_clear', 'cof_admin_clear');
// Lightweight periodic synchronization: only configured leagues, never changes local match records.
register_activation_hook(__FILE__, function(){
    if (!wp_next_scheduled('cof_refresh_event')) wp_schedule_event(time()+180, 'twicedaily', 'cof_refresh_event');
});
register_deactivation_hook(__FILE__, function(){wp_clear_scheduled_hook('cof_refresh_event');});
add_action('cof_refresh_event', function(){
    foreach(cof_competitions() as $code=>$name) {
        if (!cof_provider_id($code))continue;
        if (cof_settings()['provider']==='api-football' && !cof_key())break;
        if (cof_settings()['provider']==='thesportsdb') cof_tsdb_window($code,true);
        cof_get_fixtures($code);
        cof_get_standings($code);
    }
});
add_shortcode('cancha_oficial', 'cof_shortcode');
add_shortcode('cancha_oficial_competicion', 'cof_shortcode_competition');
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('cancha-futbol-oficial', COF_URL.'assets/oficial.css', [], COF_VERSION);
    wp_enqueue_script('cancha-futbol-oficial', COF_URL.'assets/oficial.js', [], COF_VERSION, true);
    $connected=[];foreach(cof_competitions() as $comp=>$name)$connected[$comp]=cof_provider_id($comp)>0 && (cof_settings()['provider']==='thesportsdb' || (bool)cof_key());
    wp_add_inline_script('cancha-futbol-oficial','window.canchaOfficialConnected = '.wp_json_encode($connected).';','before');
});
add_action('admin_enqueue_scripts', function($hook) {
    if (isset($_GET['page']) && $_GET['page']==='cancha-oficial') {
        wp_enqueue_style('cancha-futbol-oficial-admin', COF_URL.'assets/oficial-admin.css', [], COF_VERSION);
    }
});
// Only replace the official-view callback installed by CANCHA Glass, never other callbacks.
function cof_attach_cancha_theme() {
    global $wp_filter;
    $hook='cancha_render_futbol_oficial';
    if (!empty($wp_filter[$hook]) && isset($wp_filter[$hook]->callbacks)) {
        foreach ($wp_filter[$hook]->callbacks as $priority=>$callbacks) {
            foreach ($callbacks as $entry) {
                $callable=$entry['function']??null;
                if (!($callable instanceof Closure)) continue;
                try {
                    $r=new ReflectionFunction($callable);
                    $file=str_replace('\\','/', (string)$r->getFileName());
                    if (substr($file,-25)==='/cancha-glass/inc/api.php') {
                        remove_action($hook,$callable,$priority);
                    }
                } catch (ReflectionException $e) { /* Leave third-party callbacks unchanged. */ }
            }
        }
    }
    add_action($hook, 'cof_echo_module', 9);
}
add_action('init','cof_attach_cancha_theme',100);
function cof_echo_module(){echo cof_shortcode([]);}

// Opt-in data adapter for other CANCHA modules; read-only, never updates amateur matches.
function cancha_oficial_get_matches($competition='liga_profesional') {
    if (!array_key_exists($competition, cof_competitions())) return new WP_Error('invalid_competition','Competición no reconocida.');
    return cof_get_fixtures($competition);
}
add_filter('plugin_action_links_'.plugin_basename(__FILE__),function($links){
    array_unshift($links, '<a href="'.esc_url(admin_url('admin.php?page=cancha-oficial')).'">Configurar</a>');
    return $links;
});
