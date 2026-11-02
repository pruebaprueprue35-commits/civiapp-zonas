<?php
if (!defined('ABSPATH')) exit;
function cof_competitions() {
    return [
        'liga_profesional'=>'Liga Profesional Argentina',
        'primera_nacional'=>'Primera Nacional',
        'copa_argentina'=>'Copa Argentina',
        'libertadores'=>'CONMEBOL Libertadores',
        'sudamericana'=>'CONMEBOL Sudamericana',
    ];
}
function cof_defaults() {
    return [
        'provider'=>'thesportsdb',
        'season'=>(string)wp_date('Y'),
        'timezone'=>'America/Argentina/Buenos_Aires',
        'ids'=>['liga_profesional'=>128,'primera_nacional'=>129,'copa_argentina'=>130,'libertadores'=>13,'sudamericana'=>11],
        'tsdb_ids'=>['liga_profesional'=>4406,'primera_nacional'=>4616,'copa_argentina'=>4500,'libertadores'=>4501,'sudamericana'=>4724],
        'secret'=>'',
        'cache_minutes'=>90,
        'read_existing_cancha_key'=>1,
    ];
}
function cof_settings(){return wp_parse_args((array)get_option('cof_settings',[]),cof_defaults());}
function cof_season(){ $s=cof_settings(); return preg_match('/^20\d{2}$/',(string)$s['season']) ? (string)$s['season'] : wp_date('Y'); }
function cof_key() {
    if (defined('CANCHA_OFICIAL_API_KEY') && is_string(CANCHA_OFICIAL_API_KEY) && CANCHA_OFICIAL_API_KEY!=='') return CANCHA_OFICIAL_API_KEY;
    $opts=cof_settings();
    if ($opts['secret']) {
        $raw=base64_decode($opts['secret'],true);
        if($raw!==false && strlen($raw)>28 && function_exists('openssl_decrypt')) {
            $key=hash('sha256',wp_salt('auth').wp_salt('secure_auth'),true);
            $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
            if ($plain!==false) return $plain;
        }
    }
    if(!empty($opts['read_existing_cancha_key']) && function_exists('cancha_api_key') && function_exists('cancha_core_settings')) {
        $old=cancha_core_settings();
        if(($old['api_provider']??'')==='api-football') return (string)cancha_api_key();
    }
    return '';
}
function cof_encrypt($plain){
    if(!function_exists('openssl_encrypt') || !function_exists('random_bytes')) return '';
    $iv=random_bytes(12);$tag='';$key=hash('sha256',wp_salt('auth').wp_salt('secure_auth'),true);
    $data=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
    return $data===false?'':base64_encode($iv.$tag.$data);
}
function cof_register_menu(){
    // CANCHA parent menu is owned by the theme; do not duplicate it.
    if (!function_exists('cancha_core_dashboard') && !function_exists('cancha_core_settings')) {
        add_menu_page('CANCHA','CANCHA','manage_options','cancha-core','cof_fallback_dashboard','dashicons-shield',27);
    }
    add_submenu_page('cancha-core','Fútbol oficial','⚽ Fútbol oficial','manage_options','cancha-oficial','cof_admin_screen');
}
function cof_fallback_dashboard(){echo '<div class="wrap"><h1>CANCHA</h1><p><a href="'.esc_url(admin_url('admin.php?page=cancha-oficial')).'">Configurar fútbol oficial</a></p></div>';}
function cof_settings_register(){
    register_setting('cof_settings_group','cof_settings',['type'=>'array','sanitize_callback'=>'cof_sanitize','default'=>cof_defaults()]);
}
function cof_sanitize($input){
    $old=cof_settings(); if(!current_user_can('manage_options') || !is_array($input))return $old;
    $out=$old;
    $out['provider']=in_array(($input['provider']??''),['api-football','thesportsdb'],true)?$input['provider']:$old['provider'];
    $out['season']=preg_match('/^20\d{2}$/',(string)($input['season']??''))?(string)$input['season']:$old['season'];
    $out['timezone']=in_array(($input['timezone']??''),['America/Argentina/Buenos_Aires','America/Argentina/Tucuman','UTC'],true)?$input['timezone']:'America/Argentina/Buenos_Aires';
    $out['cache_minutes']=max(60,min(360,absint($input['cache_minutes']??90)));
    $out['read_existing_cancha_key']=empty($input['read_existing_cancha_key'])?0:1;
    foreach(['ids','tsdb_ids'] as $group){
        $out[$group]=[];
        foreach(cof_competitions() as $code=>$label) {
            $out[$group][$code]=min(999999,max(0,absint($input[$group][$code]??$old[$group][$code]??0)));
        }
    }
    if(!empty($input['delete_key'])) $out['secret']='';
    elseif(!empty($input['new_key'])){
        $new=trim((string)$input['new_key']);
        if(strlen($new)<15 || strlen($new)>512 || preg_match('/[\x00-\x1F\x7F]/',$new)) add_settings_error('cof_settings','badkey','La clave no tiene el formato esperado.','error');
        else { $enc=cof_encrypt($new); if(!$enc) add_settings_error('cof_settings','encrypt','No se puede cifrar la clave. Usá CANCHA_OFICIAL_API_KEY en wp-config.php.','error'); else $out['secret']=$enc; }
    }
    return $out;
}
function cof_notice($message,$type='success'){
    set_transient('cof_admin_notice_'.get_current_user_id(),['text'=>$message,'type'=>$type],90);
}
function cof_admin_probe(){
    if(!current_user_can('manage_options'))wp_die('Acceso denegado');
    check_admin_referer('cof_probe');
    $comp=sanitize_key(wp_unslash($_POST['cof_comp']??''));
    if(!isset(cof_competitions()[$comp]))wp_die('Competición inválida');
    $identity=cof_probe_league_identity($comp);
    if(is_wp_error($identity)){
        cof_notice(cof_competitions()[$comp].': '.$identity->get_error_message(),'error');
        wp_safe_redirect(admin_url('admin.php?page=cancha-oficial'));exit;
    }
    $result=cof_get_fixtures($comp,true);
    $title=cof_competitions()[$comp];
    if (is_wp_error($result))cof_notice($title.': '.$result->get_error_message(),'error');
    else cof_notice($title.': proveedor identifica «'.($identity['name']??'No confirmado').'». Partidos recibidos: '.count($result['matches']).'. Este número no garantiza cobertura completa.');
    wp_safe_redirect(admin_url('admin.php?page=cancha-oficial'));exit;
}
function cof_admin_clear(){
    if(!current_user_can('manage_options'))wp_die('Acceso denegado');
    check_admin_referer('cof_clear');
    $comp=sanitize_key(wp_unslash($_POST['cof_comp']??''));
    if(!isset(cof_competitions()[$comp]))wp_die('Competición inválida');
    cof_purge($comp);
    cof_notice('Se limpió la caché de '.cof_competitions()[$comp].'. No se modificaron partidos, usuarios ni torneos.');
    wp_safe_redirect(admin_url('admin.php?page=cancha-oficial'));exit;
}
function cof_admin_screen(){
    if(!current_user_can('manage_options'))wp_die('Acceso denegado');
    $s=cof_settings();
    $notice=get_transient('cof_admin_notice_'.get_current_user_id());if($notice)delete_transient('cof_admin_notice_'.get_current_user_id());
    echo '<div class="wrap cof-admin"><h1>CANCHA · Fútbol oficial</h1><p>Resultados nativos dentro de CANCHA. No escribe en Campeonatos amateur, Predicciones, GamiPress ni usuarios.</p>';
    if($notice)echo '<div class="notice notice-'.esc_attr($notice['type']).'"><p>'.esc_html($notice['text']).'</p></div>';
    settings_errors('cof_settings');
    echo '<div class="cof-admin-grid"><div class="cof-admin-panel"><h2>Conexión y temporada</h2><form action="'.esc_url(admin_url('options.php')).'" method="post">';
    settings_fields('cof_settings_group');
    echo '<table class="form-table"><tr><th>Proveedor</th><td><select name="cof_settings[provider]"><option value="api-football" '.selected($s['provider'],'api-football',false).'>API-Football (recomendado)</option><option value="thesportsdb" '.selected($s['provider'],'thesportsdb',false).'>TheSportsDB gratuito (cinco torneos; datos parciales)</option></select><p class="description">API-Football permite usar su plan gratuito con clave; la cobertura de temporadas puede estar limitada.</p></td></tr>';
    echo '<tr><th>Temporada</th><td><input type="number" min="2020" max="2099" name="cof_settings[season]" value="'.esc_attr($s['season']).'"></td></tr>';
    echo '<tr><th>Zona horaria</th><td><select name="cof_settings[timezone]">';
    foreach(['America/Argentina/Buenos_Aires'=>'Argentina / Buenos Aires','America/Argentina/Tucuman'=>'Argentina / Tucumán','UTC'=>'UTC'] as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($s['timezone'],$key,false).'>'.esc_html($label).'</option>';
    echo '</select></td></tr>';
    echo '<tr><th>Clave privada API-Football</th><td><input type="password" name="cof_settings[new_key]" autocomplete="new-password" class="regular-text" placeholder="'.($s['secret']?'Clave cifrada guardada':'Ingresar clave').'"><p class="description">No se publica en HTML. También podés definir <code>CANCHA_OFICIAL_API_KEY</code> en wp-config.php.</p><label><input type="checkbox" name="cof_settings[delete_key]" value="1"> Borrar clave de este módulo</label><p><label><input type="checkbox" value="1" name="cof_settings[read_existing_cancha_key]" '.checked($s['read_existing_cancha_key'],1,false).'> Reutilizar clave API-Football de CANCHA Core, si existe</label></p></td></tr>';
    echo '<tr><th>Caché de partidos</th><td><input type="number" min="60" max="360" name="cof_settings[cache_minutes]" value="'.esc_attr($s['cache_minutes']).'"> minutos</td></tr></table>';
    echo '<h2>Identificadores de competición</h2><p>IDs sugeridos para API-Football. Verificalos con el botón Probar porque pueden variar por proveedor. Los IDs de TheSportsDB se identificaron en sus páginas oficiales y son independientes; el plan gratuito limita cada consulta.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Competición</th><th>API-Football ID</th><th>TheSportsDB ID</th></tr></thead><tbody>';
    foreach(cof_competitions() as $code=>$label){
        echo '<tr><td>'.esc_html($label).'</td><td><input type="number" min="0" max="999999" name="cof_settings[ids]['.esc_attr($code).']" value="'.esc_attr($s['ids'][$code]??0).'" aria-label="ID API Football '.esc_attr($label).'"></td><td><input type="number" min="0" max="999999" name="cof_settings[tsdb_ids]['.esc_attr($code).']" value="'.esc_attr($s['tsdb_ids'][$code]??0).'" aria-label="ID TheSportsDB '.esc_attr($label).'"></td></tr>';
    }
    echo '</tbody></table>';
    submit_button('Guardar configuración');echo '</form></div><div class="cof-admin-panel"><h2>Comprobar proveedores</h2><p>Probá después de guardar. Se mostrará la cantidad de partidos reales que devuelve el proveedor.</p>';
    foreach(cof_competitions() as $code=>$label){
        echo '<div class="cof-probe"><strong>'.esc_html($label).'</strong><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('cof_probe');echo '<input type="hidden" name="action" value="cof_probe"><input type="hidden" name="cof_comp" value="'.esc_attr($code).'"><button class="button button-secondary">Probar</button></form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('cof_clear');echo '<input type="hidden" name="action" value="cof_clear"><input type="hidden" name="cof_comp" value="'.esc_attr($code).'"><button class="button">Limpiar caché</button></form></div>';
    }
    echo '<h2>Uso en páginas</h2><p><code>[cancha_oficial]</code> · Panel general</p><p><code>[cancha_oficial_competicion codigo="libertadores"]</code> · Una copa</p>';
    echo '<p>En CANCHA Glass 1.3, la página <strong>Fútbol oficial</strong> utiliza este módulo automáticamente mientras esté activo.</p>';
    echo '<p><a class="button button-primary" href="'.esc_url(home_url('/futbol-oficial/')).'" target="_blank" rel="noopener">Abrir página pública</a></p>';
    echo '<p><strong>Plan gratuito:</strong> los resultados pueden no estar disponibles para el año elegido. Se mostrarán avisos en vez de datos inventados.</p></div></div></div>';
}
