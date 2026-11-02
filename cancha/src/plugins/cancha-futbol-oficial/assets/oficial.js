(() => {
  // Connect CANCHA Glass league cards to their native competition detail view.
  // Do not replace theme markup or intercept its click handlers.
  const connected = window.canchaOfficialConnected || {};
  for (const [key, active] of Object.entries(connected)) {
    const card = document.querySelector('[data-cancha-fav="official:' + key + '"]');
    if (!card) continue;
    const link = card.querySelector('a.ligas-official-link');
    if (link) {
      try {
        const href = new URL(link.href);
        href.hash = '';
        href.searchParams.set('cof_comp', key);
        href.searchParams.set('cof_view', 'partidos');
        link.href = href.toString();
      } catch (_) { /* Leave the original WordPress link. */ }
    }
    const status = card.querySelector('.ligas-status');
    if (!status || !active) continue;
    status.textContent = '● Fuente configurada';
    status.classList.remove('unconnected');
    status.classList.add('connected');
  }
})();
