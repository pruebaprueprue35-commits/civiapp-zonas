<?php
/** Public match card and administrative match metadata. */
defined('ABSPATH') || exit;
add_action('add_meta_boxes_cancha_partido',function(){
 add_meta_box('cancha_match_extra','Información del encuentro','cancha_match_extra_box','cancha_partido','normal','default');
});
function cancha_match_extra_box($post){
 wp_nonce_field('cancha_match_extra','cancha_match_extra_nonce');
 foreach(['venue'=>'Estadio / predio','referee'=>'Árbitro','attendance'=>'Público (asistentes)'] as $key=>$label){
  $type=$key==='attendance'?'number':'text';
  echo '<p><label><strong>'.esc_html($label).'</strong><br><input class="widefat" type="'.esc_attr($type).'" name="cancha_match_extra['.esc_attr($key).']" value="'.esc_attr(get_post_meta($post->ID,'_cancha_match_'.$key,true)).'"></label></p>';
 }
 echo '<p class="description">La crónica del encuentro puede escribirse en la descripción principal del partido. Las estadísticas públicas se publican solo al validarse el marcador.</p>';
}
add_action('save_post_cancha_partido',function($id){
 if(!cancha_comp_save_allowed($id,'cancha_match_extra_nonce','cancha_match_extra'))return;
 $fields=$_POST['cancha_match_extra']??[];if(!is_array($fields))return;
 foreach(['venue','referee','attendance'] as $key){$value=$fields[$key]??'';
  $value=$key==='attendance'?min(1000000,absint($value)):substr(sanitize_text_field(wp_unslash($value)),0,120);
  update_post_meta($id,'_cancha_match_'.$key,$value);
 }
},35);
function cancha_match_profile_html($match_id){
 $id=absint($match_id);if(get_post_type($id)!=='cancha_partido'||get_post_status($id)!=='publish')return '';
 $home=absint(get_post_meta($id,'_cancha_home',true));$away=absint(get_post_meta($id,'_cancha_away',true));
 $comp=absint(get_post_meta($id,'_cancha_comp_id',true));$round=absint(get_post_meta($id,'_cancha_round',true));
 $final=get_post_meta($id,'_cancha_result_status',true)==='final';
 $hs=cancha_comp_valid_score(get_post_meta($id,'_cancha_home_score',true));
 $as=cancha_comp_valid_score(get_post_meta($id,'_cancha_away_score',true));
 if($hs===null||$as===null)$final=false;
 $html='<div class="cancha-match-heading"><div><span class="eyebrow">LOCAL</span><a href="'.esc_url(get_permalink($home)).'">'.cancha_public_emblem($home).'<strong>'.esc_html(get_the_title($home)).'</strong></a></div>';
 $html.='<div class="match-score"><small>'.($final?'FINALIZADO':'PROGRAMADO').'</small><strong>'.($final?absint($hs).' : '.absint($as):'— : —').'</strong><span>Jornada '.absint($round).'</span></div>';
 $html.='<div><span class="eyebrow">VISITANTE</span><a href="'.esc_url(get_permalink($away)).'">'.cancha_public_emblem($away).'<strong>'.esc_html(get_the_title($away)).'</strong></a></div></div>';
 $html.=cancha_profile_facts(['Fecha y hora local'=>str_replace('T',' ',get_post_meta($id,'_cancha_start',true)),'Estadio'=>get_post_meta($id,'_cancha_match_venue',true),'Árbitro'=>get_post_meta($id,'_cancha_match_referee',true),'Asistentes'=>get_post_meta($id,'_cancha_match_attendance',true)]);
 if($comp)$html.='<p><a href="'.esc_url(get_permalink($comp)).'">Volver a '.esc_html(get_the_title($comp)).' →</a></p>';
 if(!$final)return $html.'<p class="muted">La planilla individual se hará pública cuando se valide el resultado.</p>';
 $records=get_post_meta($id,'_cancha_lineup',true);
 if(!is_array($records)||!$records)return $html.'<p class="muted">Todavía no se cargó la planilla de futbolistas de este partido.</p>';
 $html.='<section class="cancha-profile-section"><h2>Planilla del partido</h2><div class="cancha-standings-wrap"><table class="cancha-standings"><thead><tr><th>Jugador</th><th>Equipo</th><th>Min.</th><th>Goles</th><th>Asist.</th><th>Amar.</th><th>Rojas</th></tr></thead><tbody>';
 foreach($records as $r){$pid=absint($r['player']??0);$team=absint($r['team']??0);
  if(!$pid||get_post_status($pid)!=='publish')continue;
  $html.='<tr><td><a href="'.esc_url(get_permalink($pid)).'">'.esc_html(get_the_title($pid)).'</a></td><td>'.esc_html(get_the_title($team)).'</td>';
  foreach(['minutes','goals','assists','yellow','red'] as $k)$html.='<td>'.absint($r[$k]??0).'</td>';
  $html.='</tr>';
 }
 return $html.'</tbody></table></div></section>';
}
add_action('cancha_render_partido_detalle',function($id){echo cancha_match_profile_html($id);});
