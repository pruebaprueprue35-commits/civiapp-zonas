<?php
/** Campeonato amateur: experiencia propia (torneo · zonas · jornadas · eliminatorias · equipos · jugadores). */
get_header(); while(have_posts()):the_post(); $scope='torneo:'.get_post_field('post_name',get_the_ID()); ?>
<article <?php post_class('cancha-amateur-tournament'); ?> data-origin="amateur">
<?php if (function_exists('cancha_amateur_tournament_html')) { echo cancha_amateur_tournament_html(get_the_ID()); } else { ?>
<div class="glass content-panel"><div class="eyebrow">CAMPEONATO AMATEUR</div><h1><?php the_title(); ?></h1><div class="entry-content"><?php the_content(); ?></div>
<?php if (has_action('cancha_render_campeonato_detalle')) do_action('cancha_render_campeonato_detalle',get_the_ID()); ?></div>
<?php } ?>
<?php $sponsors = cancha_sponsors_html($scope,12); if ($sponsors): ?><section class="glass championship-sponsors"><div class="eyebrow">NOS ACOMPAÑAN</div><h2>Sponsors del campeonato</h2><?php echo $sponsors; // Escaped within renderer. ?></section><?php endif; ?>
</article><?php endwhile; get_footer(); ?>
