<?php
/** Read-only, date-scoped fixture adapter. No writes to amateur competitions. */
defined('ABSPATH') || exit;
function cof_calendar_key($comp, $day) {
    return 'cof_day_' . md5(cof_cache_key($comp,'fixtures') . '|' . $day . '|' . wp_timezone_string());
}
function cof_calendar_validate_day($day) {
    if (!is_string($day) || !preg_match('/^20\d{2}-\d{2}-\d{2}$/D', $day)) return false;
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$day,wp_timezone());
    return $parsed && $parsed->format('Y-m-d')===$day;
}
function cof_local_day($raw){
    $raw=(string)$raw;
    if(preg_match('/^20\d{2}-\d{2}-\d{2}$/',$raw))return $raw;
    try{$d=new DateTimeImmutable($raw);}catch(Exception $e){return '';}
    return $d->setTimezone(wp_timezone())->format('Y-m-d');
}
/** A one-day query increases the useful range without pretending a free plan has a complete fixture. */
function cof_get_calendar_day($comp,$day,$force=false){
    if(!isset(cof_competitions()[$comp]) || !cof_calendar_validate_day($day))return new WP_Error('cof_calendar_day','Fecha o liga incorrecta.');
    if(!cof_provider_id($comp))return new WP_Error('cof_calendar_id','El proveedor no tiene un ID configurado para esta liga.');
    $key=cof_calendar_key($comp,$day);
    if(!$force){$cached=get_transient($key);if(is_array($cached))return $cached;}
    $win=null;$raw=null;
    $settings=cof_settings();
    if($settings['provider']==='api-football'){
        $raw=cof_http('fixtures',['league'=>cof_provider_id($comp),'season'=>(int)cof_season(),'date'=>$day,'timezone'=>$settings['timezone']]);
        if(is_wp_error($raw))return $raw;
        $matches=cof_parse_api_matches($raw);
    }else{
        // Free eventsday is limited (often up to 3 records): merge with the full-round window and
        // keep only matches whose kickoff falls on this day in the site timezone.
        $win=cof_tsdb_window($comp,$force);
        // eventsday only as a fallback when the full-round window is unavailable.
        $raw=is_wp_error($win)?cof_http('eventsday.php',['d'=>$day,'s'=>'Soccer','l'=>cof_provider_id($comp)]):null;
        if(is_wp_error($raw)&&is_wp_error($win))return $raw;
        if(!is_wp_error($win) && !empty($win['partial_fail']))$raw=new WP_Error('cof_partial','');
        $matches=array_merge(is_wp_error($win)?[]:$win['matches'],is_array($raw)?cof_parse_tsdb_matches($raw):[]);
        $matches=array_values(array_filter($matches,static function($m)use($day){return cof_local_day($m['date']??'')===$day;}));
    }
    $unique=[];
    foreach($matches as $m){
        $id=(string)($m['id']??'');
        $k=$id && $id!=='0'?$id:md5(($m['home']??'').'|'.($m['away']??'').'|'.($m['date']??''));
        $unique[$k]=$m;
    }
    usort($unique,static function($a,$b){return strcmp((string)$a['date'],(string)$b['date']);});
    $out=['matches'=>array_values($unique),'fetched'=>time(),'provider'=>$settings['provider'],'partial'=>true,'day'=>$day];
    set_transient($key,$out,(is_wp_error($win)||is_wp_error($raw))?5*MINUTE_IN_SECONDS:30*MINUTE_IN_SECONDS);
    if(function_exists('cancha_pred_official_reconcile_payload'))cancha_pred_official_reconcile_payload($comp,$out);
    return $out;
}
