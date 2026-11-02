<?php
/**
 * Plugin Name: CANCHA · Puesta a punto
 * Description: Respaldo descargable del tema y complementos CANCHA y datos DEMO marcados para QA (solo administradores). Aditivo y reversible: desactivar no borra nada.
 * Version: 0.2.0
 * Author: CANCHA
 * Requires PHP: 8.0
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_management_page('Respaldo CANCHA', 'Respaldo CANCHA', 'manage_options', 'cancha-respaldo', 'cpap_backup_page');
});

function cpap_backup_page() {
    if (!current_user_can('manage_options')) wp_die('Sin permiso');
    $url = wp_nonce_url(admin_url('admin-post.php?action=cpap_backup'), 'cpap_backup');
    echo '<div class="wrap"><h1>Respaldo CANCHA</h1><p>Descarga un ZIP con el tema cancha-glass, los complementos cancha-* y las opciones CANCHA (JSON). No modifica nada.</p>';
    echo '<p><a class="button button-primary" id="cpap-backup" href="' . esc_url($url) . '">Descargar respaldo</a></p>';
    $n = count(get_posts(['post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_cancha_qa_demo', 'meta_value' => '1']));
    echo '<hr><h2>Datos DEMO de amateur (QA)</h2><p>Sobre el campeonato cuyo título contiene «CANCHA DEMO»: asigna zonas a sus equipos DEMO que no tengan zona, agrega una jornada futura y semifinales DEMO de Copa Oro y Copa Plata. Todo queda marcado con <code>_cancha_qa_demo</code>. «Retirar» pasa a borrador solo esos partidos (no borra nada).</p><p>Partidos DEMO creados por esta herramienta: <strong id="cpap-demo-count">' . (int)$n . '</strong></p>';
    echo '<p><a class="button" id="cpap-demo" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpap_demo'), 'cpap_demo')) . '">Crear datos DEMO</a> <a class="button" id="cpap-demo-off" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpap_demo_off'), 'cpap_demo_off')) . '">Retirar datos DEMO (a borrador)</a></p>';
    if (isset($_GET['cpap'])) echo '<div class="notice notice-info"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['cpap']))) . '</p></div>';
    echo '</div>';
}

add_action('admin_post_cpap_backup', function () {
    if (!current_user_can('manage_options')) wp_die('Sin permiso', 403);
    check_admin_referer('cpap_backup');
    if (!class_exists('ZipArchive')) wp_die('ZipArchive no disponible');
    $tmp = wp_tempnam('cancha-respaldo');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $roots = [get_theme_root() . '/cancha-glass' => 'themes/cancha-glass'];
    foreach (glob(WP_PLUGIN_DIR . '/cancha*', GLOB_ONLYDIR) as $d) $roots[$d] = 'plugins/' . basename($d);
    foreach ($roots as $dir => $prefix) {
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getSize() > 5 * MB_IN_BYTES) continue;
            $zip->addFile($f->getPathname(), $prefix . '/' . substr($f->getPathname(), strlen($dir) + 1));
        }
    }
    global $wpdb;
    $rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'cancha%' OR option_name IN ('users_can_register','default_role','show_on_front','page_on_front','page_for_posts','permalink_structure','timezone_string','stylesheet','template','active_plugins','wp_page_for_privacy_policy')", ARRAY_A);
    $out = [];
    foreach ($rows as $r) {
        // no exportar secretos cifrados
        $v = maybe_unserialize($r['option_value']);
        if (is_array($v)) foreach ($v as $k => $_) if (preg_match('/key|secret|token|pass/i', (string)$k)) $v[$k] = '[omitido]';
        if (preg_match('/key|secret|token|pass/i', $r['option_name'])) $v = '[omitido]';
        $out[$r['option_name']] = $v;
    }
    $menus = [];
    foreach (wp_get_nav_menus() as $m) $menus[$m->name] = array_map(fn($i) => ['title' => $i->title, 'url' => $i->url, 'parent' => $i->menu_item_parent, 'id' => $i->ID], wp_get_nav_menu_items($m->term_id) ?: []);
    $out['__nav_menus'] = $menus;
    $out['__menu_locations'] = get_nav_menu_locations();
    $zip->addFromString('opciones-cancha.json', wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->close();
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="cancha-respaldo-' . gmdate('Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
});

add_action('admin_post_cpap_demo', function () {
    if (!current_user_can('manage_options')) wp_die('Sin permiso', 403);
    check_admin_referer('cpap_demo');
    $back = admin_url('tools.php?page=cancha-respaldo');
    $comp = null;
    foreach (get_posts(['post_type' => 'cancha_campeonato', 'post_status' => 'publish', 'numberposts' => 50]) as $c) if (stripos($c->post_title, 'CANCHA DEMO') !== false) { $comp = $c; break; }
    if (!$comp) { wp_safe_redirect(add_query_arg('cpap', 'No se encontró el campeonato CANCHA DEMO.', $back)); exit; }
    $teams = array_values(array_filter(cancha_comp_teams($comp->ID), fn($t) => stripos($t->post_title, 'DEMO') !== false));
    if (count($teams) < 8) { wp_safe_redirect(add_query_arg('cpap', 'El torneo DEMO necesita al menos 8 equipos DEMO.', $back)); exit; }
    if (get_posts(['post_type' => 'cancha_partido', 'post_status' => 'any', 'numberposts' => 1, 'meta_key' => '_cancha_qa_demo', 'meta_value' => '1'])) { wp_safe_redirect(add_query_arg('cpap', 'Los datos DEMO ya existen; no se duplicaron.', $back)); exit; }
    $zones = ['A', 'B', 'C', 'D']; $byzone = [];
    foreach ($teams as $i => $t) {
        $z = (string)get_post_meta($t->ID, '_cancha_zone', true);
        if ($z === '') { $z = $zones[$i % 4]; update_post_meta($t->ID, '_cancha_zone', $z); update_post_meta($t->ID, '_cancha_qa_demo_zone', '1'); }
        $byzone[$z][] = $t->ID;
    }
    $max = 0; foreach (cancha_comp_matches($comp->ID) as $m) $max = max($max, absint(get_post_meta($m->ID, '_cancha_round', true)));
    $round = $max + 1; $now = new DateTimeImmutable('now', wp_timezone());
    $mk = function ($h, $a, $start, $extra = []) use ($comp, $round) {
        $id = wp_insert_post(['post_type' => 'cancha_partido', 'post_status' => 'publish', 'post_title' => get_the_title($h) . ' vs ' . get_the_title($a) . ' (DEMO QA)']);
        foreach (array_merge(['_cancha_comp_id' => $comp->ID, '_cancha_home' => $h, '_cancha_away' => $a, '_cancha_round' => $round, '_cancha_start' => $start, '_cancha_result_status' => 'scheduled', '_cancha_qa_demo' => '1'], $extra) as $k => $v) update_post_meta($id, $k, $v);
        return $id;
    };
    $n = 0; $slot = 0;
    foreach ($byzone as $z => $ids) {
        for ($i = 0; $i + 1 < count($ids) && $i < 4; $i += 2) {
            $mk($ids[$i], $ids[$i + 1], $now->modify('+' . (2 + intdiv($slot, 4)) . ' days')->format('Y-m-d') . 'T' . ['15:00', '16:30', '18:00', '19:30'][$slot % 4]); $n++; $slot++;
        }
    }
    $z = array_keys($byzone); $ko = $now->modify('+9 days')->format('Y-m-d');
    if (count($z) >= 2) {
        $A = $byzone[$z[0]]; $B = $byzone[$z[1]];
        $mk($A[0], $B[1], $ko . 'T17:00', ['_cancha_stage' => 'ko', '_cancha_cup' => 'oro', '_cancha_ko_round' => 'semifinal']);
        $mk($B[0], $A[1], $ko . 'T18:30', ['_cancha_stage' => 'ko', '_cancha_cup' => 'oro', '_cancha_ko_round' => 'semifinal']);
        if (count($A) > 3 && count($B) > 3) {
            $mk($A[2], $B[3], $ko . 'T15:00', ['_cancha_stage' => 'ko', '_cancha_cup' => 'plata', '_cancha_ko_round' => 'semifinal']);
            $mk($B[2], $A[3], $ko . 'T16:00', ['_cancha_stage' => 'ko', '_cancha_cup' => 'plata', '_cancha_ko_round' => 'semifinal']); $n += 2;
        }
        $n += 2;
    }
    wp_safe_redirect(add_query_arg('cpap', "Creados $n partidos DEMO (jornada $round y semifinales) y zonas asignadas.", $back)); exit;
});
add_action('admin_post_cpap_demo_off', function () {
    if (!current_user_can('manage_options')) wp_die('Sin permiso', 403);
    check_admin_referer('cpap_demo_off');
    $n = 0;
    foreach (get_posts(['post_type' => 'cancha_partido', 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_cancha_qa_demo', 'meta_value' => '1']) as $p) { wp_update_post(['ID' => $p->ID, 'post_status' => 'draft']); $n++; }
    wp_safe_redirect(add_query_arg('cpap', "$n partidos DEMO pasados a borrador.", admin_url('tools.php?page=cancha-respaldo'))); exit;
});
