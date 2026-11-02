<?php
/** Minimal installable webapp. Never cache authentication or personal information. */
defined('ABSPATH') || exit;
function cancha_pwa_enabled() { return !empty(cancha_core_settings()['pwa_enabled']); }
add_action('init', function () {
    add_rewrite_rule('^cancha\.webmanifest$', 'index.php?cancha_pwa=manifest', 'top');
    add_rewrite_rule('^cancha-sw\.js$', 'index.php?cancha_pwa=sw', 'top');
});
add_filter('query_vars', function ($vars) { $vars[] = 'cancha_pwa'; return $vars; });
add_action('wp_head', function () {
    if (!cancha_pwa_enabled()) return;
    echo '<link rel="manifest" href="' . esc_url(home_url('/cancha.webmanifest')) . '">';
    echo '<meta name="theme-color" content="#071d25">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-title" content="CANCHA">';
    echo '<link rel="apple-touch-icon" href="' . esc_url(get_template_directory_uri() . '/assets/img/icon-192.png') . '">';
});
add_action('template_redirect', function () {
    $type = get_query_var('cancha_pwa');
    if (!$type) return;
    if (!cancha_pwa_enabled()) { status_header(404); exit; }
    if ($type === 'manifest') {
        header('Content-Type: application/manifest+json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        echo wp_json_encode([
          'name'=>'CANCHA - Tu fútbol', 'short_name'=>'CANCHA', 'description'=>'Campeonatos y comunidad de fútbol.',
          'id'=>home_url('/'), 'start_url'=>home_url('/'), 'scope'=>home_url('/'), 'display'=>'standalone',
          'background_color'=>'#061b23', 'theme_color'=>'#071d25', 'lang'=>'es-AR',
          'icons'=>[
             ['src'=>get_template_directory_uri().'/assets/img/icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any maskable'],
             ['src'=>get_template_directory_uri().'/assets/img/icon-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable'],
          ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($type === 'sw') {
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Service-Worker-Allowed: ' . (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/'));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache, must-revalidate');
        // Embed only public, static resources. Never cache pages, auth, API, cookies, results or requests with query strings.
        $assets = [
            get_template_directory_uri() . '/assets/css/theme.css',
            get_template_directory_uri() . '/assets/js/theme.js',
            get_template_directory_uri() . '/assets/img/icon-192.png',
            get_template_directory_uri() . '/assets/img/icon-512.png',
        ];
        $json = wp_json_encode($assets);
        echo 'const CACHE="cancha-static-' . esc_js(CANCHA_VERSION) . '";const ASSETS=' . $json . ';'; ?>
self.addEventListener('install', event => { event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(ASSETS)).then(() => self.skipWaiting())); });
self.addEventListener('activate', event => { event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith('cancha-static-') && k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', event => {
 const req = event.request;
 if (req.method !== 'GET' || new URL(req.url).origin !== self.location.origin) return;
 const url = new URL(req.url);
 if (req.mode === 'navigate') {
   event.respondWith(fetch(req).catch(() => new Response('<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CANCHA · Sin conexión</title><body style="font-family:system-ui;background:#061b23;color:white;padding:3rem"><h1>Estás sin conexión</h1><p>Volvé a conectarte para consultar resultados y predicciones.</p></body></html>', {headers:{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'}})));
   return;
 }
 if (url.search || !ASSETS.includes(url.href)) return;
 event.respondWith(caches.match(req).then(cached => cached || fetch(req)));
});
<?php exit;
    }
    status_header(404); exit;
}, 0);
