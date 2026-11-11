// Comportamento del prototipo: lista richiesta, contatori, modulo, ricerca (il menu e' in nav.js).
// ES module senza dipendenze. Tutto l'accesso a localStorage passa da quote.js (load/save).
import { addItem, canAdd, setQty, removeItem, isValidQty, load, save, emailBody, itemKey, QTY_MAX, STORAGE_KEY } from './quote.js';
import { buildIndex, search } from './search.js';

const LANG = document.documentElement.lang || 'it';
const EN = LANG === 'en';
/** Confezione e prodotto di origine di una voce, es. "conf. da 40 – Prisma". */
const packOf = (n) => (EN ? `pack of ${n}` : `conf. da ${n}`);
const meta = (i) => [i.conf ? packOf(i.conf) : '', i.product || ''].filter(Boolean).join(' – ');
// Testi dell'interfaccia generati da JS, nella lingua della pagina. L'email di richiesta resta in italiano
// (la legge ARNOplast).
const MSG = EN ? {
  qtyInvalid: `Invalid quantity: enter a whole number from 1 to ${QTY_MAX}.`,
  qtyOverMax: (have) => `Maximum quantity per line: ${QTY_MAX.toLocaleString('en-GB')} (you already have ${have.toLocaleString('en-GB')}).`,
  added: (code, qty) => `${code} added to the request (quantity ${qty}).`,
  removed: (code) => `${code} removed from the request.`,
  qtyUpdated: (code, qty) => `Quantity of ${code} updated to ${qty}.`,
  qtyLabel: 'Qty',
  qtyAria: (i) => `Quantity ${i.code}${meta(i) ? ` (${meta(i)})` : ''}`,
  remove: (i) => `Remove ${i.code}${meta(i) ? ` (${meta(i)})` : ''}`,
  emptyList: 'The list is empty: add at least one item from the product pages before sending.',
  name: 'Enter your first and last name.',
  email: 'Enter a valid email address.',
  privacy: 'To send the request you must accept the privacy policy.',
  fix: 'Please check the highlighted fields:',
  thanks: 'We tried to open your email program with the request already filled in. If it does not open, write to info@arnoplast.it and paste the text below. Your list stays saved.',
  copyLabel: 'Text of the request, to copy',
  noResults: 'No results.',
  kindFamily: 'Family',
  kindProduct: 'Product',
} : {
  qtyInvalid: `Quantità non valida: inserisci un numero intero da 1 a ${QTY_MAX}.`,
  qtyOverMax: (have) => `Quantità massima per riga: ${QTY_MAX.toLocaleString('it-IT')} (ne hai già ${have.toLocaleString('it-IT')}).`,
  added: (code, qty) => `${code} aggiunto alla richiesta (quantità ${qty}).`,
  removed: (code) => `${code} rimosso dalla richiesta.`,
  qtyUpdated: (code, qty) => `Quantità di ${code} aggiornata a ${qty}.`,
  qtyLabel: 'Q.tà',
  qtyAria: (i) => `Quantità ${i.code}${meta(i) ? ` (${meta(i)})` : ''}`,
  remove: (i) => `Rimuovi ${i.code}${meta(i) ? ` (${meta(i)})` : ''}`,
  emptyList: 'La lista è vuota: aggiungi almeno un articolo dalle schede prodotto prima di inviare.',
  name: 'Inserisci nome e cognome.',
  email: 'Inserisci un indirizzo email valido.',
  privacy: "Per inviare la richiesta devi accettare l'informativa privacy.",
  fix: 'Controlla i campi evidenziati:',
  thanks: 'Abbiamo provato ad aprire il tuo programma di posta con la richiesta già compilata. Se non si apre, scrivi a info@arnoplast.it incollando il testo qui sotto. La lista resta salvata.',
  copyLabel: 'Testo della richiesta, da copiare',
  noResults: 'Nessun risultato.',
  kindFamily: 'Famiglia',
  kindProduct: 'Prodotto',
};

// localStorage può non esistere o lanciare (modalità privata, cookie bloccati): quote.js lo gestisce.
let storage = null;
try { storage = window.localStorage; } catch { storage = null; }

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

let list = load(storage);

