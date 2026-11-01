// Contatti: controllo dei campi obbligatori, mailto precompilato (versione di prova) e mappa al clic. Script classico.
(function () {
  'use strict';
  var form = document.getElementById('contact-form');
  var msg = document.getElementById('contact-message');
  function show(html, ok) { msg.className = 'form-message' + (ok ? ' ok' : ''); msg.innerHTML = html; msg.hidden = false; msg.focus(); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  if (form) form.addEventListener('submit', function (e) {
    e.preventDefault();
    var f = form.elements, bad = [];
    [].forEach.call(form.querySelectorAll('.field.invalid'), function (d) { d.classList.remove('invalid'); });
    [].forEach.call(form.querySelectorAll('[required]'), function (el) {
      var empty = el.type === 'checkbox' ? !el.checked : !String(el.value).trim();
      if (empty) { bad.push(el); var d = el.closest('.field'); if (d) d.classList.add('invalid'); }
    });
    if (bad.length) {
      var names = bad.map(function (el) { if (el.getAttribute('data-label')) return el.getAttribute('data-label'); var l = form.querySelector('label[for="' + el.id + '"]'); return (l ? l.textContent : el.name).replace('*', '').trim(); });
      show(esc(form.getAttribute('data-err-required')) + ' ' + names.map(esc).join(', ') + '.', false); bad[0].focus(); return;
    }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.email.value.trim())) { f.email.closest('.field').classList.add('invalid'); show(esc(form.getAttribute('data-err-email')), false); f.email.focus(); return; }
    if (f.website && f.website.value) return; // trappola antispam: i visitatori veri non la compilano
    var lines = [['Nome', (f.name.value + ' ' + f.surname.value).trim()], ['Email', f.email.value], ['Telefono', f.phone.value],
      ['Ragione sociale', f.company.value], ['Referente aziendale', f.contact.value], ['Tipologia utente', f.type.value],
      ['Indirizzo', [f.address.value, f.zip.value, f.city.value].some(function (v) { return v.trim(); }) ? [f.address.value, f.zip.value, f.city.value, f.country.value].filter(Boolean).join(', ') : '']]
      .filter(function (r) { return String(r[1]).trim(); }).map(function (r) { return r[0] + ': ' + r[1]; });
    var body = lines.join('\n') + '\n\n' + f.message.value.trim();
    var mail = form.getAttribute('data-email');
    location.href = 'mailto:' + mail + '?subject=' + encodeURIComponent(form.getAttribute('data-subject')) + '&body=' + encodeURIComponent(body);
    show(esc(form.getAttribute('data-sent')) + ' <a href="mailto:' + esc(mail) + '">' + esc(mail) + '</a>.', true);
  });
  var box = document.querySelector('[data-map-src]');
  var btn = box && box.querySelector('[data-map-load]');
  if (btn) btn.addEventListener('click', function (e) {
    e.preventDefault();
    var fr = document.createElement('iframe');
    fr.src = box.getAttribute('data-map-src'); fr.title = box.getAttribute('data-map-title') || ''; fr.loading = 'lazy';
    fr.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
    box.textContent = ''; box.classList.add('is-loaded'); box.appendChild(fr);
  });
}());
