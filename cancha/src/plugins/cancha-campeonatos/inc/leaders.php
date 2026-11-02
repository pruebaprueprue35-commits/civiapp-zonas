<?php
/** Verified competition leaders, without user fantasy points. */
defined('ABSPATH') || exit;
function cancha_comp_player_leaders($competition,$limit=10){
 $players=[];
 foreach(cancha_comp_matches(absint($competition),2000) as $match){
  if(get_post_meta($match->ID,'_cancha_result_status',true)!=='final')continue;
  $rows=get_post_meta($match->ID,'_cancha_lineup',true);if(!is_array($rows))continue;
  foreach($rows as $row){
   $id=absint($row['player']??0);if(!$id||get_post_type($id)!=='cancha_jugador'||get_post_status($id)!=='publish')continue;
   if(!isset($players[$id]))$players[$id]=['id'=>$id,'team'=>absint($row['team']??0),'pj'=>0,'goals'=>0,'assists'=>0,'yellow'=>0,'red'=>0];
   $players[$id]['pj']++;$players[$id]['goals']+=absint($row['goals']??0);
   $players[$id]['assists']+=absint($row['assists']??0);$players[$id]['yellow']+=absint($row['yellow']??0);$players[$id]['red']+=absint($row['red']??0);
  }
 }
 $out=array_values($players);
 usort($out,static function($a,$b){return ($b['goals']<=>$a['goals'])?:($b['assists']<=>$a['assists'])?:($a['id']<=>$b['id']);});
 return array_slice($out,0,min(100,max(1,absint($limit))));
}
function cancha_comp_leaders_html($competition,$limit=10){
 $rows=cancha_comp_player_leaders($competition,$limit);
 if(!$rows)return '<div class="module-empty">Se mostrarán goleadores cuando cargues planillas de partidos finales.</div>';
 $html='<div class="cancha-standings-wrap"><table class="cancha-standings"><thead><tr><th>#</th><th>Jugador</th><th>Club</th><th>PJ</th><th>Goles</th><th>Asist.</th><th>Amar.</th><th>Rojas</th></tr></thead><tbody>';
 foreach($rows as $i=>$r){$html.='<tr><td>'.($i+1).'</td><th scope="row"><a href="'.esc_url(get_permalink($r['id'])).'">'.esc_html(get_the_title($r['id'])).'</a></th>';
 $html.='<td>'.esc_html(get_the_title($r['team'])).'</td>';
 foreach(['pj','goals','assists','yellow','red'] as $key)$html.='<td>'.absint($r[$key]).'</td>';
 $html.='</tr>';}
 return $html.'</tbody></table></div>';
}
// 0.5.0: goleadores en la pestaña «Jugadores» del torneo (inc/amateur.php).
add_shortcode('cancha_goleadores',function($atts){$a=shortcode_atts(['campeonato'=>0,'limite'=>10],$atts,'cancha_goleadores');return cancha_comp_leaders_html(absint($a['campeonato']),absint($a['limite']));});
