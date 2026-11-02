(() => {
  'use strict';
  const root = document.documentElement;
  const themeButton = document.getElementById('theme-toggle');
  const navToggle = document.querySelector('.nav-toggle');
  const nav = document.getElementById('site-nav');
  const menuBottom = document.querySelector('.cancha-more-bottom');
  const menuClose = document.querySelector('.cancha-menu-close');
  const menuTheme = document.querySelector('.cancha-menu-theme');
  let saved = null;
  try { saved = localStorage.getItem('cancha-theme'); } catch (_e) {}
  const config = window.canchaUI || {};
  const initial = saved==='light'||saved==='dark' ? saved : (config.defaultTheme==='dark'?'dark':'light');
  function setTheme(theme) {
    root.dataset.theme = theme;
    themeButton?.setAttribute('aria-pressed',String(theme==='dark'));
    themeButton?.setAttribute('aria-label',theme==='dark'?'Activar modo claro':'Activar modo oscuro');
    const icon = themeButton?.querySelector('.cancha-theme-icon');if(icon)icon.textContent=theme==='dark'?'☼':'☾';
    const word = themeButton?.querySelector('.cancha-theme-word');if(word)word.textContent=theme==='dark'?'Claro':'Oscuro';
  }
  setTheme(initial);
  const toggleTheme = () => {
    const next=root.dataset.theme==='dark'?'light':'dark';setTheme(next);
    try { localStorage.setItem('cancha-theme',next); } catch (_e) {}
  };
  themeButton?.addEventListener('click',toggleTheme);
  menuTheme?.addEventListener('click',toggleTheme);
  function setMenu(open){
    nav?.classList.toggle('open',open);
    navToggle?.setAttribute('aria-expanded',String(open));
    menuBottom?.setAttribute('aria-expanded',String(open));
    navToggle?.setAttribute('aria-label',open?'Cerrar menú':'Abrir menú');
  }
  navToggle?.addEventListener('click',()=>setMenu(!nav?.classList.contains('open')));
  menuBottom?.addEventListener('click',()=>setMenu(!nav?.classList.contains('open')));
  menuClose?.addEventListener('click',()=>setMenu(false));
  document.addEventListener('click',e=>{if(nav?.classList.contains('open') && !nav.contains(e.target) && !navToggle?.contains(e.target) && !menuBottom?.contains(e.target))setMenu(false);});
  document.addEventListener('keydown',e=>{if(e.key==='Escape' && nav?.classList.contains('open')){setMenu(false);navToggle?.focus();}});
  document.querySelectorAll('.cancha-league-toggle').forEach(button=>button.addEventListener('click',()=>{
    const league=button.closest('.cancha-fixture-league');if(!league)return;
    const collapsed=league.classList.toggle('is-collapsed');
    button.setAttribute('aria-expanded',String(!collapsed));
    button.setAttribute('aria-label',collapsed?'Expandir liga':'Contraer liga');
    button.textContent=collapsed?'⌄':'⌃';
  }));
  const dateScroll=document.querySelector('.cancha-day-scroll');
  const selectedDay=dateScroll?.querySelector('.cancha-day-item.is-selected');
  if(dateScroll && selectedDay){
    const left=selectedDay.offsetLeft-dateScroll.offsetLeft-(dateScroll.clientWidth-selectedDay.clientWidth)/2;
    dateScroll.scrollLeft=Math.max(0,left);
  }
  const app = window.canchaUI || {};
  if (!('serviceWorker' in navigator)) return;
  if (!app.pwa) {
    navigator.serviceWorker.getRegistrations().then(registrations => registrations.forEach(reg => {
      if (reg.active && reg.active.scriptURL === app.sw) reg.unregister().catch(() => {});
    })).catch(() => {});
    return;
  }
  if (location.protocol !== 'https:' && !['localhost','127.0.0.1'].includes(location.hostname)) return;
  window.addEventListener('load', () => navigator.serviceWorker.register(app.sw, { scope: app.scope || '/' }).catch(() => {}));
  const strip = document.getElementById('cancha-install');
  const installButton = document.getElementById('cancha-install-button');
  if (!strip || !installButton || window.matchMedia('(display-mode: standalone)').matches) return;
  let deferred = null;
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault(); deferred = event; strip.hidden = false;
  });
  installButton.addEventListener('click', async () => {
    if (!deferred) return;
    try { await deferred.prompt(); } catch (_e) {}
    deferred = null;
    strip.hidden = true;
  });
  const ios = /iPhone|iPad|iPod/i.test(navigator.userAgent);
  if (ios && !('standalone' in navigator && navigator.standalone)) {
    strip.hidden = false; installButton.textContent = 'Cómo instalar';
    installButton.addEventListener('click', () => { const p = strip.querySelector('p'); if(p) p.textContent = app.installText || 'En Safari, tocá Compartir y Agregar a inicio.'; });
  }
})();
