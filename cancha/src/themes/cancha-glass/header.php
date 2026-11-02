<?php defined('ABSPATH') || exit; ?>
<!doctype html><html <?php language_attributes(); ?>><head>
<meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){try{var t=localStorage.getItem('cancha-theme');}catch(e){}var d=<?php echo wp_json_encode(cancha_ui_setting('default_color_mode','light')==='dark'?'dark':'light'); ?>;document.documentElement.dataset.theme=(t==='light'||t==='dark')?t:d;})();</script>
<?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?>
<a class="skip-link" href="#contenido">Saltar al contenido</a>
<div class="site-wrap">
<header class="site-header cancha-ligas-header">
 <div class="header-main">
   <a class="brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="CANCHA · Inicio">
    <?php if (has_custom_logo()) echo wp_get_attachment_image((int)get_theme_mod('custom_logo'),'medium',false,['class'=>'brand-logo','alt'=>'CANCHA']);
    else { ?><span class="brand-symbol" aria-hidden="true">◢</span><span>CANCHA</span><?php } ?>
   </a>
   <?php if(cancha_ui_setting('header_search',1)): ?>
   <form class="ligas-header-search" method="get" action="<?php echo esc_url(home_url('/')); ?>" role="search">
     <label class="screen-reader-text" for="cancha-global-search">Buscar campeonatos, clubes y jugadores</label>
     <span aria-hidden="true" class="ligas-search-icon">⌕</span><input id="cancha-global-search" name="s" type="search" placeholder="Buscar campeonatos..." value="<?php echo esc_attr(get_search_query()); ?>" autocomplete="off">
   </form><?php endif; ?>
   <div class="header-actions">
    <a class="ligas-account" href="<?php echo esc_url(is_user_logged_in()?cancha_route('mi-panel'):cancha_auth_url('ingresar')); ?>" aria-label="<?php echo is_user_logged_in()?'Mi cuenta':'Ingresar o registrarme'; ?>"><?php echo is_user_logged_in()?'Mi cuenta':'Ingresar'; ?></a>
    <button class="icon-button cancha-theme-visible" id="theme-toggle" type="button" aria-label="Activar modo oscuro" aria-pressed="false"><span class="cancha-theme-icon" aria-hidden="true">☾</span><span class="cancha-theme-word">Oscuro</span></button>
    <button class="nav-toggle ligas-menu-button" type="button" aria-expanded="false" aria-controls="site-nav" aria-label="Abrir menú">☰</button>
   </div>
 </div>
 <nav class="site-nav" id="site-nav" aria-label="Menú de CANCHA"><div class="cancha-menu-title"><strong>Menú de CANCHA</strong><button type="button" class="cancha-menu-close" aria-label="Cerrar menú">×</button></div><div class="cancha-menu-tools"><a href="<?php echo esc_url(is_user_logged_in()?cancha_route('mi-panel'):cancha_auth_url('ingresar')); ?>">👤 <?php echo is_user_logged_in()?'Mi cuenta':'Ingresar / Registrarse'; ?></a><button class="cancha-menu-theme" type="button">◐ Cambiar apariencia</button></div>
 <?php if (has_nav_menu('primary')) wp_nav_menu(['theme_location'=>'primary','container'=>false,'menu_class'=>'nav-items','depth'=>1]);
 else { ?><ul class="nav-items">
 <?php foreach (['/'=>'Inicio','partidos'=>'Partidos profesionales','futbol-oficial'=>'Tablas y ligas profesionales','amateur'=>'Fútbol amateur','predicciones'=>'Predicciones','novedades'=>'Novedades','ranking'=>'Ranking','mi-panel'=>'Mi panel','buscar'=>'Buscar'] as $slug=>$label) { ?>
 <li><a href="<?php echo esc_url($slug==='/'?home_url('/'):cancha_route($slug)); ?>"><?php echo esc_html($label); ?></a></li><?php } ?>
 </ul><?php } ?>
 </nav>
</header><main class="site-main" id="contenido">
