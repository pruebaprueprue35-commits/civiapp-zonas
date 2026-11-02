<?php get_header();while(have_posts()):the_post(); ?>
<article <?php post_class('glass content-panel'); ?>>
<?php echo function_exists('cancha_player_profile_html') ? cancha_player_profile_html(get_the_ID()) : '<h1>'.esc_html(get_the_title()).'</h1>'; ?>
<?php if(trim(get_the_content())): ?><section class="cancha-profile-section"><h2>Trayectoria y biografía</h2><div class="entry-content"><?php the_content(); ?></div></section><?php endif; ?></article>
<?php if(has_action('cancha_ads_player'))do_action('cancha_ads_player'); ?>
<?php endwhile;get_footer(); ?>
