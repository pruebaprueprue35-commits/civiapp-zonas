<?php
/** Guided sports setup and deliberately scoped CSV bulk import. */
defined('ABSPATH') || exit;
add_action('admin_menu',function(){
 add_submenu_page('cancha-core','Guía de campeonatos','🏁 Guía de torneos','manage_options','cancha-tournament-guide','cancha_comp_guide_screen');
 add_submenu_page('cancha-core','Importar datos','📥 Importar CSV','manage_options','cancha-csv-import','cancha_comp_csv_screen');
},36);
function cancha_comp_guide_screen(){
 if(!current_user_can('manage_options'))wp_die('Sin permisos');
 echo '<div class="wrap cancha-admin"><h1>CANCHA · Gestión de campeonatos</h1><p>Panel para organizar toda la información de cada torneo sin salir de CANCHA.</p>';
 $steps=[
  ['1. Crear campeonato','Nombre, descripción, escudo, categoría, sede, directiva, reglamento y reglas de puntuación.','post-new.php?post_type=cancha_campeonato'],
  ['2. Cargar clubes','Asigná cada equipo al campeonato, cargá su escudo, colores, estadio e historia.','post-new.php?post_type=cancha_equipo'],
  ['3. Incorporar jugadores','Asociá cada jugador a su club. Cargá dorsal, posición y fotografía.','post-new.php?post_type=cancha_jugador'],
  ['4. Directivas y técnicos','Presidente, delegado, entrenador y personal de clubes o de la organización.','post-new.php?post_type=cancha_staff'],
  ['5. Programar partidos','Local, visitante, fecha, jornada y resultados. Después cargá planillas, goles y tarjetas.','post-new.php?post_type=cancha_partido'],
  ['6. Revisar y publicar','Validá resultados de la jornada, revisá tabla y hacé el aviso público.','admin.php?page=cancha-jornadas'],
  ['7. Historial de campeones','Documentá las ediciones y campeones anteriores del campeonato.','post-new.php?post_type=cancha_temporada'],
 ];
 echo '<div class="cancha-admin-cards">';foreach($steps as [$heading,$desc,$link])echo '<a class="cancha-admin-card" href="'.esc_url(admin_url($link)).'"><strong>'.esc_html($heading).'</strong><span>'.esc_html($desc).'</span></a>';echo '</div>';
 echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=cancha-csv-import')).'">Importar clubes o jugadores mediante CSV</a></p>';
 echo '<p class="description">Los resultados finales generan tablas y estadísticas, pero no puntos de predicciones o GamiPress. No se publican datos privados de menores.</p></div>';
}
function cancha_comp_csv_screen(){
 if(!current_user_can('manage_options'))wp_die('Sin permisos');
 $msg=get_transient('cancha_csv_'.get_current_user_id());if($msg)delete_transient('cancha_csv_'.get_current_user_id());
 echo '<div class="wrap cancha-admin"><h1>Importación CSV de CANCHA</h1><p>Podés importar equipos de un campeonato o el plantel de un club desde una planilla CSV en UTF-8. Se omiten nombres duplicados dentro del destino.</p>';
 if($msg)echo '<div class="notice notice-info inline"><p>'.esc_html($msg).'</p></div>';
 echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('cancha_import_csv','cancha_csv_nonce');
 echo '<input type="hidden" name="action" value="cancha_import_csv"><table class="form-table">';
 echo '<tr><th>Importar</th><td><label><input type="radio" name="cancha_import_type" value="teams" checked> Equipos</label> &nbsp; <label><input type="radio" name="cancha_import_type" value="players"> Jugadores</label></td></tr>';
 echo '<tr><th>Campeonato (para equipos)</th><td><select name="cancha_csv_comp">'.cancha_comp_options_html(cancha_comp_tournaments()).'</select></td></tr>';
 echo '<tr><th>Club (para jugadores)</th><td><select name="cancha_csv_team">'.cancha_comp_options_html(cancha_comp_teams()).'</select></td></tr>';
 echo '<tr><th>Archivo</th><td><input type="file" name="cancha_csv_file" accept=".csv,text/csv" required><p class="description">Máximo 1 MB y 1.000 filas por envío. No se importan documentos, fechas de nacimiento ni domicilios.</p></td></tr></table>';
 submit_button('Importar datos');echo '</form>';
 echo '<h2>Formatos CSV compatibles</h2><p><strong>Equipos:</strong> encabezados <code>nombre,ciudad,colores,cancha,fundacion</code></p><p><strong>Jugadores:</strong> <code>nombre,numero,posicion,nacionalidad</code></p><p>Ejemplos completos en el archivo README del complemento. Los datos se importan como publicados: revisá nombres y permisos antes de subir el archivo.</p></div>';
}
add_action('admin_post_cancha_import_csv',function(){
 if(!current_user_can('manage_options'))wp_die('No autorizado',403);check_admin_referer('cancha_import_csv','cancha_csv_nonce');
 $type=sanitize_key($_POST['cancha_import_type']??'');$comp=absint($_POST['cancha_csv_comp']??0);$team=absint($_POST['cancha_csv_team']??0);
 $target=$type==='teams'?$comp:$team;
 $valid=($type==='teams' && get_post_type($comp)==='cancha_campeonato' && get_post_status($comp)==='publish') ||
  ($type==='players' && get_post_type($team)==='cancha_equipo' && get_post_status($team)==='publish');
 $summary='';
 if(!$valid)$summary='Seleccioná un campeonato o club publicado para importar.';
 elseif(empty($_FILES['cancha_csv_file']) || (int)($_FILES['cancha_csv_file']['error']??99)!==UPLOAD_ERR_OK)$summary='El archivo no pudo recibirse.';
 else {
  $file=$_FILES['cancha_csv_file'];$name=sanitize_file_name($file['name']??'');$size=absint($file['size']??0);
  if(!preg_match('/\.csv$/i',$name)||$size<3||$size>1024*1024||!is_uploaded_file($file['tmp_name']))$summary='Solo se admite un archivo CSV de hasta 1 MB cargado por el navegador.';
  else $summary=cancha_comp_import_csv($file['tmp_name'],$type,$target);
 }
 set_transient('cancha_csv_'.get_current_user_id(),$summary,150);
 wp_safe_redirect(admin_url('admin.php?page=cancha-csv-import'));exit;
});
function cancha_csv_fold($value){ $value=trim((string)$value);return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value); }
function cancha_comp_import_csv($path,$type,$target){
 $fp=fopen($path,'rb');if(!$fp)return 'No se pudo leer CSV.';
 $line=fgets($fp,8192);if($line===false){fclose($fp);return 'El archivo está vacío.';}
 $delimiter=substr_count($line,';')>substr_count($line,',')?';':',';rewind($fp);
 $headers=fgetcsv($fp,0,$delimiter,'"','');if(!$headers){fclose($fp);return 'No se encontró el encabezado.';}
 $headers=array_map(static function($s){return sanitize_key(trim(preg_replace('/^\xEF\xBB\xBF/','',(string)$s)));},$headers);
 $required=$type==='teams'?'nombre':'nombre';if(!in_array($required,$headers,true)){fclose($fp);return 'Falta la columna nombre.';}
 $post_type=$type==='teams'?'cancha_equipo':'cancha_jugador';$relation=$type==='teams'?'comp_id':'team';
 $existing=[];foreach(cancha_people_all($post_type,$relation,$target,3000) as $p)$existing[cancha_csv_fold($p->post_title)]=true;
 $created=0;$skipped=0;$errors=0;$row_count=0;
 while(($values=fgetcsv($fp,0,$delimiter,'"',''))!==false && $row_count<1000){
  $row_count++;if(!is_array($values)||count($values)>30){$errors++;continue;}
  $row=[];foreach($headers as $index=>$key)$row[$key]=(string)($values[$index]??'');
  $title=substr(sanitize_text_field($row['nombre']??''),0,130);
  $dedup=cancha_csv_fold($title);if(strlen($title)<2||isset($existing[$dedup])){$skipped++;continue;}
  $id=wp_insert_post(['post_type'=>$post_type,'post_status'=>'publish','post_title'=>$title,'post_content'=>'','post_author'=>get_current_user_id(),'comment_status'=>'closed'],true);
  if(is_wp_error($id)){$errors++;continue;}
  update_post_meta($id,'_cancha_'.$relation,$target);
  if($type==='teams'){
   foreach(['ciudad'=>'city','colores'=>'colors','cancha'=>'homeground','fundacion'=>'founded'] as $source=>$field){
    $kind=$field==='founded'?'year':'text';update_post_meta($id,'_cancha_'.$field,cancha_people_sanitize($row[$source]??'',$kind));
   }
  }else{
   foreach(['numero'=>'number','posicion'=>'position','nacionalidad'=>'nationality'] as $source=>$field){
    update_post_meta($id,'_cancha_'.$field,cancha_people_sanitize($row[$source]??'',$field==='number'?'number':($field==='position'?'position':'text')));
   }
  }
  $existing[$dedup]=true;$created++;
 }
 fclose($fp);
 return sprintf('Importación completada: %d creados, %d omitidos y %d errores. Se procesaron %d filas (máximo 1.000 por archivo).',$created,$skipped,$errors,$row_count);
}
