<?php
/** Per-match player appearances and discipline; no GamiPress point grants. */
defined('ABSPATH') || exit;
function cancha_match_event_eligible($match_id){
 $home=(int)get_post_meta($match_id,'_cancha_home',true);$away=(int)get_post_meta($match_id,'_cancha_away',true);
 $out=[];
 foreach(cancha_people_all('cancha_jugador','',0,1000) as $p){
   $team=absint(cancha_person_field($p->ID,'team'));
   if($team && in_array($team,[$home,$away],true))$out[$p->ID]=['team'=>$team,'name'=>$p->post_title];
 }
 return $out;
}
function cancha_events_row($key,$record,$eligible){
 $player=absint($record['player']??0);
 $opts='<option value="0">— Elegir jugador —</option>';
 foreach($eligible as $pid=>$info){$opts.='<option value="'.absint($pid).'" '.selected($player,$pid,false).'>'.esc_html(get_the_title($info['team']).' · '.$info['name']).'</option>';}
 $html='<tr><td><select name="cancha_lineup['.esc_attr($key).'][player]">'.$opts.'</select></td>';
 foreach(['goals'=>'Goles','assists'=>'Asist.','yellow'=>'Amar.','red'=>'Rojas','minutes'=>'Minutos'] as $name=>$title){
  $max=$name==='minutes'?130:($name==='yellow'?2:($name==='red'?1:20));
  $val=isset($record[$name]) ? absint($record[$name]) : 0;
  $html.='<td><input aria-label="'.esc_attr($title).'" type="number" min="0" max="'.$max.'" name="cancha_lineup['.esc_attr($key).']['.$name.']" value="'.esc_attr($val).'" style="max-width:72px"></td>';
 }
 $html.='<td><label><input type="checkbox" name="cancha_lineup['.esc_attr($key).'][starter]" value="1" '.checked(!empty($record['starter']),true,false).'> Titular</label></td></tr>';
 return $html;
}
function cancha_match_events_metabox($post){
 wp_nonce_field('cancha_match_events','cancha_match_events_nonce');
 $eligible=cancha_match_event_eligible($post->ID);
 echo '<p class="description">Primero guardá el partido con los equipos. Luego cargá la participación individual, goles, asistencias y tarjetas. Solo los partidos <strong>finales y publicados</strong> aportan estadísticas públicas. La ficha individual exige que cada jugador pertenezca a uno de los dos clubes del encuentro.</p>';
 if(!$eligible){echo '<p>Asigná jugadores a los clubes y guardá los equipos del partido para cargar datos individuales.</p>';return;}
 $rows=get_post_meta($post->ID,'_cancha_lineup',true);if(!is_array($rows))$rows=[];
 echo '<div class="cancha-events-scroll"><table class="widefat striped cancha-events-table"><thead><tr><th>Jugador y club</th><th>Goles</th><th>Asist.</th><th>Amar.</th><th>Rojas</th><th>Min.</th><th>Formación</th></tr></thead><tbody id="cancha-event-rows">';
 $i=0;foreach($rows as $record){echo cancha_events_row($i++,$record,$eligible);}
 for($k=0;$k<3;$k++)echo cancha_events_row($i++,[],$eligible);
 echo '</tbody></table></div><p><button type="button" id="cancha-add-player" class="button">+ Agregar otro jugador</button></p>';
 echo '<template id="cancha-player-template">'.cancha_events_row('__INDEX__',[],$eligible).'</template>';
 echo '<p class="description">Registrar tarjetas sin jugadores válidos no genera estadísticas. Un jugador solo puede figurar una vez por partido. Las tarjetas amarillas admiten 0–2 por jugador.</p>';
}
add_action('add_meta_boxes_cancha_partido',function(){add_meta_box('cancha_match_events','Planilla · jugadores y estadísticas','cancha_match_events_metabox','cancha_partido','normal','default');});
add_action('admin_enqueue_scripts',function(){
 $screen=get_current_screen();if(!$screen||$screen->post_type!=='cancha_partido')return;
 wp_enqueue_script('cancha-match-events',plugins_url('../assets/match-events.js',__FILE__),[],CANCHA_COMP_VERSION,true);
});
function cancha_event_sanitize_rows($raw,$eligible){
 if(!is_array($raw))return [];$rows=[];$used=[];
 foreach(array_slice($raw,0,70) as $item){
  if(!is_array($item))continue;$id=absint($item['player']??0);
  if(!$id||!isset($eligible[$id])||isset($used[$id]))continue;
  $record=['player'=>$id,'team'=>absint($eligible[$id]['team'])];
  foreach(['goals'=>20,'assists'=>20,'yellow'=>2,'red'=>1,'minutes'=>130] as $key=>$max){
    $v=$item[$key]??0;$record[$key]=is_scalar($v)?min($max,absint($v)):0;
  }
  $record['starter']=!empty($item['starter'])?1:0;$rows[]=$record;$used[$id]=true;
 }
 return $rows;
}
add_action('save_post_cancha_partido',function($id){
 if(!cancha_comp_save_allowed($id,'cancha_match_events_nonce','cancha_match_events'))return;
 // Players must be stored after the fixture/club assignments have been updated.
 $eligible=cancha_match_event_eligible($id);
 update_post_meta($id,'_cancha_lineup',cancha_event_sanitize_rows($_POST['cancha_lineup']??[],$eligible));
},30);
function cancha_player_stats($player_id){
 $player_id=absint($player_id);$stats=['pj'=>0,'goles'=>0,'asistencias'=>0,'amarillas'=>0,'rojas'=>0,'minutos'=>0,'titular'=>0];
 foreach(cancha_comp_matches(0,2000) as $match){
  if(get_post_meta($match->ID,'_cancha_result_status',true)!=='final')continue;
  $events=get_post_meta($match->ID,'_cancha_lineup',true);
  if(!is_array($events))continue;
  foreach($events as $event){
   if((int)($event['player']??0)!==$player_id)continue;
   $stats['pj']++;$stats['goles']+=absint($event['goals']??0);$stats['asistencias']+=absint($event['assists']??0);
   $stats['amarillas']+=absint($event['yellow']??0);$stats['rojas']+=absint($event['red']??0);
   $stats['minutos']+=absint($event['minutes']??0);$stats['titular']+=!empty($event['starter'])?1:0;
   break;
  }
 }
 return $stats;
}
function cancha_team_stats($team){
 $id=absint($team);$result=['pj'=>0,'g'=>0,'e'=>0,'p'=>0,'gf'=>0,'gc'=>0,'dg'=>0,'pts'=>0];
 $competition=absint(cancha_person_field($id,'comp_id'));
 if(!$competition)return $result;
 foreach(cancha_comp_standings($competition) as $row)if((int)$row['id']===$id)return array_intersect_key($row,$result);
 return $result;
}
