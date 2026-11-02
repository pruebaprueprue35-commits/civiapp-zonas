<?php
/** News feed uses native WP posts/comments; no duplicate tables or social network exposure. */
defined('ABSPATH') || exit;
function cancha_news_cards($count=4,$paginate=false) {
 $page=$paginate?max(1,absint(get_query_var('paged'))):1;
 $q=new WP_Query(['post_type'=>'post','post_status'=>'publish','posts_per_page'=>min(20,max(1,(int)$count)),'paged'=>$page,'ignore_sticky_posts'=>false]);
 if(!$q->have_posts()){echo '<div class="module-empty">Todavía no hay novedades publicadas. Se pueden crear desde CANCHA → Novedades.</div>';return;}
 echo '<div class="cancha-news-grid">';
 while($q->have_posts()){$q->the_post();echo '<article class="glass cancha-news-card"><a class="news-cover" href="'.esc_url(get_permalink()).'">';
 if(has_post_thumbnail())the_post_thumbnail('medium_large',['loading'=>'lazy']);else echo '<span class="news-placeholder" aria-hidden="true">📰</span>';
 echo '</a><div class="news-body"><span class="eyebrow">'.esc_html(get_the_date()).'</span><h3><a href="'.esc_url(get_permalink()).'">'.esc_html(get_the_title()).'</a></h3><p>'.esc_html(wp_trim_words(get_the_excerpt(),25)).'</p><a class="news-read" href="'.esc_url(get_permalink()).'">Leer y comentar →</a> <span class="news-count">'.absint(get_comments_number()).' comentarios</span></div></article>';
 }
 echo '</div>';wp_reset_postdata();
 if($paginate && $q->max_num_pages>1){echo '<nav class="cancha-pagination" aria-label="Más noticias">';echo paginate_links(['total'=>$q->max_num_pages,'current'=>$page,'type'=>'plain']);echo '</nav>';}
}
function cancha_news_admin() {
 if(!current_user_can('manage_options'))wp_die('Sin permisos');
 cancha_core_admin_header('Novedades y comunidad','Usamos las entradas y comentarios nativos de WordPress, con moderación integrada.');
 echo '<div class="cancha-admin-cards"><a class="cancha-admin-card" href="'.esc_url(admin_url('post-new.php')).'"><strong>Publicar novedad</strong><span>Crear noticia, imagen, texto y categorías.</span></a>';
 echo '<a class="cancha-admin-card" href="'.esc_url(admin_url('edit.php')).'"><strong>Gestionar noticias</strong><span>Editar, programar y archivar publicaciones.</span></a>';
 echo '<a class="cancha-admin-card" href="'.esc_url(admin_url('edit-comments.php')).'"><strong>Moderación</strong><span>Aprobar, responder o eliminar comentarios.</span></a>';
 echo '<a class="cancha-admin-card" href="'.esc_url(admin_url('options-discussion.php')).'"><strong>Reglas de comentarios</strong><span>Configurar avisos, moderación y filtros.</span></a></div>';
 echo '<p>Las novedades se ven en <a href="'.esc_url(cancha_route('novedades')).'">/novedades/</a>. Solo las cuentas registradas pueden comentar en entradas de CANCHA. Los comentarios requieren moderación según las opciones de WordPress.</p></div>';
}
// For blog posts, require login to leave a comment; guests can still read.
add_filter('comments_open',function($open,$id){return get_post_type($id)==='post' && !is_user_logged_in() ? false : $open;},10,2);
add_action('cancha_render_noticias',function(){cancha_news_cards(4);});
