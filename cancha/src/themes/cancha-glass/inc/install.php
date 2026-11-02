<?php
/** Non-destructive initial setup; do not modify user's homepage or existing menus. */
defined('ABSPATH') || exit;
function cancha_install_pages() {
    $pages = [
       'campeonatos'=>'Campeonatos',
       'futbol-oficial'=>'Fútbol oficial',
       'calendario'=>'Calendario y partidos',
       'partidos'=>'Partidos',
       'amateur'=>'Fútbol amateur',
       'predicciones'=>'Predicciones',
       'ranking'=>'Ranking',
       'mi-panel'=>'Mi panel',
       'novedades'=>'Novedades',
       'buscar'=>'Buscar',
    ];
    foreach ($pages as $slug => $title) {
        if (get_page_by_path($slug)) continue;
        wp_insert_post([
            'post_type'=>'page', 'post_status'=>'publish', 'post_title'=>$title,
            'post_name'=>$slug, 'post_content'=>'', 'post_author'=>get_current_user_id(),
            'comment_status'=>'closed', 'ping_status'=>'closed',
        ]);
    }
}
add_action('after_switch_theme', function () {
    cancha_install_pages();
    // Register before flushing; init ran before after_switch_theme in the usual WordPress cycle.
    flush_rewrite_rules(false);
    update_option('cancha_theme_installed', CANCHA_VERSION, false);
});
add_action('admin_init', function () {
    // Refresh routes after a normal theme ZIP update without flushing every request.
    if (get_option('cancha_theme_installed') !== CANCHA_VERSION && current_user_can('manage_options')) {
        cancha_install_pages();
        flush_rewrite_rules(false);
        update_option('cancha_theme_installed', CANCHA_VERSION, false);
    }
});
