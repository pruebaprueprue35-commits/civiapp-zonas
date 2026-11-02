<?php
/** Separates official predictions from the amateur ledger and from GamiPress. */
defined('ABSPATH') || exit;
function cancha_pred_official_table(){global $wpdb;return $wpdb->prefix.'cancha_predictions_official';}
function cancha_pred_official_create_table(){
 global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';
 $table=cancha_pred_official_table();$charset=$wpdb->get_charset_collate();
 dbDelta("CREATE TABLE $table (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 user_id bigint(20) unsigned NOT NULL,
 provider varchar(24) NOT NULL,
 competition varchar(40) NOT NULL,
 fixture_id varchar(48) NOT NULL,
 pick varchar(1) NOT NULL,
 home_name varchar(200) NOT NULL DEFAULT '',
 away_name varchar(200) NOT NULL DEFAULT '',
 kickoff_utc varchar(30) NOT NULL DEFAULT '',
 points int(11) NOT NULL DEFAULT 0,
 settled varchar(32) NOT NULL DEFAULT '',
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY user_fixture (user_id,provider,competition,fixture_id),
 KEY fixture_lookup (provider,competition,fixture_id),
 KEY kickoff_lookup (kickoff_utc)
 ) $charset;");
 update_option('cancha_pred_official_db_version','0.2.0',false);
}
register_activation_hook(CANCHA_PRED_FILE,'cancha_pred_official_create_table');
add_action('admin_init',function(){if(current_user_can('manage_options')&&get_option('cancha_pred_official_db_version')!=='0.2.0')cancha_pred_official_create_table();});
function cancha_pred_official_points(){return min(100,max(1,absint(get_option('cancha_pred_official_points',10))));}
function cancha_pred_official_source(){return function_exists('cof_settings')?cof_settings()['provider']:'';}
function cancha_pred_official_is_open($match){
 $id=(string)($match['id']??'');if(!preg_match('/^[1-9][0-9]{0,17}$/D',$id))return false;
 $raw=(string)($match['date']??'');
 // Timezone-qualified, verified provider kickoffs only: no date-only guessing.
 if(!preg_match('/^20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}.*(?:Z|[+-]\d{2}:?\d{2})$/i',$raw))return false;
 $ts=strtotime($raw);
 if(!$ts||$ts<=time()+60 ||$ts>time()+90*DAY_IN_SECONDS)return false;
 $status=strtoupper(trim((string)($match['status']??'')));
 return in_array($status,['','NS','NOT STARTED','SCHEDULED','TIMED','TBD'],true);
}
function cancha_pred_official_existing($provider,$comp,$id,$user=0){
 global $wpdb;$uid=$user?:get_current_user_id();if(!$uid)return null;
 $table=cancha_pred_official_table();
 return $wpdb->get_row($wpdb->prepare("SELECT pick,points,settled FROM $table WHERE user_id=%d AND provider=%s AND competition=%s AND fixture_id=%s LIMIT 1",$uid,$provider,$comp,$id),ARRAY_A);
}
function cancha_pred_official_inline($comp,$m){
 if(!cancha_pred_official_is_open($m))return '';
 $provider=cancha_pred_official_source();if(!in_array($provider,['api-football','thesportsdb'],true))return '';
 $id=(string)$m['id'];$name=(function_exists('cof_competitions')?(cof_competitions()[$comp]??'Fútbol oficial'):'Fútbol oficial');
 // 0.3.0: sin cuenta habilitada no se repite un aviso por partido; la lista muestra un único acceso.
 if(!cancha_pred_user_allowed())return '';
 $out='<div class="cancha-fixture-prediction"><span>🎯 1 · X · 2 · +'.cancha_pred_official_points().' pts</span>';
 $existing=cancha_pred_official_existing($provider,$comp,$id);
 $out.='<form class="cancha-inline-picks" action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
 $out.='<input type="hidden" name="action" value="cancha_pred_official_save"><input type="hidden" name="competition" value="'.esc_attr($comp).'"><input type="hidden" name="fixture_id" value="'.esc_attr($id).'"><input type="hidden" name="match_day" value="'.esc_attr(function_exists('cof_local_day')?cof_local_day($m['date']):substr($m['date'],0,10)).'"><input type="hidden" name="cancha_return" value="'.esc_attr(cancha_pred_current_url()).'">';
 $out.=wp_nonce_field('cancha_pred_official_'.$comp.'_'.$id,'cancha_pred_nonce',true,false);
 foreach(['1'=>'Local','X'=>'Empate','2'=>'Visitante'] as $pick=>$label){$pick=(string)$pick;$out.='<button type="submit" name="pick" value="'.esc_attr($pick).'" class="'.((($existing['pick']??'')===$pick)?'is-picked':'').'" aria-label="Pronosticar '.esc_attr($label).' en '.esc_attr($name).'" aria-pressed="'.((($existing['pick']??'')===$pick)?'true':'false').'">'.esc_html($pick).'</button>';}
 return $out.'</form></div>';
}
function cancha_pred_amateur_inline($id){
 $post=get_post($id);if(!$post || !cancha_pred_is_open($post))return '';
 if(!cancha_pred_user_allowed())return '';
 $out='<div class="cancha-fixture-prediction"><span>🎯 1 · X · 2 · +'.cancha_pred_points_per_hit().' pts</span>';
 $current=cancha_pred_pick_for($id);
 $out.='<form class="cancha-inline-picks" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="cancha_pred_save"><input type="hidden" name="match_id" value="'.absint($id).'"><input type="hidden" name="cancha_return" value="'.esc_attr(cancha_pred_current_url()).'">'.wp_nonce_field('cancha_pred_'.$id,'cancha_pred_nonce',true,false);
 foreach(['1'=>'Local','X'=>'Empate','2'=>'Visitante'] as $pick=>$label){$pick=(string)$pick;$out.='<button type="submit" name="pick" value="'.$pick.'" class="'.($current===$pick?'is-picked':'').'" aria-label="Pronosticar '.esc_attr($label).'" aria-pressed="'.($current===$pick?'true':'false').'">'.$pick.'</button>';}
 return $out.'</form></div>';
}
add_action('admin_post_cancha_pred_official_save',function(){
 if(!cancha_pred_user_allowed())wp_die('Iniciá sesión, verificá tu cuenta y aceptá los términos.','CANCHA',['response'=>403]);
 $comp=sanitize_key(wp_unslash($_POST['competition']??''));$id=sanitize_text_field(wp_unslash($_POST['fixture_id']??''));
 $pick=sanitize_text_field(wp_unslash($_POST['pick']??''));$day=sanitize_text_field(wp_unslash($_POST['match_day']??''));
 if(!function_exists('cof_competitions')||!isset(cof_competitions()[$comp])||!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||!in_array($pick,['1','X','2'],true)
   ||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cancha_pred_nonce']??'')),'cancha_pred_official_'.$comp.'_'.$id)
   ||!function_exists('cof_calendar_validate_day')||!cof_calendar_validate_day($day))wp_die('Solicitud inválida.','CANCHA',['response'=>403]);
 if(!function_exists('cof_get_calendar_day'))wp_die('El proveedor oficial no está disponible.','CANCHA',['response'=>503]);
 $match=null;
 foreach([cof_get_calendar_day($comp,$day), function_exists('cof_get_fixtures')?cof_get_fixtures($comp):null] as $res){
  if(!is_array($res))continue;
  foreach(($res['matches']??[]) as $m){if((string)($m['id']??'')===$id){$match=$m;break 2;}}
 }
 if(!$match){wp_safe_redirect(cancha_pred_return_url(['pred_status'=>'proveedor','tipo'=>'oficiales']));exit;}
 if(!cancha_pred_official_is_open($match)){wp_safe_redirect(cancha_pred_return_url(['pred_status'=>'cerrado','tipo'=>'oficiales']));exit;}
 global $wpdb;$table=cancha_pred_official_table();$now=current_time('mysql');$source=cancha_pred_official_source();
 $kickoff=gmdate('Y-m-d H:i:s',strtotime($match['date']));
 $sql=$wpdb->prepare("INSERT INTO $table (user_id,provider,competition,fixture_id,pick,home_name,away_name,kickoff_utc,points,settled,created_at,updated_at) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,0,'',%s,%s) ON DUPLICATE KEY UPDATE pick=VALUES(pick),updated_at=VALUES(updated_at)",get_current_user_id(),$source,$comp,$id,$pick,substr(sanitize_text_field($match['home']),0,200),substr(sanitize_text_field($match['away']),0,200),$kickoff,$now,$now);
 if($wpdb->query($sql)===false)wp_die('No se pudo guardar el pronóstico.','CANCHA',['response'=>500]);
 wp_safe_redirect(cancha_pred_return_url(['pred_status'=>'guardado','pred_oficial'=>'guardado','tipo'=>'oficiales']));exit;
});
function cancha_pred_official_final_winner($m){
 $status=strtoupper(trim((string)($m['status']??'')));
 if(!in_array($status,['FT','AET','PEN','MATCH FINISHED','FINISHED','FULL TIME','FULLTIME','AFTER EXTRA TIME','AFTER PENALTIES'],true))return '';
 if(!is_numeric($m['home_score']??null)||!is_numeric($m['away_score']??null))return '';
 $home=(int)$m['home_score'];$away=(int)$m['away_score'];
 return $home>$away?'1':($home<$away?'2':'X');
}
function cancha_pred_official_reconcile_payload($comp,$payload){
 if(!function_exists('cof_settings'))return;
 $provider=$payload['provider']??'';if(!in_array($provider,['api-football','thesportsdb'],true))return;
 global $wpdb;$table=cancha_pred_official_table();
 foreach(($payload['matches']??[]) as $m){
  $result=cancha_pred_official_final_winner($m);$id=(string)($m['id']??'');
  if(!$result||!preg_match('/^[1-9][0-9]{0,17}$/D',$id))continue;
  $wpdb->query($wpdb->prepare("UPDATE $table SET points=IF(pick=%s,%d,0), settled=%s, updated_at=%s WHERE provider=%s AND competition=%s AND fixture_id=%s",$result,cancha_pred_official_points(),$result,current_time('mysql'),$provider,$comp,$id));
 }
}
add_action('cancha_pred_official_reconcile_cron',function(){
 if(!function_exists('cof_get_calendar_day')||!function_exists('cof_competitions'))return;
 global $wpdb;$table=cancha_pred_official_table();
 // Only consult days with actual pending predictions, not every league every hour.
 $today=gmdate('Y-m-d H:i:s');$after=gmdate('Y-m-d H:i:s',time()-7*DAY_IN_SECONDS);
 $rows=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT provider,competition,LEFT(kickoff_utc,10) AS day FROM $table WHERE kickoff_utc BETWEEN %s AND %s AND settled='' LIMIT 20",$after,$today),ARRAY_A);
 foreach((array)$rows as $r){if(($r['provider']??'')!==cancha_pred_official_source())continue;if(!isset(cof_competitions()[$r['competition']]))continue;cof_get_calendar_day($r['competition'],$r['day'],true);}
});
register_activation_hook(CANCHA_PRED_FILE,function(){if(!wp_next_scheduled('cancha_pred_official_reconcile_cron'))wp_schedule_event(time()+HOUR_IN_SECONDS,'twicedaily','cancha_pred_official_reconcile_cron');});
register_deactivation_hook(CANCHA_PRED_FILE,function(){wp_clear_scheduled_hook('cancha_pred_official_reconcile_cron');});
function cancha_pred_official_today(){
 if(!function_exists('cof_get_calendar_day')||!function_exists('cof_competitions'))return '';
 $day=wp_date('Y-m-d');$out='<section class="cancha-pred-official"><h3>Partidos oficiales de hoy</h3><p>Los partidos futuros con fecha confirmada aparecen automáticamente desde la API. La cobertura gratuita puede ser parcial.</p>';
 $n=0;
 foreach(cof_competitions() as $comp=>$label){
  if(!cof_provider_id($comp))continue;
  $res=cof_get_calendar_day($comp,$day);if(is_wp_error($res))continue;
  foreach(($res['matches']??[]) as $m){if(!cancha_pred_official_is_open($m))continue;
   $n++;$out.='<article class="cancha-pred-official-item"><strong>'.esc_html($label).'</strong><p>'.esc_html($m['home'].' vs '.$m['away']).'</p>'.cancha_pred_official_inline($comp,$m).'</article>';
  }
 }
 if(!$n)$out.='<p>Hoy no se recibieron partidos oficiales futuros con hora confirmada. Consultá otras fechas en <a href="'.esc_url(home_url('/')).'">Partidos</a>.</p>';
 return $out.'</section>';
}

add_action('admin_menu',function(){},80);
