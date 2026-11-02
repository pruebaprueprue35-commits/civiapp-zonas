(() => {
  'use strict';
  const layout = document.querySelector('.ligas-layout');
  if (!layout) return;
  const filterButtons = [...layout.querySelectorAll('[data-ligas-filter]')];
  const sections = [...layout.querySelectorAll('[data-ligas-section]')];
  const cards = [...layout.querySelectorAll('[data-cancha-fav]')];
  let favs = [];
  try { favs = JSON.parse(localStorage.getItem('cancha_ligas_favorites') || '[]'); if (!Array.isArray(favs)) favs=[]; } catch (_) { favs=[]; }
  const store = () => { try { localStorage.setItem('cancha_ligas_favorites', JSON.stringify(favs)); } catch (_) {} };
  const paint = () => {
    cards.forEach(card => {
      const id = card.dataset.canchaFav;
      const btn = card.querySelector('[data-favorite-id]');
      const isFav = favs.includes(id);
      if (btn) { btn.setAttribute('aria-pressed',String(isFav)); btn.textContent = isFav ? '★' : '☆'; }
    });
  };
  paint();
  layout.addEventListener('click', event => {
    const target = event.target.closest('[data-favorite-id]');
    if (!target) return;
    event.preventDefault(); event.stopPropagation();
    const id = target.dataset.favoriteId;
    if (!id) return;
    favs = favs.includes(id) ? favs.filter(x=>x!==id) : [...favs,id];
    store(); paint();
    const current=filterButtons.find(b=>b.classList.contains('is-active'))?.dataset.ligasFilter || 'todos';
    if(current==='favoritos') changeFilter(current);
  });
  function changeFilter(f) {
    filterButtons.forEach(b=>{const active=b.dataset.ligasFilter===f;b.classList.toggle('is-active',active);b.setAttribute('aria-pressed',String(active));});
    sections.forEach(sec=>{
      const kind=sec.dataset.ligasSection;
      const visible=f==='todos'||f==='favoritos'||f===kind;
      sec.hidden=!visible;
      sec.querySelectorAll('[data-cancha-fav]').forEach(card=>{card.hidden=f==='favoritos' && !favs.includes(card.dataset.canchaFav);});
      let empty=sec.querySelector('.ligas-no-favs');
      if(!empty && f==='favoritos'){empty=document.createElement('p');empty.className='ligas-no-favs';empty.textContent='No hay favoritos en esta sección. Tocá una estrella para guardar uno.';sec.append(empty);}
      if(empty)empty.hidden=f!=='favoritos'||[...sec.querySelectorAll('[data-cancha-fav]')].some(card=>!card.hidden);
    });
  }
  filterButtons.forEach(btn=>btn.addEventListener('click',()=>changeFilter(btn.dataset.ligasFilter)));
  const track=document.getElementById('ligas-sponsor-track');
  layout.querySelectorAll('.ligas-sponsor-arrow').forEach(btn=>btn.addEventListener('click',()=>{
    if(!track) return;
    const delta=track.getBoundingClientRect().width*.7*(btn.classList.contains('next')?1:-1);
    track.scrollBy({left:delta,behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
  }));
  const dismiss=layout.querySelector('.ligas-dismiss');
  const mainAd=layout.querySelector('.ligas-featured-ad');
  if(mainAd && dismiss){
    let hidden=false;try{hidden=sessionStorage.getItem('cancha_ad_dismissed')==='1';}catch(_){}
    if(hidden)mainAd.hidden=true;
    dismiss.addEventListener('click',()=>{mainAd.hidden=true;try{sessionStorage.setItem('cancha_ad_dismissed','1');}catch(_){}});
  }
})();
