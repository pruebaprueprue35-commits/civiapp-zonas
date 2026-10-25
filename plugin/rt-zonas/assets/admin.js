/* global RTZ, L */
(function () {
  'use strict';

  // Avance de la importación mientras la pantalla está abierta (no depende del tráfico del sitio).
  var box = document.getElementById('rtz-progreso');
  if (box && (box.dataset.estado === 'pendiente' || box.dataset.estado === 'importando')) {
    var bar = box.querySelector('.rtz-bar span');
    var text = box.querySelector('.rtz-prog-text');
    var err = box.querySelector('.rtz-prog-error');
    var fails = 0;
    var step = function () {
      var body = new URLSearchParams({ action: 'rtz_avanzar', nonce: RTZ.nonce });
      fetch(RTZ.ajax, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res || !res.success) { throw new Error('respuesta'); }
          var p = res.data;
          fails = 0;
          bar.style.width = p.porcentaje + '%';
          text.textContent = p.estado === 'importando' || p.estado === 'pendiente'
            ? 'Importando versión ' + p.version + ': ' + p.hechas + ' de ' + p.partes + ' partes (' + p.porcentaje + '%), ' + p.zonas + ' zonas.'
            : (p.estado === 'terminado' ? 'Listo: ' + p.municipios + ' municipios y ' + p.zonas + ' zonas cargadas.' : 'Terminó con errores. Podés reintentar.');
          err.textContent = p.error || '';
          if (p.estado === 'importando' || p.estado === 'pendiente') { setTimeout(step, 1500); } else { setTimeout(function () { location.reload(); }, 1500); }
        })
        .catch(function () {
          fails++;
          err.textContent = 'Se cortó la conexión; reintentando…';
          if (fails < 20) { setTimeout(step, 5000); }
        });
    };
    setTimeout(step, 500);
  }

  // Mapa de revisión: con mapa base (Leaflet + OpenStreetMap) si carga; si no, dibujo propio en SVG.
  var mapEl = document.getElementById('rtz-mapa');
  if (mapEl) {
    fetch(RTZ.rest + (RTZ.rest.indexOf('?') >= 0 ? '&' : '?') + 'municipio=' + encodeURIComponent(mapEl.dataset.municipio), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (fc) {
        if (typeof L !== 'undefined') { drawLeaflet(mapEl, fc); } else { drawSvg(mapEl, fc); }
      })
      .catch(function () { mapEl.textContent = 'No se pudieron cargar las zonas.'; });
  }

  function label(p) {
    return p.codigo + ' · ' + p.nombre + ' — ' + (p.poblacion === null ? 'población sin dato' : p.poblacion.toLocaleString('es-AR') + ' hab.');
  }

  function drawLeaflet(el, fc) {
    var map = L.map(el);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© colaboradores de OpenStreetMap' }).addTo(map);
    var layer = L.geoJSON(fc, {
      style: function (f) {
        var flagged = f.properties.marcas && f.properties.marcas.length;
        return { color: flagged ? '#d97706' : '#2563eb', weight: 2, fillOpacity: flagged ? 0.25 : 0.12 };
      },
      onEachFeature: function (f, l) {
        var t = document.createElement('div');
        t.textContent = label(f.properties);
        l.bindPopup(t);
      }
    }).addTo(map);
    var b = layer.getBounds();
    if (b.isValid()) { map.fitBounds(b, { padding: [12, 12] }); } else { map.setView([-34.6, -64], 4); }
  }

  function drawSvg(el, fc) {
    var NS = 'http://www.w3.org/2000/svg';
    var minx = Infinity, miny = Infinity, maxx = -Infinity, maxy = -Infinity;
    var polys = function (g) { return !g ? [] : (g.type === 'Polygon' ? [g.coordinates] : (g.type === 'MultiPolygon' ? g.coordinates : [])); };
    fc.features.forEach(function (f) {
      polys(f.geometry).forEach(function (rings) {
        rings[0].forEach(function (p) { minx = Math.min(minx, p[0]); maxx = Math.max(maxx, p[0]); miny = Math.min(miny, p[1]); maxy = Math.max(maxy, p[1]); });
      });
    });
    el.textContent = '';
    if (!isFinite(minx)) { el.textContent = 'Este municipio no tiene zonas dibujables.'; return; }
    var k = Math.cos((miny + maxy) / 2 * Math.PI / 180);
    var w = (maxx - minx) * k || 1, h = (maxy - miny) || 1;
    var W = 1000, H = Math.max(200, Math.round(W * h / w));
    var sx = function (x) { return 10 + (x - minx) * k / w * (W - 20); };
    var sy = function (y) { return 10 + (maxy - y) / h * (H - 20); };
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Zonas del municipio');
    svg.style.width = '100%'; svg.style.height = '100%';
    fc.features.forEach(function (f) {
      var flagged = f.properties.marcas && f.properties.marcas.length;
      var d = '';
      polys(f.geometry).forEach(function (rings) {
        rings.forEach(function (r) { d += r.map(function (p, i) { return (i ? 'L' : 'M') + sx(p[0]).toFixed(1) + ' ' + sy(p[1]).toFixed(1); }).join('') + 'Z'; });
      });
      var path = document.createElementNS(NS, 'path');
      path.setAttribute('d', d);
      path.setAttribute('fill-rule', 'evenodd');
      path.setAttribute('fill', flagged ? 'rgba(217,119,6,.25)' : 'rgba(37,99,235,.12)');
      path.setAttribute('stroke', flagged ? '#d97706' : '#2563eb');
      path.setAttribute('stroke-width', '2');
      var title = document.createElementNS(NS, 'title');
      title.textContent = label(f.properties);
      path.appendChild(title);
      svg.appendChild(path);
      var c = f.properties.centro;
      var t = document.createElementNS(NS, 'text');
      t.setAttribute('x', sx(c.lng)); t.setAttribute('y', sy(c.lat));
      t.setAttribute('text-anchor', 'middle'); t.setAttribute('font-size', '18'); t.setAttribute('fill', '#1d2327');
      t.textContent = f.properties.codigo;
      svg.appendChild(t);
    });
    el.appendChild(svg);
    var note = document.createElement('p');
    note.className = 'description';
    note.textContent = 'Sin mapa de fondo (no se pudo conectar con OpenStreetMap). Tocá una zona para ver su nombre.';
    el.parentNode.insertBefore(note, el.nextSibling);
  }
})();
