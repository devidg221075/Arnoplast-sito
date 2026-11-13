// Statistiche senza cookie: una segnalazione per pagina vista (pagina e provenienza, niente altro).
// Non si salva nulla nel browser e non si usa alcun identificativo. Rispetta «Non tracciare» e il controllo globale della privacy.
(function () {
  var endpoint = document.currentScript && document.currentScript.getAttribute('data-endpoint');
  if (!endpoint || !navigator.sendBeacon) return;
  if (navigator.doNotTrack === '1' || navigator.globalPrivacyControl === true) return;
  var body = new URLSearchParams();
  body.append('p', location.pathname);
  body.append('r', document.referrer || '');
  try { navigator.sendBeacon(endpoint, body); } catch (e) { /* le statistiche non devono mai disturbare il sito */ }
})();
