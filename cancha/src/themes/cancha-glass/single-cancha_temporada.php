<?php get_header();while(have_posts()):the_post();$id=get_the_ID(); ?>
<article <?php post_class('glass content-panel'); ?>><div class="eyebrow">HISTORIAL DE CAMPEONES</div><h1><?php the_title(); ?></h1>
<?php if(function_exists('cancha_person_field')): ?>
<p>Edición: <?php echo esc_html(cancha_person_field($id,'season')); ?></p><p>Campeón: <?php $winner=(int)cancha_person_field($id,'champion'); echo $winner?'<a href="'.esc_url(get_permalink($winner)).'">'.esc_html(get_the_title($winner)).'</a>':'Por definir'; ?></p>
<?php endif; ?><div class="entry-content"><?php the_content(); ?></div></article>
<?php endwhile;get_footer(); ?>