function announce(text) {
  const live = $('#live');
  if (!live) return;
  live.textContent = '';
  // Ritardo minimo: i lettori di schermo annunciano anche un testo ripetuto.
  requestAnimationFrame(() => { live.textContent = text; });
}

/** Interpreta una quantità digitata: intero 1..QTY_MAX, altrimenti null. */
function parseQty(value) {
  const s = String(value ?? '').trim();
  if (!/^\d+$/.test(s)) return null;
  const n = Number(s);
  return isValidQty(n) && n <= QTY_MAX ? n : null;
}

function updateCounters(bump = false) {
  const n = String(list.length);
  for (const el of $$('.quote-count')) {
    el.textContent = n;
    if (bump) {
      el.classList.remove('bump');
      void el.offsetWidth; // riavvia l'animazione
      el.classList.add('bump');
    }
  }
}

function commit(next, { bump = false } = {}) {
  list = next;
  save(storage, list);
  updateCounters(bump);
}

/* ---------- Pulsanti "Aggiungi alla richiesta" ---------- */
function rowMessage(btn, text) {
  const wrap = btn.parentElement;
  let msg = wrap.querySelector('.qty-msg');
  if (!msg) {
    msg = document.createElement('span');
    msg.className = 'qty-msg';
    msg.setAttribute('role', 'status');
    msg.setAttribute('aria-live', 'polite');
    wrap.appendChild(msg);
  }
  msg.textContent = text;
  return msg;
}

function siblingQty(btn) {
  return btn.parentElement ? btn.parentElement.querySelector('.qty') : null;
}

function onAdd(btn) {
  const input = siblingQty(btn);
  const qty = input ? parseQty(input.value) : 1;
  const code = btn.dataset.code || '';
  const conf = Number(btn.dataset.conf);
  const item = { code, description: btn.dataset.description || '', product: btn.dataset.product || '',
                 conf: Number.isInteger(conf) && conf > 0 ? conf : null };
  const key = itemKey(item);
  const over = qty !== null && !canAdd(list, key, qty);
  if (qty === null || over) {
    if (input) {
      input.setAttribute('aria-invalid', 'true');
      input.focus();
    }
    const have = over ? (list.find((i) => itemKey(i) === key) || { qty: 0 }).qty : 0;
    const text = over ? MSG.qtyOverMax(have) : MSG.qtyInvalid;
    const msg = rowMessage(btn, text);
    if (input) input.setAttribute('aria-describedby', msg.id || (msg.id = `msg-${Math.random().toString(36).slice(2, 8)}`));
    return;
  }
  if (input) {
    input.removeAttribute('aria-invalid');
    input.value = String(qty);
  }
  rowMessage(btn, '');
  commit(addItem(list, { ...item, qty }), { bump: true });
  btn.classList.add('is-added');
  clearTimeout(btn._addedTimer);
  btn._addedTimer = setTimeout(() => btn.classList.remove('is-added'), 1600);
  announce(MSG.added(code, qty));
}

document.addEventListener('click', (e) => {
  const btn = e.target.closest && e.target.closest('.add-to-quote');
  if (btn) onAdd(btn);
});
document.addEventListener('input', (e) => {
  const input = e.target;
  if (!(input instanceof HTMLInputElement) || !input.classList.contains('qty')) return;
  if (input.getAttribute('aria-invalid') === 'true' && parseQty(input.value) !== null) {
    input.removeAttribute('aria-invalid');
    const msg = input.parentElement && input.parentElement.querySelector('.qty-msg');
    if (msg) msg.textContent = '';
  }
});
document.addEventListener('keydown', (e) => {
  // Invio nel campo quantità della tabella = aggiungi.
  const input = e.target;
  if (e.key === 'Enter' && input instanceof HTMLInputElement && input.classList.contains('qty') && !input.closest('#quote-list')) {
    const btn = input.parentElement && input.parentElement.querySelector('.add-to-quote');
    if (btn) { e.preventDefault(); onAdd(btn); }
  }
});

/* ---------- Pagina richiesta ---------- */
const listEl = $('#quote-list');
const emptyEl = $('#quote-empty');

