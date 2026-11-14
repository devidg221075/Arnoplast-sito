# Backoffice: decisioni prese e punti aperti

Variazione al progetto: niente WordPress. Il sito resta statico; i contenuti si gestiscono da un backoffice e con Claude, tenuti sincronizzati.

## Decisioni del cliente (11 novembre 2026)

| Tema | Decisione |
|---|---|
| Hosting | VPS con hosting condiviso, accesso cPanel senza root. SSH (porta 22) da chiedere al fornitore se serve |
| Utenti | Amministratore (accesso completo) + personale ARNOplast con ruolo limitato |
| Conversazioni | Tutto nel backoffice: risposte inviate dal backoffice con il modello e-mail grafico; le risposte del cliente via e-mail rientrano nella conversazione. Vale per contatti e preventivi. Niente va perso |
| Catalogo | Tre modi: carico il PDF e approvo le differenze; modifica a mano; importazione da Excel/CSV. Italiano e inglese |
| Preventivi | Stati e note interne; preventivo con prezzi (inseriti a mano, riga per riga); risposta con allegato; esportazione Excel/CSV |
| Mittente e-mail | Casella ARNOplast via SMTP (info@arnoplast.it) |
| Pubblicazione | Bozza, anteprima, Pubblica; storico delle versioni e ripristino |
| Contenuti | Pagine istituzionali, Fiere e Open Day, Galleria e video, Download e area riservata, catalogo |
| Claude | Le modifiche fatte con Claude e quelle fatte dal backoffice devono restare sincronizzate |
| Riuso | Stesso programma, un'installazione separata per ogni sito, con moduli attivabili |
| Sicurezza | Registro delle attività; copie di sicurezza automatiche |

## Proposta tecnica (da confermare)

- **Una sola fonte dei contenuti: il repository Git.** Backoffice e Claude modificano gli stessi file di dati. È questo che li tiene sincronizzati.
- **Sito pubblico statico**, rigenerato a ogni pubblicazione: il backoffice non rallenta il sito.
- **Backoffice in PHP + MySQL**, perché funziona su qualunque cPanel senza root ed è il più facile da reinstallare su altri siti.
- **Rigenerazione del sito**: sull'hosting se cPanel offre «Setup Python App»; altrimenti in un servizio esterno automatico che poi carica i file sull'hosting.
- **Posta**: invio via SMTP; lettura delle risposte in arrivo con un'operazione pianificata (cron) sulla casella.
- **Nucleo riusabile**: accessi e ruoli, pagine, richieste e conversazioni, modelli e-mail, pubblicazione, registro, copie di sicurezza. **Moduli per sito**: catalogo, fiere, galleria, preventivi.

## Punti aperti

1. Voci disponibili nel cPanel: Setup Python App, Terminal/SSH Access, Cron Jobs, Git Version Control, versione di PHP, MySQL.
2. Credenziali SMTP/IMAP della casella e chi gestisce la posta di ARNOplast.
3. Cosa può fare il ruolo ARNOplast (solo richieste e preventivi, o anche contenuti).
4. Preventivo in PDF: intestazione, condizioni, IVA, validità, numerazione.
5. Privacy: tempi di conservazione di richieste e conversazioni (non scelta la scadenza automatica); informativa da aggiornare con il consulente.
6. Area riservata: accessi per singolo cliente o password unica.

## Hosting verificato (11 novembre 2026)

- Sito di lavoro: sottodominio `nuovo.arnoplast.it`, PHP 8.3. `arnoplast.it` (WordPress attuale) è su PHP 8.0.
- cPanel: presenti MySQL, Processi Cron, Git Version Control, Gestione MultiPHP. Assenti Setup Python App, Terminal, SSH Access.
- Disco all'82,7% (12,12 su 14,65 GB).
- Tra circa due settimane: passaggio a un nuovo VPS con PHP 8.5 e più spazio. Da sapere: accesso root/SSH e possibilità di installare Python.
- Scelte conseguenti: backoffice compatibile con PHP 8.3–8.5; rigenerazione del sito fuori dall'hosting (GitHub Actions) con consegna via Git o FTP, da rivalutare sul nuovo VPS.
- Nuovo VPS: solo cPanel, senza root né SSH (confermato). Quindi: rigenerazione del sito fuori dall'hosting (GitHub Actions), consegna via Git Version Control o FTP. Dominio definitivo: `www.arnoplast.it`; backoffice su indirizzo separato (es. `gestione.arnoplast.it`).

## Piano a tappe (proposta)

1. **Moduli ed e-mail**: contatti e preventivo che spediscono via SMTP; quattro modelli e-mail con la grafica del sito (cliente e ARNOplast, IT/EN); registro delle richieste; accesso al backoffice con ruoli e registro attività. `.htaccess` con reindirizzamenti e intestazioni di sicurezza.
2. **Conversazioni**: risposte dal backoffice, lettura della posta in arrivo (cron), allegati, stati e note, esportazione.
3. **Preventivi con prezzi**: compilazione riga per riga, PDF, invio e storico.
4. **Contenuti e pubblicazione**: pagine, fiere, galleria, video, download; bozza, anteprima, Pubblica; sincronizzazione con il repository; storico e ripristino.
5. **Catalogo**: modifica a mano, importazione Excel/CSV, caricamento PDF con confronto e approvazione; sfogliatore e download.
6. **Rifiniture e riuso**: copie di sicurezza automatiche, area riservata, guida d'uso, installazione su un secondo sito.

## Posta (dati non riservati)

