// Menu principale: pannello del telefono (pulsante hamburger), sottomenu a fisarmonica (telefono) o a tendina
// (desktop: anche al passaggio del mouse, via CSS). Script classico senza dipendenze: funziona anche da file://.
// Tasti: Invio/Spazio aprono e chiudono, freccia giu' apre e porta alla prima voce, frecce su/giu' tra le voci,
// Home/Fine alla prima/ultima, Esc chiude e torna al pulsante. Il fuoco che esce da un sottomenu lo chiude.
(function () {
  var nav = document.getElementById('site-menu');
  if (!nav) return;
  var toggle = document.querySelector('.menu-toggle');
  var subs = [].slice.call(nav.querySelectorAll('.has-sub'));
  var desktop = window.matchMedia('(min-width: 1024px)');

  function links(li) { return [].slice.call(li.querySelectorAll('.sub a')); }
  // shut: chiusa con Esc o col pulsante mentre il mouse e' sopra: il passaggio del mouse non la riapre finche' non esce.
  function setSub(li, open, shut) {
    li.classList.toggle('open', open);
    li.classList.toggle('shut', !open && !!shut);
    li.querySelector('.menu-btn').setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function closeAll(except) { subs.forEach(function (li) { if (li !== except && li.classList.contains('open')) setSub(li, false); }); }
  function setPanel(open) {
    if (!toggle) return;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    nav.classList.toggle('open', open);
  }

  subs.forEach(function (li) {
    var btn = li.querySelector('.menu-btn');
    // Sul telefono la sezione della pagina corrente parte aperta.
    if (!desktop.matches && li.classList.contains('is-active')) setSub(li, true);
    btn.addEventListener('click', function () {
      var open = btn.getAttribute('aria-expanded') !== 'true';
      if (desktop.matches) closeAll(li);
      setSub(li, open, true);
    });
    li.addEventListener('mouseleave', function () {
      li.classList.remove('shut');
      if (desktop.matches && li.classList.contains('open') && !li.contains(document.activeElement)) setSub(li, false);
    });
    li.addEventListener('keydown', function (e) {
      var items = links(li);
      var i = items.indexOf(document.activeElement);
      if (e.key === 'Escape') {
        if (li.classList.contains('open') || (desktop.matches && li.matches(':hover'))) {
          e.preventDefault(); e.stopPropagation();
          setSub(li, false, true); btn.focus();
        }
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!li.classList.contains('open')) setSub(li, true);
        items[i < 0 ? 0 : Math.min(i + 1, items.length - 1)].focus();
      } else if (e.key === 'ArrowUp' && i >= 0) {
        e.preventDefault();
        if (i === 0) btn.focus(); else items[i - 1].focus();
      } else if ((e.key === 'Home' || e.key === 'End') && i >= 0) {
        e.preventDefault();
        items[e.key === 'Home' ? 0 : items.length - 1].focus();
      }
    });
    li.addEventListener('focusout', function (e) {
      if (desktop.matches && li.classList.contains('open') && !li.contains(e.relatedTarget)) setSub(li, false);
    });
  });

  // Frecce sinistra/destra tra le voci di primo livello (desktop).
  nav.addEventListener('keydown', function (e) {
    if (!desktop.matches || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
    var top = [].slice.call(nav.querySelectorAll('.menu > li > a, .menu > li > .menu-btn')).filter(function (el) { return el.offsetParent; });
    var cur = document.activeElement.closest('.menu > li');
    var i = top.indexOf(cur && cur.querySelector('a, .menu-btn'));
    if (i < 0) return;
    e.preventDefault();
    closeAll();
    top[(i + (e.key === 'ArrowRight' ? 1 : -1) + top.length) % top.length].focus();
  });

  if (toggle) {
    toggle.addEventListener('click', function () { setPanel(toggle.getAttribute('aria-expanded') !== 'true'); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (toggle && toggle.getAttribute('aria-expanded') === 'true') { setPanel(false); toggle.focus(); }
  });
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('.site-header')) {
      if (desktop.matches && !e.target.closest('.has-sub')) closeAll();
      return;
    }
    closeAll();
    if (toggle && toggle.getAttribute('aria-expanded') === 'true') setPanel(false);
  });
  // Passando da telefono a desktop (rotazione, finestra ridimensionata) si riparte chiusi.
  var onChange = function () { closeAll(); setPanel(false); };
  if (desktop.addEventListener) desktop.addEventListener('change', onChange); else desktop.addListener(onChange);
})();
