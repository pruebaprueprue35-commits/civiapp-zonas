<?php
/**
 * Plugin Name: CANCHA · Ads
 * Description: Campañas gráficas con ubicaciones, vigencia y etiquetas de publicidad, dentro del menú CANCHA. Sin scripts externos.
 * Version: 0.6.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
define('CANCHA_ADS_VERSION','0.6.0');
function cancha_ads_slots(){
    return [
        'home_top'=>'Inicio · sponsor principal (banner grande)',
        'home_mid'=>'Inicio · carrusel de sponsors secundarios',
        'home_bottom'=>'Inicio · después de los módulos',
        'footer'=>'Pie de página (todas las páginas)',
        'championship'=>'Campeonatos · página del campeonato',
        'club'=>'Clubes · ficha de equipo',
        'player'=>'Jugadores · ficha individual',
        'news_feed'=>'Novedades · feed y noticias',
        'search'=>'Buscador · página de resultados',
        'partidos'=>'Partidos profesionales · entre ligas',
        'amateur'=>'Fútbol amateur · portada de torneos',
        'predicciones'=>'Predicciones · debajo de las pestañas',
    ];
}
add_action('init',function(){
    $caps=['edit_post','delete_post','edit_posts','edit_others_posts','publish_posts','read_private_posts','delete_posts','delete_others_posts','delete_private_posts','delete_published_posts','edit_private_posts','edit_published_posts','create_posts'];
    register_post_type('cancha_anuncio',[
       'labels'=>['name'=>'Ads · Anuncios','singular_name'=>'Anuncio','add_new_item'=>'Crear anuncio','edit_item'=>'Editar anuncio','menu_name'=>'📢 Ads'],
       'public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'show_ui'=>true,'show_in_menu'=>'cancha-core',
       'capabilities'=>array_fill_keys($caps,'manage_options')+['read_post'=>'read'],'map_meta_cap'=>false,
       'supports'=>['title','thumbnail','page-attributes'],
    ]);
});
add_action('add_meta_boxes_cancha_anuncio',function(){add_meta_box('cancha_ad_details','Campaña y ubicación','cancha_ads_metabox','cancha_anuncio','normal','high');});
function cancha_ads_metabox($post){
    wp_nonce_field('cancha_ads_save','cancha_ads_nonce');
    $slot=get_post_meta($post->ID,'_cancha_slot',true)?:'home_mid';
    $url=get_post_meta($post->ID,'_cancha_url',true);
    $from=get_post_meta($post->ID,'_cancha_start',true);$to=get_post_meta($post->ID,'_cancha_end',true);
    $comp=absint(get_post_meta($post->ID,'_cancha_competition',true));
    echo '<p><label for="cancha_ads_slot"><strong>Ubicación</strong></label><br><select name="cancha_ads_slot" id="cancha_ads_slot" class="widefat">';
    foreach(cancha_ads_slots() as $code=>$name)echo '<option value="'.esc_attr($code).'" '.selected($slot,$code,false).'>'.esc_html($name).'</option>';
    echo '</select></p><p><label><strong>Destino HTTPS</strong><br><input type="url" class="widefat" name="cancha_ads_url" value="'.esc_attr($url).'" placeholder="https://anunciante.example"></label></p>';
    echo '<p><label>Desde <input type="date" name="cancha_ads_start" value="'.esc_attr($from).'"></label> &nbsp; <label>Hasta <input type="date" name="cancha_ads_end" value="'.esc_attr($to).'"></label></p>';
    echo '<p><label>Campeonato (solo para la ubicación Campeonato) <select name="cancha_ads_comp"><option value="0">Todos los campeonatos</option>';
    if(post_type_exists('cancha_campeonato'))foreach(get_posts(['post_type'=>'cancha_campeonato','post_status'=>'publish','numberposts'=>200]) as $t){echo '<option value="'.absint($t->ID).'" '.selected($comp,$t->ID,false).'>'.esc_html(get_the_title($t)).'</option>';}
    echo '</select></label></p>';
    echo '<p>Asigná una <strong>imagen destacada</strong> para el anuncio. Sin imagen ni enlace HTTPS, el anuncio no se mostrará. Solo campañas publicadas y vigentes aparecen.</p>';
    echo '<p>El anuncio se identificará como «Publicidad». No usamos seguimiento individual de usuarios ni ejecutamos JavaScript del anunciante.</p>';
}
function cancha_ads_valid_date($v){
    if(!is_scalar($v) || !preg_match('/^\d{4}-\d\d-\d\d$/',(string)$v))return '';
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$v);
    return $dt&&$dt->format('Y-m-d')===$v ? (string)$v : '';
}
add_action('save_post_cancha_anuncio',function($id){
    if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
    if(wp_is_post_revision($id)||!current_user_can('manage_options'))return;
    if(!isset($_POST['cancha_ads_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cancha_ads_nonce'])),'cancha_ads_save'))return;
    $slot=sanitize_key($_POST['cancha_ads_slot']??'');
    if(!array_key_exists($slot,cancha_ads_slots()))$slot='home_mid';
    $url=esc_url_raw(wp_unslash($_POST['cancha_ads_url']??''),['https']);
    if($url && wp_parse_url($url,PHP_URL_SCHEME)!=='https')$url='';
    $start=cancha_ads_valid_date(sanitize_text_field(wp_unslash($_POST['cancha_ads_start']??'')));
    $end=cancha_ads_valid_date(sanitize_text_field(wp_unslash($_POST['cancha_ads_end']??'')));
    if($start && $end && $start>$end){$end='';set_transient('cancha_ads_error_'.get_current_user_id(),'Fecha final anterior al inicio: revisá la campaña.',60);}
    $comp=absint($_POST['cancha_ads_comp']??0);
    if($comp && (get_post_type($comp)!=='cancha_campeonato'||get_post_status($comp)!=='publish'))$comp=0;
    foreach(['_cancha_slot'=>$slot,'_cancha_url'=>$url,'_cancha_start'=>$start,'_cancha_end'=>$end,'_cancha_competition'=>$comp] as $key=>$value)update_post_meta($id,$key,$value);
});
add_action('admin_notices',function(){
    $error=get_transient('cancha_ads_error_'.get_current_user_id());if(!$error)return;
    delete_transient('cancha_ads_error_'.get_current_user_id());echo '<div class="notice notice-warning"><p>'.esc_html($error).'</p></div>';
});
function cancha_ads_current($slot,$competition=0,$limit=4){
    if(!array_key_exists($slot,cancha_ads_slots()))return [];
    $today=wp_date('Y-m-d');$out=[];
    foreach(get_posts(['post_type'=>'cancha_anuncio','post_status'=>'publish','numberposts'=>200,'meta_key'=>'_cancha_slot','meta_value'=>$slot,'orderby'=>['menu_order'=>'ASC','title'=>'ASC']]) as $ad){
        $from=get_post_meta($ad->ID,'_cancha_start',true);$end=get_post_meta($ad->ID,'_cancha_end',true);
        if(($from && $from>$today)||($end && $end<$today))continue;
        $filter=absint(get_post_meta($ad->ID,'_cancha_competition',true));
        if($slot==='championship' && $filter && $filter!==absint($competition))continue;
        if(!has_post_thumbnail($ad)||!get_post_meta($ad->ID,'_cancha_url',true))continue;
        $out[]=$ad;if(count($out)>=max(1,min(12,absint($limit))))break;
    }
    return $out;
}
/** 0.6.0: las nuevas ubicaciones usan campañas de «Inicio · después de los módulos» mientras no tengan propias. */
function cancha_ads_fallback_slot($slot){return in_array($slot,['partidos','amateur','predicciones','news_feed'],true)?'home_bottom':'';}
function cancha_ads_html($slot,$competition=0,$limit=4){
    $ads=cancha_ads_current($slot,$competition,$limit);
    if(!$ads && cancha_ads_fallback_slot($slot))$ads=cancha_ads_current(cancha_ads_fallback_slot($slot),$competition,$limit);
    if(!$ads)return '';
    $html='<aside class="cancha-ad-zone" aria-label="Espacios publicitarios"><div class="cancha-ad-heading">Publicidad</div><div class="cancha-ad-items">';
    foreach($ads as $ad){
        $url=esc_url(get_post_meta($ad->ID,'_cancha_url',true),['https']);
        $image=get_the_post_thumbnail($ad,'large',['loading'=>'lazy','alt'=>get_the_title($ad)]);
        $html.='<a class="cancha-ad-card" href="'.$url.'" target="_blank" rel="noopener noreferrer sponsored" aria-label="Publicidad: '.esc_attr(get_the_title($ad)).'">'.$image.'<span>'.esc_html(get_the_title($ad)).'</span></a>';
    }
    return $html.'</div></aside>';
}
add_shortcode('cancha_ads',function($atts){
    $a=shortcode_atts(['slot'=>'home_mid','campeonato'=>0,'limit'=>4],$atts,'cancha_ads');
    return cancha_ads_html(sanitize_key($a['slot']),absint($a['campeonato']),absint($a['limit']));
});
foreach(['home_top','home_mid','home_bottom','footer','club','player','news_feed','search','partidos','amateur','predicciones'] as $slot){
    add_action('cancha_ads_'.$slot,static function()use($slot){echo cancha_ads_html($slot);});
}
add_action('cancha_render_campeonato_detalle',function($id){echo cancha_ads_html('championship',absint($id));},30);
add_action('wp_enqueue_scripts',function(){wp_enqueue_style('cancha-ads',plugins_url('assets/ads.css',__FILE__),[],CANCHA_ADS_VERSION);});
add_action('admin_enqueue_scripts',function(){
    $screen=get_current_screen();if(!$screen||$screen->post_type!=='cancha_anuncio')return;
    wp_enqueue_style('cancha-ads-admin',plugins_url('assets/ads-admin.css',__FILE__),[],CANCHA_ADS_VERSION);
});
add_filter('manage_cancha_anuncio_posts_columns',function($columns){$columns['cancha_slot']='Ubicación';$columns['cancha_dates']='Vigencia';return $columns;});
add_action('manage_cancha_anuncio_posts_custom_column',function($col,$id){
    if($col==='cancha_slot')echo esc_html(cancha_ads_slots()[get_post_meta($id,'_cancha_slot',true)]??'—');
    if($col==='cancha_dates')echo esc_html((get_post_meta($id,'_cancha_start',true)?:'Desde ahora').' / '.(get_post_meta($id,'_cancha_end',true)?:'Sin vencimiento'));
},10,2);
