<?php
/**
 * Plugin Name: CANCHA · Campeonatos
 * Description: Campeonatos amateur, equipos, jornadas, resultados validados y tabla de posiciones. Se administra dentro de CANCHA.
 * Version: 0.5.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: cancha-campeonatos
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
define('CANCHA_COMP_VERSION','0.5.0');
define('CANCHA_COMP_FILE',__FILE__);
require_once __DIR__ . '/inc/rounds.php';
// New modules load after basic functions; their callbacks run once WordPress is initialized.
require_once __DIR__ . '/inc/people.php';
require_once __DIR__ . '/inc/match-events.php';
require_once __DIR__ . '/inc/directory.php';
require_once __DIR__ . '/inc/import.php';
require_once __DIR__ . '/inc/matches-details.php';
require_once __DIR__ . '/inc/leaders.php';
require_once __DIR__ . '/inc/amateur.php';
function cancha_comp_capabilities(){
    $caps=['edit_post','delete_post','edit_posts','edit_others_posts','publish_posts','read_private_posts','delete_posts','delete_others_posts','delete_private_posts','delete_published_posts','edit_private_posts','edit_published_posts','create_posts'];
    return array_fill_keys($caps,'manage_options') + ['read_post'=>'read'];
}
function cancha_comp_types(){
    $base=['show_ui'=>true,'show_in_menu'=>'cancha-core','capabilities'=>cancha_comp_capabilities(),'map_meta_cap'=>false,'exclude_from_search'=>true];
    register_post_type('cancha_campeonato',array_merge($base,[
        'labels'=>['name'=>'Campeonatos','singular_name'=>'Campeonato','add_new_item'=>'Crear campeonato','edit_item'=>'Editar campeonato','menu_name'=>'🏆 Campeonatos'],
        'public'=>true,'publicly_queryable'=>true,'show_in_rest'=>true,'has_archive'=>false,'rewrite'=>['slug'=>'campeonato','with_front'=>false],
        'supports'=>['title','editor','thumbnail','page-attributes'],'exclude_from_search'=>false,
    ]));
    register_post_type('cancha_equipo',array_merge($base,[
        'labels'=>['name'=>'Equipos','singular_name'=>'Equipo','add_new_item'=>'Crear equipo','edit_item'=>'Editar equipo','menu_name'=>'👕 Equipos'],
        'public'=>true,'publicly_queryable'=>true,'show_in_rest'=>false,'exclude_from_search'=>false,'rewrite'=>['slug'=>'club','with_front'=>false],'supports'=>['title','editor','thumbnail'],
    ]));
    register_post_type('cancha_partido',array_merge($base,[
        'labels'=>['name'=>'Partidos y resultados','singular_name'=>'Partido','add_new_item'=>'Crear partido','edit_item'=>'Editar partido','menu_name'=>'⚽ Partidos'],
        'public'=>true,'publicly_queryable'=>true,'show_in_rest'=>false,'exclude_from_search'=>false,'rewrite'=>['slug'=>'partido-cancha','with_front'=>false],'supports'=>['title','editor'],
    ]));
}
add_action('init','cancha_comp_types');
register_activation_hook(__FILE__,function(){cancha_comp_types();flush_rewrite_rules(false);});
register_deactivation_hook(__FILE__,function(){flush_rewrite_rules(false);});
function cancha_comp_tournaments(){return get_posts(['post_type'=>'cancha_campeonato','post_status'=>'publish','numberposts'=>300,'orderby'=>'title','order'=>'ASC']);}
function cancha_comp_teams($tournament_id=0){
    $args=['post_type'=>'cancha_equipo','post_status'=>'publish','numberposts'=>500,'orderby'=>'title','order'=>'ASC'];
    if($tournament_id) $args['meta_query']=[['key'=>'_cancha_comp_id','value'=>absint($tournament_id),'compare'=>'=']];
    return get_posts($args);
}
function cancha_comp_matches($tournament_id=0,$limit=1000){
    $args=['post_type'=>'cancha_partido','post_status'=>'publish','numberposts'=>min(2000,max(1,absint($limit))),'orderby'=>'date','order'=>'ASC'];
    if($tournament_id) $args['meta_query']=[['key'=>'_cancha_comp_id','value'=>absint($tournament_id),'compare'=>'=']];
    return get_posts($args);
}
function cancha_comp_options_html($posts,$selected=0,$blank='— Seleccionar —'){
    $html='<option value="0">'.esc_html($blank).'</option>';
    foreach($posts as $post){$html.='<option value="'.absint($post->ID).'" '.selected($selected,$post->ID,false).'>'.esc_html($post->post_title).'</option>';}
    return $html;
}
add_action('add_meta_boxes',function(){
    add_meta_box('cancha_comp_config','Reglas de puntuación','cancha_comp_config_box','cancha_campeonato','normal','high');
    add_meta_box('cancha_team_config','Campeonato asignado','cancha_comp_team_box','cancha_equipo','normal','high');
    add_meta_box('cancha_fixture_config','Jornada y resultado','cancha_comp_match_box','cancha_partido','normal','high');
});
function cancha_comp_config_box($post){
    wp_nonce_field('cancha_comp_save','cancha_comp_nonce');
    foreach(['points_win'=>['Victoria',3],'points_draw'=>['Empate',1],'points_loss'=>['Derrota',0]] as $key=>$details){
        $value=get_post_meta($post->ID,'_cancha_'.$key,true);if($value==='')$value=$details[1];
        echo '<p><label>'.esc_html($details[0]).' <input type="number" min="0" max="20" name="cancha_'.esc_attr($key).'" value="'.esc_attr($value).'"></label></p>';
    }
    echo '<p>Por defecto: 3 puntos victoria, 1 empate, 0 derrota. Desempates: puntos, diferencia de gol, goles a favor y nombre.</p>';
}
function cancha_comp_team_box($post){
    wp_nonce_field('cancha_team_save','cancha_team_nonce');
    $selected=absint(get_post_meta($post->ID,'_cancha_comp_id',true));
    echo '<p><label for="cancha_comp_id">Torneo</label> <select id="cancha_comp_id" name="cancha_comp_id" required>'.cancha_comp_options_html(cancha_comp_tournaments(),$selected).'</select></p><p>Primero publicá el campeonato y luego vinculá sus equipos.</p>';
}
function cancha_comp_match_box($post){
    wp_nonce_field('cancha_match_save','cancha_match_nonce');
    $comp=absint(get_post_meta($post->ID,'_cancha_comp_id',true));
    $home=absint(get_post_meta($post->ID,'_cancha_home',true));$away=absint(get_post_meta($post->ID,'_cancha_away',true));
    $round=absint(get_post_meta($post->ID,'_cancha_round',true));$start=get_post_meta($post->ID,'_cancha_start',true);
    $status=get_post_meta($post->ID,'_cancha_result_status',true)?:'scheduled';
    echo '<p><label>Campeonato <select name="cancha_comp_id" id="cancha_match_comp" required>'.cancha_comp_options_html(cancha_comp_tournaments(),$comp).'</select></label></p>';
    $teams=cancha_comp_teams($comp);
    echo '<p><label>Local <select name="cancha_home" required>'.cancha_comp_options_html($teams,$home).'</select></label> &nbsp; <label>Visitante <select name="cancha_away" required>'.cancha_comp_options_html($teams,$away).'</select></label></p>';
    echo '<p class="description">Si cambiás de campeonato, guardá primero y después elegí los equipos. Local y visitante deben ser distintos y pertenecer al mismo torneo.</p>';
    echo '<p><label>Jornada <input type="number" name="cancha_round" min="1" max="200" value="'.esc_attr($round?:1).'" required></label> &nbsp; <label>Fecha y hora local <input type="datetime-local" name="cancha_start" value="'.esc_attr($start).'" required></label></p>';
    echo '<p><label>Goles local <input type="number" min="0" max="99" name="cancha_home_score" value="'.esc_attr(get_post_meta($post->ID,'_cancha_home_score',true)).'" style="width:85px"></label> &nbsp; <label>Goles visitante <input type="number" min="0" max="99" name="cancha_away_score" value="'.esc_attr(get_post_meta($post->ID,'_cancha_away_score',true)).'" style="width:85px"></label></p>';
    echo '<p><label>Estado <select name="cancha_result_status"><option value="scheduled" '.selected($status,'scheduled',false).'>Programado / pendiente</option><option value="final" '.selected($status,'final',false).'>Resultado validado y definitivo</option></select></label></p>';
    echo '<p><strong>Publicación:</strong> solo partidos publicados y con resultado final válido computan en la tabla. La notificación masiva a usuarios requiere el complemento de avisos (no se envían notificaciones desde aquí).</p>';
}
function cancha_comp_save_allowed($id,$nonce_name,$nonce_action){
    if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)return false;
    if(wp_is_post_revision($id)||!current_user_can('manage_options'))return false;
    return isset($_POST[$nonce_name]) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$nonce_name])),$nonce_action);
}
add_action('save_post_cancha_campeonato',function($id){
    if(!cancha_comp_save_allowed($id,'cancha_comp_nonce','cancha_comp_save'))return;
    foreach(['points_win'=>3,'points_draw'=>1,'points_loss'=>0] as $key=>$default)update_post_meta($id,'_cancha_'.$key,min(20,max(0,(int)($_POST['cancha_'.$key]??$default))));
});
add_action('save_post_cancha_equipo',function($id){
    if(!cancha_comp_save_allowed($id,'cancha_team_nonce','cancha_team_save'))return;
    $comp=absint($_POST['cancha_comp_id']??0);
    if($comp && get_post_type($comp)==='cancha_campeonato' && get_post_status($comp)==='publish')update_post_meta($id,'_cancha_comp_id',$comp);
});
function cancha_comp_valid_score($v){return is_scalar($v) && preg_match('/^(?:0|[1-9][0-9]?)$/',(string)$v) ? (int)$v : null;}
function cancha_comp_valid_datetime($v){
    if(!is_scalar($v)||!preg_match('/^20\d\d-\d\d-\d\dT\d\d:\d\d$/',(string)$v))return '';
    $d=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',(string)$v);
    return $d&&$d->format('Y-m-d\\TH:i')===$v?(string)$v:'';
}
add_action('save_post_cancha_partido',function($id){
    if(!cancha_comp_save_allowed($id,'cancha_match_nonce','cancha_match_save'))return;
    $comp=absint($_POST['cancha_comp_id']??0);$home=absint($_POST['cancha_home']??0);$away=absint($_POST['cancha_away']??0);
    $valid=$comp && get_post_status($comp)==='publish' && get_post_type($comp)==='cancha_campeonato' && $home && $away && $home!==$away
        && get_post_type($home)==='cancha_equipo' && get_post_type($away)==='cancha_equipo'
        && get_post_status($home)==='publish' && get_post_status($away)==='publish'
        && (int)get_post_meta($home,'_cancha_comp_id',true)===$comp && (int)get_post_meta($away,'_cancha_comp_id',true)===$comp;
    if(!$valid){
        update_post_meta($id,'_cancha_result_status','scheduled');
        delete_post_meta($id,'_cancha_home');delete_post_meta($id,'_cancha_away');
        set_transient('cancha_comp_error_'.get_current_user_id(),'Elegí un campeonato publicado y dos equipos diferentes que pertenezcan a ese torneo.',60);
        return;
    }
    update_post_meta($id,'_cancha_comp_id',$comp);update_post_meta($id,'_cancha_home',$home);update_post_meta($id,'_cancha_away',$away);
    update_post_meta($id,'_cancha_round',min(200,max(1,absint($_POST['cancha_round']??1))));
    update_post_meta($id,'_cancha_start',cancha_comp_valid_datetime(sanitize_text_field(wp_unslash($_POST['cancha_start']??''))));
    $hs=cancha_comp_valid_score($_POST['cancha_home_score']??'');$as=cancha_comp_valid_score($_POST['cancha_away_score']??'');
    if($hs!==null && $as!==null){update_post_meta($id,'_cancha_home_score',$hs);update_post_meta($id,'_cancha_away_score',$as);}
    else{delete_post_meta($id,'_cancha_home_score');delete_post_meta($id,'_cancha_away_score');}
    $final=($_POST['cancha_result_status']??'')==='final' && $hs!==null && $as!==null;
    update_post_meta($id,'_cancha_result_status',$final?'final':'scheduled');
    if(($_POST['cancha_result_status']??'')==='final'&&!$final)set_transient('cancha_comp_error_'.get_current_user_id(),'Faltan marcadores válidos: el partido quedó pendiente y NO suma puntos.',60);
});
add_action('admin_notices',function(){
    $notice=get_transient('cancha_comp_error_'.get_current_user_id());if(!$notice)return;
    delete_transient('cancha_comp_error_'.get_current_user_id());echo '<div class="notice notice-warning is-dismissible"><p>'.esc_html($notice).'</p></div>';
});
function cancha_comp_standings($comp){
    $comp=absint($comp);$table=[];
    foreach(cancha_comp_teams($comp) as $team){
        $table[$team->ID]=['id'=>$team->ID,'name'=>get_the_title($team),'pj'=>0,'g'=>0,'e'=>0,'p'=>0,'gf'=>0,'gc'=>0,'dg'=>0,'pts'=>0];
    }
    $win=(int)get_post_meta($comp,'_cancha_points_win',true);$draw=(int)get_post_meta($comp,'_cancha_points_draw',true);$lose=(int)get_post_meta($comp,'_cancha_points_loss',true);
    // Defaults for tournaments created before options were saved.
    if(get_post_meta($comp,'_cancha_points_win',true)==='')$win=3;
    if(get_post_meta($comp,'_cancha_points_draw',true)==='')$draw=1;
    foreach(cancha_comp_matches($comp) as $match){
        if(get_post_meta($match->ID,'_cancha_result_status',true)!=='final')continue;
        if(get_post_meta($match->ID,'_cancha_stage',true)==='ko')continue; // las eliminatorias no suman en la tabla
        $home=absint(get_post_meta($match->ID,'_cancha_home',true));$away=absint(get_post_meta($match->ID,'_cancha_away',true));
        if(!$home||!$away||$home===$away||!isset($table[$home],$table[$away]))continue;
        $hs=cancha_comp_valid_score(get_post_meta($match->ID,'_cancha_home_score',true));$as=cancha_comp_valid_score(get_post_meta($match->ID,'_cancha_away_score',true));
        if($hs===null||$as===null)continue;
        $table[$home]['pj']++;$table[$away]['pj']++;
        $table[$home]['gf']+=$hs;$table[$home]['gc']+=$as;$table[$away]['gf']+=$as;$table[$away]['gc']+=$hs;
        if($hs>$as){$table[$home]['g']++;$table[$away]['p']++;$table[$home]['pts']+=$win;$table[$away]['pts']+=$lose;}
        elseif($hs<$as){$table[$away]['g']++;$table[$home]['p']++;$table[$away]['pts']+=$win;$table[$home]['pts']+=$lose;}
        else{$table[$home]['e']++;$table[$away]['e']++;$table[$home]['pts']+=$draw;$table[$away]['pts']+=$draw;}
    }
    foreach($table as &$row)$row['dg']=$row['gf']-$row['gc'];unset($row);
    $rows=array_values($table);
    usort($rows,static function($a,$b){return ($b['pts']<=>$a['pts']) ?: ($b['dg']<=>$a['dg']) ?: ($b['gf']<=>$a['gf']) ?: strcmp($a['name'],$b['name']);});
    return $rows;
}
function cancha_comp_standings_html($comp){
    $rows=cancha_comp_standings($comp);if(!$comp)return '<div class="module-empty">Indicá el ID de un campeonato.</div>';
    if(!$rows)return '<div class="module-empty">Todavía no hay equipos publicados para este campeonato.</div>';
    $html='<div class="cancha-standings-wrap"><table class="cancha-standings"><thead><tr><th>Pos.</th><th>Equipo</th><th>PJ</th><th>G</th><th>E</th><th>P</th><th>GF</th><th>GC</th><th>DG</th><th>Pts</th></tr></thead><tbody>';
    foreach($rows as $i=>$r){$html.='<tr><td>'.($i+1).'</td><th scope="row">'.esc_html($r['name']).'</th>';
        foreach(['pj','g','e','p','gf','gc','dg','pts'] as $field)$html.='<td>'.esc_html($r[$field]).'</td>';
        $html.='</tr>';
    }
    return $html.'</tbody></table></div>';
}
function cancha_comp_fixture_html($comp,$round=0){
    if(!$comp)return '<div class="module-empty">Indicá el ID de un campeonato.</div>';
    $matches=cancha_comp_matches($comp);$items=[];
    foreach($matches as $m){
        $r=absint(get_post_meta($m->ID,'_cancha_round',true));if($round && $r!==$round)continue;
        $items[]=['id'=>$m->ID,'round'=>$r,'date'=>get_post_meta($m->ID,'_cancha_start',true),'home'=>get_the_title(absint(get_post_meta($m->ID,'_cancha_home',true))),'away'=>get_the_title(absint(get_post_meta($m->ID,'_cancha_away',true))),'final'=>get_post_meta($m->ID,'_cancha_result_status',true)==='final','hs'=>get_post_meta($m->ID,'_cancha_home_score',true),'as'=>get_post_meta($m->ID,'_cancha_away_score',true)];
    }
    usort($items,static function($a,$b){return ($a['round']<=>$b['round']) ?: strcmp($a['date'],$b['date']);});
    if(!$items)return '<div class="module-empty">No se publicaron partidos de esta jornada todavía.</div>';
    $html='<div class="cancha-fixture-list">';
    foreach($items as $m){$score=$m['final']?absint($m['hs']).' : '.absint($m['as']):'— : —';
        $html.='<div class="fixture-row"><span>'.esc_html($m['home']).'</span><strong>'.esc_html($score).'</strong><span>'.esc_html($m['away']).'</span><small>J'.absint($m['round']).' · '.esc_html(str_replace('T',' ',$m['date'])).'</small></div>';
    }
    return $html.'</div>';
}
function cancha_comp_list_html(){
    $comps=cancha_comp_tournaments();if(!$comps)return '<div class="module-empty">Por ahora no hay campeonatos publicados.</div>';
    $html='<div class="cancha-comp-grid">';
    foreach($comps as $t){$url=get_permalink($t);$img=get_the_post_thumbnail($t,'medium_large',['class'=>'cancha-comp-cover']);
        $html.='<a href="'.esc_url($url).'" class="cancha-comp-card">'.($img?:'<div class="cancha-comp-placeholder">🏆</div>').'<h3>'.esc_html(get_the_title($t)).'</h3><span>Ver tabla y partidos →</span></a>';
    }
    return $html.'</div>';
}
add_shortcode('cancha_tabla',function($atts){$a=shortcode_atts(['campeonato'=>0],$atts,'cancha_tabla');return cancha_comp_standings_html(absint($a['campeonato']));});
add_shortcode('cancha_partidos',function($atts){$a=shortcode_atts(['campeonato'=>0,'jornada'=>0],$atts,'cancha_partidos');return cancha_comp_fixture_html(absint($a['campeonato']),absint($a['jornada']));});
add_action('cancha_render_campeonatos',function(){echo cancha_comp_list_html();});
// 0.5.0: la tabla y el fixture del torneo se muestran en las pestañas Zonas / Fixture (inc/amateur.php).
add_action('cancha_render_proximos_partidos',function(){
    $all=[];
    foreach(cancha_comp_matches(0,250) as $match){
        if(get_post_meta($match->ID,'_cancha_result_status',true)==='final')continue;
        $date=get_post_meta($match->ID,'_cancha_start',true);
        if(!$date || $date < wp_date('Y-m-d\\TH:i'))continue;
        $all[]=$match;
    }
    usort($all,static function($a,$b){return strcmp(get_post_meta($a->ID,'_cancha_start',true),get_post_meta($b->ID,'_cancha_start',true));});
    if(!$all){echo '<div class="module-empty">No hay próximos partidos publicados.</div>';return;}
    echo '<div class="cancha-fixture-list">';foreach(array_slice($all,0,6) as $m){
        echo '<div class="fixture-row"><span>'.esc_html(get_the_title((int)get_post_meta($m->ID,'_cancha_home',true))).'</span><strong>vs.</strong><span>'.esc_html(get_the_title((int)get_post_meta($m->ID,'_cancha_away',true))).'</span><small>'.esc_html(str_replace('T',' ',get_post_meta($m->ID,'_cancha_start',true))).'</small></div>';
    }echo '</div>';
});
add_action('cancha_render_calendario',function(){
    $comps=cancha_comp_tournaments();if(!$comps){echo '<div class="module-empty">Todavía no hay campeonatos.</div>';return;}
    foreach($comps as $comp){echo '<h2 style="margin:18px 0 12px">'.esc_html(get_the_title($comp)).'</h2>'.cancha_comp_fixture_html($comp->ID);}
});
add_action('admin_menu',function(){
    if(!function_exists('cancha_core_dashboard')) add_menu_page('CANCHA','CANCHA','manage_options','cancha-core',function(){echo '<div class="wrap"><h1>CANCHA</h1><p>Gestión deportiva.</p></div>';},'dashicons-groups',26);
},9);
add_action('admin_enqueue_scripts',function($hook){
    $screen=get_current_screen();if(!$screen||!in_array($screen->post_type,['cancha_campeonato','cancha_equipo','cancha_partido'],true))return;
    wp_enqueue_style('cancha-competitions-admin',plugins_url('assets/competitions-admin.css',__FILE__),[],CANCHA_COMP_VERSION);
});
add_action('wp_enqueue_scripts',function(){wp_enqueue_style('cancha-competitions',plugins_url('assets/competitions.css',__FILE__),[],CANCHA_COMP_VERSION);});
add_filter('manage_cancha_partido_posts_columns',function($cols){$cols['cancha_match_info']='Jornada y marcador';return $cols;});
add_action('manage_cancha_partido_posts_custom_column',function($col,$id){
 if($col==='cancha_match_info'){
    $st=get_post_meta($id,'_cancha_result_status',true);echo (get_post_meta($id,'_cancha_stage',true)==='ko'?esc_html(trim(ucfirst((string)get_post_meta($id,'_cancha_cup',true)).' '.(string)get_post_meta($id,'_cancha_ko_round',true))):'J'.absint(get_post_meta($id,'_cancha_round',true))).' · '.($st==='final'?'✅ Final':'🕓 Pendiente');
 }
},10,2);

// Migrate rewrites when updating without deactivating the plugin.
add_action('admin_init',function(){ if(!current_user_can('manage_options'))return; if(get_option('cancha_comp_schema_version')!==CANCHA_COMP_VERSION){flush_rewrite_rules(false);update_option('cancha_comp_schema_version',CANCHA_COMP_VERSION,false);} });
