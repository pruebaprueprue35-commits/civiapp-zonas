<?php get_header();$keyword=get_search_query(); ?>
<section class="glass content-panel cancha-search-results"><div class="eyebrow">DIRECTORIO CANCHA</div><h1>Buscar jugadores, equipos y torneos</h1><?php get_search_form(); ?>
<?php if(has_action('cancha_ads_search'))do_action('cancha_ads_search'); ?>
<?php if($keyword): ?><p class="muted">Resultados para <strong><?php echo esc_html($keyword); ?></strong></p>
<div class="cancha-search-list"><?php if(have_posts()):while(have_posts()):the_post();
$type=get_post_type();$type_name=['post'=>'Novedad','cancha_campeonato'=>'Campeonato','cancha_equipo'=>'Equipo','cancha_jugador'=>'Jugador','cancha_staff'=>'Directiva / cuerpo técnico'][$type]??'Contenido'; ?>
<a class="glass search-result" href="<?php the_permalink(); ?>">
<?php if(has_post_thumbnail())the_post_thumbnail('thumbnail',['loading'=>'lazy']);else echo '<span class="search-emblem">⚽</span>'; ?>
<span><small class="eyebrow"><?php echo esc_html($type_name); ?></small><strong><?php the_title(); ?></strong></span><span aria-hidden="true">→</span></a>
<?php endwhile;the_posts_pagination();else: ?><div class="module-empty">No encontramos coincidencias. Probá con otro nombre o parte del apellido.</div><?php endif; ?></div>
<?php endif; ?></section><?php get_footer(); ?>