- Casella di invio: `noreply@arnoplast.it`. Server `mail.arnoplast.it`: SMTP 465 (SSL), IMAP 993, POP3 995, con autenticazione.
- La password non va mai scritta nel repository: sta solo nel file di configurazione sull'hosting. Quella comunicata in chat va cambiata.
- Mittente e casella delle risposte: `richieste@arnoplast.it` (già attiva). Il backoffice legge solo questa casella. `noreply@` non si usa per le conversazioni.


## Tappa 2 — Conversazioni (fatta)

- Risposta al cliente dalla scheda della richiesta: modello grafico di ARNOplast, nella lingua della richiesta, firmata con il nome di chi scrive; oggetto con il riferimento; intestazioni `In-Reply-To`/`References` per tenere unito il filo nella posta del cliente.
- Lettura della casella `richieste@arnoplast.it` via IMAP (senza estensioni), ogni 5 minuti con cron o a mano dalla pagina Posta. Solo i messaggi con riferimento o in risposta a una conversazione nostra; il resto non si tocca (nessun segno di «letto», nessuno spostamento).
- Risposte automatiche e posta di liste ignorate; stessi messaggi mai importati due volte; una richiesta chiusa o con preventivo inviato torna «in lavorazione» se il cliente riscrive; notifica a `notify_to` per ogni risposta.
- Nell'elenco il segnale «Da rispondere» compare quando l'ultimo messaggio è del cliente.
- Limite noto: gli allegati dei clienti non si copiano nel backoffice (si segnalano; restano nella casella). Da decidere con la tappa 3 (preventivi) se servono.


## Tappa 3 — Preventivi con prezzi (fatta)

- Formato: PDF allegato a una e-mail breve con la grafica del sito. Numero = riferimento della richiesta; rifatto dopo la modifica diventa «rev. 2».
- Voci: prezzo unitario e sconto per riga, trasporto/imballo, IVA a scelta (22, 10, 4, 0), validità, pagamento, consegna, note; totali calcolati dal programma.
- Condizioni generali: testo fisso fornito da ARNOplast (italiano e inglese), da inserire in «Condizioni PDF»; finché è vuoto il PDF parte senza e il backoffice lo segnala prima dell'invio.
- Prezzi inseriti a mano (nessun listino): nessun controllo dei codici articolo sul catalogo.
- PDF in italiano o in inglese secondo la lingua della richiesta.


## Tappa 4 — Contenuti e pubblicazione (in corso)

- Ordine scelto: Fiere e Open Day, Pagine istituzionali, Galleria e video, Download e area riservata. Il catalogo viene più avanti.
- 4a (fatta per le fiere): bozza, anteprima, stato per scheda; nessuna modifica al sito. Archivio generico `content` (bozza/pubblicato) pronto per le altre sezioni.
- 4b (scelta del cliente il 14 novembre: FTP automatico da GitHub Actions; realizzata, da collegare): «Pubblica» salva i file dati e le foto nuove nel repository GitHub in un solo commit sul ramo `main`; `.github/workflows/pubblica.yml` ricostruisce il sito e lo consegna via FTP (anche ogni notte). Passi per il cliente (utente FTP dedicato, segreti GitHub, chiave a permessi minimi, ramo `main`) in `docs/pubblicazione-ftp.md`.
- Inglese: campi accanto all'italiano; «Traduzione richiesta» segna le schede che Claude traduce su richiesta (non c'è traduzione automatica nel backoffice).
- L'Open Day è una pagina con testi fissi: va con le pagine istituzionali.
- Solo l'amministratore modifica i contenuti (il ruolo ARNOplast vede richieste e preventivi).
- Pagine istituzionali (4a, fatta): i testi fissi del sito si correggono con uno strato `data/testi.json` applicato dalla build sopra gli originali (`arnoplast/site/texts.py`); il backoffice lo gestisce per gruppi di pagina (voce «Testi»). Testi legali esclusi. **Ancora da fare:** storia dell'azienda (testo lungo con tappe) e imballaggi, che non sono testi fissi singoli.
- Galleria (4a, fatta): foto, categoria, testi alternativi italiano/inglese (nuovo campo `alt_en`, che il sito usa al posto del testo generico inglese), ordine, aggiunta ed eliminazione, in bozza. Le foto nuove le converte la build (`tools/galleria_apply.py`, `arnoplast/site/gallery_tools.py`): il backoffice non elabora immagini, così non dipende dalle librerie dell'hosting. Gli originali delle foto nuove vivono nel repository in `content/photos/galleria/` (e `content/photos/fiere/`), salvati da «Pubblica».

## Catalogo (tappa 5): decisioni del 14 novembre 2026
- **Una sola fonte**: i prodotti stanno nel database del backoffice (codice, linea, nome, testi IT/EN, righe della tabella tecnica, foto, pagina del catalogo); il sito si genera da lì.
- **Aggiornamento completo**: caricamento di IDML di InDesign (preferito: testi e tabelle già strutturati) o PDF (ripiego). Schermata delle differenze per prodotto e campo (nuovi, cambiati, eliminati); l'esito entra come bozza dopo l'approvazione.
- **Piccole correzioni** (un testo, una riga di tabella, una foto): dalla scheda prodotto, in bozza fino a «Pubblica», segnate «modificato a mano».
- **Conflitti (scelta del cliente)**: la correzione manuale prevale sul nuovo file. Se il file porta un valore diverso, il conflitto compare nella schermata delle differenze con l'avviso; nessuna correzione si perde in silenzio.
- **Prodotti riconosciuti dal codice** a ogni importazione.
- **Il catalogo stampato non cambia**: elenco delle correzioni esportabile (CSV) per chi aggiorna l'IDML, così sito e catalogo non divergono.
- **Ordine**: dopo la «Pubblica» (4b).
