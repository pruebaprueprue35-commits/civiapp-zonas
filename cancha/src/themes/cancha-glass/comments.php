<?php defined('ABSPATH') || exit;if(post_password_required())return; ?>
<section id="comentarios" class="glass cancha-comments"><h2>Conversación <span class="comment-number">(<?php echo absint(get_comments_number()); ?>)</span></h2>
<?php if(have_comments()): ?><ol class="comment-list"><?php wp_list_comments(['style'=>'ol','short_ping'=>true,'avatar_size'=>44]); ?></ol><?php the_comments_pagination(); ?><?php endif; ?>
<?php if(is_user_logged_in() && comments_open()): ?><?php comment_form(['title_reply'=>'Dejá tu comentario','label_submit'=>'Publicar comentario','comment_notes_after'=>'']); ?>
<?php elseif(!is_user_logged_in()): ?><p>Para comentar, ingresá a tu cuenta de CANCHA.</p><a class="button primary" href="<?php echo esc_url(cancha_auth_url('ingresar')); ?>">Ingresar para comentar</a> <a class="button ghost" href="<?php echo esc_url(cancha_auth_url('registro')); ?>">Registrarme</a>
<?php else: ?><p>Los comentarios están cerrados para esta noticia.</p><?php endif; ?></section>
