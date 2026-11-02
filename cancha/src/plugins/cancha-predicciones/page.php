<?php
/**
 * CANCHA Predicciones 0.3.0 — página con pestañas separadas «Oficiales» y «Amateur».
 * Nunca mezcla fixtures: cada pestaña consulta solo su origen y guarda en su propia tabla.
 */
defined('ABSPATH') || exit;

function cancha_pred_current_url(){
    $uri=isset($_SERVER['REQUEST_URI'])?esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])):'/';
    $url=home_url($uri);
    return remove_query_arg(['pred_status','pred_oficial'],$url);
}
/** Vuelve a la página desde donde se pronosticó (solo mismo sitio) o a /predicciones/. */
function cancha_pred_return_url($args){
    $fallback=function_exists('cancha_route')?cancha_route('predicciones'):home_url('/predicciones/');
    $raw=isset($_POST['cancha_return'])?esc_url_raw(wp_unslash($_POST['cancha_return'])):'';
    $base=$raw?wp_validate_redirect($raw,$fallback):$fallback;
    if(strpos($base,'/predicciones')===false)unset($args['tipo']);
    return add_query_arg($args,remove_query_arg(['pred_status','pred_oficial'],$base));
}
function cancha_pred_notice_html(){
    $st=isset($_GET['pred_status'])?sanitize_key(wp_unslash($_GET['pred_status'])):'';
    $msg=['guardado'=>['ok','Pronóstico guardado. Podés cambiarlo hasta que empiece el partido.'],
        'cerrado'=>['warn','Ese partido ya comenzó o está cerrado: no se aceptan más pronósticos.'],
        'proveedor'=>['warn','La fuente externa no confirmó ese partido. Probá de nuevo en unos minutos.'],
        'error'=>['warn','No se pudo guardar el pronóstico. Intentá nuevamente.']][$st]??null;
    return $msg?'<p class="cancha-pred-notice is-'.$msg[0].'" role="status">'.esc_html($msg[1]).'</p>':'';
}
add_action('wp_body_open',function(){
    // Aviso global cuando el pronóstico se hizo desde Partidos, Amateur o la ficha de un torneo.
    if(isset($_GET['pred_status']) && !is_page('predicciones'))echo '<div class="cancha-pred-toast">'.cancha_pred_notice_html().'</div>';
});

