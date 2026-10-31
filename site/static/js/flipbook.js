// Sfogliatore dei cataloghi: doppia pagina (copertina sola) su schermo largo, pagina singola su telefono. Script
// classico senza dipendenze. Senza JS resta l'elenco verticale delle pagine. Tastiera: frecce, Home/Fine; swipe su touch.
(function () {
  'use strict';
  var box = document.querySelector('[data-flip]');
  if (!box) return;
  var n = +box.getAttribute('data-n'), base = box.getAttribute('data-base');
  var L = function (k) { return box.getAttribute('data-l-' + k) || ''; };
  var alts = [].map.call(box.querySelectorAll('.fb-list img'), function (i) { return i.alt; });
  var wide = window.matchMedia ? window.matchMedia('(min-width: 900px)') : { matches: false };
  var pad = function (x) { return ('00' + x).slice(-3); };
  var cur = 1; // prima pagina visibile (1-based)
  var el = function (tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html) e.innerHTML = html; return e; };

  var stage = el('div', 'fb-stage'), prev = el('button', 'fb-btn fb-nav prev', '<span aria-hidden="true">&#8249;</span>'),
      next = el('button', 'fb-btn fb-nav next', '<span aria-hidden="true">&#8250;</span>');
  prev.type = next.type = 'button';
  prev.setAttribute('aria-label', L('prev')); next.setAttribute('aria-label', L('next'));
  var spread = el('div', 'fb-spread');
  stage.appendChild(prev); stage.appendChild(spread); stage.appendChild(next);

  var bar = el('div', 'fb-bar'), pos = el('span', 'fb-pos'), go = el('label', 'fb-go'), inp = el('input'),
      thBtn = el('button', 'fb-btn', L('thumbs')), fsBtn = el('button', 'fb-btn', L('full'));
  pos.setAttribute('aria-live', 'polite');
  inp.type = 'number'; inp.min = 1; inp.max = n; inp.inputMode = 'numeric';
  go.appendChild(document.createTextNode(L('go') + ' ')); go.appendChild(inp);
  thBtn.type = fsBtn.type = 'button'; thBtn.setAttribute('aria-expanded', 'false');
  bar.appendChild(pos); bar.appendChild(go); bar.appendChild(thBtn);
  if (box.requestFullscreen) bar.appendChild(fsBtn);

  var th = el('div', 'fb-th'); th.setAttribute('role', 'group'); th.setAttribute('aria-label', L('thumbs'));
  var thumbs = [];
  for (var i = 1; i <= n; i++) (function (p) {
    var b = el('button'); b.type = 'button'; b.setAttribute('aria-label', L('page') + ' ' + p);
    var im = el('img'); im.loading = 'lazy'; im.alt = ''; im.width = 72; im.height = 102; im.src = base + pad(p) + '-t.webp';
    b.appendChild(im); b.addEventListener('click', function () { go_to(p, 0); }); th.appendChild(b); thumbs.push(b);
  }(i));

  box.appendChild(stage); box.appendChild(bar); box.appendChild(th); box.classList.add('is-on');

  // Con doppia pagina la copertina e' sola, poi coppie (2-3, 4-5...): la pagina "di sinistra" e' sempre pari.
  function first(p) { return wide.matches ? (p <= 1 ? 1 : p - (p % 2)) : p; }
  function size() { return wide.matches && cur > 1 && cur < n ? 2 : 1; }
  function render(dx) {
    cur = first(Math.min(Math.max(cur, 1), n));
    var two = size() === 2, shown = two ? [cur, cur + 1] : [cur];
    var s = spread.cloneNode(false); s.className = 'fb-spread' + (two ? ' is-two' : ''); s.style.setProperty('--dx', (dx || 0) + 'px');
    shown.forEach(function (p) { var im = el('img'); im.src = base + pad(p) + '.webp'; im.alt = alts[p - 1] || ''; im.decoding = 'async'; s.appendChild(im); });
    spread.parentNode.replaceChild(s, spread); spread = s;
    pos.textContent = L('pages') + ' ' + shown.join('–') + ' ' + L('of') + ' ' + n;
    inp.value = ''; inp.placeholder = String(shown[0]);
    prev.disabled = cur <= 1; next.disabled = cur + size() > n;
    thumbs.forEach(function (b, j) { if (shown.indexOf(j + 1) >= 0) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current'); });
    [cur - 2, cur + size()].forEach(function (p) { if (p >= 1 && p <= n) (new Image()).src = base + pad(p) + '.webp'; }); // pagine vicine in anticipo
  }
  function go_to(p, dx) { cur = p; render(dx); }
  function step(dir) { var t = dir > 0 ? cur + size() : first(cur - 1); if (t >= 1 && t <= n) go_to(t, dir * 30); }
  prev.addEventListener('click', function () { step(-1); });
  next.addEventListener('click', function () { step(1); });
  inp.addEventListener('change', function () { var v = parseInt(inp.value, 10); if (v >= 1 && v <= n) go_to(v, 0); });
  thBtn.addEventListener('click', function () { var on = box.classList.toggle('show-th'); thBtn.setAttribute('aria-expanded', on ? 'true' : 'false'); });
  fsBtn.addEventListener('click', function () { if (document.fullscreenElement) document.exitFullscreen(); else box.requestFullscreen(); });
  box.addEventListener('keydown', function (e) {
    if (e.target === inp) return;
    if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
    else if (e.key === 'Home') { e.preventDefault(); go_to(1, 0); }
    else if (e.key === 'End') { e.preventDefault(); go_to(n, 0); }
  });
  var x0 = null;
  stage.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  stage.addEventListener('touchend', function (e) {
    if (x0 === null) return; var dx = e.changedTouches[0].clientX - x0; x0 = null;
    if (Math.abs(dx) > 50) step(dx < 0 ? 1 : -1);
  });
  if (wide.addEventListener) wide.addEventListener('change', function () { render(0); });
  render(0);
}());