function renderQuote() {
  if (!listEl) return;
  listEl.textContent = '';
  if (emptyEl) emptyEl.hidden = list.length > 0;
  if (!list.length) return;
  const ul = document.createElement('ul');
  ul.className = 'quote-lines';
  for (const item of list) {
    const li = document.createElement('li');
    li.className = 'quote-line';
    li.dataset.code = item.code;
    const key = itemKey(item);
    li.dataset.key = key;

    const code = document.createElement('span');
    code.className = 'ql-code';
    code.textContent = item.code;

    const desc = document.createElement('span');
    desc.className = 'ql-desc';
    desc.textContent = item.description;
    if (meta(item)) {
      const m = document.createElement('small');
      m.className = 'ql-meta';
      m.textContent = item.conf ? packOf(item.conf) : '';
      if (item.product) {
        const pr = document.createElement('span');
        pr.className = 'ql-prod';
        pr.textContent = item.product;
        m.append(' ', pr);
      }
      desc.append(' ', m);
    }

    const qtyWrap = document.createElement('label');
    qtyWrap.className = 'ql-qty';
    const qtyText = document.createElement('span');
    qtyText.setAttribute('aria-hidden', 'true');
    qtyText.textContent = MSG.qtyLabel;
    const qty = document.createElement('input');
    qty.type = 'number';
    qty.className = 'qty';
    qty.min = '1';
    qty.max = String(QTY_MAX);
    qty.step = '1';
    qty.inputMode = 'numeric';
    qty.value = String(item.qty);
    qty.setAttribute('aria-label', MSG.qtyAria(item));
    qty.addEventListener('change', () => {
      const n = parseQty(qty.value);
      if (n === null) {
        // Valore non valido: torna all'ultimo valore salvato.
        const cur = list.find((i) => itemKey(i) === key);
        qty.value = String(cur ? cur.qty : item.qty);
        announce(MSG.qtyInvalid);
        return;
      }
      commit(setQty(list, key, n));
      qty.value = String(n);
      announce(MSG.qtyUpdated(item.code, n));
    });
    qtyWrap.append(qtyText, qty);

    const rm = document.createElement('button');
    rm.type = 'button';
    rm.className = 'ql-remove';
    rm.setAttribute('aria-label', MSG.remove(item));
    const x = document.createElement('span');
    x.setAttribute('aria-hidden', 'true');
    x.textContent = '\u00d7';
    rm.appendChild(x);
    rm.addEventListener('click', () => {
      const lis = $$('.quote-line', listEl);
      const idx = lis.indexOf(li);
      commit(removeItem(list, key));
      renderQuote();
      announce(MSG.removed(item.code));
      // Sposta il fuoco su un elemento sensato dopo la rimozione.
      const next = $$('.ql-remove', listEl)[Math.min(idx, list.length - 1)];
      if (next) next.focus();
      else if (emptyEl) { emptyEl.tabIndex = -1; emptyEl.focus(); }
    });

    li.append(code, desc, qtyWrap, rm);
    ul.appendChild(li);
  }
  listEl.appendChild(ul);
}

/* ---------- Modulo ---------- */
const form = $('#quote-form');