/** Partidos oficiales abiertos para pronosticar en los próximos 7 días, agrupados por día y competición. */
function cancha_pred_official_open_matches(){
    if(!function_exists('cof_competitions')||!function_exists('cof_get_fixtures'))return [];
    $out=[];$limit=time()+7*DAY_IN_SECONDS;
    foreach(cof_competitions() as $comp=>$label){
        if(!cof_provider_id($comp))continue;
        $res=cof_get_fixtures($comp);if(is_wp_error($res))continue;
        foreach(($res['matches']??[]) as $m){
            if(!cancha_pred_official_is_open($m))continue;
            if(strtotime($m['date'])>$limit)continue;
            $out[]=['comp'=>$comp,'label'=>$label,'m'=>$m];
        }
    }
    usort($out,static function($a,$b){return strcmp($a['m']['date'],$b['m']['date']);});
    return $out;
}
function cancha_pred_access_html(){
    if(!is_user_logged_in()){
        $login=function_exists('cancha_auth_url')?add_query_arg('redirect_to',cancha_route('predicciones'),cancha_auth_url('ingresar')):wp_login_url();
        $reg=function_exists('cancha_auth_url')?cancha_auth_url('registro'):wp_registration_url();
        return '<div class="cancha-pred-access"><p><strong>Ingresá para pronosticar.</strong> Es gratis, recreativo y sin dinero real.</p><a class="button primary" href="'.esc_url($login).'">Ingresar</a> <a class="button ghost" href="'.esc_url($reg).'">Crear cuenta</a></div>';
    }
    if(cancha_pred_user_allowed())return '';
    if(function_exists('cancha_terms_published')&&!cancha_terms_published())
        return '<div class="cancha-pred-access"><p>Las predicciones se habilitan cuando CANCHA publique sus términos y condiciones vigentes.</p></div>';
    if(get_user_meta(get_current_user_id(),'cancha_email_verified',true)==='pending')
        return '<div class="cancha-pred-access"><p>Confirmá tu correo con el enlace que te enviamos para empezar a pronosticar.</p></div>';
    return '<div class="cancha-pred-access"><p>Para participar aceptá los términos vigentes.</p>'.(function_exists('cancha_terms_accept_form')?cancha_terms_accept_form():'').'</div>';
}
function cancha_pred_my_official(){
    if(!is_user_logged_in())return '';
    global $wpdb;$t=cancha_pred_official_table();
    $rows=$wpdb->get_results($wpdb->prepare("SELECT competition,home_name,away_name,kickoff_utc,pick,points,settled FROM $t WHERE user_id=%d ORDER BY kickoff_utc DESC LIMIT 10",get_current_user_id()),ARRAY_A);
    if(!$rows)return '';
    $names=function_exists('cof_competitions')?cof_competitions():[];
    $h='<details class="cancha-pred-mine"><summary>Mis pronósticos oficiales ('.count($rows).')</summary><ul>';
    foreach($rows as $r){$when=wp_date('j M H:i',strtotime($r['kickoff_utc'].' UTC'));
        $state=$r['settled']===''?'Pendiente':((int)$r['points']>0?'+'.(int)$r['points'].' pts':'0 pts');
        $h.='<li><span>'.esc_html($names[$r['competition']]??$r['competition']).' · '.esc_html($when).'</span><strong>'.esc_html($r['home_name'].' vs '.$r['away_name']).'</strong><em>Elegiste '.esc_html($r['pick']).' · '.esc_html($state).'</em></li>';}
    return $h.'</ul></details>';
}
function cancha_pred_my_amateur(){
    if(!is_user_logged_in())return '';
    global $wpdb;$t=cancha_pred_table();
    $rows=$wpdb->get_results($wpdb->prepare("SELECT match_id,pick,points FROM $t WHERE user_id=%d ORDER BY updated_at DESC LIMIT 10",get_current_user_id()),ARRAY_A);
    if(!$rows)return '';
    $h='<details class="cancha-pred-mine"><summary>Mis pronósticos amateur ('.count($rows).')</summary><ul>';
    foreach($rows as $r){$id=absint($r['match_id']);if(get_post_type($id)!=='cancha_partido')continue;
        $final=get_post_meta($id,'_cancha_result_status',true)==='final';
        $h.='<li><span>'.esc_html(get_the_title(absint(get_post_meta($id,'_cancha_comp_id',true)))).'</span><strong>'.esc_html(get_the_title(absint(get_post_meta($id,'_cancha_home',true))).' vs '.get_the_title(absint(get_post_meta($id,'_cancha_away',true)))).'</strong><em>Elegiste '.esc_html($r['pick']).' · '.esc_html($final?((int)$r['points']>0?'+'.(int)$r['points'].' pts':'0 pts'):'Pendiente').'</em></li>';}
    return $h.'</ul></details>';
}
function cancha_pred_official_points_total(){
    if(!is_user_logged_in())return 0;global $wpdb;$t=cancha_pred_official_table();
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(points),0) FROM $t WHERE user_id=%d",get_current_user_id()));
}

