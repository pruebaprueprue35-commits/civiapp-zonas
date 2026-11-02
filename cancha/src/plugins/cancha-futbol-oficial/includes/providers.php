<?php
if (!defined('ABSPATH')) exit;
function cof_provider_id($comp) {
    $s=cof_settings();$group=$s['provider']==='thesportsdb'?'tsdb_ids':'ids';
    return absint($s[$group][$comp]??0);
}
function cof_cache_key($comp,$kind) {
    $s=cof_settings();
    // 'v120' namespaces caches built after the UTC timestamp fix; old transients simply expire.
    $parts=['v120',$comp,$kind,$s['provider'],cof_provider_id($comp),cof_season(),$s['timezone']];
    // Key rotation invalidates cached data for a previous API account.
    if($s['provider']==='api-football')$parts[]=hash('sha256',cof_key());
    return 'cof_'.md5(implode('|',$parts));
}
function cof_purge($comp){
    foreach(['fixtures','standings'] as $kind){$key=cof_cache_key($comp,$kind);delete_transient($key);delete_transient($key.'_fail');}
    delete_transient('cof_win_'.md5(cof_cache_key($comp,'window')));
}
function cof_check_budget(){
    $today=gmdate('Y-m-d');$record=(array)get_option('cof_budget',[]);
    if(($record['day']??'')!==$today)$record=['day'=>$today,'count'=>0];
    // 75 leaves room for other tools using a shared free key (nominal 100/day).
    if((int)$record['count']>=75)return new WP_Error('cof_quota','Se alcanzó el límite preventivo de 75 consultas diarias de CANCHA Fútbol Oficial.');
    $record['count']++;update_option('cof_budget',$record,false);
    return true;
}
function cof_http($endpoint,$query=[]) {
    $s=cof_settings();
    $query=array_filter($query,static function($v){return $v!==null && $v!=='';});
    if($s['provider']==='api-football') {
        $secret=cof_key();
        if(!$secret)return new WP_Error('cof_key','Falta la clave API-Football. Configurala en CANCHA → Fútbol oficial.');
        $base='https://v3.football.api-sports.io/';
        if(!in_array($endpoint,['fixtures','standings','leagues'],true))return new WP_Error('cof_endpoint','Consulta no permitida.');
        $headers=['x-apisports-key'=>$secret,'Accept'=>'application/json'];
        $guard=cof_check_budget();if(is_wp_error($guard))return $guard;
    } else {
        if(!in_array($endpoint,['eventspastleague.php','eventsnextleague.php','lookuptable.php','lookupleague.php','eventsday.php','eventsround.php'],true))return new WP_Error('cof_endpoint','Consulta no permitida.');
        $base='https://www.thesportsdb.com/api/v1/json/123/';$headers=['Accept'=>'application/json'];
        // Free key: ~30 requests/minute per server IP. Keep a local budget below it to avoid HTTP 429 bursts.
        $bucket='cof_tsdb_rpm_'.gmdate('YmdHi');$used=(int)get_transient($bucket);
        if($used>=24)return new WP_Error('cof_429','Límite local de consultas por minuto (se reintenta en breve).');
        set_transient($bucket,$used+1,2*MINUTE_IN_SECONDS);
    }
    $url=add_query_arg($query,$base.$endpoint);
    $response=wp_safe_remote_get($url,['timeout'=>14,'redirection'=>0,'headers'=>$headers,'limit_response_size'=>1024*1024,'sslverify'=>true]);
    if(is_wp_error($response))return new WP_Error('cof_network','El proveedor no respondió.');
    $status=(int)wp_remote_retrieve_response_code($response);
    if($status===429)return new WP_Error('cof_429','Límite de consultas alcanzado en el proveedor.');
    if($status!==200)return new WP_Error('cof_http','El proveedor devolvió HTTP '.$status.'.');
    $body=(string)wp_remote_retrieve_body($response);
    $data=json_decode($body,true);
    if(!is_array($data))return new WP_Error('cof_format','Respuesta JSON incorrecta del proveedor.');
    if($s['provider']==='api-football' && !empty($data['errors']))return new WP_Error('cof_api','La API rechazó la consulta (revisá la clave, temporada y cobertura de tu plan).');
    return $data;
}

