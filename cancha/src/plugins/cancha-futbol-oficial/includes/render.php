<?php
if (!defined('ABSPATH')) exit;
function cof_page_url(){
    $id=get_queried_object_id();
    if($id && get_post_type($id)==='page')return get_permalink($id);
    $page=get_page_by_path('futbol-oficial');return $page?get_permalink($page):home_url('/futbol-oficial/');
}
function cof_url($comp='',$view='partidos'){
    $params=[];if($comp){$params['cof_comp']=$comp;$params['cof_view']=$view;}
    return $params?add_query_arg($params,cof_page_url()):cof_page_url();
}
function cof_short_date($iso){
    if(!$iso)return 'Fecha a confirmar';
    try {
        $s=cof_settings(); $zone=new DateTimeZone($s['timezone']);
        // TheSportsDB sends date-only values; never shift them to the previous day.
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$iso)){
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$iso,$zone);
            return $d ? $d->format('d/m') . ' · Hora a confirmar' : 'Fecha a confirmar';
        }
        $date=new DateTimeImmutable($iso,new DateTimeZone('UTC'));
        return $date->setTimezone($zone)->format('d/m · H:i');
    }catch(Exception $e){return 'Fecha a confirmar';}
}
function cof_status($code,$has_score=false){
    $code=strtoupper(trim((string)$code));
    if(in_array($code,['1H','2H','HT','ET','BT','P','LIVE','IN PLAY'],true))return ['En vivo','live'];
    if(in_array($code,['FT','AET','PEN','MATCH FINISHED','FINISHED','FULL TIME'],true))return ['Final','final'];
    if(in_array($code,['PST','CANC','SUSP','INT','ABD','AWD','WO'],true))return ['Suspendido / aplazado','delayed'];
    if(in_array($code,['NS','TBD','NOT STARTED','SCHEDULED',''],true))return $has_score?['Final','final']:['Programado','upcoming'];
    return [sanitize_text_field($code),'other'];
}
function cof_match_html($match){
    $has=$match['home_score']!==null && $match['away_score']!==null;
    [$label,$status]=cof_status($match['status'],$has);
    $score=$has?((int)$match['home_score'].' - '.(int)$match['away_score']):'vs';
    return '<div class="cof-match">'
        .'<div class="cof-match-top"><span>'.esc_html(cof_short_date($match['date'])).'</span><span class="cof-status cof-'.$status.'">'.esc_html($label).'</span></div>'
        .'<div class="cof-match-main"><span class="cof-team">'.esc_html($match['home']).'</span><strong class="cof-score">'.esc_html($score).'</strong><span class="cof-team cof-away">'.esc_html($match['away']).'</span></div>'
        .($match['round']?'<div class="cof-match-round">'.esc_html($match['round']).'</div>':'').'</div>';
}
function cof_is_final($m){return cof_status($m['status'],$m['home_score']!==null && $m['away_score']!==null)[1]==='final';}
function cof_is_upcoming($m){return in_array(cof_status($m['status'],$m['home_score']!==null && $m['away_score']!==null)[1],['upcoming','delayed'],true);}
function cof_league_tile($code,$title){
    $icon=in_array($code,['libertadores','sudamericana'],true)?'🏆':'⚽';
    $kind=in_array($code,['libertadores','sudamericana'],true)?'CONMEBOL':'ARGENTINA';
    $s=cof_settings();$enabled=cof_provider_id($code)>0 && ($s['provider']==='thesportsdb'||cof_key());
    $label=$enabled?'Proveedor configurado':'Pendiente de conexión';
    return '<a class="cof-competition-card cof-card-'.esc_attr($code).'" href="'.esc_url(cof_url($code)).'">'
       .'<div class="cof-competition-art"><span class="cof-competition-icon" aria-hidden="true">'.$icon.'</span><div><small>'.esc_html($kind).'</small><h3>'.esc_html($title).'</h3></div></div>'
       .'<div class="cof-competition-footer"><span class="cof-status '.($enabled?'cof-ready':'cof-pending').'">● '.esc_html($label).'</span><span aria-hidden="true">›</span></div></a>';
}
function cof_standings_html($comp){
    $r=cof_get_standings($comp);
    if(is_wp_error($r))return '<div class="cof-empty">'.esc_html($r->get_error_message()).'</div>';
    if(empty($r['groups']))return '<div class="cof-empty">Este proveedor no devuelve una tabla de posiciones para esta competición y temporada. Puede tratarse de una copa eliminatoria o una limitación del plan.</div>';
    $html='';
    if (cof_settings()['provider']==='thesportsdb') {
        $html.='<p class="cof-disclosure"><strong>Tabla parcial:</strong> la API gratuita de TheSportsDB puede entregar como máximo cinco posiciones. No representa la clasificación completa.</p>';
    }
    if (cof_settings()['provider']==='thesportsdb' && $comp==='liga_profesional') {
        $html.='<p class="cof-disclosure"><strong>Advertencia:</strong> TheSportsDB no garantiza una tabla diferenciada y actualizada de Apertura y Clausura. Comprobá la etapa indicada antes de utilizarla.</p>';
    }
    $groupno=0;
    foreach($r['groups'] as $group){
        if(++$groupno>12)break;
        $name=$group[0]['group']??'';
        $html.='<div class="cof-table-wrap">'.($name?'<h3>'.esc_html($name).'</h3>':'').'<table class="cof-table"><thead><tr><th>#</th><th>Equipo</th><th>PJ</th><th>G</th><th>E</th><th>P</th><th>DG</th><th>Pts</th></tr></thead><tbody>';
        foreach(array_slice($group,0,40) as $row){
            $html.='<tr><td>'.esc_html($row['rank']).'</td><td>'.esc_html($row['team']).'</td><td>'.esc_html($row['played']).'</td><td>'.esc_html($row['win']).'</td><td>'.esc_html($row['draw']).'</td><td>'.esc_html($row['lose']).'</td><td>'.esc_html($row['goals_for']-$row['goals_against']).'</td><td><strong>'.esc_html($row['points']).'</strong></td></tr>';
        }
        $html.='</tbody></table></div>';
    }
    return $html.'<p class="cof-freshness">'.(!empty($r['stale'])?'Última tabla disponible (puede estar desactualizada).':'Última consulta: '.esc_html(wp_date('d/m/Y H:i',$r['fetched']))).'</p>';
}
function cof_selected_competition($comp,$view){
    $name=cof_competitions()[$comp];
    $html='<div class="cof-breadcrumb"><a href="'.esc_url(cof_url()).'">← Todas las competiciones</a></div><h2>'.esc_html($name).'</h2><nav class="cof-tabs" aria-label="Vistas de competición">';
    foreach(['partidos'=>'Próximos y en vivo','resultados'=>'Resultados','posiciones'=>'Posiciones'] as $slug=>$title){
        $html.='<a href="'.esc_url(cof_url($comp,$slug)).'" '.($view===$slug?'aria-current="page" class="cof-active"':'').'>'.esc_html($title).'</a>';
    }
    $html.='</nav>';
    if($view==='posiciones')return $html.cof_standings_html($comp);
    $data=cof_get_fixtures($comp);
    if(is_wp_error($data))return $html.'<div class="cof-empty">'.esc_html($data->get_error_message()).'</div>';
    $matches=$data['matches'];
    if($view==='resultados'){
        $matches=array_reverse(array_values(array_filter($matches,'cof_is_final')));
    }else{
        $matches=array_values(array_filter($matches,static function($m){return !cof_is_final($m);}));
    }
    if(!$matches)$html.='<div class="cof-empty">No hay encuentros disponibles en esta vista y temporada. Revisá la cobertura de la API.</div>';
    else {foreach(array_slice($matches,0,30) as $m)$html.=cof_match_html($m);}
    $html.='<p class="cof-freshness">'.(!empty($data['stale'])?'Mostrando última consulta disponible; la actualización falló.':'Consultado: '.esc_html(wp_date('d/m/Y H:i',$data['fetched']))).'</p>';
    $html.='<p class="cof-disclosure">Se muestran encuentros disponibles en el proveedor, no necesariamente el fixture completo. API-Football: ventana de 14 días anteriores y 21 siguientes. TheSportsDB gratuito: resultados limitados.</p>';
    return $html;
}
function cof_shortcode($atts=[]){
    $attrs=shortcode_atts(['competicion'=>'','vista'=>'partidos'],$atts,'cancha_oficial');
    $comp=sanitize_key($attrs['competicion']?:wp_unslash($_GET['cof_comp']??''));
    $view=sanitize_key($attrs['vista']);
    if(isset($_GET['cof_view']) && !$attrs['competicion'])$view=sanitize_key(wp_unslash($_GET['cof_view']));
    if(!in_array($view,['partidos','resultados','posiciones'],true))$view='partidos';
    $html='<div class="cof-shell"><div class="cof-head"><div><span class="cof-eyebrow">CANCHA · FÚTBOL OFICIAL</span><h2>El fútbol, en tu cancha</h2><p>Fixture, resultados y posiciones conectados a fuentes deportivas. Actualizados mediante caché.</p></div><span class="cof-brand" aria-hidden="true">⚽</span></div>';
    if($comp && array_key_exists($comp,cof_competitions())){
        $html.='<div class="cof-content">'.cof_selected_competition($comp,$view).'</div>';
    }else{
        $html.='<div class="cof-grid">';foreach(cof_competitions() as $code=>$title)$html.=cof_league_tile($code,$title);
        $html.='</div>';
        $html.='<div class="cof-overview"><h3>Resultados recientes y próximos partidos</h3>';
        foreach(cof_competitions() as $code=>$name){
            $html.='<section id="'.esc_attr($code).'" class="cof-overview-league"><div class="cof-overview-heading"><h4>'.esc_html($name).'</h4><a href="'.esc_url(cof_url($code)).'">Ver detalles ›</a></div>';
            $data=get_transient(cof_cache_key($code,'fixtures'));
            if(!is_array($data))$html.='<p class="cof-empty cof-small">Elegí esta competición para consultar los partidos. Así evitamos gastar solicitudes de la API al abrir la portada.</p>';
            else {
                $past=array_reverse(array_values(array_filter($data['matches'],'cof_is_final')));
                $future=array_values(array_filter($data['matches'],static function($m){return !cof_is_final($m);}));
                $few=array_merge(array_slice($past,0,1),array_slice($future,0,2));
                if(!$few)$html.='<p class="cof-empty cof-small">Sin partidos disponibles para esta consulta.</p>';
                foreach($few as $m)$html.=cof_match_html($m);
            }
            $html.='</section>';
        }
        $html.='</div>';
    }
    return $html.'<footer class="cof-footer">Fuente: '.esc_html(cof_settings()['provider']).' · Los marcadores oficiales no alteran los resultados amateur ni las predicciones. Los horarios se muestran en '.esc_html(cof_settings()['timezone']).'.</footer></div>';
}
function cof_shortcode_competition($atts=[]){
    $a=shortcode_atts(['codigo'=>'liga_profesional','vista'=>'partidos'],$atts,'cancha_oficial_competicion');
    $comp=sanitize_key($a['codigo']);if(!array_key_exists($comp,cof_competitions()))return '<p>Competición desconocida.</p>';
    return cof_shortcode(['competicion'=>$comp,'vista'=>$a['vista']]);
}
