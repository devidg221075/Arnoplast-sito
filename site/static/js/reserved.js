// Area riservata (anteprima): nessun accesso vero nel prototipo; mostra un avviso chiaro invece di fingere un login.
(function () {
  'use strict';
  var form = document.getElementById('reserved-form'), msg = document.getElementById('reserved-message');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    msg.hidden = false; msg.className = 'form-message';
    var note = form.querySelector('.ct-note');
    msg.textContent = note.textContent; note.hidden = true; // stesso avviso: si mostra una volta sola
    msg.focus();
  });
}());
