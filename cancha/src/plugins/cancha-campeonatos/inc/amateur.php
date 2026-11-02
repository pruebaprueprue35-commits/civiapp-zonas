<?php
/**
 * CANCHA Amateur 0.5.0 — experiencia propia del fútbol amateur, separada del profesional.
 * Torneo → zonas → fixture por jornada → eliminatorias (Copa Oro / Copa Plata) → equipos → jugadores.
 * Datos aditivos: _cancha_zone (equipo), _cancha_stage / _cancha_cup / _cancha_ko_round (partido).
 * Solo administradores editan (manage_options); el público únicamente consulta.
 */
defined('ABSPATH') || exit;

function cancha_am_ko_rounds(){
    return ['32avos'=>'32avos de final','16avos'=>'16avos de final','octavos'=>'Octavos de final','cuartos'=>'Cuartos de final','semifinal'=>'Semifinal','tercer'=>'Tercer puesto','final'=>'Final'];
}
function cancha_am_cups(){return [''=>'Eliminatoria','oro'=>'Copa Oro','plata'=>'Copa Plata'];}
function cancha_am_team_zone($team){$z=(string)get_post_meta($team,'_cancha_zone',true);return preg_match('/^[A-Za-z0-9 ]{1,12}$/',$z)?strtoupper(trim($z)):'';}
function cancha_am_is_ko($match){return get_post_meta(is_object($match)?$match->ID:$match,'_cancha_stage',true)==='ko';}

/* ---------- Escritorio (solo administradores) ---------- */
add_action('add_meta_boxes',function(){
    add_meta_box('cancha_am_zone','Zona del equipo','cancha_am_zone_box','cancha_equipo','side');
    add_meta_box('cancha_am_stage','Fase del partido (zonas o eliminatoria)','cancha_am_stage_box','cancha_partido','side');
});
function cancha_am_zone_box($post){
    wp_nonce_field('cancha_am_save','cancha_am_nonce');
    echo '<p><label for="cancha_zone">Zona / grupo</label><br><input id="cancha_zone" name="cancha_zone" maxlength="12" pattern="[A-Za-z0-9 ]{0,12}" value="'.esc_attr(cancha_am_team_zone($post->ID)).'" placeholder="A"></p><p class="description">Ej.: A, B, C. Vacío = tabla única.</p>';
}
function cancha_am_stage_box($post){
    wp_nonce_field('cancha_am_save','cancha_am_nonce');
    $stage=get_post_meta($post->ID,'_cancha_stage',true)==='ko'?'ko':'';
    $cup=(string)get_post_meta($post->ID,'_cancha_cup',true);$round=(string)get_post_meta($post->ID,'_cancha_ko_round',true);
    echo '<p><label>Fase<br><select name="cancha_stage"><option value="">Fase de zonas / regular (suma en la tabla)</option><option value="ko" '.selected($stage,'ko',false).'>Eliminatoria (no suma en la tabla)</option></select></label></p>';
    echo '<p><label>Copa<br><select name="cancha_cup">';foreach(cancha_am_cups() as $k=>$l)echo '<option value="'.esc_attr($k).'" '.selected($cup,$k,false).'>'.esc_html($l).'</option>';echo '</select></label></p>';
    echo '<p><label>Instancia<br><select name="cancha_ko_round"><option value="">—</option>';foreach(cancha_am_ko_rounds() as $k=>$l)echo '<option value="'.esc_attr($k).'" '.selected($round,$k,false).'>'.esc_html($l).'</option>';echo '</select></label></p>';
}
function cancha_am_can_save($id){
    if((defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)||wp_is_post_revision($id)||!current_user_can('manage_options'))return false;
    return isset($_POST['cancha_am_nonce'])&&wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cancha_am_nonce'])),'cancha_am_save');
}
add_action('save_post_cancha_equipo',function($id){
    if(!cancha_am_can_save($id))return;
    $z=strtoupper(trim(sanitize_text_field(wp_unslash($_POST['cancha_zone']??''))));
    if($z===''||preg_match('/^[A-Z0-9 ]{1,12}$/',$z))update_post_meta($id,'_cancha_zone',$z);
},20);
add_action('save_post_cancha_partido',function($id){
    if(!cancha_am_can_save($id))return;
    $stage=($_POST['cancha_stage']??'')==='ko'?'ko':'';
    $cup=sanitize_key(wp_unslash($_POST['cancha_cup']??''));if(!isset(cancha_am_cups()[$cup]))$cup='';
    $round=sanitize_key(wp_unslash($_POST['cancha_ko_round']??''));if(!isset(cancha_am_ko_rounds()[$round]))$round='';
    update_post_meta($id,'_cancha_stage',$stage);
    update_post_meta($id,'_cancha_cup',$stage?$cup:'');
    update_post_meta($id,'_cancha_ko_round',$stage?$round:'');
},20);

