<?php
/** Club, competition, season, footballer and staff administration. */
defined('ABSPATH') || exit;
add_action('init',function(){
 $caps=cancha_comp_capabilities();
 $common=['show_ui'=>true,'show_in_menu'=>'cancha-core','capabilities'=>$caps,'map_meta_cap'=>false,
    'show_in_rest'=>false,'supports'=>['title','editor','thumbnail'],'public'=>true,'publicly_queryable'=>true,'exclude_from_search'=>false];
 register_post_type('cancha_jugador',array_merge($common,['labels'=>['name'=>'Jugadores','singular_name'=>'Jugador','menu_name'=>'👟 Jugadores','add_new_item'=>'Agregar jugador','edit_item'=>'Ficha del jugador'], 'rewrite'=>['slug'=>'jugador','with_front'=>false]]));
 register_post_type('cancha_staff',array_merge($common,['labels'=>['name'=>'Directiva y cuerpo técnico','singular_name'=>'Miembro del staff','menu_name'=>'👥 Directivas y staff','add_new_item'=>'Agregar directivo o técnico','edit_item'=>'Ficha del directivo'], 'rewrite'=>['slug'=>'directivo','with_front'=>false]]));
 register_post_type('cancha_temporada',array_merge($common,['labels'=>['name'=>'Historial de campeones','singular_name'=>'Temporada','menu_name'=>'🥇 Historial de campeones','add_new_item'=>'Agregar edición histórica','edit_item'=>'Edición histórica'], 'rewrite'=>['slug'=>'temporada-cancha','with_front'=>false]]));
},12);
function cancha_person_field($id,$key){return get_post_meta($id,'_cancha_'.$key,true);}
function cancha_person_input($key,$label,$value,$type='text',$placeholder=''){
 echo '<p><label for="cancha_extra_'.esc_attr($key).'"><strong>'.esc_html($label).'</strong></label><br><input class="widefat" id="cancha_extra_'.esc_attr($key).'" type="'.esc_attr($type).'" name="cancha_extra['.esc_attr($key).']" value="'.esc_attr($value).'" placeholder="'.esc_attr($placeholder).'"></p>';
}
function cancha_person_select($key,$label,$posts,$selected=0,$blank='— Sin asignar —'){
 echo '<p><label for="cancha_extra_'.esc_attr($key).'"><strong>'.esc_html($label).'</strong></label><br><select class="widefat" name="cancha_extra['.esc_attr($key).']" id="cancha_extra_'.esc_attr($key).'">';
 echo cancha_comp_options_html($posts,absint($selected),$blank).'</select></p>';
}
function cancha_people_all($type,$filter='',$target=0,$limit=500){
 $args=['post_type'=>$type,'post_status'=>'publish','numberposts'=>$limit,'orderby'=>'title','order'=>'ASC'];
 if($filter)$args['meta_query']=[['key'=>'_cancha_'.$filter,'value'=>absint($target),'compare'=>'=']];
 return get_posts($args);
}
function cancha_people_box($post){
 wp_nonce_field('cancha_people_save','cancha_people_nonce');$id=$post->ID;
 echo '<p class="description">Completá los campos de esta ficha. Foto, escudo o emblema: usar «Imagen destacada». El texto principal se edita con el editor de WordPress.</p>';
 if($post->post_type==='cancha_campeonato'){
   cancha_person_input('category','Categoría y modalidad',cancha_person_field($id,'category'),'text','Primera división · Fútbol 11');
   cancha_person_input('location','Sede / localidad',cancha_person_field($id,'location'),'text','Ciudad / Barrio');
   cancha_person_input('organizer','Entidad organizadora',cancha_person_field($id,'organizer'));
   cancha_person_input('start_date','Fecha de inicio',cancha_person_field($id,'start_date'),'date');
   cancha_person_input('end_date','Fecha de finalización',cancha_person_field($id,'end_date'),'date');
   cancha_person_input('format','Formato de competencia',cancha_person_field($id,'format'),'text','Todos contra todos · Ida y vuelta');
   cancha_person_input('regulation_url','Reglamento (URL HTTPS)',cancha_person_field($id,'regulation_url'),'url','https://...');
 }elseif($post->post_type==='cancha_equipo'){
   cancha_person_input('founded','Año de fundación',cancha_person_field($id,'founded'),'number','1998');
   cancha_person_input('homeground','Cancha / estadio',cancha_person_field($id,'homeground'));
   cancha_person_input('city','Ciudad / barrio',cancha_person_field($id,'city'));
   cancha_person_input('colors','Colores del club',cancha_person_field($id,'colors'),'text','Verde y blanco');
   cancha_person_input('website','Web / red oficial (HTTPS)',cancha_person_field($id,'website'),'url','https://...');
 }elseif($post->post_type==='cancha_jugador'){
   cancha_person_select('team','Club actual',cancha_comp_teams(0),cancha_person_field($id,'team'));
   cancha_person_input('number','Número de camiseta',cancha_person_field($id,'number'),'number','10');
   $position=cancha_person_field($id,'position');
   echo '<p><label><strong>Posición principal</strong><br><select name="cancha_extra[position]" class="widefat">';
   foreach(['arquero'=>'Arquero','defensor'=>'Defensor','mediocampista'=>'Mediocampista','delantero'=>'Delantero','otro'=>'Otra'] as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected($position,$k,false).'>'.esc_html($v).'</option>';
   echo '</select></label></p>';
   cancha_person_input('nationality','Nacionalidad (opcional)',cancha_person_field($id,'nationality'));
   cancha_person_input('foot','Pierna hábil (opcional)',cancha_person_field($id,'foot'));
   echo '<p class="description"><strong>Privacidad:</strong> no publicamos documento, domicilio, datos de contacto ni fecha de nacimiento. Para menores, obtené autorización antes de subir foto y datos identificatorios.</p>';
 }elseif($post->post_type==='cancha_staff'){
   cancha_person_select('team','Club (si pertenece a un equipo)',cancha_comp_teams(0),cancha_person_field($id,'team'));
   cancha_person_select('competition','Campeonato (si pertenece a la organización)',cancha_comp_tournaments(),cancha_person_field($id,'competition'));
   cancha_person_input('role','Cargo / función',cancha_person_field($id,'role'),'text','Presidente, delegado, director técnico...');
   cancha_person_input('since','En funciones desde (año)',cancha_person_field($id,'since'),'number');
   echo '<p class="description">Un miembro puede estar asociado a un club o al campeonato. No incluyas teléfonos ni documentos privados en la descripción.</p>';
 }elseif($post->post_type==='cancha_temporada'){
   cancha_person_select('competition','Campeonato histórico',cancha_comp_tournaments(),cancha_person_field($id,'competition'));
   cancha_person_input('season','Temporada / edición',cancha_person_field($id,'season'),'text','2025 · Apertura');
   cancha_person_select('champion','Club campeón',cancha_comp_teams(0),cancha_person_field($id,'champion'));
   cancha_person_select('runnerup','Subcampeón (opcional)',cancha_comp_teams(0),cancha_person_field($id,'runnerup'));
   cancha_person_input('final_date','Fecha de coronación',cancha_person_field($id,'final_date'),'date');
   echo '<p class="description">El historial se administra por edición; es independiente de la tabla actual y no se sobrescribe al comenzar otra temporada.</p>';
 }
}
add_action('add_meta_boxes',function(){
 foreach(['cancha_campeonato'=>'Datos del campeonato','cancha_equipo'=>'Ficha institucional del club','cancha_jugador'=>'Datos deportivos del jugador','cancha_staff'=>'Directiva / cuerpo técnico','cancha_temporada'=>'Resultado de la edición histórica'] as $t=>$title){
   add_meta_box('cancha_people_'.$t,$title,'cancha_people_box',$t,'normal','high');
 }
});
function cancha_people_schemas(){
 return [
  'cancha_campeonato'=>['category'=>'text','location'=>'text','organizer'=>'text','start_date'=>'date','end_date'=>'date','format'=>'text','regulation_url'=>'url'],
  'cancha_equipo'=>['founded'=>'year','homeground'=>'text','city'=>'text','colors'=>'text','website'=>'url'],
  'cancha_jugador'=>['team'=>'team','number'=>'number','position'=>'position','nationality'=>'text','foot'=>'text'],
  'cancha_staff'=>['team'=>'team','competition'=>'competition','role'=>'text','since'=>'year'],
  'cancha_temporada'=>['competition'=>'competition','season'=>'text','champion'=>'team','runnerup'=>'team','final_date'=>'date']
 ];
}
function cancha_people_sanitize($raw,$kind){
 if(!is_scalar($raw))return '';$raw=(string)wp_unslash($raw);
 switch($kind){
 case 'team':$id=absint($raw);return $id && get_post_type($id)==='cancha_equipo' && get_post_status($id)==='publish' ? $id:0;
 case 'competition':$id=absint($raw);return $id && get_post_type($id)==='cancha_campeonato' && get_post_status($id)==='publish' ? $id:0;
 case 'year':$n=absint($raw);return $n>=1850 && $n <= (int)wp_date('Y')+1 ? $n:0;
 case 'number':return $raw===''?'':min(99,absint($raw));
 case 'date':return function_exists('cancha_valid_date') ? cancha_valid_date($raw) : (preg_match('/^20\d\d-\d\d-\d\d$/',$raw)?$raw:'');
 case 'url':$url=esc_url_raw($raw,['https']);return wp_parse_url($url,PHP_URL_SCHEME)==='https' ? $url:'';
 case 'position':return in_array($raw,['arquero','defensor','mediocampista','delantero','otro'],true)?$raw:'otro';
 default:return substr(sanitize_text_field($raw),0,190);
 }
}
function cancha_people_save($id,$post){
 if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
 if(wp_is_post_revision($id)||!current_user_can('manage_options')||empty($_POST['cancha_people_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cancha_people_nonce'])),'cancha_people_save'))return;
 $schemas=cancha_people_schemas();$fields=$schemas[$post->post_type]??[];
 if(!$fields || !isset($_POST['cancha_extra']) || !is_array($_POST['cancha_extra']))return;
 foreach($fields as $key=>$type){$value=cancha_people_sanitize($_POST['cancha_extra'][$key]??'',$type);update_post_meta($id,'_cancha_'.$key,$value);}
 // Members of championship staff and club officials must have just one public association.
 if($post->post_type==='cancha_staff' && cancha_person_field($id,'team'))update_post_meta($id,'_cancha_competition',0);
}
foreach(['cancha_campeonato','cancha_equipo','cancha_jugador','cancha_staff','cancha_temporada'] as $type){add_action('save_post_'.$type,'cancha_people_save',20,2);}
function cancha_people_members($type,$relation,$id){return cancha_people_all($type,$relation,$id,500);}
function cancha_public_emblem($id,$size='thumbnail'){
 if(has_post_thumbnail($id))return get_the_post_thumbnail($id,$size,['class'=>'cancha-profile-image','loading'=>'lazy']);
 return '<span class="cancha-profile-placeholder" aria-hidden="true">'.(get_post_type($id)==='cancha_jugador'?'👟':'⚽').'</span>';
}
function cancha_people_cards($posts,$tag='Equipo'){
 if(!$posts)return '<div class="module-empty">Todavía no hay registros publicados.</div>';
 $html='<div class="cancha-directory-grid">';
 foreach($posts as $p){$html.='<a class="glass cancha-directory-card" href="'.esc_url(get_permalink($p)).'">'.cancha_public_emblem($p->ID).'<div><small class="eyebrow">'.esc_html($tag).'</small><h3>'.esc_html(get_the_title($p)).'</h3>';
 if($tag==='Jugador')$html.='<span>'.esc_html(ucfirst(cancha_person_field($p->ID,'position'))).' · #'.esc_html(cancha_person_field($p->ID,'number')).'</span>';
 if($tag==='Directiva')$html.='<span>'.esc_html(cancha_person_field($p->ID,'role')).'</span>';
 $html.='</div><span aria-hidden="true">→</span></a>';}
 return $html.'</div>';
}
function cancha_champions_html($competition){
 $entries=cancha_people_members('cancha_temporada','competition',$competition);
 if(!$entries)return '<p class="muted">Todavía no se registraron campeones de ediciones anteriores.</p>';
 usort($entries,static function($a,$b){return strcmp(cancha_person_field($b->ID,'season'),cancha_person_field($a->ID,'season'));});
 $html='<div class="cancha-history">';
 foreach($entries as $record){$winner=absint(cancha_person_field($record->ID,'champion'));$runner=absint(cancha_person_field($record->ID,'runnerup'));
 $html.='<div class="history-row"><strong>'.esc_html(cancha_person_field($record->ID,'season')).'</strong><span>🏆 '.($winner?'<a href="'.esc_url(get_permalink($winner)).'">'.esc_html(get_the_title($winner)).'</a>':'Por definir').'</span>';
 if($runner)$html.='<span>Subcampeón: '.esc_html(get_the_title($runner)).'</span>';
 $html.='</div>';}
 return $html.'</div>';
}
add_action('admin_enqueue_scripts',function(){
 $screen=get_current_screen();if(!$screen||!in_array($screen->post_type,['cancha_jugador','cancha_staff','cancha_temporada'],true))return;
 wp_enqueue_style('cancha-competitions-admin',plugins_url('../assets/competitions-admin.css',__FILE__),[],CANCHA_COMP_VERSION);
});
