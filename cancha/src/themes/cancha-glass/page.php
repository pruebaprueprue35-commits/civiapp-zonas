<?php get_header(); while (have_posts()): the_post();
$slug = get_post_field('post_name', get_the_ID());
$modules = [
 'campeonatos'=>['CAMPEONATOS AMATEUR','Campeonatos','Explorá los torneos y sus equipos.','campeonatos'],
 'futbol-oficial'=>['FÚTBOL PROFESIONAL','Fútbol oficial','Liga Argentina, Libertadores, Sudamericana y Primera Nacional.','futbol_oficial'],
 'calendario'=>['AGENDA DEPORTIVA','Calendario y partidos','Seguí los próximos encuentros y resultados verificados.','calendario'],
 'predicciones'=>['PARTICIPÁ','Predicciones','Pronosticá resultados por puntos, sin apuestas ni dinero.','predicciones'],
 'ranking'=>['COMUNIDAD','Ranking','Conocé las posiciones y tu progreso.','ranking'],
];
if ($slug === 'campeonatos') { cancha_ligas_catalog(false); }
elseif ($slug === 'partidos' || $slug === 'calendario') { echo '<div class="cancha-page-banner">'; cancha_ligas_main_banner(); echo '</div>'; cancha_matchday_render(); }
elseif ($slug === 'amateur') { if (has_action('cancha_render_amateur')) do_action('cancha_render_amateur'); else echo '<section class="glass content-panel"><h1>Fútbol amateur</h1><div class="module-empty">Activá CANCHA Campeonatos.</div></section>'; }
elseif ($slug === 'predicciones') { echo '<section class="cancha-pred-page" data-origin="predicciones">'; cancha_render_module('predicciones'); echo '</section>'; }
elseif ($slug === 'buscar') { echo '<section class="glass content-panel"><h1>Buscar en CANCHA</h1>'.get_search_form(false).'<p class="muted">Encontrá jugadores, equipos, campeonatos y noticias.</p></section>'; }
elseif ($slug === 'novedades') { echo '<section class="glass content-panel"><h1>Novedades de CANCHA</h1><p class="muted">Noticias, actualizaciones y conversaciones de nuestra comunidad.</p>'; cancha_news_cards(12, true); if(has_action('cancha_ads_news_feed'))do_action('cancha_ads_news_feed'); echo '</section>'; }
elseif ($slug === 'mi-panel') { echo cancha_my_panel_html(); }
elseif (isset($modules[$slug])) { $module = $modules[$slug]; ?>
<section class="glass content-panel module-page module-<?php echo esc_attr($slug); ?>"><?php if ($slug !== 'futbol-oficial'): ?><div class="eyebrow"><?php echo esc_html($module[0]); ?></div><h1><?php echo esc_html($module[1]); ?></h1><p class="muted"><?php echo esc_html($module[2]); ?></p><?php endif; ?>
<?php if ($slug === 'predicciones' && !is_user_logged_in()): ?><div class="module-empty"><p>Para realizar predicciones será necesario iniciar sesión.</p><a class="button primary" href="<?php echo esc_url(add_query_arg('redirect_to', cancha_route('predicciones'), cancha_auth_url('ingresar'))); ?>">Ingresar o registrarme</a></div>
<?php elseif ($slug === 'predicciones' && !cancha_current_user_can_predict()): ?>
<div class="module-empty"><p>Para participar, verificá tu cuenta y aceptá los términos vigentes.</p><?php echo cancha_terms_accept_form(); ?><a href="<?php echo esc_url(cancha_route('mi-panel')); ?>">Ir a Mi panel</a></div>
<?php else: ?><?php if (trim(get_the_content())) the_content(); else cancha_render_module($module[3]); ?><?php endif; ?>
</section>
<?php } else { ?>
<article <?php post_class('glass content-panel'); ?>><h1><?php the_title(); ?></h1><div class="entry-content"><?php the_content(); ?></div></article>
<?php } endwhile; get_footer(); ?>