/* ---------- Datos ---------- */
function cancha_am_match_data($m){
    $id=$m->ID;$h=absint(get_post_meta($id,'_cancha_home',true));$a=absint(get_post_meta($id,'_cancha_away',true));
    return ['id'=>$id,'home'=>$h,'away'=>$a,'round'=>max(1,absint(get_post_meta($id,'_cancha_round',true))),'start'=>(string)get_post_meta($id,'_cancha_start',true),
        'final'=>get_post_meta($id,'_cancha_result_status',true)==='final','hs'=>get_post_meta($id,'_cancha_home_score',true),'as'=>get_post_meta($id,'_cancha_away_score',true),
        'ko'=>cancha_am_is_ko($id),'cup'=>(string)get_post_meta($id,'_cancha_cup',true),'ko_round'=>(string)get_post_meta($id,'_cancha_ko_round',true)];
}
/** Tablas por zona: solo partidos de fase regular, entre equipos de la misma zona del torneo. */
function cancha_am_zone_tables($comp){
    $rows=cancha_comp_standings($comp); // ya excluye eliminatorias (ver cancha_comp_standings)
    $zones=[];
    foreach($rows as $r){$zones[cancha_am_team_zone($r['id'])][]=$r;}
    ksort($zones,SORT_NATURAL);
    return $zones;
}

/* ---------- Componentes públicos ---------- */
function cancha_am_date_label($start){
    if(!preg_match('/^20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}$/',$start))return 'A confirmar';
    $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$start,wp_timezone());
    return $d?wp_date('D j M · H:i',$d->getTimestamp()):'A confirmar';
}
function cancha_am_match_row($m){
    $d=is_array($m)?$m:cancha_am_match_data($m);if(!$d['home']||!$d['away'])return '';
    $score=$d['final']&&is_numeric($d['hs'])&&is_numeric($d['as'])?absint($d['hs']).' - '.absint($d['as']):'vs';
    $hz=cancha_am_team_zone($d['home']);
    $out='<article class="cancha-am-match'.($d['final']?' is-final':'').'" data-origin="amateur"><a class="cancha-am-match-main" href="'.esc_url(get_permalink($d['id'])).'">';
    $out.='<span class="cancha-am-when">'.($d['final']?'Final':esc_html(cancha_am_date_label($d['start']))).'</span>';
    $out.='<span class="cancha-am-team is-home">'.esc_html(get_the_title($d['home'])).'</span><strong class="cancha-am-score">'.esc_html($score).'</strong><span class="cancha-am-team is-away">'.esc_html(get_the_title($d['away'])).'</span>';
    if($hz && !$d['ko'])$out.='<span class="cancha-am-tag">Zona '.esc_html($hz).'</span>';
    $out.='</a>';
    if(function_exists('cancha_pred_amateur_inline'))$out.=cancha_pred_amateur_inline($d['id']);
    return $out.'</article>';
}
function cancha_am_table_html($rows,$title=''){
    if(!$rows)return '';
    $h='<div class="cancha-am-table">'.($title?'<h3>'.esc_html($title).'</h3>':'').'<div class="cancha-standings-wrap"><table class="cancha-standings"><thead><tr><th>#</th><th>Equipo</th><th>PJ</th><th>G</th><th>E</th><th>P</th><th>DG</th><th>Pts</th></tr></thead><tbody>';
    foreach($rows as $i=>$r){$h.='<tr><td>'.($i+1).'</td><th scope="row"><a href="'.esc_url(get_permalink($r['id'])).'">'.esc_html($r['name']).'</a></th>';foreach(['pj','g','e','p','dg','pts'] as $f)$h.='<td>'.esc_html($r[$f]).'</td>';$h.='</tr>';}
    return $h.'</tbody></table></div></div>';
}
function cancha_am_bracket_html($ko,$cup_label){
    $order=array_keys(cancha_am_ko_rounds());$by=[];
    foreach($ko as $d){$by[$d['ko_round']?:'final'][]=$d;}
    uksort($by,static function($a,$b)use($order){return array_search($a,$order,true)<=>array_search($b,$order,true);});
    $h='<section class="cancha-am-cup"><h3>'.esc_html($cup_label).'</h3><div class="cancha-am-bracket">';
    foreach($by as $r=>$list){
        $h.='<div class="cancha-am-bracket-col"><h4>'.esc_html(cancha_am_ko_rounds()[$r]??$r).'</h4>';
        foreach($list as $d){$w=0;if($d['final']&&is_numeric($d['hs'])&&is_numeric($d['as'])){$w=(int)$d['hs']>(int)$d['as']?$d['home']:((int)$d['hs']<(int)$d['as']?$d['away']:0);}
            $h.='<a class="cancha-am-tie" href="'.esc_url(get_permalink($d['id'])).'"><span class="'.($w===$d['home']?'is-winner':'').'">'.esc_html(get_the_title($d['home'])).'<b>'.($d['final']?absint($d['hs']):'').'</b></span><span class="'.($w===$d['away']?'is-winner':'').'">'.esc_html(get_the_title($d['away'])).'<b>'.($d['final']?absint($d['as']):'').'</b></span><small>'.esc_html($d['final']?'Final':cancha_am_date_label($d['start'])).'</small></a>';}
        $h.='</div>';
    }
    return $h.'</div></section>';
}
function cancha_am_status($comp,$matches){
    $fin=0;foreach($matches as $m)if(get_post_meta($m->ID,'_cancha_result_status',true)==='final')$fin++;
    if(get_post_meta($comp,'_cancha_tournament_status',true)==='finished')return ['Finalizado','finished',$fin];
    return $fin?['En juego','playing',$fin]:['Próximamente','upcoming',$fin];
}

