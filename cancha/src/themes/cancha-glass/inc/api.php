<?php
/** Resultados oficiales opcionales: HTTP saliente contra hosts fijos, con caché. */
defined('ABSPATH') || exit;
function cancha_official_response($provider, $league_id, $season, $mode='past') {
    $league_id = absint($league_id);
    if (!$league_id || !in_array($mode, ['past','next'], true)) return new WP_Error('params','Configuración de liga inválida.');
    $options=cancha_core_settings();
    $key=cancha_api_key();
    $request=[];
    switch ($provider) {
        case 'thesportsdb':
            $endpoint=$mode==='past' ? 'eventspastleague.php' : 'eventsnextleague.php';
            $request=['url'=>'https://www.thesportsdb.com/api/v1/json/123/'.$endpoint.'?id='.$league_id,'headers'=>[]];
            break;
        case 'api-football':
            if (!$key) return new WP_Error('missing_key','Falta la clave API-Football.');
            if (!preg_match('/^20\d\d$/', $season)) return new WP_Error('season','Indicá un año de temporada válido.');
            $request=['url'=>'https://v3.football.api-sports.io/fixtures?'.http_build_query(['league'=>$league_id,'season'=>$season,'last'=>10]),'headers'=>['x-apisports-key'=>$key]];
            if ($mode==='next') $request['url']='https://v3.football.api-sports.io/fixtures?'.http_build_query(['league'=>$league_id,'season'=>$season,'next'=>10]);
            break;
        case 'football-data':
            return new WP_Error('free_coverage','football-data.org no ofrece gratuitamente la Liga Argentina ni copas CONMEBOL en su catálogo anunciado. Elegí otra fuente.');
        default: return new WP_Error('no_provider','Seleccioná un proveedor gratuito en CANCHA → Configuración.');
    }
    $cache_key='cancha_official_'.md5($provider.'|'.$league_id.'|'.$season.'|'.$mode);
    $cached=get_transient($cache_key);
    if (is_array($cached)) return $cached;
    // Host fijo, HTTPS y redirecciones deshabilitadas: no aceptar destinos arbitrarios desde opciones o usuarios.
    $response=wp_safe_remote_get($request['url'],['timeout'=>max(2,min(18,(int)$options['api_timeout'])),'redirection'=>0,'headers'=>$request['headers'],'limit_response_size'=>262144]);
    if (is_wp_error($response))return new WP_Error('fetch','No se pudo consultar el proveedor.');
    if (wp_remote_retrieve_response_code($response)!==200)return new WP_Error('provider_error','El proveedor no respondió correctamente (HTTP '.absint(wp_remote_retrieve_response_code($response)).').');
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if (!is_array($data)) return new WP_Error('invalid_data','El proveedor devolvió datos no válidos.');
    $events=[];
    if ($provider==='thesportsdb') {
        foreach ((array)($data['events']??[]) as $e) {
            if (!is_array($e)) continue;
            $events[]=[
                'home'=>sanitize_text_field($e['strHomeTeam']??''),'away'=>sanitize_text_field($e['strAwayTeam']??''),
                'home_score'=>isset($e['intHomeScore']) && $e['intHomeScore']!=='' ? (int)$e['intHomeScore'] : null,
                'away_score'=>isset($e['intAwayScore']) && $e['intAwayScore']!=='' ? (int)$e['intAwayScore'] : null,
                'date'=>sanitize_text_field($e['dateEvent']??''), 'status'=>sanitize_text_field($e['strStatus']??''),
            ];
        }
    } else {
        if (!empty($data['errors'])) return new WP_Error('api_error','API-Football notificó un error de consulta. Revisá tu plan, la temporada y el identificador de liga.');
        foreach ((array)($data['response']??[]) as $e) {
            if (!is_array($e)) continue;
            $events[]=[
                'home'=>sanitize_text_field($e['teams']['home']['name']??''),'away'=>sanitize_text_field($e['teams']['away']['name']??''),
                'home_score'=>is_numeric($e['goals']['home']??null)?(int)$e['goals']['home']:null,
                'away_score'=>is_numeric($e['goals']['away']??null)?(int)$e['goals']['away']:null,
                'date'=>sanitize_text_field($e['fixture']['date']??''),'status'=>sanitize_text_field($e['fixture']['status']['short']??''),
            ];
        }
    }
    $events=array_slice($events,0,10);
    // Mantener caché larga de resultados ya finalizados, corta para próximos encuentros.
    set_transient($cache_key,$events,$mode==='past'?3*HOUR_IN_SECONDS:HOUR_IN_SECONDS);
    return $events;
}
function cancha_official_html() {
    $s=cancha_core_settings();
    if ($s['api_provider']==='none') return '<div class="module-empty">Resultados oficiales: elegí un proveedor en CANCHA → Configuración. No se publican marcadores inventados.</div>';
    $ids=(array)($s['official_comp_ids']??[]);
    $labels=['liga_profesional'=>'Liga Profesional Argentina','primera_nacional'=>'Primera Nacional','libertadores'=>'Copa Libertadores','sudamericana'=>'Copa Sudamericana'];
    $html='<div class="cancha-official-results">';$active=0;
    foreach($labels as $code=>$label){
        $id=absint($ids[$code]??0);if(!$id)continue;$active++;
        $html.='<section class="cancha-official-competition" id="'.esc_attr(sanitize_title($code)).'"><h3>'.esc_html($label).'</h3>';
        $events=cancha_official_response($s['api_provider'],$id,$s['official_season'],'past');
        if(is_wp_error($events)){$html.='<div class="module-empty">'.esc_html($events->get_error_message()).'</div></section>';continue;}
        if(!$events){$html.='<div class="module-empty">El proveedor no devolvió resultados para esta competición y temporada.</div></section>';continue;}
        $html.='<div class="cancha-fixture-list">';
        foreach($events as $e){
            if(!$e['home'] || !$e['away'])continue;
            $score=($e['home_score']!==null&&$e['away_score']!==null)?absint($e['home_score']).' : '.absint($e['away_score']):'— : —';
            $html.='<div class="fixture-row"><span>'.esc_html($e['home']).'</span><strong>'.esc_html($score).'</strong><span>'.esc_html($e['away']).'</span><small>'.esc_html(substr($e['date'],0,10)).'</small></div>';
        }
        $html.='</div></section>';
    }
    if(!$active)$html.='<div class="module-empty">Configurá el identificador de al menos una competición en CANCHA → Configuración.</div>';
    return $html.'<p class="muted">Fuente: '.esc_html($s['api_provider']).' · Datos externos consultados con caché. En TheSportsDB gratuito algunas consultas solo devuelven un partido; esta lista no garantiza calendario completo.</p></div>';
}
add_action('cancha_render_futbol_oficial',function(){echo cancha_official_html();});
