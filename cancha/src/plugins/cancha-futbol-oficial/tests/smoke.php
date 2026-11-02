<?php
// CLI smoke harness: deterministic WordPress stubs, no live site or provider credentials.
define("ABSPATH", "/fake/wp/");
define("MINUTE_IN_SECONDS",60);define("HOUR_IN_SECONDS",3600);define("DAY_IN_SECONDS",86400);
error_reporting(E_ALL);
$GLOBALS['opts']=[];$GLOBALS['transients']=[];$GLOBALS['http_calls']=0;$GLOBALS['fake_http']=[];
class WP_Error { private $code,$message; public function __construct($c,$m){$this->code=$c;$this->message=$m;} public function get_error_message(){return $this->message;}public function get_error_code(){return $this->code;} }
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(...$args){} function add_filter(...$args){} function add_shortcode(...$args){}
function plugin_dir_path($path){return dirname($path).'/';} function plugin_dir_url($path){return 'https://example.test/wp-content/plugins/cancha-futbol-oficial/';}
function plugin_basename($path){return basename($path);}function wp_date($format,$timestamp=null,$timezone=null){return $timestamp===null?date($format):($timezone? (new DateTimeImmutable('@'.$timestamp))->setTimezone($timezone)->format($format):date($format,$timestamp));}
function wp_salt($key=''){return 'test-key-123456-'. $key;}
function wp_parse_args($args,$defaults){return array_merge($defaults,(array)$args);}
function get_option($k,$default=false){return $GLOBALS['opts'][$k]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['opts'][$key]=$value;return true;}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function set_transient($k,$value,$expiry){$GLOBALS['transients'][$k]=$value;return true;}
function delete_transient($k){unset($GLOBALS['transients'][$k]);}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$s));}
function wp_unslash($s){return $s;}
function absint($x){return abs((int)$x);}
function is_admin(){return false;}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_attr($s){return esc_html($s);}function esc_url($s){return esc_html($s);}
function home_url($path=''){return 'https://example.test'.$path;}
function get_page_by_path($path){return (object)['ID'=>22];}
function get_queried_object_id(){return 22;}
function get_post_type($id){return 'page';}
function get_permalink($post){return 'https://example.test/futbol-oficial/';}
function add_query_arg($query,$url){return $url.'?'.http_build_query($query);}
function shortcode_atts($defaults,$atts,$tag=''){return array_merge($defaults,(array)$atts);}
function wp_safe_remote_get($url,$opts){
    $GLOBALS['http_calls']++;
    if (!str_starts_with($url,'https://v3.football.api-sports.io/') && !str_starts_with($url,'https://www.thesportsdb.com/api/v1/json/123/')) throw new Exception('Host mismatch');
    if (array_key_exists('return_error',$GLOBALS['fake_http']))return new WP_Error('http',$GLOBALS['fake_http']['return_error']);
    return ['response'=>['code'=>200],'body'=>json_encode($GLOBALS['fake_http']['body']??[])];
}
function wp_remote_retrieve_response_code($r){return $r['response']['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function assert_ok($flag,$label){if(!$flag)throw new Exception('FAIL: '.$label);echo "PASS: $label\n";}
require dirname(__DIR__).'/includes/settings.php';
require dirname(__DIR__).'/includes/providers.php';
require dirname(__DIR__).'/includes/render.php';
$all=cof_competitions();assert_ok(count($all)===5,'5 competiciones configuradas');
assert_ok(cof_provider_id('liga_profesional')===4406,'Liga gratis habilitada por defecto');
$GLOBALS['opts']['cof_settings']=array_merge(cof_defaults(),['provider'=>'api-football']);
assert_ok(cof_provider_id('libertadores')===13,'ID aislado por competición');
assert_ok(cof_key()==='','no se expone clave predeterminada');
$key='abcdef1234567890abcdef123456';$encoded=cof_encrypt($key);
$GLOBALS['opts']['cof_settings']=array_merge(cof_defaults(),['provider'=>'api-football','secret'=>$encoded]);
assert_ok($encoded && cof_key()===$key,'clave cifrada y recuperable');
$fixtures=['response'=>[[
'fixture'=>['id'=>100,'date'=>'2026-11-03T22:00:00+00:00','status'=>['short'=>'FT']],
'league'=>['round'=>'Fecha 8'],'teams'=>['home'=>['name'=>'Argentinos Juniors'],'away'=>['name'=>'Racing Club']],
'goals'=>['home'=>0,'away'=>2]
],[
'fixture'=>['id'=>101,'date'=>'2026-11-04T22:00:00+00:00','status'=>['short'=>'NS']],
'league'=>['round'=>'Fecha 9'],'teams'=>['home'=>['name'=>'Boca'],'away'=>['name'=>'River']],
'goals'=>['home'=>null,'away'=>null]
]]];
$parsed=cof_parse_api_matches($fixtures);
assert_ok(count($parsed)===2 && $parsed[0]['home_score']===0 && $parsed[1]['home_score']===null,'parse de resultados 0 y fixture sin goles');
assert_ok(cof_is_final($parsed[0]) && cof_is_upcoming($parsed[1]),'estados final y próximo');
assert_ok(strpos(cof_match_html($parsed[0]),'0 - 2')!==false,'marcador 0-2 mostrado');
$GLOBALS['fake_http']['body']=['response'=>[['league'=>['name'=>'Liga Profesional Argentina'],'country'=>['name'=>'Argentina']]]];
$probe=cof_probe_league_identity('liga_profesional');
assert_ok(!is_wp_error($probe) && $probe['name']==='Liga Profesional Argentina','validación de identidad de liga');
$GLOBALS['fake_http']['body']=['response'=>[['league'=>['name'=>'Sudamericana'],'country'=>['name'=>'South America']]]];
assert_ok(is_wp_error(cof_probe_league_identity('liga_profesional')),'ID de otra liga rechazado');
$GLOBALS['fake_http']['body']=$fixtures;
$result=cof_get_fixtures('liga_profesional');
assert_ok(!is_wp_error($result) && count($result['matches'])===2,'consulta API mock y normalización');
$before=$GLOBALS['http_calls'];cof_get_fixtures('liga_profesional');
assert_ok($before===$GLOBALS['http_calls'],'caché evita peticiones duplicadas');
$GLOBALS['fake_http']['body']=['response'=>[['league'=>['standings'=>[[
['rank'=>1,'team'=>['name'=>'Club A'],'points'=>7,'all'=>['played'=>3,'win'=>2,'draw'=>1,'lose'=>0,'goals'=>['for'=>4,'against'=>1]]]
]]]]]];
$stand=cof_get_standings('liga_profesional');
assert_ok(count($stand['groups'])===1 && $stand['groups'][0][0]['points']===7,'tabla desde API normalizada');
assert_ok(strpos(cof_standings_html('liga_profesional'),'Club A')!==false,'tabla HTML renderizada');
$home=cof_shortcode([]);
assert_ok(strpos($home,'Liga Profesional Argentina')!==false && strpos($home,'CONMEBOL Sudamericana')!==false,'portada de 5 competiciones');
assert_ok(strpos($home,'Elegí esta competición')!==false,'portada sin gasto de cuota para ligas no consultadas');
assert_ok(!is_wp_error(cof_get_fixtures('liga_profesional')),'consulta cacheada sobrevive');
assert_ok(is_wp_error(cof_get_fixtures('xxx')),'competición inválida rechazada');
assert_ok(strpos(cof_match_html(array_merge($parsed[0],['home'=>'<script>alert(1)</script>'])),'<script>')===false,'salida HTML escapada');
$before=$GLOBALS['http_calls'];
cof_purge('liga_profesional');assert_ok(get_transient(cof_cache_key('liga_profesional','fixtures'))===false,'limpieza selectiva de caché');
$GLOBALS['opts']['cof_settings']=array_merge(cof_defaults(),['provider'=>'thesportsdb']);
$GLOBALS['fake_http']['body']=['events'=>[['idEvent'=>'88','strHomeTeam'=>'Boca','strAwayTeam'=>'River','dateEvent'=>'2026-11-03','intHomeScore'=>'1','intAwayScore'=>'1','strStatus'=>'Match Finished']]];
$tsdb=cof_parse_tsdb_matches($GLOBALS['fake_http']['body']);
assert_ok(count($tsdb)===1 && $tsdb[0]['home_score']===1,'TheSportsDB parse independiente');
assert_ok(cof_provider_id('liga_profesional')===4406 && cof_provider_id('sudamericana')===4724,'IDs gratuitos verificados de TheSportsDB');
$GLOBALS['fake_http']['body']=['leagues'=>[['strLeague'=>'Copa Sudamericana','strCountry'=>'South America']]];
assert_ok(!is_wp_error(cof_probe_league_identity('sudamericana')),'TheSportsDB confirma la identidad de la liga');
assert_ok(is_wp_error(cof_probe_league_identity('liga_profesional')),'TheSportsDB rechaza un ID incorrecto');
$GLOBALS['opts']['cof_settings']['tsdb_ids']['sudamericana']=0;
assert_ok(is_wp_error(cof_get_fixtures('sudamericana')),'sin ID no inventa cobertura');
assert_ok(cof_status('PST')[1]==='delayed','aplazamientos etiquetados');
assert_ok(cof_status('HT')[1]==='live','en juego etiquetado');
assert_ok(cof_short_date('2026-11-03')==='03/11 · Hora a confirmar','fecha sin hora no cambia de día');
$budget=['day'=>gmdate('Y-m-d'),'count'=>75];update_option('cof_budget',$budget);
assert_ok(is_wp_error(cof_check_budget()),'presupuesto preventivo máximo');
print "SMOKE TESTS COMPLETE\n";