/** Página /amateur/: tarjetas de torneos + próximos partidos amateur (nunca profesionales). */
function cancha_amateur_hub_html(){
    $comps=cancha_comp_tournaments();
    $h='<div class="cancha-am-hub" data-origin="amateur"><header class="cancha-am-hero"><span class="eyebrow">FÚTBOL AMATEUR</span><h1>Torneos de CANCHA</h1><p>Zonas, fixture por jornada, tablas, eliminatorias, clubes y planteles. Los partidos profesionales están en <a href="'.esc_url(cancha_route('partidos')).'">Partidos</a>.</p></header>';
    if(has_action('cancha_ads_amateur')){ob_start();do_action('cancha_ads_amateur');$h.=ob_get_clean();}
    if(!$comps)return $h.'<div class="module-empty">Todavía no hay torneos publicados. Los administran los organizadores desde el escritorio.</div></div>';
    $h.='<section class="cancha-am-section"><h2>Torneos</h2><div class="cancha-am-grid">';
    foreach($comps as $c){
        $matches=cancha_comp_matches($c->ID,1000);$teams=cancha_comp_teams($c->ID);[$st,$cls,$fin]=cancha_am_status($c->ID,$matches);
        $zones=[];$rounds=[];$ko=0;foreach($teams as $t){$z=cancha_am_team_zone($t->ID);if($z)$zones[$z]=1;}
        foreach($matches as $m){if(cancha_am_is_ko($m))$ko++;else $rounds[absint(get_post_meta($m->ID,'_cancha_round',true))]=1;}
        $img=get_the_post_thumbnail_url($c,'thumbnail');$cat=(string)get_post_meta($c->ID,'_cancha_category',true);
        $h.='<a class="cancha-am-card" href="'.esc_url(get_permalink($c)).'">'.($img?'<img src="'.esc_url($img).'" alt="" loading="lazy">':'<span class="cancha-am-crest" aria-hidden="true">🏆</span>');
        $h.='<span class="cancha-am-card-body"><strong>'.esc_html(get_the_title($c)).'</strong>'.($cat?'<small>'.esc_html($cat).'</small>':'').'<span class="cancha-am-pill is-'.esc_attr($cls).'">'.esc_html($st).'</span>';
        $h.='<span class="cancha-am-metrics">'.count($teams).' equipos · '.($zones?count($zones).' zonas · ':'').count($rounds).' jornadas'.($ko?' · eliminatorias':'').'</span>';
        $total=count($matches);$h.='<span class="ligas-progress" aria-label="'.absint($fin).' de '.absint($total).' partidos jugados"><span style="width:'.esc_attr((string)($total?round($fin*100/$total):0)).'%"></span></span></span><span aria-hidden="true" class="cancha-am-chev">›</span></a>';
    }
    $h.='</div></section>';
    // Próximos partidos amateur
    $now=wp_date('Y-m-d\TH:i');$next=get_posts(['post_type'=>'cancha_partido','post_status'=>'publish','numberposts'=>12,'meta_key'=>'_cancha_start','orderby'=>'meta_value','order'=>'ASC','meta_query'=>[['key'=>'_cancha_start','value'=>$now,'compare'=>'>=']]]);
    $h.='<section class="cancha-am-section"><h2>Próximos partidos amateur</h2>';
    if(function_exists('cancha_pred_user_allowed')&&!cancha_pred_user_allowed())$h.='<a class="cancha-pred-cta" href="'.esc_url(add_query_arg('tipo','amateur',cancha_route('predicciones'))).'"><span aria-hidden="true">🎯</span><span>Pronosticá 1 · X · 2 los partidos amateur</span><span aria-hidden="true">›</span></a>';
    if(!$next)$h.='<div class="module-empty">No hay partidos amateur programados.</div>';
    else{$group=[];foreach($next as $m)$group[absint(get_post_meta($m->ID,'_cancha_comp_id',true))][]=$m;
        foreach($group as $cid=>$list){$h.='<div class="cancha-am-block"><h3><a href="'.esc_url(get_permalink($cid)).'">'.esc_html(get_the_title($cid)).'</a></h3>';foreach($list as $m)$h.=cancha_am_match_row($m);$h.='</div>';}}
    return $h.'</section></div>';
}
add_action('cancha_render_amateur',function(){echo cancha_amateur_hub_html();});
add_shortcode('cancha_amateur',function(){return cancha_amateur_hub_html();});

