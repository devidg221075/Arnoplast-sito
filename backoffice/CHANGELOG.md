# Changelog del backoffice ARNOplast

Formato: versione, data, cosa cambia. La versione sta nel file `VERSION` e compare in basso nella barra laterale: cliccandola si apre questa pagina («Novità»).

## 0.10.3 — 14 novembre 2026

### Correzioni
- **«Pubblica» tiene allineata la copia dei dati nel pacchetto del backoffice** (`seed/fiere.json`, `seed/galleria.json`, `seed/video.json`): a ogni pubblicazione le copie vengono aggiornate nello stesso commit, così restano uguali ai dati del sito e il controllo automatico non segnala più differenze. Sistemato anche lo stato attuale delle fiere.

## 0.10.2 — 14 novembre 2026

### Novità
- **Testi: controllo su tutte le pagine di servizio**. Nuovi gruppi in «Testi»: «Dati societari e contatti» (ragione sociale, indirizzo, telefoni, e-mail, partita IVA, REA, capitale sociale, sito), «Collegamenti social e app» (Facebook, LinkedIn, Instagram, App Store, Google Play), «Contatti: tipologia utente» (12 voci del menu del modulo), «Testi alternativi delle foto di testata» e «Descrizioni per i motori di ricerca» (Fiere e Cataloghi). «Made in Italy» e il titolo del riquadro Facebook della home entrano nei testi del piè di pagina, della home e della sezione social.
- **Dati societari e collegamenti solo per l'amministratore**: il redattore non li vede né li modifica; valgono per italiano e inglese (una sola colonna). Indirizzi web solo https, e-mail controllata.
- Nel backoffice i campi hanno un'etichetta chiara (per esempio «Telefono principale»), accanto al nome tecnico.
- Senza correzioni il sito costruito resta identico (verificato confrontando tutte le pagine).

### Tecnico
- `pagetexts.py` (chiavi `corp.*`, `link.*`, `ut.N`, `hero.*.alt`, `meta.*`), `refresh_contacts()` nella build, controlli di formato in Python e PHP, test dedicati.

## 0.10.1 — 14 novembre 2026

### Novità
- **Testi: tutta la pagina Azienda**. Nuovi gruppi «Azienda: la storia» (titoli, tappe 1975, 1983, anni Ottanta e Novanta, «Oggi», in italiano e inglese) e «Azienda: imballaggi» (titolo, paragrafi, righe della tabella e nota). I paragrafi lunghi accettano fino a 3000 caratteri.
- Per le pagine di servizio restano fuori, per ora, i **dati societari** (indirizzo, telefoni, e-mail, partita IVA) e le voci del modulo contatti.

### Tecnico
- `arnoplast/site/pagetexts.py` (chiavi `story.*` e `pack.*`), limite per tipo di testo, seed dei testi aggiornato, `tests/test_pagetexts.py`.

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