function formMessage(html, ok = false) {
  const box = $('#form-message');
  if (!box) return;
  box.className = `form-message${ok ? ' ok' : ''}`;
  box.replaceChildren();
  if (Array.isArray(html)) {
    const p = document.createElement('p');
    p.style.margin = '0 0 .4em';
    p.textContent = html[0];
    const ul = document.createElement('ul');
    ul.style.margin = '0';
    ul.style.paddingLeft = '1.2em';
    for (const t of html.slice(1)) {
      const li = document.createElement('li');
      li.textContent = t;
      ul.appendChild(li);
    }
    box.append(p, ul);
  } else {
    box.textContent = html;
  }
  box.hidden = false;
  box.focus({ preventScroll: true });
  box.scrollIntoView({ block: 'nearest', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
}

// Un mailto con molte righe supera facilmente ~2000 caratteri e puo' essere troncato o non aprirsi:
// il testo completo resta sempre disponibile e copiabile.
function showCopyable(text) {
  const box = $('#form-message');
  if (!box) return;
  const label = document.createElement('label');
  label.className = 'copy-label';
  label.textContent = MSG.copyLabel;
  const ta = document.createElement('textarea');
  ta.readOnly = true;
  ta.className = 'copy-text';
  ta.rows = Math.min(14, text.split('\n').length + 1);
  ta.value = text;
  ta.addEventListener('focus', () => ta.select());
  label.appendChild(ta);
  box.appendChild(label);
}

if (form) {
  const field = (name) => form.elements.namedItem(name);
  form.addEventListener('input', (e) => {
    if (e.target.getAttribute && e.target.getAttribute('aria-invalid') === 'true' && e.target.checkValidity()) {
      e.target.removeAttribute('aria-invalid');
    }
  });
  form.addEventListener('change', (e) => {
    if (e.target.type === 'checkbox' && e.target.checked) e.target.removeAttribute('aria-invalid');
  });
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (storage) list = load(storage); // riallinea con eventuali modifiche da altre schede
    const errors = [];
    let firstBad = null;
    const check = (el, ok, msg) => {
      if (!el) return;
      if (ok) { el.removeAttribute('aria-invalid'); return; }
      el.setAttribute('aria-invalid', 'true');
      errors.push(msg);
      firstBad = firstBad || el;
    };
    const name = field('name');
    const email = field('email');
    const privacy = field('privacy');
    if (name) name.value = name.value.trim();
    if (email) email.value = email.value.trim();
    check(name, name && name.checkValidity(), MSG.name);
    check(email, email && email.checkValidity() && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value), MSG.email);
    check(privacy, privacy && privacy.checked, MSG.privacy);
    if (!list.length) {
      errors.unshift(MSG.emptyList);
    }
    if (errors.length) {
      formMessage(errors.length === 1 ? errors[0] : [MSG.fix, ...errors]);
      if (firstBad) firstBad.focus({ preventScroll: true });
      return;
    }
    const trap = field('website');
    if (trap && trap.value) return; // trappola antispam: i visitatori veri non la compilano
    const data = {};
    for (const k of ['name', 'company', 'email', 'phone', 'country', 'notes']) {
      const el = field(k);
      data[k] = el ? el.value : '';
    }
    const endpoint = form.getAttribute('data-endpoint');
    if (endpoint) {
      // Invio al backoffice: la richiesta viene salvata e partono le e-mail al cliente e ad ARNOplast.
      const btn = form.querySelector('button[type=submit]');
      const label = btn.textContent;
      const failed = () => {
        formMessage(`${form.getAttribute('data-fail')} info@arnoplast.it.`);
        showCopyable(`A: info@arnoplast.it\nOggetto: Richiesta di preventivo\n\n${emailBody(list, data)}`);
      };
      btn.disabled = true;
      btn.textContent = form.getAttribute('data-busy');
      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'omit',
        body: JSON.stringify({ kind: 'quote', lang: LANG, privacy: true, website: '', ...data, items: list }),
      })
        .then((r) => r.json().then((j) => ({ ok: r.ok && j && j.ok, ref: j && j.ref })))
        .then((r) => {
          if (!r.ok) { failed(); return; }
          formMessage(`${form.getAttribute('data-ok')}${r.ref ? ` ${r.ref}` : ''}.`, true);
          list = [];
          save(storage, list);
          updateCounters();
          renderQuote();
          form.reset();
        })
        .catch(failed)
        .finally(() => { btn.disabled = false; btn.textContent = label; });
      return;
    }
    // PROTOTIPO: l'invio vero (email a info@arnoplast.it + copia al cliente, lato server)
    // arriva con WordPress. Qui apriamo il programma di posta con un link mailto precompilato.
    const href = 'mailto:info@arnoplast.it?subject=Richiesta%20di%20preventivo&body='
      + encodeURIComponent(emailBody(list, data));
    window.location.href = href;
    formMessage(MSG.thanks, true);
    showCopyable(`A: info@arnoplast.it\nOggetto: Richiesta di preventivo\n\n${emailBody(list, data)}`);
    // La lista resta salvata dopo l'invio.
  });
}

