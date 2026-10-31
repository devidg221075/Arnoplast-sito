// Carosello dell'hero della home: ruota la foto di sfondo e la didascalia (link alla sezione), titolo e testi
// restano fissi. Script classico senza dipendenze (funziona anche da file://).
// - Foto: solo la prima e' nel markup; le altre (data-src/data-srcset) si caricano dopo l'evento load, a browser
//   inattivo, o subito al primo comando dell'utente. Di sera (19-7, ora locale; ?luce=notte|giorno per forzare)
//   le slide con data-night-srcset usano la foto notturna, come l'hero di Azienda.
// - Rotazione ogni ~6,5 s: il tempo e' l'animazione della barra dell'indicatore attivo (hs-prog in home.css),
//   quindi pausa e ripresa ripartono da dove erano. Ferma al passaggio del mouse, col fuoco nel carosello,
//   mentre si scrive nella ricerca o con la scheda nascosta; il pulsante pausa la ferma finche' non si riprende.
// - Con prefers-reduced-motion: reduce nessuna rotazione automatica ne' movimento: comandi manuali attivi.
(function () {
  'use strict';
  var root = document.querySelector('[data-hs]');
  if (!root) return;
  var pics = [].slice.call(root.querySelectorAll('.hs-pic'));
  var caps = [].slice.call(root.querySelectorAll('.hs-cap'));
  var dots = [].slice.call(root.querySelectorAll('.hs-dot'));
  var live = root.querySelector('.hs-caps');
  var pauseBtn = root.querySelector('.hs-pause');
  var n = Math.min(pics.length, caps.length);
  if (n < 2) return;

  var q = /[?&]luce=(notte|giorno)/.exec(location.search), hr = new Date().getHours();
  var night = q ? q[1] === 'notte' : hr >= 19 || hr < 7;
  var mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  var calm = !!(mq && mq.matches);
  var cur = 0, paused = calm, holds = {}, leaveTimer = 0;

  // --- Caricamento rinviato delle foto (una slide alla volta o tutte).
  function load(i) {
    [].forEach.call(pics[i].querySelectorAll('[data-srcset]'), function (el) {
      var set = (night && el.getAttribute('data-night-srcset')) || el.getAttribute('data-srcset');
      el.removeAttribute('data-srcset');
      el.setAttribute('srcset', set);
      if (el.hasAttribute('data-src')) { el.setAttribute('src', el.getAttribute('data-src')); el.removeAttribute('data-src'); }
    });
  }
  function loadAll() { for (var i = 1; i < n; i++) load(i); }
  function whenReady(i, cb) {
    load(i);
    var img = pics[i].querySelector('img');
    if (!img || (img.complete && img.naturalWidth)) return cb();
    var done = false;
    var go = function () { if (!done) { done = true; cb(); } };
    img.addEventListener('load', go, { once: true });
    img.addEventListener('error', go, { once: true });
    setTimeout(go, 4000); // rete lenta: la didascalia non resta indietro troppo a lungo
  }
  var idle = window.requestIdleCallback || function (f) { return setTimeout(f, 200); };
  if (document.readyState === 'complete') idle(loadAll, { timeout: 3000 });
  else window.addEventListener('load', function () { idle(loadAll, { timeout: 3000 }); });

  // --- Stato: rotazione attiva solo senza pausa e senza "trattenute" (mouse, fuoco, ricerca, scheda nascosta).
  function sync() {
    var held = Object.keys(holds).some(function (k) { return holds[k]; });
    root.classList.toggle('is-playing', !paused);
    root.classList.toggle('is-held', held);
    live.setAttribute('aria-live', !paused && !held ? 'off' : 'polite');
    if (pauseBtn) {
      pauseBtn.setAttribute('aria-label', pauseBtn.getAttribute(paused ? 'data-play' : 'data-pause'));
      pauseBtn.classList.toggle('is-paused', paused);
    }
  }
  function hold(k, on) { holds[k] = on; sync(); }

  function show(i) {
    i = (i + n) % n;
    if (i === cur) return;
    var from = cur;
    cur = i;
    // Didascalia e indicatore subito (risposta immediata al comando), foto quando e' pronta.
    caps.forEach(function (c, j) { c.classList.toggle('is-active', j === i); });
    dots.forEach(function (d, j) { if (j === i) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current'); });
    whenReady(i, function () {
      if (cur !== i) return;
      clearTimeout(leaveTimer);
      pics.forEach(function (p, j) {
        p.classList.toggle('is-leaving', j === from && j !== i);
        p.classList.toggle('is-active', j === i);
        if (j === i) p.removeAttribute('aria-hidden'); else p.setAttribute('aria-hidden', 'true');
      });
      leaveTimer = setTimeout(function () { pics[from].classList.remove('is-leaving'); }, 1300);
    });
  }

  // La barra dell'indicatore attivo e' il timer: alla fine della sua animazione si passa alla slide seguente.
  root.addEventListener('animationend', function (e) {
    if (e.animationName === 'hs-prog' && !paused) show(cur + 1);
  });

  root.querySelector('.hs-prev').addEventListener('click', function () { loadAll(); show(cur - 1); });
  root.querySelector('.hs-next').addEventListener('click', function () { loadAll(); show(cur + 1); });
  dots.forEach(function (d, j) { d.addEventListener('click', function () { loadAll(); show(j); }); });
  if (pauseBtn) {
    if (calm) pauseBtn.hidden = true;
    pauseBtn.addEventListener('click', function () { paused = !paused; sync(); });
  }

  // Tastiera: frecce quando il fuoco e' nel carosello.
  root.addEventListener('keydown', function (e) {
    if (e.altKey || e.ctrlKey || e.metaKey || /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
      e.preventDefault();
      loadAll();
      show(cur + (e.key === 'ArrowRight' ? 1 : -1));
    }
  });

  // Swipe orizzontale sui touch screen (lo scorrimento verticale della pagina resta libero).
  var x0 = null, y0 = 0;
  root.addEventListener('touchstart', function (e) {
    var t = e.touches[0];
    x0 = e.touches.length === 1 && !e.target.closest('a, button, label') ? t.clientX : null;
    y0 = t.clientY;
  }, { passive: true });
  root.addEventListener('touchend', function (e) {
    if (x0 === null) return;
    var t = e.changedTouches[0], dx = t.clientX - x0, dy = t.clientY - y0;
    x0 = null;
    if (Math.abs(dx) > 48 && Math.abs(dx) > Math.abs(dy) * 1.5) { loadAll(); show(cur + (dx < 0 ? 1 : -1)); }
  }, { passive: true });

  // Pause temporanee: mouse sopra, fuoco dentro, ricerca in uso, scheda nascosta.
  root.addEventListener('mouseenter', function () { hold('hover', true); });
  root.addEventListener('mouseleave', function () { hold('hover', false); });
  root.addEventListener('focusin', function () { hold('focus', true); });
  root.addEventListener('focusout', function (e) { if (!root.contains(e.relatedTarget)) hold('focus', false); });
  var search = document.getElementById('site-search');
  if (search) {
    search.addEventListener('focus', function () { hold('search', true); });
    search.addEventListener('blur', function () { hold('search', false); });
  }
  document.addEventListener('visibilitychange', function () { hold('hidden', document.hidden); });

  root.classList.add('hs-on');
  sync();
})();