/** Verify API-Football IDs from the provider instead of trusting suggested IDs blindly. */
function cof_probe_league_identity($comp){
    $s=cof_settings();$id=cof_provider_id($comp);
    if(!$id)return new WP_Error('cof_no_id','Falta ID para esta competición.');
    if($s['provider']==='thesportsdb'){
        $data=cof_http('lookupleague.php',['id'=>$id]);
        if(is_wp_error($data))return $data;
        $league=$data['leagues'][0]??null;
        if(!is_array($league))return new WP_Error('cof_season','TheSportsDB no reconoce el ID de la competición.');
        $name=sanitize_text_field($league['strLeague']??'');
        $expected=['liga_profesional'=>'Argentinian Primera Division',
            'primera_nacional'=>'Argentinian Primera B Nacional',
            'copa_argentina'=>'Copa Argentina','libertadores'=>'Copa Libertadores',
            'sudamericana'=>'Copa Sudamericana'];
        if(stripos($name,$expected[$comp]??'unknown')===false)
            return new WP_Error('cof_mismatch','El ID '.$id.' corresponde a «'.$name.'» y no coincide con la competición elegida.');
        return ['name'=>$name,'country'=>sanitize_text_field($league['strCountry']??'')];
    }
    $data=cof_http('leagues',['id'=>$id,'season'=>(int)cof_season()]);
    if(is_wp_error($data))return $data;
    $league=$data['response'][0]['league']??null;
    if(!is_array($league))return new WP_Error('cof_season','No se encontró esta competición para el año elegido. Revisá la temporada disponible en tu plan.');
    $name=sanitize_text_field($league['name']??'');
    $country=sanitize_text_field($data['response'][0]['country']['name']??'');
    $expected=['liga_profesional'=>['liga profesional','primera division','primera división'],
        'primera_nacional'=>['primera nacional'], 'copa_argentina'=>['copa argentina'],
        'libertadores'=>['libertadores'], 'sudamericana'=>['sudamericana']];
    $ok=false;
    foreach(($expected[$comp]??[]) as $word){if(stripos($name,$word)!==false){$ok=true;break;}}
    if(!$ok)return new WP_Error('cof_mismatch','El ID '.$id.' pertenece a «'.$name.'» y no coincide con «'.cof_competitions()[$comp].'». Revisá los IDs.');
    return ['name'=>$name,'country'=>$country];
}

/**
 * TheSportsDB strTimestamp is UTC but comes without a timezone suffix ("2026-11-02T22:00:00").
 * 1.1.0 treated it as local time (wrong kickoff hour) and predictions rejected it. Normalize to "...Z".
 */
