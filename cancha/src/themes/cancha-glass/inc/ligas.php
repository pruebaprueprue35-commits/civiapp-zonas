<?php
/** CANCHA Ligas UI v1.3 — read-only adapters for existing modules. */
defined('ABSPATH') || exit;

function cancha_ligas_ad_items($slot = 'home_mid', $max = 12) {
    $items = [];
    if (function_exists('cancha_ads_current')) {
        foreach (cancha_ads_current($slot, 0, $max) as $post) {
            $url = esc_url(get_post_meta($post->ID, '_cancha_url', true), ['https']);
            $image = get_the_post_thumbnail_url($post, 'large');
            if (!$image || !$url) continue;
            $items[] = ['type'=>'ad','title'=>get_the_title($post),'url'=>$url,'image'=>$image,'id'=>$post->ID];
        }
    }
    return $items;
}
function cancha_ligas_sponsor_items($scope = 'home_mid', $max = 16) {
    $items=[];
    if (!function_exists('cancha_active_sponsors')) return $items;
    foreach (cancha_active_sponsors($scope, $max) as $post) {
        $image = get_the_post_thumbnail_url($post, 'medium_large');
        $url = esc_url(get_post_meta($post->ID, '_cancha_url', true), ['https']);
        $items[]=['type'=>'sponsor','title'=>get_the_title($post),'url'=>$url,'image'=>$image ?: '','id'=>$post->ID];
    }
    return $items;
}
function cancha_ligas_editorial_banner() {
    echo '<div class="ligas-home-hero ligas-home-empty"><div class="ligas-hero-copy"><span class="ligas-hero-kicker">EL FÚTBOL QUE NOS UNE</span><h1>'.esc_html(cancha_ui_setting('hero_title','Viví cada campeonato desde adentro.')).'</h1><p>'.esc_html(cancha_ui_setting('hero_subtitle','Resultados, jugadores, torneos y comunidad en un solo lugar.')).'</p><a class="ligas-hero-link" href="'.esc_url(cancha_route('campeonatos')).'">Explorar campeonatos <span aria-hidden="true">→</span></a></div></div>';
}
function cancha_ligas_main_banner() {
    if (!cancha_ui_setting('home_show_sponsors', 1)) { cancha_ligas_editorial_banner();return; }
    $ads=cancha_ligas_ad_items('home_top', 1);
    $sponsors=cancha_ligas_sponsor_items('general', 1);
    $source=cancha_ui_setting('main_sponsor_source','auto');
    $featured=$source==='general'?($sponsors[0]??$ads[0]??null):($ads[0]??$sponsors[0]??null);
    if (!$featured) {
        $settings=cancha_core_settings();
        if (!empty($settings['sponsor_logo']) || !empty($settings['sponsor_name'])) {
            $featured=['type'=>'sponsor','title'=>(string)$settings['sponsor_name'],'url'=>esc_url($settings['sponsor_url'],['https']),
                'image'=>wp_get_attachment_image_url(absint($settings['sponsor_logo']),'large') ?: '','id'=>0];
        }
    }
    if (!$featured) {
        cancha_ligas_editorial_banner();return;
    }
    $url=$featured['url']; $image=$featured['image']; $title=$featured['title'];
    echo '<section class="ligas-featured-ad" aria-label="Sponsor principal"><span class="ligas-ad-disclosure">'.($featured['type']==='ad'?'Publicidad':'Sponsor principal').'</span>';
    if ($url) echo '<a class="ligas-featured-ad-link" href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer sponsored" aria-label="'.esc_attr($title).'">';
    if ($image) echo '<img class="ligas-featured-image" src="'.esc_url($image).'" alt="'.esc_attr($title).'" fetchpriority="high">';
    else echo '<div class="ligas-featured-noimage"><span>SPONSOR PRINCIPAL</span><strong>'.esc_html($title).'</strong></div>';
    if ($url) echo '</a>';
    echo '<button class="ligas-dismiss" type="button" aria-label="Ocultar este sponsor" title="Ocultar publicidad">×</button></section>';
}
function cancha_ligas_sponsor_carousel() {
    if (!cancha_ui_setting('home_show_sponsors', 1)) return;
    $items = array_merge(cancha_ligas_sponsor_items('home_mid', 16), cancha_ligas_ad_items('home_mid', 12));
    if (!$items) return;
    echo '<section class="ligas-sponsor-carousel" aria-label="Sponsors secundarios"><button class="ligas-sponsor-arrow prev" aria-label="Sponsors anteriores" type="button">‹</button><div class="ligas-sponsor-track" id="ligas-sponsor-track">';
    foreach ($items as $i=>$item) {
        $inside=($item['image']?'<img src="'.esc_url($item['image']).'" alt="" loading="lazy">':'<span class="ligas-sponsor-initial" aria-hidden="true">★</span>').'<strong>'.esc_html($item['title']).'</strong>';
        if ($item['url']) echo '<a class="ligas-sponsor-tile" href="'.esc_url($item['url']).'" target="_blank" rel="noopener noreferrer sponsored" aria-label="Sponsor: '.esc_attr($item['title']).'">'.$inside.'</a>';
        else echo '<div class="ligas-sponsor-tile">'.$inside.'</div>';
    }
    echo '</div><button class="ligas-sponsor-arrow next" aria-label="Sponsors siguientes" type="button">›</button></section>';
}
function cancha_ligas_officials() {
    $opts=cancha_core_settings();$provider=$opts['api_provider'];$ids=(array)($opts['official_comp_ids']??[]);
    $data=[
      ['liga_profesional','Liga Profesional','Argentina','argentina','⚽'],
      ['primera_nacional','Primera Nacional','Argentina','primera','🏟'],
      ['libertadores','CONMEBOL Libertadores','Copa continental','libertadores','🏆'],
      ['sudamericana','CONMEBOL Sudamericana','Copa continental','sudamericana','🏆'],
    ];
    echo '<section class="ligas-section" data-ligas-section="oficiales"><div class="ligas-section-head"><h2><span aria-hidden="true">🏆</span> Campeonatos oficiales</h2><a href="'.esc_url(cancha_route('futbol-oficial')).'">Ver resultados <span aria-hidden="true">›</span></a></div><div class="ligas-official-grid">';
    foreach ($data as $item) {
        [$code,$name,$category,$art,$symbol]=$item;
        $configured=$provider!=='none' && !empty($ids[$code]);
        echo '<article class="ligas-official-card" data-cancha-fav="official:'.esc_attr($code).'"><a class="ligas-official-link" href="'.esc_url(cancha_route('futbol-oficial').'#'.sanitize_title($code)).'">';
        echo '<div class="ligas-official-art art-'.esc_attr($art).'"><span class="ligas-official-symbol" aria-hidden="true">'.esc_html($symbol).'</span><div><span class="ligas-official-category">'.esc_html($category).'</span><h3>'.esc_html($name).'</h3></div></div>';
        echo '<div class="ligas-official-meta"><span class="ligas-status '.($configured?'connected':'unconnected').'">'.($configured?'● Fuente configurada':'○ Conectar resultados').'</span><span class="ligas-card-more" aria-hidden="true">›</span></div></a>';
        echo '<button class="ligas-favorite ligas-favorite-official" type="button" data-favorite-id="official:'.esc_attr($code).'" aria-label="Agregar '.esc_attr($name).' a favoritos" aria-pressed="false">☆</button></article>';
    }
    echo '</div></section>';
}
function cancha_ligas_amateur() {
    echo '<section class="ligas-section" id="campeonatos-amateur" data-ligas-section="amateur"><div class="ligas-section-head"><div><h2><span aria-hidden="true">♧</span> Campeonatos amateur</h2><p>Torneos creados por la comunidad de CANCHA</p></div>';
    $can_create=current_user_can('manage_options');
    if ($can_create && post_type_exists('cancha_campeonato')) echo '<a class="ligas-create-btn" href="'.esc_url(admin_url('post-new.php?post_type=cancha_campeonato')).'">＋ Crear campeonato</a>';
    else echo '<a class="ligas-create-btn" href="'.esc_url(cancha_route('campeonatos')).'">Ver torneos</a>';
    echo '</div>';
    if (!post_type_exists('cancha_campeonato')) {
        echo '<div class="ligas-empty"><h3>Próximamente, campeonatos amateur</h3><p>Activá CANCHA Campeonatos para gestionar torneos, equipos y jugadores.</p></div></section>';return;
    }
    $posts=get_posts(['post_type'=>'cancha_campeonato','post_status'=>'publish','numberposts'=>100,'orderby'=>'date','order'=>'DESC']);
    if (!$posts){echo '<div class="ligas-empty"><h3>No hay torneos publicados todavía</h3><p>Cuando se publique un campeonato, aparecerá aquí automáticamente.</p></div></section>';return;}
    echo '<div class="ligas-amateur-grid">';
    foreach ($posts as $post) {
        $id=$post->ID;$teams=function_exists('cancha_comp_teams') ? count(cancha_comp_teams($id)) : 0;
        $matches=function_exists('cancha_comp_matches') ? cancha_comp_matches($id,500) : [];
        $rounds=[];$finished=0;
        foreach ($matches as $m){$round=(int)get_post_meta($m->ID,'_cancha_round',true);if($round>0)$rounds[$round]=true;if(get_post_meta($m->ID,'_cancha_result_status',true)==='final')$finished++;}
        $total=count($matches);$end=get_post_meta($id,'_cancha_tournament_status',true)==='finished';$is_active=$finished>0 && !$end;
        $status=$end?'Finalizado':($is_active?'En juego':'Próximamente');
        $cls=$end?'finished':($is_active?'playing':'upcoming');
        $img=get_the_post_thumbnail_url($post,'medium');
        echo '<article class="ligas-amateur-card" data-cancha-fav="amateur:'.absint($id).'">';
        echo '<a class="ligas-amateur-main" href="'.esc_url(get_permalink($post)).'">';
        if ($img) echo '<img class="ligas-amateur-logo" src="'.esc_url($img).'" alt="" loading="lazy">';
        else echo '<span class="ligas-amateur-logo ligas-amateur-logo-fallback" aria-hidden="true">🏆</span>';
        echo '<span class="ligas-amateur-info"><strong>'.esc_html(get_the_title($post)).'</strong><span class="ligas-status '.esc_attr($cls).'">● '.esc_html($status).'</span>';
        echo '<span class="ligas-amateur-metrics">'.absint($teams).' equipos <span aria-hidden="true">│</span> '.absint($total).' partidos';
        if ($rounds) echo ' <span aria-hidden="true">│</span> '.count($rounds).' jornadas';
        echo '</span>';
        if ($total)echo '<span class="ligas-progress" aria-label="'.absint($finished).' de '.absint($total).' partidos finalizados"><span style="width:'.esc_attr((string)round($finished*100/$total)).'%"></span></span>';
        else echo '<span class="ligas-progress"><span style="width:0%"></span></span>';
        echo '</span></a>';
        echo '<button class="ligas-favorite" type="button" data-favorite-id="amateur:'.absint($id).'" aria-label="Añadir '.esc_attr(get_the_title($post)).' a favoritos" aria-pressed="false">☆</button>';
        echo '</article>';
    }
    echo '</div></section>';
}
function cancha_ligas_catalog($include_extras=true) {
    echo '<div class="ligas-layout" id="cancha-ligas">';
    cancha_ligas_main_banner();cancha_ligas_sponsor_carousel();
    echo '<nav class="ligas-filters" aria-label="Filtrar campeonatos"><button type="button" class="is-active" data-ligas-filter="todos" aria-pressed="true">Todos</button><button type="button" data-ligas-filter="oficiales" aria-pressed="false">Oficiales</button><button type="button" data-ligas-filter="amateur" aria-pressed="false">Amateur</button><button type="button" data-ligas-filter="favoritos" aria-pressed="false">☆ Favoritos</button></nav>';
    if (cancha_ui_setting('home_show_official',1))cancha_ligas_officials();
    if (cancha_ui_setting('home_show_amateur',1))cancha_ligas_amateur();
    if ($include_extras && cancha_ui_setting('home_show_predictions',1)) {
        echo '<section class="ligas-section ligas-predictions" data-ligas-extra><div class="ligas-section-head"><h2><span aria-hidden="true">🎯</span> Predicciones</h2><a href="'.esc_url(cancha_route('predicciones')).'">Participar ›</a></div>';
        if (has_action('cancha_render_predicciones')) {do_action('cancha_render_predicciones');}
        else echo '<div class="ligas-empty"><p>Pronosticá el resultado de partidos amateur por puntos. Instalá CANCHA Predicciones para empezar.</p><a class="ligas-create-btn" href="'.esc_url(cancha_route('predicciones')).'">Ver predicciones</a></div>';
        echo '</section>';
    }
    echo '</div>';
}
