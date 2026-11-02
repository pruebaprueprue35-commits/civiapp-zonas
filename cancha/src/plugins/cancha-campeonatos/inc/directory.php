<?php
/** Read-only public profile presentation. Escaping is performed at every output. */
defined('ABSPATH') || exit;
function cancha_profile_facts($facts){
 $out='<div class="cancha-facts">';foreach($facts as $label=>$value){if($value===null||$value==='')continue;
  $out.='<div class="fact"><span>'.esc_html($label).'</span><strong>'.esc_html((string)$value).'</strong></div>';}
 return $out.'</div>';
}
function cancha_team_profile_html($id){
 $id=absint($id);if(get_post_type($id)!=='cancha_equipo'||get_post_status($id)!=='publish')return '';
 $comp=absint(get_post_meta($id,'_cancha_comp_id',true));$stats=cancha_team_stats($id);
 $html='<section class="cancha-profile-top"><div class="profile-cover">'.cancha_public_emblem($id,'medium').'</div><div><span class="eyebrow">FICHA DEL CLUB</span><h1>'.esc_html(get_the_title($id)).'</h1>';
 if($comp)$html.='<a class="cancha-secondary-link" href="'.esc_url(get_permalink($comp)).'">'.esc_html(get_the_title($comp)).' →</a>';
 $html.='</div></section>';
 $html.=cancha_profile_facts(['Fundado'=>cancha_person_field($id,'founded'),'Localidad'=>cancha_person_field($id,'city'), 'Estadio / cancha'=>cancha_person_field($id,'homeground'),'Colores'=>cancha_person_field($id,'colors')]);
 $web=cancha_person_field($id,'website');if($web)$html.='<p><a target="_blank" rel="noopener noreferrer" href="'.esc_url($web).'">Sitio oficial del club ↗</a></p>';
 $html.='<section class="cancha-profile-section"><h2>Estadísticas del equipo</h2><div class="cancha-stat-strip">';
 foreach(['pj'=>'Jugados','g'=>'Ganados','e'=>'Empatados','p'=>'Perdidos','gf'=>'Goles a favor','gc'=>'En contra','dg'=>'Diferencia','pts'=>'Puntos'] as $k=>$v)$html.='<div><strong>'.absint($stats[$k]??0).'</strong><span>'.esc_html($v).'</span></div>';
 $html.='</div><p class="muted">Cifras del campeonato actual, calculadas únicamente sobre partidos finales validados.</p></section>';
 $html.='<section class="cancha-profile-section"><h2>Plantel de jugadores</h2>'.cancha_people_cards(cancha_people_members('cancha_jugador','team',$id),'Jugador').'</section>';
 $html.='<section class="cancha-profile-section"><h2>Directiva y cuerpo técnico</h2>'.cancha_people_cards(cancha_people_members('cancha_staff','team',$id),'Directiva').'</section>';
 return $html;
}
function cancha_player_profile_html($id){
 $id=absint($id);if(get_post_type($id)!=='cancha_jugador'||get_post_status($id)!=='publish')return '';
 $team=absint(cancha_person_field($id,'team'));$s=cancha_player_stats($id);
 $html='<section class="cancha-profile-top"><div class="profile-cover">'.cancha_public_emblem($id,'medium').'</div><div><span class="eyebrow">FICHA DEL JUGADOR</span><h1>'.esc_html(get_the_title($id)).'</h1>';
 if($team)$html.='<a class="cancha-secondary-link" href="'.esc_url(get_permalink($team)).'">'.esc_html(get_the_title($team)).' →</a>';
 $html.='</div></section>';
 $html.=cancha_profile_facts(['Camiseta'=>cancha_person_field($id,'number'), 'Posición'=>ucfirst(cancha_person_field($id,'position')),'Nacionalidad'=>cancha_person_field($id,'nationality'),'Pierna hábil'=>cancha_person_field($id,'foot')]);
 $html.='<section class="cancha-profile-section"><h2>Rendimiento en partidos validados</h2><div class="cancha-stat-strip">';
 foreach(['pj'=>'Partidos','titular'=>'Titularidades','goles'=>'Goles','asistencias'=>'Asistencias','amarillas'=>'Amarillas','rojas'=>'Rojas','minutos'=>'Minutos'] as $k=>$label)$html.='<div><strong>'.absint($s[$k]).'</strong><span>'.esc_html($label).'</span></div>';
 return $html.'</div><p class="muted">Solo se contabilizan planillas guardadas en encuentros finales y publicados.</p></section>';
}
function cancha_staff_profile_html($id){
 $id=absint($id);if(get_post_type($id)!=='cancha_staff'||get_post_status($id)!=='publish')return '';
 $team=absint(cancha_person_field($id,'team'));$competition=absint(cancha_person_field($id,'competition'));
 $html='<section class="cancha-profile-top"><div class="profile-cover">'.cancha_public_emblem($id,'medium').'</div><div><span class="eyebrow">DIRECTIVA Y CUERPO TÉCNICO</span><h1>'.esc_html(get_the_title($id)).'</h1><strong>'.esc_html(cancha_person_field($id,'role')).'</strong>';
 if($team)$html.='<p><a href="'.esc_url(get_permalink($team)).'">'.esc_html(get_the_title($team)).' →</a></p>';
 elseif($competition)$html.='<p><a href="'.esc_url(get_permalink($competition)).'">'.esc_html(get_the_title($competition)).' →</a></p>';
 return $html.'</div></section>'.cancha_profile_facts(['En funciones desde'=>cancha_person_field($id,'since')]);
}
function cancha_comp_profile_extra_html($comp){
 $comp=absint($comp);
 $html='<section class="cancha-profile-section"><h2>Ficha del campeonato</h2>';
 $html.=cancha_profile_facts(['Categoría y modalidad'=>cancha_person_field($comp,'category'),'Localidad'=>cancha_person_field($comp,'location'),'Organizador'=>cancha_person_field($comp,'organizer'),'Inicio'=>cancha_person_field($comp,'start_date'),'Finalización'=>cancha_person_field($comp,'end_date'),'Formato'=>cancha_person_field($comp,'format')]);
 $url=cancha_person_field($comp,'regulation_url');if($url)$html.='<p><a href="'.esc_url($url).'" rel="noopener noreferrer" target="_blank">Consultar reglamento oficial ↗</a></p>';
 $html.='</section>'; // 0.5.0: equipos participantes en la pestaña «Equipos».
 $html.='<section class="cancha-profile-section"><h2>Directiva del campeonato</h2>'.cancha_people_cards(cancha_people_members('cancha_staff','competition',$comp),'Directiva').'</section>';
 $html.='<section class="cancha-profile-section"><h2>Últimos campeones</h2>'.cancha_champions_html($comp).'</section>';
 return $html;
}
add_action('cancha_render_campeonato_detalle',function($comp){echo cancha_comp_profile_extra_html($comp);},5);
add_shortcode('cancha_plantel',function($atts){$a=shortcode_atts(['equipo'=>0],$atts,'cancha_plantel');return cancha_people_cards(cancha_people_members('cancha_jugador','team',absint($a['equipo'])),'Jugador');});
add_shortcode('cancha_jugador',function($atts){$a=shortcode_atts(['id'=>0],$atts,'cancha_jugador');return cancha_player_profile_html(absint($a['id']));});
add_shortcode('cancha_equipo',function($atts){$a=shortcode_atts(['id'=>0],$atts,'cancha_equipo');return cancha_team_profile_html(absint($a['id']));});
add_shortcode('cancha_historial',function($atts){$a=shortcode_atts(['campeonato'=>0],$atts,'cancha_historial');return cancha_champions_html(absint($a['campeonato']));});
add_shortcode('cancha_directorio',function($atts){$a=shortcode_atts(['tipo'=>'jugadores','equipo'=>0],$atts,'cancha_directorio');
 if($a['tipo']==='equipos')return cancha_people_cards(cancha_comp_teams(absint($a['equipo'])),'Equipo');
 return cancha_people_cards(cancha_people_all('cancha_jugador','',0,300),'Jugador');
});
