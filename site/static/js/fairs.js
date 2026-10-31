// Fiere: il sito e' statico e il passaggio fra le "fiere passate" avviene alla build (serve una ricostruzione
// giornaliera programmata). Se il sito pubblicato non e' stato ricostruito, qui si nascondono (attributo hidden) le
// schede con data-fair-end precedente a oggi (data locale di chi visita, confronto fra stringhe ISO AAAA-MM-GG).
// Una lista [data-fairs] che resta vuota si nasconde e mostra il suo messaggio (data-fairs-none = id). Nessun nodo
// viene spostato. Script classico (funziona anche da file://), accodato ad anim.js dalla build. Senza JS: la build.
(function () {
  'use strict';
  var cards = document.querySelectorAll('[data-fair-end]');
  if (!cards.length) return;
  var n = new Date();
  var p = function (x) { return (x < 10 ? '0' : '') + x; };
  var today = n.getFullYear() + '-' + p(n.getMonth() + 1) + '-' + p(n.getDate());
  Array.prototype.forEach.call(cards, function (c) {
    if (c.getAttribute('data-fair-end') < today) c.hidden = true;
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-fairs]'), function (list) {
    if (list.querySelector('[data-fair-end]:not([hidden])')) return;
    list.hidden = true;
    var none = document.getElementById(list.getAttribute('data-fairs-none') || '');
    if (none) none.hidden = false;
  });
})();
