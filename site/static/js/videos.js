// Videoteca: filtri per sezione, ricerca (senza accenti), conteggio, stato vuoto e lettore in finestra (<dialog>).
// Il lettore di YouTube (senza cookie) o Vimeo si crea solo al clic; chiudendo la finestra viene rimosso.
// Senza JS ogni scheda resta un link al video. Script classico, nessuna dipendenza.
(function () {
  'use strict';
  var lib = document.querySelector('[data-video-library]');
  if (!lib) return;
  var cards = [].slice.call(lib.querySelectorAll('[data-video-card]'));
  var filters = [].slice.call(lib.querySelectorAll('[data-video-filter]'));
  var input = lib.querySelector('#video-search');
  var result = lib.querySelector('[data-video-result]');
  var empty = lib.querySelector('[data-video-empty]');
  var norm = function (s) { return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
  var haystack = cards.map(function (c) { return norm(c.getAttribute('data-search') || ''); });
  var group = 'all';

  function apply() {
    var q = norm(input.value).trim().split(/\s+/).filter(Boolean), n = 0;
    cards.forEach(function (c, i) {
      var ok = (group === 'all' || c.getAttribute('data-group') === group) && q.every(function (w) { return haystack[i].indexOf(w) >= 0; });
      c.hidden = !ok; if (ok) n++;
    });
    result.textContent = n + ' ' + lib.getAttribute(n === 1 ? 'data-one' : 'data-many');
    empty.hidden = n !== 0;
  }
  function setGroup(g) {
    group = g;
    filters.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-video-filter') === g ? 'true' : 'false'); });
    apply();
  }
  filters.forEach(function (b) { b.addEventListener('click', function () { setGroup(b.getAttribute('data-video-filter')); }); });
  input.addEventListener('input', apply);
  lib.querySelector('[data-video-reset]').addEventListener('click', function () { input.value = ''; setGroup('all'); input.focus(); });
  var h = (location.hash || '').slice(1);
  if (h && filters.some(function (b) { return b.getAttribute('data-video-filter') === h; })) setGroup(h);

  // --- Lettore
  var dlg = document.getElementById('video-dialog');
  if (!dlg || typeof dlg.showModal !== 'function') return;
  var title = dlg.querySelector('#video-dialog-title'), player = dlg.querySelector('[data-video-player]');
  var ext = dlg.querySelector('[data-video-external]'), prov = dlg.querySelector('[data-video-provider]');
  var opener = null;
  lib.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('[data-video-open]') : null;
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault(); opener = a;
    var f = document.createElement('iframe');
    f.src = a.getAttribute('data-src'); f.title = a.getAttribute('data-title');
    f.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen'; f.setAttribute('allowfullscreen', '');
    f.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    player.textContent = ''; player.appendChild(f);
    title.textContent = a.getAttribute('data-title'); prov.textContent = a.getAttribute('data-provider'); ext.href = a.href;
    dlg.showModal();
  });
  function close() { player.textContent = ''; if (opener) opener.focus(); }
  dlg.querySelector('[data-video-close]').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('close', close);
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
}());
