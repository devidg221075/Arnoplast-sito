// Post di Facebook della home: il riquadro ufficiale si carica solo dopo il clic (nessuna richiesta a terzi prima).
// Senza JS il pulsante resta un normale link alla pagina Facebook. Script classico.
(function () {
  'use strict';
  var box = document.querySelector('[data-fb-box]');
  var btn = box && box.querySelector('[data-fb-load]');
  if (!btn) return;
  btn.addEventListener('click', function (e) {
    e.preventDefault();
    var f = document.createElement('iframe');
    f.src = box.getAttribute('data-fb-src');
    f.title = box.getAttribute('data-fb-title') || '';
    f.width = 500; f.height = 640; f.loading = 'lazy';
    f.setAttribute('scrolling', 'no');
    f.setAttribute('allow', 'encrypted-media');
    f.setAttribute('referrerpolicy', 'origin-when-cross-origin');
    box.textContent = '';
    box.classList.add('is-loaded');
    box.appendChild(f);
  });
}());
