// Video e Tutorial: il lettore (YouTube senza cookie / Vimeo) si crea solo al clic sulla scheda. Senza JS la scheda
// resta un link al video. Script classico.
(function () {
  'use strict';
  [].forEach.call(document.querySelectorAll('[data-vsrc]'), function (box) {
    var a = box.querySelector('a');
    if (!a) return;
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var f = document.createElement('iframe');
      f.src = box.getAttribute('data-vsrc');
      f.title = box.getAttribute('data-vtitle') || '';
      f.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
      f.setAttribute('allowfullscreen', '');
      f.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
      box.textContent = '';
      box.appendChild(f);
      f.focus();
    });
  });
}());
