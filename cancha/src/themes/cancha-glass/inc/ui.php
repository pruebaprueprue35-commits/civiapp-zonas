<?php
/** CANCHA site appearance and navigation from the central admin panel. */
defined('ABSPATH') || exit;
function cancha_ui_defaults() {
 return ['hero_title'=>'Viví el fútbol desde adentro.','hero_subtitle'=>'Campeonatos amateur, fútbol oficial, resultados, predicciones y tu comunidad en un solo lugar.','accent'=>'#13d676','show_news'=>1,'news_count'=>4,'header_search'=>1,'home_show_sponsors'=>1,'home_show_official'=>1,'home_show_amateur'=>1,'home_show_predictions'=>1,'main_sponsor_source'=>'auto','default_color_mode'=>'light','hide_frontend_toolbar'=>'all'];
}
function cancha_ui_setting($key,$fallback=null) {
 $s=get_option('cancha_ui_options',[]);$defaults=cancha_ui_defaults();
 return isset($s[$key])?$s[$key]:($fallback!==null?$fallback:($defaults[$key]??''));
}
add_action('admin_init',function(){register_setting('cancha_ui_group','cancha_ui_options',['type'=>'array','sanitize_callback'=>'cancha_ui_sanitize','default'=>cancha_ui_defaults()]);});
function cancha_ui_sanitize($a) {
 if(!current_user_can('manage_options')||!is_array($a))return get_option('cancha_ui_options',[]);
 $color=sanitize_hex_color($a['accent']??'');
 return ['hero_title'=>substr(sanitize_text_field($a['hero_title']??''),0,130),
 'hero_subtitle'=>substr(sanitize_textarea_field($a['hero_subtitle']??''),0,350),
 'accent'=>$color ?: '#13d676','show_news'=>empty($a['show_news'])?0:1,
 'news_count'=>min(12,max(1,absint($a['news_count']??4))),
 'header_search'=>empty($a['header_search'])?0:1,
 'home_show_sponsors'=>empty($a['home_show_sponsors'])?0:1,
 'home_show_official'=>empty($a['home_show_official'])?0:1,
 'home_show_amateur'=>empty($a['home_show_amateur'])?0:1,
 'home_show_predictions'=>empty($a['home_show_predictions'])?0:1,
 'main_sponsor_source'=>in_array(($a['main_sponsor_source']??'auto'),['auto','general'],true)?$a['main_sponsor_source']:'auto',
 'default_color_mode'=>in_array(($a['default_color_mode']??'light'),['light','dark'],true)?$a['default_color_mode']:'light',
 'hide_frontend_toolbar'=>in_array(($a['hide_frontend_toolbar']??'all'),['all','subscribers','never'],true)?$a['hide_frontend_toolbar']:'all'];
}
function cancha_ui_screen() {
 if(!current_user_can('manage_options'))wp_die('Acceso denegado');
 cancha_core_admin_header('Apariencia y menús','Personalizá la identidad pública sin editar archivos ni instalar un constructor.');$o=get_option('cancha_ui_options',[]);$o=wp_parse_args($o,cancha_ui_defaults()); ?>
 <div class="cancha-admin-cards">
  <a class="cancha-admin-card" href="<?php echo esc_url(admin_url('customize.php')); ?>"><strong>Logo y título del sitio</strong><span>Apariencia → Personalizar → Identidad del sitio.</span></a>
  <a class="cancha-admin-card" href="<?php echo esc_url(admin_url('nav-menus.php')); ?>"><strong>Editar menús</strong><span>Creá un menú principal y uno de pie. Asignalos a «Navegación principal» y «Pie».</span></a>
  <a class="cancha-admin-card" href="<?php echo esc_url(admin_url('admin.php?page=cancha-settings#cancha-users')); ?>"><strong>Usuarios y Google</strong><span>Registro, privacidad, términos y autenticación.</span></a>
 </div>
 <form method="post" action="options.php"><?php settings_fields('cancha_ui_group'); ?>
 <h2>Accesibilidad y navegación pública</h2><table class="form-table">
 <tr><th>Aspecto predeterminado</th><td><select name="cancha_ui_options[default_color_mode]"><option value="light" <?php selected($o['default_color_mode'],'light'); ?>>Claro</option><option value="dark" <?php selected($o['default_color_mode'],'dark'); ?>>Oscuro</option></select><p class="description">Cada usuario puede cambiarlo desde el botón visible en su celular; se recuerda su preferencia.</p></td></tr>
 <tr><th>Barra de herramientas en la web pública</th><td><select name="cancha_ui_options[hide_frontend_toolbar]"><option value="all" <?php selected($o['hide_frontend_toolbar'],'all'); ?>>Ocultar a todos, también administradores (solo frontend)</option><option value="subscribers" <?php selected($o['hide_frontend_toolbar'],'subscribers'); ?>>Ocultar solo a suscriptores</option><option value="never" <?php selected($o['hide_frontend_toolbar'],'never'); ?>>No ocultar</option></select><p class="description">No oculta la barra ni menús dentro de /wp-admin/. Los enlaces externos «Acceder / Registrarse» que agreguen otros complementos requieren localizar su origen.</p></td></tr>
 </table><h2>Diseño de portada</h2><table class="form-table">
 <tr><th>Mensaje principal</th><td><input class="large-text" maxlength="130" name="cancha_ui_options[hero_title]" value="<?php echo esc_attr($o['hero_title']); ?>"></td></tr>
 <tr><th>Descripción</th><td><textarea class="large-text" rows="3" maxlength="350" name="cancha_ui_options[hero_subtitle]"><?php echo esc_textarea($o['hero_subtitle']); ?></textarea></td></tr>
 <tr><th>Color destacado</th><td><input type="color" name="cancha_ui_options[accent]" value="<?php echo esc_attr($o['accent']); ?>"><p class="description">Identifica los botones y enlaces. Las secciones administrativas conservan sus colores propios.</p></td></tr>
 <tr><th>Inicio y módulos</th><td><label><input type="checkbox" name="cancha_ui_options[show_news]" value="1" <?php checked($o['show_news']); ?>> Mostrar feed de novedades en la portada</label><p><label>Número de noticias <input type="number" min="1" max="12" name="cancha_ui_options[news_count]" value="<?php echo esc_attr($o['news_count']); ?>"></label></p><label><input type="checkbox" value="1" name="cancha_ui_options[header_search]" <?php checked($o['header_search']); ?>> Mostrar búsqueda en el encabezado</label><br><label><input type="checkbox" name="cancha_ui_options[home_show_sponsors]" value="1" <?php checked($o['home_show_sponsors']); ?>> Mostrar sponsors de inicio</label></td></tr>
 <tr><th>Secciones de portada / Ligas</th><td>
 <label><input type="checkbox" name="cancha_ui_options[home_show_official]" value="1" <?php checked($o['home_show_official']); ?>> Campeonatos oficiales</label><br>
 <label><input type="checkbox" name="cancha_ui_options[home_show_amateur]" value="1" <?php checked($o['home_show_amateur']); ?>> Campeonatos amateur</label><br>
 <label><input type="checkbox" name="cancha_ui_options[home_show_predictions]" value="1" <?php checked($o['home_show_predictions']); ?>> Predicciones</label>
 </td></tr>
 <tr><th>Sponsor principal</th><td><select name="cancha_ui_options[main_sponsor_source]">
 <option value="auto" <?php selected($o['main_sponsor_source'],'auto'); ?>>Automático (Ads portada y luego sponsor general)</option>
 <option value="general" <?php selected($o['main_sponsor_source'],'general'); ?>>Priorizar sponsor general</option>
 </select><p class="description">El sponsor principal aparece en un banner grande; los secundarios usan un carrusel con sus logos. Las imágenes del anunciante deben estar previamente publicadas en CANCHA.</p></td></tr>
 </table><?php submit_button('Guardar apariencia'); ?></form>
 <h2>Menús disponibles</h2><p><strong>Principal:</strong> Inicio, Campeonatos, Buscar, Fútbol oficial, Partidos, Predicciones, Ranking, Novedades y Mi panel. <strong>Pie:</strong> enlaces institucionales, términos y privacidad.</p>
 <p>Si todavía no creaste un menú, CANCHA muestra una navegación básica que ya incluye búsqueda y novedades. Podés personalizarla en el editor de menús de WordPress.</p>
 <p><a class="button button-primary" href="<?php echo esc_url(admin_url('nav-menus.php')); ?>">Administrar menús ahora</a></p></div>
 <?php
}
add_action('wp_enqueue_scripts',function(){
 $color=sanitize_hex_color(cancha_ui_setting('accent')) ?: '#13d676';
 wp_add_inline_style('cancha-glass',':root{--accent:'.$color.';}');
},20);
