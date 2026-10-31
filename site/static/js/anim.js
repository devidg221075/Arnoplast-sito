// Animazioni del sito: ingressi allo scroll a strati, contatori che salgono, parallasse di riserva,
// inclinazione delle schede linea. Script classico (niente moduli: funziona anche da file://), senza dipendenze.
// Con prefers-reduced-motion: reduce o senza IntersectionObserver non fa nulla: il contenuto resta com'e'.
(function () {
  'use strict';
  var d = document;

  // Transizioni tra pagine (@view-transition in motion.css): una navigazione interrotta rifiuta le promesse
  // della transizione; sulla pagina nuova ci pensa lo script in linea di base.html, qui la pagina che si chiude.
  window.addEventListener('pageswap', function (e) {
    var vt = e.viewTransition;
    if (vt) ['ready', 'finished', 'updateCallbackDone'].forEach(function (k) { if (vt[k]) vt[k].catch(function () {}); });
  });
  var mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  if ((mq && mq.matches) || !('IntersectionObserver' in window)) return;

  var $$ = function (sel) { return Array.prototype.slice.call(d.querySelectorAll(sel)); };
  var vh = window.innerHeight || d.documentElement.clientHeight;

  // --- Ingressi: solo gli elementi ancora sotto la piega partono nascosti (nessun lampo, nessun CLS).
  var reveal = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      e.target.classList.remove('rv-wait');
      reveal.unobserve(e.target);
    });
  }, { rootMargin: '0px 0px -12% 0px', threshold: 0.01 });
  var revealEls = $$('[data-reveal]');
  var tops = revealEls.map(function (el) { return el.getBoundingClientRect().top; });
  revealEls.forEach(function (el, i) {
    if (el.getAttribute('data-reveal') === 'stagger') {
      Array.prototype.forEach.call(el.children, function (c, j) { c.style.setProperty('--ri', Math.min(j, 8)); });
    }
    if (tops[i] < vh * 0.92) return;
    el.classList.add('rv-wait');
    reveal.observe(el);
  });

  // --- Contatori: da 0 al valore stampato nella pagina, quando entrano in vista.
  var ease = function (t) { return t === 1 ? 1 : 1 - Math.pow(2, -10 * t); };
  function countUp(el) {
    var target = parseInt(el.textContent, 10);
    if (!(target > 0)) return;
    // Larghezza fissata alle cifre finali (cifre tabulari): il testo intorno non si sposta.
    if (getComputedStyle(el).display === 'inline') {
      el.style.display = 'inline-block';
      el.style.minWidth = String(target).length + 'ch';
      el.style.textAlign = 'right';
    }
    var dur = 1400 + Math.min(String(target).length, 4) * 150;
    var t0 = null;
    function step(now) {
      if (t0 === null) t0 = now;
      var p = Math.min(1, (now - t0) / dur);
      el.textContent = String(Math.round(target * ease(p)));
      if (p < 1) requestAnimationFrame(step);
    }
    el.textContent = '0';
    requestAnimationFrame(step);
  }
  var counters = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      counters.unobserve(e.target);
      countUp(e.target);
    });
  }, { threshold: 0.6 });
  $$('[data-countup]').forEach(function (el) { counters.observe(el); });

  // --- Parallasse di riserva (browser senza animation-timeline): un solo rAF per scroll, prima letture poi scritture.
  var hasTimeline = window.CSS && CSS.supports && CSS.supports('animation-timeline: view()');
  var layers = hasTimeline ? [] : $$('[data-parallax]');
  if (layers.length) {
    var ticking = false;
    var update = function () {
      ticking = false;
      var h = window.innerHeight;
      var rects = layers.map(function (el) { return el.getBoundingClientRect(); });
      layers.forEach(function (el, i) {
        var r = rects[i];
        if (r.bottom < -200 || r.top > h + 200) return;
        if (el.tagName === 'IMG') {
          var p = Math.max(-1, Math.min(1, (r.top + r.height / 2 - h / 2) / (h / 2 + r.height / 2)));
          el.style.transform = 'translate3d(0,' + (p * -7).toFixed(2) + '%,0) scale(1.16)';
        } else {
          el.style.transform = 'translate3d(0,' + (Math.max(0, -r.top) * 0.28).toFixed(1) + 'px,0)';
        }
      });
    };
    var onScroll = function () { if (!ticking) { ticking = true; requestAnimationFrame(update); } };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    update();
  }

  // --- Schede linea della home: leggera inclinazione 3D che segue il puntatore (solo mouse).
  if (window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    $$('.a-card a').forEach(function (a) {
      var raf = 0;
      a.addEventListener('pointermove', function (ev) {
        if (raf) return;
        raf = requestAnimationFrame(function () {
          raf = 0;
          var r = a.getBoundingClientRect();
          var x = (ev.clientX - r.left) / r.width - 0.5;
          var y = (ev.clientY - r.top) / r.height - 0.5;
          a.style.transform = 'perspective(900px) translateY(-6px) rotateX(' + (y * -5).toFixed(2) + 'deg) rotateY(' + (x * 6).toFixed(2) + 'deg)';
        });
      });
      a.addEventListener('pointerleave', function () {
        if (raf) { cancelAnimationFrame(raf); raf = 0; }
        a.style.transform = '';
      });
    });
  }
})();
