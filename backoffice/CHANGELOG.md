# Changelog del backoffice ARNOplast

Formato: versione, data, cosa cambia. La versione sta nel file `VERSION` e compare nella barra in alto.

## 0.9.0 — 13 novembre 2026 (prova sul server, non ancora definitiva)

### Novità
- **Statistiche proprie senza cookie**: pagine viste, visite, visitatori stimati, pagine di ingresso e di uscita, percorsi più frequenti, provenienze, dispositivi, browser e paesi (paesi solo con il database geografico GeoLite2).
- **Listini riservati ai commerciali**: account per persona con ruolo «Commerciale», accesso limitato ai listini, download registrati nel registro attività, revoca immediata.
- **Video e tutorial**: elenco per gruppo, aggiunta da link YouTube o Vimeo, modifica, eliminazione e annullamento in bozza.
- **Testi del sito**: campi precompilati con il testo attuale, annullamento delle modifiche per pagina.
- **Galleria**: modifica, ordine, aggiunta di foto, annullamento.
- **Fiere e Open Day**: schede in bozza con modifica e annullamento.
- **Nuova grafica**: barra superiore scura, carte, tabelle, pulsanti e campi; versione per telefono.

### Sicurezza
- Il backoffice non salva l'indirizzo IP dei visitatori: serve solo a un'impronta giornaliera.
- Le segnalazioni di pagina non usano cookie e rispettano «Non tracciare».
- Eliminate le pagine di prova e le vecchie copie di configurazione dal server.

### Tecnico
- Dipendenza `maxmind-db/reader` (lettura del database geografico, solo PHP).
- Prove automatiche per ogni sezione (`backoffice/tests/`) e controlli di parità con i dati del sito.

## 0.8.0 — 11 novembre 2026
- Area riservata: accesso dal backoffice e listini in PDF.

## 0.7.0 — 10 novembre 2026
- Galleria modificabile dal backoffice; strumenti di conversione per la build.

## 0.6.0 — 8 novembre 2026
- Testi fissi del sito, Fiere e Open Day in bozza, pubblicazione del sito di prova.

## 0.5.0 — 5 novembre 2026
- Preventivi con prezzi e PDF, revisioni, invio al cliente.
- Lettura automatica della casella delle richieste.

## 0.1.0 — primi moduli
- Richieste dai moduli del sito, risposte al cliente, e-mail con logo incorporato.
