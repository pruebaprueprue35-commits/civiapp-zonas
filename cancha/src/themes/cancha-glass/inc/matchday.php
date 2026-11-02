<?php
/** CANCHA Partidos: fecha navegable SOLO de fútbol profesional. El amateur tiene su propia experiencia en /amateur/. */
defined('ABSPATH') || exit;
function cancha_day_valid($day){
    if(!is_string($day)||!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$day))return false;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$day,wp_timezone());
    return $d && $d->format('Y-m-d')===$day;
}
function cancha_day_selected(){
    $today=wp_date('Y-m-d');
    $candidate=isset($_GET['fecha'])?sanitize_text_field(wp_unslash($_GET['fecha'])):$today;
    if(!cancha_day_valid($candidate))return $today;
    $diff=(new DateTimeImmutable($today,wp_timezone()))->diff(new DateTimeImmutable($candidate,wp_timezone()));
    return $diff->days>45?$today:$candidate;
}
function cancha_day_url($day,$base=''){
    $url=$base?:cancha_route('partidos');
    return add_query_arg('fecha',$day,$url);
}
function cancha_day_relative_label($day){
    $today=new DateTimeImmutable(wp_date('Y-m-d'),wp_timezone());
    $d=new DateTimeImmutable($day,wp_timezone());
    $days=(int)$today->diff($d)->format('%r%a');
    $labels=[-2=>'Anteayer',-1=>'Ayer',0=>'Hoy',1=>'Mañana',2=>'Pasado mañana'];
    return $labels[$days]??wp_date('D j M',$d->getTimestamp());
}
function cancha_match_kickoff($raw){
    if(!is_string($raw) || !preg_match('/^20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}/',$raw))return null;
    try{return new DateTimeImmutable($raw, preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i',$raw)?null:wp_timezone());}
    catch(Exception $e){return null;}
}
function cancha_match_day($raw){
    $dt=cancha_match_kickoff($raw);
    if($dt)return $dt->setTimezone(wp_timezone())->format('Y-m-d');
    return preg_match('/^20\d{2}-\d{2}-\d{2}$/D',(string)$raw)?$raw:'';
}
function cancha_amateur_day_matches($day){
    if(!post_type_exists('cancha_partido'))return [];
    return get_posts(['post_type'=>'cancha_partido','post_status'=>'publish','posts_per_page'=>200,
        'meta_key'=>'_cancha_start','orderby'=>'meta_value','order'=>'ASC',
        'meta_query'=>[['key'=>'_cancha_start','value'=>[$day.'T00:00',$day.'T23:59'],'compare'=>'BETWEEN','type'=>'CHAR']]]);
}
function cancha_day_navigation($day,$home=false){
    $selected=new DateTimeImmutable($day,wp_timezone());
    $base=$home?home_url('/'):cancha_route('partidos');
    echo '<nav class="cancha-datebar" aria-label="Elegir fecha de partidos">';
    echo '<a class="cancha-day-arrow" aria-label="Día anterior" href="'.esc_url(cancha_day_url($selected->modify('-1 day')->format('Y-m-d'),$base)).'">‹</a>';
    echo '<div class="cancha-day-scroll">';
    for($i=-2;$i<=2;$i++){
        $d=$selected->modify(($i>=0?'+':'').$i.' days')->format('Y-m-d');
        $active=$d===$day;
        echo '<a class="cancha-day-item'.($active?' is-selected':'').'" href="'.esc_url(cancha_day_url($d,$base)).'" '.($active?'aria-current="date"':'').'><span>'.esc_html(cancha_day_relative_label($d)).'</span><small>'.esc_html(wp_date('j M',(new DateTimeImmutable($d,wp_timezone()))->getTimestamp())).'</small></a>';
    }
    echo '</div><a class="cancha-day-arrow" aria-label="Día siguiente" href="'.esc_url(cancha_day_url($selected->modify('+1 day')->format('Y-m-d'),$base)).'">›</a>';
    echo '<form class="cancha-calendar-picker" method="get" action="'.esc_url($base).'" aria-label="Ir a una fecha"><label for="cancha-date-input">📅 <span class="screen-reader-text">Elegir fecha</span></label><input id="cancha-date-input" type="date" name="fecha" value="'.esc_attr($day).'" min="'.esc_attr(wp_date('Y-m-d',strtotime('-45 days'))).'" max="'.esc_attr(wp_date('Y-m-d',strtotime('+45 days'))).'" onchange="this.form.submit()"></form>';
    echo '</nav>';
}
function cancha_amateur_result_row($post){
    $id=$post->ID;$h=absint(get_post_meta($id,'_cancha_home',true));$a=absint(get_post_meta($id,'_cancha_away',true));
    if(!$h || !$a)return;
    $start=(string)get_post_meta($id,'_cancha_start',true);$dt=cancha_match_kickoff($start);
    $status=(string)get_post_meta($id,'_cancha_result_status',true);$final=$status==='final';
    $home_score=get_post_meta($id,'_cancha_home_score',true);$away_score=get_post_meta($id,'_cancha_away_score',true);
    echo '<article class="cancha-fixture"><a class="cancha-fixture-main" href="'.esc_url(get_permalink($id)).'">';
    echo '<span class="cancha-fixture-time">'.($final?'Final':($dt?esc_html($dt->format('H:i')):'A confirmar')).'</span>';
    echo '<span class="cancha-fixture-team">'.esc_html(get_the_title($h)).'</span><strong class="cancha-fixture-result">'.($final&&is_numeric($home_score)&&is_numeric($away_score)?esc_html($home_score.' – '.$away_score):'vs').'</strong><span class="cancha-fixture-team">'.esc_html(get_the_title($a)).'</span><span class="cancha-fixture-more" aria-hidden="true">›</span></a>';
    if(function_exists('cancha_pred_amateur_inline'))echo cancha_pred_amateur_inline($id);
    echo '</article>';
}
function cancha_pro_league_badge($comp){
    return ['liga_profesional'=>'LPF','primera_nacional'=>'PN','copa_argentina'=>'CA','libertadores'=>'LIB','sudamericana'=>'SUD'][$comp]??'⚽';
}
function cancha_official_result_row($match,$comp){
    $dt=cancha_match_kickoff($match['date']??'');
    $s=strtoupper((string)($match['status']??''));
    $final=in_array($s,['FT','AET','PEN','MATCH FINISHED','FINISHED','FULL TIME','FULLTIME','AFTER EXTRA TIME','AFTER PENALTIES'],true);
    $live=in_array($s,['1H','2H','HT','ET','BT','P','LIVE','IN PLAY'],true);
    $off=in_array($s,['PST','CANC','SUSP','INT','ABD','AWD','WO','POSTPONED','MATCH POSTPONED','CANCELLED'],true);
    $has=is_numeric($match['home_score']??null)&&is_numeric($match['away_score']??null);
    $home=$match['home']??'';$away=$match['away']??'';
    $time=$dt?$dt->setTimezone(wp_timezone())->format('H:i'):'A conf.';
    $state=$final?'Final':($live?'En vivo':($off?'Aplazado':''));
    echo '<article class="cancha-fixture cancha-pro-row'.($final?' is-final':'').($live?' is-live':'').'" data-origin="profesional"><div class="cancha-fixture-main">';
    echo '<span class="cancha-fixture-time">'.esc_html($state).'</span>';
    echo '<span class="cancha-fixture-team is-home">'.esc_html($home).'</span>';
    echo '<strong class="cancha-fixture-result">'.(($final||$live)&&$has?esc_html($match['home_score'].' - '.$match['away_score']):esc_html($state?'vs':$time)).'</strong>';
    echo '<span class="cancha-fixture-team is-away">'.esc_html($away).'</span></div>';
    if(function_exists('cancha_pred_official_inline')) echo cancha_pred_official_inline($comp,$match);
    echo '</article>';
}
function cancha_matchday_render($day=null,$show_datebar=true){
    $day=$day&&cancha_day_valid($day)?$day:cancha_day_selected();
    $today=$day===wp_date('Y-m-d');
    echo '<section class="cancha-matchday" id="partidos" data-origin="profesional"><div class="cancha-matchday-heading"><div><span class="eyebrow">FÚTBOL PROFESIONAL</span><'.(is_front_page()?'h2':'h1').' class="cancha-matchday-title">Partidos · '.esc_html(cancha_day_relative_label($day)).'</'.(is_front_page()?'h2':'h1').'></div><span class="cancha-coverage-note">Horarios de Argentina</span></div>';
    if($show_datebar)cancha_day_navigation($day,is_front_page());
    if(function_exists('cancha_pred_user_allowed') && !cancha_pred_user_allowed()){
        $link=is_user_logged_in()?cancha_route('predicciones'):add_query_arg('redirect_to',cancha_route('partidos'),function_exists('cancha_auth_url')?cancha_auth_url('ingresar'):wp_login_url());
        echo '<a class="cancha-pred-cta" href="'.esc_url($link).'"><span aria-hidden="true">🎯</span><span>'.(is_user_logged_in()?'Completá tu cuenta para pronosticar 1 · X · 2':'Ingresá y pronosticá 1 · X · 2 en los partidos que todavía no empezaron').'</span><span aria-hidden="true">›</span></a>';
    }
    echo '<div class="cancha-matchday-grid">';
    $grouped=[]; // Los partidos amateur NO se listan aquí (ver /amateur/).
    $official_count=0;$official_fail=[];$official_checked=false;
    if(function_exists('cof_competitions') && function_exists('cof_get_calendar_day')){
        foreach(cof_competitions() as $comp=>$name){
            if(function_exists('cof_provider_id') && !cof_provider_id($comp))continue;
            $official_checked=true;
            $res=cof_get_calendar_day($comp,$day);
            if(is_wp_error($res)){$official_fail[]=$name;continue;}
            $matches=array_filter($res['matches']??[],static function($m)use($day){return cancha_match_day($m['date']??'')===$day;});
            if(!$matches)continue;
            $official_count+=count($matches);
            echo '<section class="cancha-fixture-league" data-origin="profesional" data-comp="'.esc_attr($comp).'"><div class="cancha-fixture-league-head"><h3><span class="cancha-league-badge" aria-hidden="true">'.esc_html(cancha_pro_league_badge($comp)).'</span> '.esc_html($name).'</h3><span class="cancha-league-count">'.count($matches).' partidos</span><button type="button" class="cancha-league-toggle" aria-expanded="true" aria-label="Contraer liga">⌃</button></div>';
            foreach($matches as $match)cancha_official_result_row($match,$comp);
            echo '</section>';
            if(!is_front_page() && $official_count && empty($cancha_ad_done) && has_action('cancha_ads_partidos')){$cancha_ad_done=true;do_action('cancha_ads_partidos');}
        }
    }
    if(!$grouped && !$official_count){echo '<div class="cancha-no-fixtures"><strong>No recibimos partidos profesionales para esta fecha.</strong><p>Liga Profesional, Primera Nacional, Copa Argentina, Libertadores y Sudamericana se consultan en la fuente externa; si no aparece ninguno, la fuente no informó partidos ese día.</p><a href="'.esc_url(cancha_day_url((new DateTimeImmutable($day,wp_timezone()))->modify('+1 day')->format('Y-m-d'),is_front_page()?home_url('/'):cancha_route('partidos'))).'">Consultar mañana →</a></div>';}
    echo '</div>';
    if($official_checked)echo '<p class="cancha-data-disclosure">Fuente externa: '.esc_html(function_exists('cof_settings')?(cof_settings()['provider']==='thesportsdb'?'TheSportsDB':'API-Football'):'API').'. La cobertura puede ser parcial y los resultados no son oficiales de CANCHA. Para ampliar partidos, configurá un proveedor con cobertura y temporada habilitadas.</p>';
    elseif(!$grouped)echo '<p class="cancha-data-disclosure">Activá CANCHA Fútbol Oficial y configurá la API para consultar las ligas profesionales.</p>';
    echo '</section>';
}
