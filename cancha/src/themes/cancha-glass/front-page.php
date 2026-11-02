<?php defined('ABSPATH') || exit; get_header(); ?>
<div class="cancha-home-v14">
 <?php cancha_day_navigation(cancha_day_selected(),true); ?>
 <?php cancha_ligas_main_banner(); cancha_ligas_sponsor_carousel(); ?>
 <?php if(isset($_GET['pred_oficial']) && sanitize_key(wp_unslash($_GET['pred_oficial']))==='guardado'): ?><div class="cancha-alert-ok" role="status">Tu pronóstico quedó guardado correctamente.</div><?php endif; ?>
 <?php cancha_matchday_render(null,false); ?>
 <div class="cancha-home-shortcuts"><a href="<?php echo esc_url(cancha_route('amateur')); ?>">🏆 Fútbol amateur</a><a href="<?php echo esc_url(cancha_route('predicciones')); ?>">🎯 Todas las predicciones</a><a href="<?php echo esc_url(cancha_route('novedades')); ?>">📰 Novedades</a></div>
 <?php if(cancha_ui_setting('home_show_amateur',1)): ?><a class="cancha-amateur-cta" href="<?php echo esc_url(cancha_route('amateur')); ?>"><span aria-hidden="true">🏆</span><span><strong>Fútbol amateur</strong><small>Torneos, zonas, fixture por jornada, equipos, jugadores y eliminatorias</small></span><span aria-hidden="true">›</span></a><?php endif; ?>
 <?php if(has_action('cancha_ads_home_bottom')) do_action('cancha_ads_home_bottom'); ?>
 <?php if (cancha_ui_setting('show_news',1)): ?><section class="ligas-section ligas-news-section"><div class="ligas-section-head"><h2>📰 Novedades</h2><a href="<?php echo esc_url(cancha_route('novedades')); ?>">Ver todas →</a></div><?php cancha_news_cards((int)cancha_ui_setting('news_count',4)); ?></section><?php endif; ?>
 <section class="glass install-strip" id="cancha-install" hidden><div><strong>CANCHA en tu celular</strong><p>Instalá la webapp para acceder desde el inicio.</p></div><button id="cancha-install-button" class="button ghost" type="button">Instalar</button></section>
</div><?php get_footer(); ?>
