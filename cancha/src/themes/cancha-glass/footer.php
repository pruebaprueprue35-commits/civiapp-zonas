</main>
<?php $footer_sponsors=cancha_sponsors_html('footer',8); if($footer_sponsors): ?><section class="glass sponsor-banner" aria-label="Sponsors de pie de página"><?php echo $footer_sponsors; ?></section><?php endif; ?>
<?php if(has_action('cancha_ads_footer')) do_action('cancha_ads_footer'); ?>
<footer class="site-footer glass"><div class="footer-brand"><strong>CANCHA</strong><span>El fútbol que nos une.</span></div>
<div class="footer-links"><a href="<?php echo esc_url(cancha_terms_url()); ?>">Términos y condiciones</a><?php $privacy=get_privacy_policy_url(); if($privacy): ?><a href="<?php echo esc_url($privacy); ?>">Privacidad</a><?php endif; ?>
<?php if(has_nav_menu('footer')) wp_nav_menu(['theme_location'=>'footer','container'=>false,'depth'=>1]); ?></div><span>© <?php echo esc_html(wp_date('Y')); ?> CANCHA</span></footer>
<nav class="mobile-tabs cancha-bottom-tabs" aria-label="Navegación principal móvil">
<?php
$tabs=[
 ['partidos','⚽','Partidos'],['amateur','🏆','Amateur'],['predicciones','◎','Predicciones'],['novedades','▤','Novedades'],
];
$path=is_front_page()?'partidos':(is_page()?get_post_field('post_name',get_queried_object_id()):'');
if(is_singular(['cancha_campeonato','cancha_equipo','cancha_jugador','cancha_partido','cancha_staff']) || $path==='campeonatos')$path='amateur';
if(in_array($path,['calendario','futbol-oficial'],true))$path='partidos';
if(is_singular('post') || is_home())$path='novedades';
foreach($tabs as $tab){
 $url=cancha_route($tab[0]);
 $active=$path===$tab[0];
 echo '<a href="'.esc_url($url).'" '.($active?'aria-current="page"':'').'><span aria-hidden="true">'.esc_html($tab[1]).'</span><small>'.esc_html($tab[2]).'</small></a>';
}
?><button class="cancha-more-bottom" type="button" aria-controls="site-nav" aria-expanded="false"><span aria-hidden="true">☰</span><small>Más</small></button>
</nav></div>
<?php wp_footer(); ?></body></html>
