<?php
/** Visual shell; the actual match result and states belong to a separate plugin. */
get_header(); while(have_posts()):the_post(); ?>
<article <?php post_class('glass content-panel'); ?>><div class="eyebrow">DETALLE DEL PARTIDO</div><h1><?php the_title(); ?></h1>
<?php if (has_action('cancha_render_partido_detalle')) do_action('cancha_render_partido_detalle',get_the_ID()); else cancha_render_module('partido_detalle','Los datos del partido aparecerán al instalar el complemento de campeonatos.'); ?>
<div class="entry-content"><?php the_content(); ?></div></article><?php endwhile; get_footer(); ?>
