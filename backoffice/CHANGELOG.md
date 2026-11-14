# Changelog del backoffice ARNOplast

Formato: versione, data, cosa cambia. La versione sta nel file `VERSION` e compare in basso nella barra laterale: cliccandola si apre questa pagina («Novità»).

## 0.10.0 — 14 novembre 2026

### Novità
- **Pubblica**: nuova voce del menu (solo amministratore). Mostra le modifiche in bozza di Testi, Fiere, Galleria e Video e, dopo la conferma, le salva nel repository del sito in un solo commit (file dati e foto nuove). GitHub Actions ricostruisce il sito e lo consegna via FTP in pochi minuti; la schermata mostra lo stato dell'ultima costruzione.
- **Costruzione automatica del sito** (GitHub Actions): a ogni pubblicazione e a mano. La notte solo se serve davvero (ieri è finita una fiera, o è il primo gennaio); negli altri giorni si ferma dopo un controllo di pochi secondi. Le prove automatiche girano su ogni modifica.
- Le pubblicazioni finiscono nel registro attività («sito.pubblicato») con il collegamento al commit.

### Tecnico
- `GitHub.php`, `Publisher.php`, `PublishView.php`, `Content::publishAll`; chiave GitHub a permessi minimi solo in `config.php` (`github`); guida in `docs/pubblicazione-ftp.md`; `tests/publish_test.php`.

## 0.9.1 — 14 novembre 2026

### Novità
- **Grafica del kit**: il backoffice adotta i fogli CSS del kit grafico (`admin.css` e `premium.css`): barra laterale antracite con voce attiva verde, barra in alto con ricerca, indicatori verdi e pesca, tabelle, grafico e attività come nel mockup. Icone dallo sprite del kit.
- **Pagina «Novità»**: le note di versione si leggono dentro il backoffice, aperta a tutti gli utenti. Si raggiunge cliccando la versione in basso a sinistra o il link nel piè di pagina.

### Tecnico
- Nuovo `extra.css` per le aggiunte ARNOplast sopra i fogli del kit; `Changelog.php` e `tests/changelog_test.php`.

## 0.9.0 — 13 novembre 2026 (prova sul server, non ancora definitiva)

### Novità
- **Statistiche proprie senza cookie**: pagine viste, visite, visitatori stimati, pagine di ingresso e di uscita, percorsi più frequenti, provenienze, dispositivi, browser e paesi (paesi solo con il database geografico GeoLite2).
- **Listini riservati ai commerciali**: account per persona con ruolo «Commerciale», accesso limitato ai listini, download registrati nel registro attività, revoca immediata.
- **Video e tutorial**: elenco per gruppo, aggiunta da link YouTube o Vimeo, modifica, eliminazione e annullamento in bozza.
- **Testi del sito**: campi precompilati con il testo attuale, annullamento delle modifiche per pagina.
- **Galleria**: modifica, ordine, aggiunta di foto, annullamento.
- **Fiere e Open Day**: schede in bozza con modifica e annullamento.
- **Nuova grafica**: barra superiore scura, carte, tabelle, pulsanti e campi; versione per telefono.

- **Ordini**: il preventivo inviato si segna come accettato e crea l'ordine (O-AAAA-NNNN). Stati confermato, in lavorazione, spedito, chiuso, solo in avanti.
- **Dashboard iniziale**: indicatori con variazione rispetto al periodo precedente, ultime richieste, andamento preventivi e ordini, attività recenti e metriche, tutti dai dati reali.
- **Nuova interfaccia**: barra laterale con il menu, barra in alto con ricerca, notifiche e utente, colori e decorazioni del mockup.
- **Ruolistica interna**: quattro ruoli (Amministratore, Redattore contenuti, ARNOplast vendite, Commerciale) con una tabella unica dei permessi. Cambio ruolo dalla pagina «Utenti», con protezione dell'ultimo amministratore; ogni cambio finisce nel registro.

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
