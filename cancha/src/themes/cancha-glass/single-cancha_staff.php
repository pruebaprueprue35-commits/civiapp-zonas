<?php get_header();while(have_posts()):the_post(); ?>
<article <?php post_class('glass content-panel'); ?>>
<?php echo function_exists('cancha_staff_profile_html') ? cancha_staff_profile_html(get_the_ID()) : '<h1>'.esc_html(get_the_title()).'</h1>'; ?>
<?php if(trim(get_the_content())): ?><section class="cancha-profile-section"><div class="entry-content"><?php the_content(); ?></div></section><?php endif; ?></article>
<?php endwhile;get_footer(); ?>
