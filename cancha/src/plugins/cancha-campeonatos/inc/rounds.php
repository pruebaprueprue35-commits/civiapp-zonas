<?php
/** Publicación por jornada controlada, sin enviar correos ni tocar puntos de GamiPress. */
defined('ABSPATH') || exit;
add_action('admin_menu',function(){
    add_submenu_page('cancha-core','Resultados por jornada','🧾 Jornadas','manage_options','cancha-jornadas','cancha_comp_rounds_screen');
},35);
function cancha_comp_rounds($comp){
    $rounds=[];
    foreach(cancha_comp_matches($comp) as $m)$rounds[]=max(1,absint(get_post_meta($m->ID,'_cancha_round',true)));
    $rounds=array_values(array_unique($rounds));sort($rounds,SORT_NUMERIC);return $rounds;
}
function cancha_comp_round_matches($comp,$round){
    $matches=[];
    foreach(cancha_comp_matches($comp) as $m)if((int)get_post_meta($m->ID,'_cancha_round',true)===$round)$matches[]=$m;
    return $matches;
}
function cancha_comp_round_snapshot($comp,$round){
    $items=[];
    foreach(cancha_comp_round_matches($comp,$round) as $m){
        $id=$m->ID;$final=get_post_meta($id,'_cancha_result_status',true)==='final';
        $h=cancha_comp_valid_score(get_post_meta($id,'_cancha_home_score',true));$a=cancha_comp_valid_score(get_post_meta($id,'_cancha_away_score',true));
        $items[]=['id'=>$id,'home'=>absint(get_post_meta($id,'_cancha_home',true)),'away'=>absint(get_post_meta($id,'_cancha_away',true)),'final'=>$final&&$h!==null&&$a!==null,'home_score'=>$h,'away_score'=>$a];
    }
    usort($items,static function($a,$b){return $a['id']<=>$b['id'];});
    return ['items'=>$items,'hash'=>hash('sha256',wp_json_encode($items)),'complete'=>count(array_filter($items,static function($r){return $r['final'];}))];
}
function cancha_comp_rounds_screen(){
    if(!current_user_can('manage_options'))wp_die('Sin permisos.');
    $comp=absint($_GET['campeonato']??0);$round=max(1,absint($_GET['jornada']??1));
    echo '<div class="wrap cancha-rounds-admin"><h1>CANCHA · Resultados por jornada</h1><p><strong>Fútbol amateur.</strong> Guardá y validá los marcadores antes de informar. Los puntos de predicciones, GamiPress y los correos masivos no están conectados todavía.</p>';
    if(!empty($_GET['cancha_msg'])){$code=sanitize_key(wp_unslash($_GET['cancha_msg']));$notices=['saved'=>'Marcadores guardados. Revisá los resultados antes de informar.','informed'=>'Aviso publicado en la ficha del campeonato. No se enviaron correos.','duplicate'=>'Este resumen ya había sido informado; no se duplicó la publicación.','partial'=>'No se publicaron avisos: aceptá expresamente la jornada parcial.','error'=>'No se pudo completar la operación: verificá los permisos y los datos.'];if(isset($notices[$code]))echo '<div class="notice notice-'.($code==='error'?'error':'success').' inline"><p>'.esc_html($notices[$code]).'</p></div>';}
    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="cancha-rounds-selector"><input type="hidden" name="page" value="cancha-jornadas"><label>Campeonato <select name="campeonato">'.cancha_comp_options_html(cancha_comp_tournaments(),$comp).'</select></label>';
    $rounds=$comp?cancha_comp_rounds($comp):[];
    echo ' <label>Jornada <select name="jornada">';foreach($rounds as $r)echo '<option value="'.absint($r).'" '.selected($round,$r,false).'>Jornada '.absint($r).'</option>';echo '</select></label> <button class="button">Ver jornada</button></form>';
    if(!$comp||get_post_type($comp)!=='cancha_campeonato'||get_post_status($comp)!=='publish'){echo '<p>Seleccioná un campeonato publicado.</p></div>';return;}
    $matches=cancha_comp_round_matches($comp,$round);
    if(!$matches){echo '<p>La jornada no contiene partidos publicados. Primero crealos desde CANCHA → Partidos.</p></div>';return;}
    $snapshot=cancha_comp_round_snapshot($comp,$round);$total=count($snapshot['items']);
    echo '<h2>'.esc_html(get_the_title($comp)).' · Jornada '.absint($round).'</h2><p>Resultados finales: <strong>'.absint($snapshot['complete']).' / '.absint($total).'</strong> · Pendientes: '.absint($total-$snapshot['complete']).'</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('cancha_save_round_'.$comp.'_'.$round,'cancha_round_nonce');
    echo '<input type="hidden" name="action" value="cancha_save_round"><input type="hidden" name="comp" value="'.absint($comp).'"><input type="hidden" name="round" value="'.absint($round).'">';
    echo '<table class="widefat striped"><thead><tr><th>Local</th><th>Goles</th><th>Goles</th><th>Visitante</th><th>Estado</th></tr></thead><tbody>';
    foreach($matches as $m){
        $id=$m->ID;$home=get_the_title(absint(get_post_meta($id,'_cancha_home',true)));$away=get_the_title(absint(get_post_meta($id,'_cancha_away',true)));
        $hs=get_post_meta($id,'_cancha_home_score',true);$as=get_post_meta($id,'_cancha_away_score',true);$final=get_post_meta($id,'_cancha_result_status',true)==='final';
        echo '<tr><td>'.esc_html($home).'</td><td><input aria-label="Goles '.esc_attr($home).'" type="number" min="0" max="99" name="scores['.absint($id).'][home]" value="'.esc_attr($hs).'" style="width:65px"></td><td><input aria-label="Goles '.esc_attr($away).'" type="number" min="0" max="99" name="scores['.absint($id).'][away]" value="'.esc_attr($as).'" style="width:65px"></td><td>'.esc_html($away).'</td><td><label><input type="checkbox" name="scores['.absint($id).'][final]" value="1" '.checked($final,true,false).'> Validado</label></td></tr>';
    }
    echo '</tbody></table><p><button class="button button-primary">Guardar y validar marcadores</button> <span>Guardar nunca envía avisos automáticamente.</span></p></form>';
    $notices=(array)get_post_meta($comp,'_cancha_round_notices',true);$record=$notices[$round]??[];
    if($record)echo '<p><strong>Última comunicación:</strong> '.esc_html($record['at']??'').(!empty($record['hash']) && $record['hash']===$snapshot['hash']?' · vigente':' · resultados cambiaron: informar nuevamente').'</p>';
    echo '<div class="cancha-round-preview"><h2>Resumen previo para los usuarios</h2>'.cancha_comp_fixture_html($comp,$round).'</div>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="cancha-inform-round">';
    wp_nonce_field('cancha_inform_round_'.$comp.'_'.$round,'cancha_round_nonce');
    echo '<input type="hidden" name="action" value="cancha_inform_round"><input type="hidden" name="comp" value="'.absint($comp).'"><input type="hidden" name="round" value="'.absint($round).'">';
    if($snapshot['complete']<$total)echo '<p><label><input type="checkbox" name="allow_partial" value="1"> Confirmo que quiero informar <strong>solo los '.absint($snapshot['complete']).' partidos finalizados</strong> y dejar los demás pendientes.</label></p>';
    echo '<p><button class="button button-primary" '.($snapshot['complete']?'':'disabled').'>Informar al usuario (aviso público)</button></p><p><small>Publica o actualiza un aviso dentro de la ficha del campeonato y guarda autor/fecha. No envía emails, push ni concede puntos de GamiPress. Es una operación idempotente por contenido.</small></p></form></div>';
}
function cancha_comp_round_redirect($comp,$round,$msg){
    wp_safe_redirect(add_query_arg(['page'=>'cancha-jornadas','campeonato'=>absint($comp),'jornada'=>absint($round),'cancha_msg'=>sanitize_key($msg)],admin_url('admin.php')));exit;
}
function cancha_comp_admin_round_validate($purpose){
    if(!current_user_can('manage_options'))wp_die('Sin permisos.',403);
    $comp=absint($_POST['comp']??0);$round=absint($_POST['round']??0);
    if(!$comp||!$round||get_post_type($comp)!=='cancha_campeonato'||get_post_status($comp)!=='publish')wp_die('Campeonato o jornada inválidos.',400);
    check_admin_referer('cancha_'.$purpose.'_round_'.$comp.'_'.$round,'cancha_round_nonce');
    return [$comp,$round];
}
add_action('admin_post_cancha_save_round',function(){
    [$comp,$round]=cancha_comp_admin_round_validate('save');
    $scores=(array)(wp_unslash($_POST['scores']??[]));
    foreach(cancha_comp_round_matches($comp,$round) as $match){
        $id=$match->ID;$values=(array)($scores[$id]??[]);
        $hs=cancha_comp_valid_score($values['home']??'');$as=cancha_comp_valid_score($values['away']??'');
        if($hs!==null&&$as!==null){update_post_meta($id,'_cancha_home_score',$hs);update_post_meta($id,'_cancha_away_score',$as);}
        else{delete_post_meta($id,'_cancha_home_score');delete_post_meta($id,'_cancha_away_score');}
        update_post_meta($id,'_cancha_result_status',!empty($values['final'])&&$hs!==null&&$as!==null?'final':'scheduled');
    }
    cancha_comp_round_redirect($comp,$round,'saved');
});
add_action('admin_post_cancha_inform_round',function(){
    [$comp,$round]=cancha_comp_admin_round_validate('inform');
    $snapshot=cancha_comp_round_snapshot($comp,$round);$total=count($snapshot['items']);
    if(!$total||!$snapshot['complete'])cancha_comp_round_redirect($comp,$round,'error');
    if($snapshot['complete']<$total && empty($_POST['allow_partial']))cancha_comp_round_redirect($comp,$round,'partial');
    $notices=(array)get_post_meta($comp,'_cancha_round_notices',true);
    if(($notices[$round]['hash']??'')===$snapshot['hash'])cancha_comp_round_redirect($comp,$round,'duplicate');
    $notices[$round]=['hash'=>$snapshot['hash'],'at'=>gmdate('Y-m-d H:i:s'),'by'=>get_current_user_id(),'final'=>$snapshot['complete'],'total'=>$total];
    ksort($notices,SORT_NUMERIC);if(count($notices)>200)$notices=array_slice($notices,-200,null,true);
    update_post_meta($comp,'_cancha_round_notices',$notices);
    do_action('cancha_comp_round_informed',$comp,$round,$snapshot['hash']);
    cancha_comp_round_redirect($comp,$round,'informed');
});
function cancha_comp_public_round_notices($comp){
    $notes=(array)get_post_meta($comp,'_cancha_round_notices',true);if(!$notes)return '';
    krsort($notes,SORT_NUMERIC);$html='<section class="cancha-round-announcements"><h2>Resultados informados</h2>';
    $shown=0;
    foreach($notes as $round=>$info){
        if($shown>=5)break;
        $snap=cancha_comp_round_snapshot($comp,(int)$round);
        if($snap['hash']!==($info['hash']??''))continue; // Una corrección sin republicar invalida el aviso anterior.
        $html.='<div class="module-empty"><strong>Jornada '.absint($round).'</strong><p>'.absint($info['final']??0).' de '.absint($info['total']??0).' partidos comunicados · '.esc_html(substr((string)($info['at']??''),0,10)).'</p></div>';
        $shown++;
    }
    return $html.'</section>';
}
add_action('cancha_render_campeonato_detalle',function($id){echo cancha_comp_public_round_notices(absint($id));},20);
