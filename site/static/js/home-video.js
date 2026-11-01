// Video della home: il lettore YouTube (senza cookie) si crea al posto dell'anteprima solo dopo il clic.
// Senza JS resta un link al video. Script classico.
(function () {
  'use strict';
  var box = document.querySelector('[data-hv-src]');
  var a = box && box.querySelector('a');
  if (!a) return;
  a.addEventListener('click', function (e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    var f = document.createElement('iframe');
    f.src = box.getAttribute('data-hv-src') + '&autoplay=1'; f.title = box.getAttribute('data-hv-title') || '';
    f.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen'; f.setAttribute('allowfullscreen', '');
    f.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    box.textContent = ''; box.appendChild(f); f.focus();
  });
}());