/* ---------- Sincronizzazione tra schede ---------- */
window.addEventListener('storage', (e) => {
  if (e.key !== null && e.key !== STORAGE_KEY) return;
  list = load(storage);
  updateCounters();
  renderQuote();
});

/* ---------- Ricerca ---------- */
const searchInput = $('#site-search');
const results = $('#search-results');
let index = null;
let indexState = 'idle'; // idle | loading | ready | failed

let indexPromise = null;
function loadIndex() {
  if (!indexPromise) {
    indexState = 'loading';
    indexPromise = fetch(`/${encodeURIComponent(LANG)}/search-index.json`, { credentials: 'same-origin' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((data) => { index = buildIndex(data); indexState = 'ready'; })
      .catch(() => { indexState = 'failed'; index = null; }); // ricerca disattivata in silenzio
  }
  return indexPromise;
}

function closeResults() {
  if (!results) return;
  results.textContent = '';
  if (searchInput) searchInput.setAttribute('aria-expanded', 'false');
}

function links() { return results ? $$('a', results) : []; }

// Frecce: il fuoco si sposta sui link dei risultati (link veri, nessun ruolo ARIA da simulare).
function moveFocus(step) {
  const items = links();
  if (!items.length) return;
  const cur = items.indexOf(document.activeElement);
  const next = cur === -1 ? (step > 0 ? 0 : items.length - 1) : cur + step;
  if (next < 0 || next >= items.length) { searchInput.focus(); return; }
  items[next].focus();
}

function renderResults() {
  if (!searchInput || !results || indexState !== 'ready') return;
  const q = searchInput.value;
  if (q.trim().length < 2) { closeResults(); return; }
  let found = [];
  try { found = search(index, q, 8); } catch { found = []; }
  results.textContent = '';
  searchInput.setAttribute('aria-expanded', 'true');
  if (!found.length) {
    const p = document.createElement('p');
    p.className = 'r-empty';
    p.textContent = MSG.noResults;
    results.appendChild(p);
    return;
  }
  const ul = document.createElement('ul');
  for (const a of found) {
    const li = document.createElement('li');
    const link = document.createElement('a');
    link.href = a.url;
    const c = document.createElement('span');
    c.className = 'r-code';
    const d = document.createElement('span');
    d.className = 'r-desc';
    if (typeof a.code === 'string') {
      // Articolo: un risultato per codice, con i prodotti in cui compare.
      c.textContent = a.code;
      const names = [...new Set(Array.isArray(a.products) ? a.products : [])];
      const more = names.length > 2 ? ` +${names.length - 2}` : '';
      d.textContent = [names.slice(0, 2).join(', ') + more, a.description].filter((x) => x && x.trim()).join(' · ');
    } else {
      c.textContent = a.kind === 'family' ? MSG.kindFamily : MSG.kindProduct;
      d.textContent = [a.name, a.parent].filter(Boolean).join(' · ');
    }
    link.append(c, d);
    li.appendChild(link);
    ul.appendChild(li);
  }
  results.appendChild(ul);
}

if (searchInput && results) {
  searchInput.setAttribute('aria-expanded', 'false');
  const ensure = () => loadIndex().then(renderResults);
  searchInput.addEventListener('focus', () => { loadIndex(); }); // carica l'indice al primo fuoco
  searchInput.addEventListener('input', ensure);
  const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); moveFocus(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); moveFocus(-1); }
    else if (e.key === 'Escape') {
      if (results.childElementCount) { e.preventDefault(); closeResults(); searchInput.focus(); }
      else if (e.target === searchInput) searchInput.value = '';
    } else if (e.key === 'Enter' && e.target === searchInput) {
      const first = links()[0];
      if (first) { e.preventDefault(); window.location.href = first.href; }
    }
  };
  searchInput.addEventListener('keydown', onKey);
  results.addEventListener('keydown', onKey);
  document.addEventListener('click', (e) => {
    if (!e.target.closest || !e.target.closest('.site-search')) closeResults();
  });
  document.addEventListener('focusin', (e) => {
    if (!e.target.closest || !e.target.closest('.site-search')) closeResults();
  });
}

// Menu (pannello del telefono, sottomenu, voce attiva): nav.js e il markup generato dalla build.

updateCounters();
renderQuote();
