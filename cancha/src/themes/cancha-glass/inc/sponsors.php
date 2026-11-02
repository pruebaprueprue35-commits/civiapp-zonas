<?php
/** Admin-only sponsors, date filtering and safe public output. */
defined('ABSPATH') || exit;
add_action('init', function () {
    register_post_type('cancha_sponsor', [
        'labels' => ['name' => 'Sponsors', 'singular_name' => 'Sponsor', 'add_new_item' => 'Agregar sponsor', 'edit_item' => 'Editar sponsor', 'menu_name' => 'Sponsors'],
        'public' => false, 'publicly_queryable' => false, 'show_ui' => true, 'show_in_menu' => 'cancha-core',
        'exclude_from_search' => true, 'supports' => ['title', 'thumbnail', 'page-attributes'],
        'map_meta_cap' => false,
        'capabilities' => array_fill_keys([
            'edit_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts',
            'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_private_posts',
            'delete_published_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts',
        ], 'manage_options') + ['read_post' => 'read'],
        'menu_icon' => 'dashicons-megaphone',
    ]);
});
function cancha_sponsor_scopes() {
    $scopes = [
        'general'=>'General · banda destacada (todas las páginas)',
        'home_top'=>'Inicio · arriba del contenido',
        'home_mid'=>'Inicio · centro (antes de secciones)',
        'home_bottom'=>'Inicio · abajo de las secciones',
        'footer'=>'Pie de página · todas las páginas',
    ];
    if (post_type_exists('cancha_campeonato')) {
        foreach (get_posts(['post_type'=>'cancha_campeonato','post_status'=>'publish','posts_per_page'=>200,'orderby'=>'title','order'=>'ASC']) as $tournament) {
            $scopes['torneo:'.$tournament->post_name] = 'Campeonato · '.$tournament->post_title;
        }
    }
    return $scopes;
}
add_action('add_meta_boxes_cancha_sponsor', function () {
    add_meta_box('cancha_sponsor_details', 'Ubicación, enlace y vigencia', 'cancha_sponsor_metabox', 'cancha_sponsor', 'normal', 'high');
});
function cancha_sponsor_metabox($post) {
    wp_nonce_field('cancha_save_sponsor', 'cancha_sponsor_nonce');
    $scope = get_post_meta($post->ID, '_cancha_scope', true) ?: 'general';
    $url = get_post_meta($post->ID, '_cancha_url', true);
    $from = get_post_meta($post->ID, '_cancha_from', true);
    $to = get_post_meta($post->ID, '_cancha_to', true);
    echo '<p><label for="cancha_scope"><strong>Ubicación del sponsor</strong></label><br><select id="cancha_scope" class="widefat" name="cancha_scope">';
    $options=cancha_sponsor_scopes();
    if (!isset($options[$scope]) && preg_match('/^torneo:[a-z0-9-]+$/',$scope)) $options[$scope]='Campeonato anterior · '.substr($scope,7);
    foreach ($options as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($scope,$value,false).'>'.esc_html($label).'</option>';
    echo '</select><small>Seleccioná una ubicación. Los campeonatos publicados aparecen automáticamente si está activo el complemento CANCHA Campeonatos.</small></p>';
    echo '<p><label><strong>Enlace HTTPS (opcional)</strong><br><input type="url" class="widefat" name="cancha_url" value="' . esc_attr($url) . '" placeholder="https://sponsor.example"></label></p>';
    echo '<p><label>Desde <input type="date" name="cancha_from" value="' . esc_attr($from) . '"></label> &nbsp; <label>Hasta <input type="date" name="cancha_to" value="' . esc_attr($to) . '"></label></p>';
    echo '<p><strong>Logo:</strong> configurá la imagen destacada a la derecha. Solo sponsors publicados y dentro de vigencia se muestran.</p><p><strong>Orden:</strong> usá el campo Orden en Atributos de página (menor número primero).</p>';
}
function cancha_valid_date($date) {
    if ($date === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date ? $date : '';
}
add_action('save_post_cancha_sponsor', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id) || !current_user_can('manage_options')) return;
    if (!isset($_POST['cancha_sponsor_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cancha_sponsor_nonce'])), 'cancha_save_sponsor')) return;
    $scope = sanitize_text_field(wp_unslash($_POST['cancha_scope'] ?? 'general'));
    if (!array_key_exists($scope, cancha_sponsor_scopes())) {
        // Conservar el valor de un torneo previo aunque haya sido archivado.
        $old_scope = get_post_meta($post_id, '_cancha_scope', true);
        $scope = $scope === $old_scope ? $old_scope : 'general';
    }
    $url = esc_url_raw(wp_unslash($_POST['cancha_url'] ?? ''), ['https']);
    $from = cancha_valid_date(sanitize_text_field(wp_unslash($_POST['cancha_from'] ?? '')));
    $to = cancha_valid_date(sanitize_text_field(wp_unslash($_POST['cancha_to'] ?? '')));
    if ($from && $to && $to < $from) $to = ''; // Never silently publish with an impossible range.
    update_post_meta($post_id, '_cancha_scope', $scope);
    update_post_meta($post_id, '_cancha_url', $url);
    update_post_meta($post_id, '_cancha_from', $from);
    update_post_meta($post_id, '_cancha_to', $to);
});
function cancha_active_sponsors($scope = 'general', $limit = 8) {
    $scope = sanitize_text_field($scope);
    if (!preg_match('/^(general|home_top|home_mid|home_bottom|footer|torneo:[a-z0-9][a-z0-9-]{0,100})$/', $scope)) return [];
    $posts = get_posts([
        'post_type' => 'cancha_sponsor', 'post_status' => 'publish', 'numberposts' => 100,
        'meta_key' => '_cancha_scope', 'meta_value' => $scope,
        'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'], 'suppress_filters' => false,
    ]);
    $today = wp_date('Y-m-d'); $result = [];
    foreach ($posts as $post) {
        $from = get_post_meta($post->ID, '_cancha_from', true);
        $to = get_post_meta($post->ID, '_cancha_to', true);
        if (($from && $from > $today) || ($to && $to < $today)) continue;
        $result[] = $post;
        if (count($result) >= $limit) break;
    }
    return $result;
}
function cancha_sponsors_html($scope = 'general', $limit = 8) {
    $posts = cancha_active_sponsors($scope, $limit);
    if (!$posts && $scope === 'general') {
        $settings = cancha_core_settings();
        if (empty($settings['sponsor_name']) && empty($settings['sponsor_logo'])) return '';
        $logo = !empty($settings['sponsor_logo']) ? wp_get_attachment_image(absint($settings['sponsor_logo']), 'thumbnail', false, ['class'=>'sponsor-logo', 'loading'=>'lazy']) : '';
        $name = esc_html($settings['sponsor_name']);
        $url = esc_url($settings['sponsor_url'], ['http','https']);
        $body = $logo . '<span>' . $name . '</span>';
        return '<div class="sponsors-items">' . ($url ? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer sponsored">' . $body . '</a>' : '<span class="sponsor-entry">' . $body . '</span>') . '</div>';
    }
    if (!$posts) return '';
    $html = '<div class="sponsors-items">';
    foreach ($posts as $post) {
        $name = get_the_title($post);
        $logo = get_the_post_thumbnail($post, 'thumbnail', ['class'=>'sponsor-logo', 'alt'=> $name, 'loading'=>'lazy']);
        $body = $logo . '<span>' . esc_html($name) . '</span>';
        $url = esc_url(get_post_meta($post->ID, '_cancha_url', true), ['http','https']);
        $html .= $url ? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer sponsored">' . $body . '</a>' : '<span class="sponsor-entry">' . $body . '</span>';
    }
    return $html . '</div>';
}
add_shortcode('cancha_sponsors', function ($atts) {
    $atts = shortcode_atts(['scope' => 'general', 'limit' => 8], $atts, 'cancha_sponsors');
    return cancha_sponsors_html($atts['scope'], min(20, max(1, absint($atts['limit']))));
});