function cof_tsdb_normalize_timestamp($match){
    $ts=trim((string)($match['strTimestamp']??''));
    if(preg_match('/^(20\d{2}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?(?:\+00:00|Z)?$/i',$ts,$m))return $m[1].'T'.$m[2].($m[3]??':00').'Z';
    if($ts!=='' && preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i',$ts))return $ts;
    $day=trim((string)($match['dateEvent']??''));$time=trim((string)($match['strTime']??''));
    if(preg_match('/^20\d{2}-\d{2}-\d{2}$/',$day) && preg_match('/^(\d{2}:\d{2})(:\d{2})?$/',$time,$t) && $time!=='00:00:00')return $day.'T'.$t[1].':00Z';
    return $day; // date-only values are not prediction-eligible
}
/** Full-round window: the free tier caps eventsday at ~3 rows, but eventsround returns the whole round. */
function cof_tsdb_window($comp,$force=false){
    $key='cof_win_'.md5(cof_cache_key($comp,'window'));
    if(!$force){$c=get_transient($key);if(is_array($c))return $c;}
    $id=cof_provider_id($comp);if(!$id)return new WP_Error('cof_no_id','Falta ID.');
    $past=cof_http('eventspastleague.php',['id'=>$id]);$next=cof_http('eventsnextleague.php',['id'=>$id]);
    if(is_wp_error($past)&&is_wp_error($next))return $next;
    $matches=array_merge(is_wp_error($past)?[]:cof_parse_tsdb_matches($past),is_wp_error($next)?[]:cof_parse_tsdb_matches($next));
    $rounds=[];
    foreach([$past,$next] as $raw){if(is_wp_error($raw))continue;foreach((array)($raw['events']??[]) as $e){$r=absint($e['intRound']??0);if($r)$rounds[$r]=true;}}
    // Current round(s) plus the next one: enough for Anteayer..Pasado mañana with few requests.
    $want=[];foreach(array_keys($rounds) as $r){$want[$r]=true;if($r<100)$want[$r+1]=true;}
    ksort($want);$want=array_slice(array_keys($want),0,3);
    $failed=is_wp_error($past)||is_wp_error($next);
    foreach($want as $r){$raw=cof_http('eventsround.php',['id'=>$id,'r'=>$r,'s'=>cof_season()]);if(is_wp_error($raw)){$failed=true;continue;}$matches=array_merge($matches,cof_parse_tsdb_matches($raw));}
    $unique=[];foreach($matches as $m){$k=$m['id']!=='0'?$m['id']:md5($m['home'].'|'.$m['away'].'|'.$m['date']);$unique[$k]=$m;}
    $out=['matches'=>array_values($unique),'fetched'=>time(),'rounds'=>$want,'partial_fail'=>$failed];
    // Incomplete windows (429/timeouts on the free key) are retried soon instead of being cached for hours.
    set_transient($key,$out,$failed?10*MINUTE_IN_SECONDS:3*HOUR_IN_SECONDS);
    return $out;
}
function cof_parse_tsdb_matches($data){
    $matches=[];
    foreach((array)($data['events']??[]) as $match){
        if(!is_array($match))continue;
        $h=trim(sanitize_text_field($match['strHomeTeam']??''));$a=trim(sanitize_text_field($match['strAwayTeam']??''));
        if(!$h||!$a)continue;
        $home=$match['intHomeScore']??null;$away=$match['intAwayScore']??null;
        $date=cof_tsdb_normalize_timestamp($match);
        $matches[]=[
            'id'=>(string)absint($match['idEvent']??0),'home'=>$h,'away'=>$a,
            'home_score'=>is_numeric($home)?(int)$home:null,'away_score'=>is_numeric($away)?(int)$away:null,
            'date'=>sanitize_text_field($date),'round'=>sanitize_text_field($match['intRound']??''),
            'status'=>sanitize_text_field($match['strStatus']??''), 'source'=>'TheSportsDB',
        ];
    }
    return $matches;
}
function cof_parse_api_matches($data){
    $matches=[];
    foreach((array)($data['response']??[]) as $match){
        if(!is_array($match))continue;
        $h=trim(sanitize_text_field($match['teams']['home']['name']??''));$a=trim(sanitize_text_field($match['teams']['away']['name']??''));
        if(!$h || !$a)continue;
        $hs=$match['goals']['home']??null;$as=$match['goals']['away']??null;
        $matches[]=[
            'id'=>(string)absint($match['fixture']['id']??0),'home'=>$h,'away'=>$a,
            'home_score'=>is_numeric($hs)?(int)$hs:null,'away_score'=>is_numeric($as)?(int)$as:null,
            'date'=>sanitize_text_field($match['fixture']['date']??''),
            'round'=>sanitize_text_field($match['league']['round']??''),
            'status'=>sanitize_text_field($match['fixture']['status']['short']??''),'source'=>'API-Football',
        ];
    }
    return $matches;
}
function cof_fetch_fixtures($comp){
    $s=cof_settings();$id=cof_provider_id($comp);if(!$id)return new WP_Error('cof_no_id','Falta configurar el ID de la competición en el proveedor elegido.');
    if($s['provider']==='api-football'){
        $tz=new DateTimeZone($s['timezone']);$now=new DateTimeImmutable('now',$tz);
        // Rolling fixture window for a compact responsive live view; never claims full season.
        $query=['league'=>$id,'season'=>(int)cof_season(),'from'=>$now->modify('-14 days')->format('Y-m-d'),'to'=>$now->modify('+21 days')->format('Y-m-d'),'timezone'=>$s['timezone']];
        $raw=cof_http('fixtures',$query);if(is_wp_error($raw))return $raw;
        $matches=cof_parse_api_matches($raw);
    }else{
        // Free tier: whole rounds around the current one (eventsround) instead of 1 past + 1 next event.
        $win=cof_tsdb_window($comp);
        if(is_wp_error($win))return $win;
        $matches=$win['matches'];
    }
    $unique=[];
    foreach($matches as $match){$k=$match['id']!=='0'?$match['id']:md5($match['home'].'|'.$match['away'].'|'.$match['date']);$unique[$k]=$match;}
    $matches=array_values($unique);
    usort($matches,static function($a,$b){return strcmp($a['date'],$b['date']);});
    $matches=array_slice($matches,0,100);
    return ['matches'=>$matches,'partial'=>true,'fetched'=>time(),'provider'=>$s['provider']];
}
function cof_get_fixtures($comp,$force=false){
    if(!array_key_exists($comp,cof_competitions()))return new WP_Error('cof_comp','Competición desconocida.');
    $cache=cof_cache_key($comp,'fixtures');$failed=get_transient($cache.'_fail');
    if(!$force){$saved=get_transient($cache);if(is_array($saved))return $saved;
        if($failed){$old=get_transient($cache.'_good');if(is_array($old)){$old['stale']=true;return $old;}return new WP_Error('cof_backoff','No se pudo consultar ahora; intentá más tarde.');}}
    $result=cof_fetch_fixtures($comp);
    if(is_wp_error($result)){
        set_transient($cache.'_fail',1,5*MINUTE_IN_SECONDS);
        if(!$force){$old=get_transient($cache.'_good');if(is_array($old)){$old['stale']=true;return $old;}}
        return $result;
    }
    delete_transient($cache.'_fail');
    $minutes=max(60,min(360,(int)cof_settings()['cache_minutes']));
    set_transient($cache,$result,$minutes*MINUTE_IN_SECONDS);
    set_transient($cache.'_good',$result,7*DAY_IN_SECONDS);
    return $result;
}
function cof_parse_api_standings($data){
    $groups=[];
    $response=(array)($data['response']??[]);
    foreach($response as $entry){
        foreach((array)($entry['league']['standings']??[]) as $group){
            if(!is_array($group))continue;
            $rows=[];
            foreach($group as $row){
                if(!is_array($row))continue;
                $all=(array)($row['all']??[]);
                $rows[]=['rank'=>(int)($row['rank']??0),'team'=>sanitize_text_field($row['team']['name']??''),
                    'played'=>(int)($all['played']??0),'win'=>(int)($all['win']??0),'draw'=>(int)($all['draw']??0),
                    'lose'=>(int)($all['lose']??0),'goals_for'=>(int)($all['goals']['for']??0),'goals_against'=>(int)($all['goals']['against']??0),'points'=>(int)($row['points']??0),
                    'group'=>sanitize_text_field($row['group']??'')];
            }
            if($rows)$groups[]=$rows;
        }
    }
    return $groups;
}
function cof_parse_tsdb_standings($data){
    $rows=[];
    foreach((array)($data['table']??[]) as $row){
        if(!is_array($row))continue;
        $rows[]=['rank'=>(int)($row['intRank']??0),'team'=>sanitize_text_field($row['strTeam']??''),
        'played'=>(int)($row['intPlayed']??0),'win'=>(int)($row['intWin']??0),'draw'=>(int)($row['intDraw']??0),'lose'=>(int)($row['intLoss']??0),
        'goals_for'=>(int)($row['intGoalsFor']??0),'goals_against'=>(int)($row['intGoalsAgainst']??0),'points'=>(int)($row['intPoints']??0),'group'=>sanitize_text_field($row['strGroup']??'')];
    }
    return $rows?[$rows]:[];
}
function cof_get_standings($comp){
    if(!array_key_exists($comp,cof_competitions()))return new WP_Error('cof_comp','Competición desconocida.');
    $key=cof_cache_key($comp,'standings');$cached=get_transient($key);if(is_array($cached))return $cached;
    if(get_transient($key.'_fail')){
        $stale=get_transient($key.'_good');if(is_array($stale)){$stale['stale']=true;return $stale;}
        return new WP_Error('cof_backoff','Consulta temporalmente no disponible.');
    }
    $id=cof_provider_id($comp);if(!$id)return new WP_Error('cof_no_id','Falta configurar el ID de esta competición.');
    $s=cof_settings();
    $data=$s['provider']==='api-football'?cof_http('standings',['league'=>$id,'season'=>(int)cof_season()]):cof_http('lookuptable.php',['l'=>$id,'s'=>cof_season()]);
    if(is_wp_error($data)){
        set_transient($key.'_fail',1,10*MINUTE_IN_SECONDS);
        $stale=get_transient($key.'_good');if(is_array($stale)){$stale['stale']=true;return $stale;}
        return $data;
    }
    $groups=$s['provider']==='api-football'?cof_parse_api_standings($data):cof_parse_tsdb_standings($data);
    $response=['groups'=>$groups,'fetched'=>time(),'provider'=>$s['provider']];
    set_transient($key,$response,6*HOUR_IN_SECONDS);
    set_transient($key.'_good',$response,7*DAY_IN_SECONDS);
    return $response;
}