function cancha_pred_page_html(){
    $tipo=isset($_GET['tipo'])&&sanitize_key(wp_unslash($_GET['tipo']))==='amateur'?'amateur':'oficiales';
    $base=function_exists('cancha_route')?cancha_route('predicciones'):home_url('/predicciones/');
    $h='<div class="cancha-pred-v3"><header class="cancha-pred-head"><span class="eyebrow">PREDICCIONES</span><h1>Pronosticá 1 · X · 2</h1><p>Recreativo, por puntos, sin dinero real ni apuestas. Podés cambiar tu elección hasta el inicio del partido.</p></header>';
    $h.=cancha_pred_notice_html();
    if(is_user_logged_in())$h.='<div class="cancha-pred-totals"><span>Oficiales <strong>'.cancha_pred_official_points_total().'</strong> pts</span><span>Amateur <strong>'.cancha_pred_user_points().'</strong> pts</span></div>';
    $h.='<nav class="cancha-pred-tabs" aria-label="Tipo de predicción">';
    foreach(['oficiales'=>'⚽ Oficiales','amateur'=>'🏆 Amateur'] as $k=>$l)$h.='<a href="'.esc_url(add_query_arg('tipo',$k,$base)).'"'.($k===$tipo?' aria-current="page" class="is-active"':'').'>'.esc_html($l).'</a>';
    $h.='</nav>'.cancha_pred_access_html();
    if(has_action('cancha_ads_predicciones')){ob_start();do_action('cancha_ads_predicciones');$h.=ob_get_clean();}
    if($tipo==='oficiales'){
        $h.='<section class="cancha-pred-section" data-origin="profesional"><p class="cancha-pred-source">Fútbol profesional · próximos 7 días · fuente '.esc_html(function_exists('cof_settings')&&cof_settings()['provider']==='api-football'?'API-Football':'TheSportsDB').' (cobertura gratuita parcial). +'.cancha_pred_official_points().' pts por acierto.</p>';
        $list=cancha_pred_official_open_matches();
        if(!$list)$h.='<div class="module-empty">La fuente externa no informó partidos profesionales futuros con horario confirmado en los próximos 7 días.</div>';
        $day='';$comp='';
        foreach($list as $it){
            $d=function_exists('cof_local_day')?cof_local_day($it['m']['date']):substr($it['m']['date'],0,10);
            if($d!==$day){if($day!=='')$h.='</div>';$day=$d;$comp='';$h.='<h2 class="cancha-pred-day">'.esc_html(function_exists('cancha_day_relative_label')?cancha_day_relative_label($d):$d).' <small>'.esc_html(wp_date('j M',strtotime($d.' 12:00'))).'</small></h2><div class="cancha-pred-dayblock">';}
            if($it['comp']!==$comp){$comp=$it['comp'];$h.='<h3 class="cancha-pred-league">'.esc_html($it['label']).'</h3>';}
            $ts=strtotime($it['m']['date']);
            $h.='<article class="cancha-pred-card" data-origin="profesional"><div class="cancha-pred-match"><span class="cancha-pred-hour">'.esc_html(wp_date('H:i',$ts)).'</span><span>'.esc_html($it['m']['home']).'</span><b>vs</b><span>'.esc_html($it['m']['away']).'</span></div>'.cancha_pred_official_inline($it['comp'],$it['m']).'</article>';
        }
        if($day!=='')$h.='</div>';
        $h.=cancha_pred_my_official().'</section>';
    }else{
        $h.='<section class="cancha-pred-section" data-origin="amateur"><p class="cancha-pred-source">Torneos amateur de CANCHA · resultados validados por la organización. +'.cancha_pred_points_per_hit().' pts por acierto.</p>';
        $matches=cancha_pred_upcoming(40);
        if(!$matches)$h.='<div class="module-empty">No hay partidos amateur futuros publicados con fecha y hora. Los organizadores los programan desde el escritorio.</div>';
        $group=[];foreach($matches as $m)$group[absint(get_post_meta($m->ID,'_cancha_comp_id',true))][]=$m;
        foreach($group as $cid=>$list){
            $h.='<h3 class="cancha-pred-league">🏆 '.esc_html($cid?get_the_title($cid):'Amateur').'</h3>';
            foreach($list as $m){$home=absint(get_post_meta($m->ID,'_cancha_home',true));$away=absint(get_post_meta($m->ID,'_cancha_away',true));if(!$home||!$away)continue;
                $start=(string)get_post_meta($m->ID,'_cancha_start',true);$dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$start,wp_timezone());
                $h.='<article class="cancha-pred-card" data-origin="amateur"><div class="cancha-pred-match"><span class="cancha-pred-hour">'.esc_html($dt?wp_date('D j M H:i',$dt->getTimestamp()):'').'</span><span>'.esc_html(get_the_title($home)).'</span><b>vs</b><span>'.esc_html(get_the_title($away)).'</span></div>'.cancha_pred_amateur_inline($m->ID).'</article>';}
        }
        $h.=cancha_pred_my_amateur().'</section>';
    }
    return $h.'</div>';
}
add_action('cancha_render_predicciones',function(){echo cancha_pred_page_html();});