/** Ficha del torneo con pestañas: Resumen · Fixture · Zonas · Eliminatorias · Equipos · Jugadores. */
function cancha_amateur_tournament_html($comp){
    $comp=absint($comp);$url=get_permalink($comp);
    $tabs=['resumen'=>'Resumen','fixture'=>'Fixture','zonas'=>'Zonas','eliminatorias'=>'Eliminatorias','equipos'=>'Equipos','jugadores'=>'Jugadores'];
    $view=sanitize_key(wp_unslash($_GET['vista']??'resumen'));if(!isset($tabs[$view]))$view='resumen';
    $matches=array_map('cancha_am_match_data',cancha_comp_matches($comp,2000));
    $regular=array_values(array_filter($matches,static function($d){return !$d['ko'];}));
    $ko=array_values(array_filter($matches,static function($d){return $d['ko'];}));
    $teams=cancha_comp_teams($comp);[$st,$cls]=cancha_am_status($comp,cancha_comp_matches($comp,2000));
    $img=get_the_post_thumbnail_url($comp,'medium');
    $h='<header class="cancha-am-thead glass">'.($img?'<img src="'.esc_url($img).'" alt="">':'<span class="cancha-am-crest" aria-hidden="true">🏆</span>').'<div><a class="cancha-am-back" href="'.esc_url(cancha_route('amateur')).'">‹ Fútbol amateur</a><h1>'.esc_html(get_the_title($comp)).'</h1><span class="cancha-am-pill is-'.esc_attr($cls).'">'.esc_html($st).'</span> <small>'.count($teams).' equipos · '.count($matches).' partidos</small></div></header>';
    $h.='<nav class="cancha-am-tabs" aria-label="Secciones del torneo">';
    foreach($tabs as $k=>$l)$h.='<a href="'.esc_url($k==='resumen'?$url:add_query_arg('vista',$k,$url)).'"'.($k===$view?' aria-current="page" class="is-active"':'').'>'.esc_html($l).'</a>';
    $h.='</nav><div class="cancha-am-panel glass">';
    if($view==='resumen'){
        $content=apply_filters('the_content',get_post_field('post_content',$comp));if(trim(wp_strip_all_tags($content)))$h.='<div class="entry-content">'.$content.'</div>';
        $up=array_values(array_filter($matches,static function($d){return !$d['final'];}));usort($up,static function($a,$b){return strcmp($a['start'],$b['start']);});
        $last=array_values(array_filter($matches,static function($d){return $d['final'];}));usort($last,static function($a,$b){return strcmp($b['start'],$a['start']);});
        $h.='<h2>Próximos partidos</h2>'.($up?implode('',array_map('cancha_am_match_row',array_slice($up,0,5))):'<div class="module-empty">Sin partidos programados.</div>');
        $h.='<h2>Últimos resultados</h2>'.($last?implode('',array_map('cancha_am_match_row',array_slice($last,0,5))):'<div class="module-empty">Todavía no hay resultados validados.</div>');
        ob_start();
        // Otros módulos (datos del torneo, avisos de jornada, publicidad) siguen enganchados.
        if(has_action('cancha_render_campeonato_detalle'))do_action('cancha_render_campeonato_detalle',$comp);
        $h.=ob_get_clean();
    }elseif($view==='fixture'){
        $rounds=[];foreach($regular as $d)$rounds[$d['round']][]=$d;ksort($rounds);
        if(!$rounds){$h.='<div class="module-empty">El fixture de la fase regular todavía no fue publicado.</div>';}
        else{
            $sel=absint($_GET['jornada']??0);
            if(!$sel||!isset($rounds[$sel])){$sel=array_key_first($rounds);foreach($rounds as $r=>$list){foreach($list as $d){if(!$d['final']){$sel=$r;break 2;}}$sel=$r;}}
            $h.='<nav class="cancha-am-rounds" aria-label="Jornadas">';
            foreach(array_keys($rounds) as $r)$h.='<a href="'.esc_url(add_query_arg(['vista'=>'fixture','jornada'=>$r],$url)).'"'.($r===$sel?' aria-current="true" class="is-active"':'').'>J'.absint($r).'</a>';
            $h.='</nav><h2>Jornada '.absint($sel).'</h2>';
            usort($rounds[$sel],static function($a,$b){return strcmp($a['start'],$b['start']);});
            foreach($rounds[$sel] as $d)$h.=cancha_am_match_row($d);
        }
    }elseif($view==='zonas'){
        $zones=cancha_am_zone_tables($comp);
        if(!$zones)$h.='<div class="module-empty">Todavía no hay equipos publicados.</div>';
        foreach($zones as $z=>$rows)$h.=cancha_am_table_html($rows,$z!==''?'Zona '.$z:(count($zones)>1?'Sin zona asignada':'Tabla de posiciones'));
        $h.='<p class="cancha-am-note">Solo cuentan partidos de fase regular con resultado validado. Desempate: puntos, diferencia de gol, goles a favor.</p>';
    }elseif($view==='eliminatorias'){
        if(!$ko)$h.='<div class="module-empty">Las eliminatorias (Copa Oro / Copa Plata) se publicarán al terminar la fase de zonas.</div>';
        else{$cups=[];foreach($ko as $d)$cups[$d['cup']][]=$d;foreach(['oro','plata',''] as $c)if(!empty($cups[$c]))$h.=cancha_am_bracket_html($cups[$c],cancha_am_cups()[$c]);}
    }elseif($view==='equipos'){
        if(!$teams)$h.='<div class="module-empty">Sin equipos publicados.</div>';
        $byz=[];foreach($teams as $t)$byz[cancha_am_team_zone($t->ID)][]=$t;ksort($byz,SORT_NATURAL);
        foreach($byz as $z=>$list){$h.=($z!==''?'<h2>Zona '.esc_html($z).'</h2>':'').'<div class="cancha-am-teams">';
            foreach($list as $t){$logo=get_the_post_thumbnail_url($t,'thumbnail');$n=count(cancha_people_all('cancha_jugador','team',$t->ID,500));
                $h.='<a class="cancha-am-team-card" href="'.esc_url(get_permalink($t)).'">'.($logo?'<img src="'.esc_url($logo).'" alt="" loading="lazy">':'<span aria-hidden="true">🛡</span>').'<strong>'.esc_html(get_the_title($t)).'</strong><small>'.absint($n).' jugadores</small></a>';}
            $h.='</div>';}
    }else{
        if(function_exists('cancha_comp_leaders_html'))$h.='<h2>Goleadores y disciplina</h2>'.cancha_comp_leaders_html($comp);
        $h.='<h2>Planteles</h2>';
        foreach($teams as $t){$players=cancha_people_all('cancha_jugador','team',$t->ID,500);if(!$players)continue;
            $h.='<details class="cancha-am-squad"><summary>'.esc_html(get_the_title($t)).' <small>'.count($players).' jugadores</small></summary><ul>';
            foreach($players as $p){$num=(string)get_post_meta($p->ID,'_cancha_number',true);$pos=(string)get_post_meta($p->ID,'_cancha_position',true);
                $h.='<li><a href="'.esc_url(get_permalink($p)).'"><b>'.esc_html($num!==''?$num:'–').'</b> '.esc_html(get_the_title($p)).' <small>'.esc_html($pos).'</small></a></li>';}
            $h.='</ul></details>';}
    }
    return $h.'</div>';
}
add_action('wp_enqueue_scripts',function(){
    wp_enqueue_style('cancha-amateur',plugins_url('assets/amateur.css',CANCHA_COMP_FILE),[],CANCHA_COMP_VERSION);
},30);
