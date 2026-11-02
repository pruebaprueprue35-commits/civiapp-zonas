<?php
/**
 * Plugin Name: CANCHA · Predicciones
 * Description: Pronósticos recreativos 1X2 para partidos amateur y oficiales verificados, con puntos internos independientes de GamiPress.
 * Version: 0.3.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Text Domain: cancha-predicciones
 */
defined('ABSPATH') || exit;
define('CANCHA_PRED_VERSION','0.3.0');
define('CANCHA_PRED_FILE',__FILE__);
require_once __DIR__.'/official-predictions.php';
require_once __DIR__.'/page.php';
function cancha_pred_table() {global $wpdb; return $wpdb->prefix.'cancha_predictions';}
function cancha_pred_create_table() {
 global $wpdb;
 require_once ABSPATH . 'wp-admin/includes/upgrade.php';
 $t=cancha_pred_table();$charset=$wpdb->get_charset_collate();
 $sql="CREATE TABLE $t (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 user_id bigint(20) unsigned NOT NULL,
 match_id bigint(20) unsigned NOT NULL,
 pick varchar(1) NOT NULL,
 points int(11) NOT NULL DEFAULT 0,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY user_match (user_id,match_id),
 KEY match_id (match_id),
 KEY user_id (user_id)
 ) $charset;";
 dbDelta($sql);
 update_option('cancha_pred_db_version',CANCHA_PRED_VERSION,false);
}
register_activation_hook(__FILE__,'cancha_pred_create_table');
add_action('admin_init',function(){if(current_user_can('manage_options') && get_option('cancha_pred_db_version')!==CANCHA_PRED_VERSION)cancha_pred_create_table();});
function cancha_pred_points_per_hit() {return min(100,max(1,absint(get_option('cancha_pred_points',10))));}
function cancha_pred_user_allowed() {
 if(!is_user_logged_in())return false;
 return !function_exists('cancha_current_user_can_predict') || cancha_current_user_can_predict();
}
function cancha_pred_is_open($match) {
 if(!is_object($match)||$match->post_type!=='cancha_partido'||$match->post_status!=='publish')return false;
 $start=get_post_meta($match->ID,'_cancha_start',true);
 return is_string($start) && preg_match('/^20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}$/',$start) && $start>wp_date('Y-m-d\TH:i')
    && get_post_meta($match->ID,'_cancha_result_status',true)!=='final';
}
function cancha_pred_pick_for($match_id,$user_id=0) {
 global $wpdb;$user_id=$user_id?:get_current_user_id();if(!$user_id)return '';
 $t=cancha_pred_table();
 return (string)$wpdb->get_var($wpdb->prepare("SELECT pick FROM $t WHERE user_id=%d AND match_id=%d",$user_id,$match_id));
}
function cancha_pred_user_points($user_id=0) {
 global $wpdb;$user_id=$user_id?:get_current_user_id();if(!$user_id)return 0;
 $t=cancha_pred_table();
 return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(points),0) FROM $t WHERE user_id=%d",$user_id));
}
function cancha_pred_status_url($status,$tipo='amateur'){return cancha_pred_return_url(['pred_status'=>sanitize_key($status),'tipo'=>$tipo]);}
add_action('admin_post_cancha_pred_save',function(){
 if(!is_user_logged_in())wp_die('Ingresá a CANCHA para participar.','CANCHA',['response'=>403]);
 if(!cancha_pred_user_allowed())wp_die('Verificá tu cuenta y aceptá los términos de CANCHA.','CANCHA',['response'=>403]);
 $id=absint($_POST['match_id']??0);$pick=sanitize_text_field(wp_unslash($_POST['pick']??''));
 if(!in_array($pick,['1','X','2'],true) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cancha_pred_nonce']??'')),'cancha_pred_'.$id))
    wp_die('Solicitud inválida. Recargá la página.','CANCHA',['response'=>403]);
 $post=get_post($id);
 if(!cancha_pred_is_open($post)){wp_safe_redirect(cancha_pred_status_url('cerrado'));exit;}
 global $wpdb;$table=cancha_pred_table();
 $now=current_time('mysql');$user=get_current_user_id();
 // Recheck match timestamp immediately before write. UNIQUE KEY prevents duplicate entries.
 $sql=$wpdb->prepare("INSERT INTO $table (user_id,match_id,pick,points,created_at,updated_at) VALUES (%d,%d,%s,0,%s,%s)
 ON DUPLICATE KEY UPDATE pick=VALUES(pick), points=0, updated_at=VALUES(updated_at)", $user,$id,$pick,$now,$now);
 $result=$wpdb->query($sql);
 if($result===false){wp_safe_redirect(cancha_pred_status_url('error'));exit;}
 wp_safe_redirect(cancha_pred_status_url('guardado'));exit;
});
function cancha_pred_sync_match($id) {
 if(get_post_type($id)!=='cancha_partido')return;
 global $wpdb;$table=cancha_pred_table();
 $status=get_post_meta($id,'_cancha_result_status',true);
 $hs=get_post_meta($id,'_cancha_home_score',true);$as=get_post_meta($id,'_cancha_away_score',true);
 $valid=get_post_status($id)==='publish' && $status==='final' && is_scalar($hs) && is_scalar($as) && preg_match('/^(?:0|[1-9]\d?)$/',(string)$hs) && preg_match('/^(?:0|[1-9]\d?)$/',(string)$as);
 $winner=$valid?(((int)$hs>(int)$as)?'1':(((int)$hs<(int)$as)?'2':'X')):'';
 $points=cancha_pred_points_per_hit();
 // Derived scoring: recalculating is idempotent and correcting final scores updates the ledger, without awarding twice.
 if($winner) $wpdb->query($wpdb->prepare("UPDATE $table SET points=IF(pick=%s,%d,0),updated_at=%s WHERE match_id=%d",$winner,$points,current_time('mysql'),$id));
 else $wpdb->query($wpdb->prepare("UPDATE $table SET points=0,updated_at=%s WHERE match_id=%d",current_time('mysql'),$id));
}
add_action('save_post_cancha_partido','cancha_pred_sync_match',150);
// Reconciliation for results edited from batch tools instead of post editor; bounded and cache-safe.
add_action('cancha_pred_reconcile',function(){
 if(!post_type_exists('cancha_partido'))return;
 global $wpdb;$table=cancha_pred_table();
 $ids=$wpdb->get_col("SELECT DISTINCT match_id FROM $table ORDER BY match_id DESC LIMIT 1000");
 foreach($ids as $id)cancha_pred_sync_match(absint($id));
});
add_action('init',function(){if(!wp_next_scheduled('cancha_pred_reconcile'))wp_schedule_event(time()+HOUR_IN_SECONDS,'hourly','cancha_pred_reconcile');});
register_deactivation_hook(__FILE__,function(){wp_clear_scheduled_hook('cancha_pred_reconcile');});
function cancha_pred_upcoming($limit=12){
 if(!post_type_exists('cancha_partido'))return [];
 $candidates=get_posts(['post_type'=>'cancha_partido','post_status'=>'publish','numberposts'=>250,'meta_key'=>'_cancha_start','orderby'=>'meta_value','order'=>'ASC',
 'meta_query'=>[['key'=>'_cancha_start','value'=>wp_date('Y-m-d\TH:i'),'compare'=>'>=']]]);
 $out=[];foreach($candidates as $m){if(cancha_pred_is_open($m)){$out[]=$m;if(count($out)>=$limit)break;}}
 return $out;
}
function cancha_pred_form_html($limit=4){
 $out='<div class="cancha-pred-panel">';
 if(isset($_GET['pred_status']) && is_page('predicciones')){
  $status=sanitize_key(wp_unslash($_GET['pred_status']));
  $messages=['guardado'=>'Predicción guardada. Podés cambiarla antes de comenzar el partido.','cerrado'=>'Ese partido ya comenzó o está cerrado.','error'=>'No se pudo guardar la predicción. Intentá nuevamente.'];
  if(isset($messages[$status]))$out.='<p class="cancha-pred-notice" role="status">'.esc_html($messages[$status]).'</p>';
 }
 if(is_user_logged_in())$out.='<p class="cancha-pred-score">Tus puntos CANCHA: <strong>'.cancha_pred_user_points().'</strong> <small>Recreativos · independientes de GamiPress</small></p>';
 $matches=cancha_pred_upcoming(min(25,max(1,(int)$limit)));
 if(!$matches){return $out.'<div class="ligas-empty"><p>No hay próximos partidos disponibles para pronosticar. El administrador puede programarlos desde CANCHA → Partidos.</p></div></div>';}
 if(!cancha_pred_user_allowed()){
  $target=function_exists('cancha_auth_url')?cancha_auth_url('ingresar'):wp_login_url();
  $out.='<div class="cancha-pred-signup"><p>Para pronosticar necesitás una cuenta verificada y aceptar los términos.</p><a class="ligas-create-btn" href="'.esc_url($target).'">Ingresar o registrarme</a></div>';
 }
 $out.='<div class="cancha-pred-list">';
 foreach($matches as $m){
  $home=absint(get_post_meta($m->ID,'_cancha_home',true));$away=absint(get_post_meta($m->ID,'_cancha_away',true));
  if(!$home||!$away)continue;
  $start=get_post_meta($m->ID,'_cancha_start',true);
  $saved=cancha_pred_user_allowed()?cancha_pred_pick_for($m->ID):'';
  $out.='<article class="cancha-pred-match"><div class="cancha-pred-time">'.esc_html(str_replace('T',' · ',$start)).'</div><div class="cancha-pred-teams"><strong>'.esc_html(get_the_title($home)).'</strong><span>VS</span><strong>'.esc_html(get_the_title($away)).'</strong></div>';
  if(cancha_pred_user_allowed()){
   $out.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="cancha-pred-picks"><input type="hidden" name="action" value="cancha_pred_save"><input type="hidden" name="match_id" value="'.absint($m->ID).'">'.wp_nonce_field('cancha_pred_'.$m->ID,'cancha_pred_nonce',true,false);
   foreach(['1'=>'Local','X'=>'Empate','2'=>'Visita'] as $pick=>$label){$pick=(string)$pick;$out.='<button type="submit" name="pick" value="'.esc_attr($pick).'" class="'.($saved===$pick?'picked':'').'" aria-label="Pronosticar '.esc_attr($label).'">'.esc_html($pick).' <small>'.esc_html($label).'</small></button>';}
   $out.='</form>';
  }else $out.='<p class="cancha-pred-locked">Iniciá sesión para elegir 1 / X / 2</p>';
  $out.='<div class="cancha-pred-points">⭐ '.cancha_pred_points_per_hit().' puntos por acierto</div></article>';
 }
 return $out.'</div></div>';
}
add_shortcode('cancha_predicciones',function($atts){return cancha_pred_page_html();});
// 0.3.0: /predicciones/ usa pestañas separadas Oficiales | Amateur (page.php).
add_action('admin_menu',function(){
 if(!current_user_can('manage_options'))return;
 add_submenu_page('cancha-core','Predicciones','🎯 Predicciones','manage_options','cancha-predicciones','cancha_pred_admin');
},40);
function cancha_pred_admin(){
 if(!current_user_can('manage_options'))wp_die('Sin permisos');
 if(($_SERVER['REQUEST_METHOD']??'')==='POST' && isset($_POST['cancha_pred_admin_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cancha_pred_admin_nonce'])),'cancha_pred_admin')){
  $points=min(100,max(1,absint($_POST['points']??10)));update_option('cancha_pred_points',$points,false);
  if(!empty($_POST['recalc']))do_action('cancha_pred_reconcile');
  echo '<div class="notice notice-success"><p>Configuración guardada. Puntuaciones recalculadas cuando corresponde.</p></div>';
 }
 global $wpdb;$t=cancha_pred_table();$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
 echo '<div class="wrap"><h1>🎯 CANCHA · Predicciones</h1><p>Pronósticos recreativos 1X2 para partidos amateur y oficiales de la API. Sin dinero real ni apuestas. Resultados validados recalculables.</p>';
 $official_count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.cancha_pred_official_table());echo '<p><strong>Pronósticos amateur:</strong> '.absint($count).' · <strong>Oficiales:</strong> '.absint($official_count).'</p>';
 echo '<form method="post">';wp_nonce_field('cancha_pred_admin','cancha_pred_admin_nonce');
 echo '<table class="form-table"><tr><th><label for="points">Puntos por acierto</label></th><td><input type="number" id="points" min="1" max="100" name="points" value="'.esc_attr(cancha_pred_points_per_hit()).'"></td></tr></table>';
 echo '<p><label><input type="checkbox" name="recalc" value="1"> Recalcular todas las predicciones existentes (máximo 1000 partidos por ejecución)</label></p>';
 submit_button('Guardar configuración');echo '</form><p><strong>Shortcode:</strong> <code>[cancha_predicciones]</code></p>';
 echo '<p>Los puntos de esta versión se guardan por separado. Integrar el saldo con GamiPress requiere un adaptador de transacciones reversibles para no duplicar créditos.</p></div>';
}
add_action('wp_enqueue_scripts',function(){wp_enqueue_style('cancha-predicciones',plugins_url('assets/predicciones.css',__FILE__),[],CANCHA_PRED_VERSION);});
