// Galleria: ingrandimento delle foto in un <dialog> (frecce, tastiera, swipe). Script classico, nessuna dipendenza.
// Senza JS le anteprime restano link alla foto grande. Il fuoco torna sull'anteprima aperta alla chiusura.
(function () {
  'use strict';
  var grid = document.querySelector('[data-gallery]');
  if (!grid || !grid.getBoundingClientRect || typeof HTMLDialogElement === 'undefined') return;
  var all = [].slice.call(grid.querySelectorAll('a'));
  var links = all; // solo le foto visibili col filtro
  var dlg = document.createElement('dialog');
  dlg.className = 'gl-dlg';
  dlg.setAttribute('aria-label', document.title);
  dlg.innerHTML = '<div class="gl-stage"><img alt=""></div><div class="gl-bar">' +
    '<button type="button" class="gl-btn gl-prev" aria-label=""><span aria-hidden="true">&#8249;</span></button>' +
    '<span class="gl-pos" aria-live="polite"></span>' +
    '<button type="button" class="gl-btn gl-next" aria-label=""><span aria-hidden="true">&#8250;</span></button></div>' +
    '<button type="button" class="gl-btn gl-x" aria-label=""><span aria-hidden="true">&times;</span></button>';
  document.body.appendChild(dlg);
  var img = dlg.querySelector('img'), pos = dlg.querySelector('.gl-pos');
  dlg.querySelector('.gl-prev').setAttribute('aria-label', grid.getAttribute('data-prev'));
  dlg.querySelector('.gl-next').setAttribute('aria-label', grid.getAttribute('data-next'));
  dlg.querySelector('.gl-x').setAttribute('aria-label', grid.getAttribute('data-close'));
  var cur = 0, opener = null;
  function show(i) {
    cur = (i + links.length) % links.length;
    var a = links[cur], th = a.querySelector('img');
    img.alt = th ? th.alt : '';
    img.width = +a.getAttribute('data-w'); img.height = +a.getAttribute('data-h');
    img.src = a.getAttribute('href');
    pos.textContent = (cur + 1) + ' ' + (grid.getAttribute('data-of') || '/') + ' ' + links.length;
    var nx = links[(cur + 1) % links.length]; (new Image()).src = nx.getAttribute('href'); // foto seguente in anticipo
  }
  all.forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault(); opener = a; show(links.indexOf(a)); dlg.showModal();
    });
  });
  // Filtri per tipo: nascondono le foto degli altri tipi e aggiornano l'ingrandimento.
  var chips = [].slice.call(document.querySelectorAll('[data-filter]'));
  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      var f = chip.getAttribute('data-filter');
      chips.forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
      [].forEach.call(grid.querySelectorAll('.gl-item'), function (li) {
        li.hidden = f !== 'all' && li.getAttribute('data-cat') !== f;
      });
      links = all.filter(function (a) { return !a.closest('.gl-item').hidden; });
    });
  });
  dlg.querySelector('.gl-prev').addEventListener('click', function () { show(cur - 1); });
  dlg.querySelector('.gl-next').addEventListener('click', function () { show(cur + 1); });
  dlg.querySelector('.gl-x').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.classList.contains('gl-stage')) dlg.close(); });
  dlg.addEventListener('close', function () { if (opener) opener.focus(); });
  dlg.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft') { e.preventDefault(); show(cur - 1); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); show(cur + 1); }
  });
  var x0 = null;
  dlg.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  dlg.addEventListener('touchend', function (e) {
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0; x0 = null;
    if (Math.abs(dx) > 50) show(cur + (dx < 0 ? 1 : -1));
  });
}());
