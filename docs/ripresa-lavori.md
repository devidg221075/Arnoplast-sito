# Dove siamo (13 novembre 2026) e da dove si riprende

## Fatto e provato sul server vero
- Backoffice su `gestione.arnoplast.it` (PHP 8.3, MySQL): richieste dai moduli, e-mail al cliente e ad ARNOplast con logo incorporato, risposte dal backoffice,
  lettura delle risposte dalla casella `richieste@arnoplast.it` (cron ogni 5 minuti), preventivo con prezzi e PDF allegato.
- Sito di prova su `nuovo.arnoplast.it` con `.htaccess` e moduli collegati al backoffice.
- Tappa 4a (bozze, senza «Pubblica») provate sul server: **Fiere** (modifica e annullamento), **Testi** (campi precompilati col testo attuale, «Annulla modifiche di questa pagina»), **Galleria** (miniature visibili; modifica e annullamento confermati).
- Video e tutorial (4a, bozze): elenco per gruppo nel backoffice, aggiunta da link YouTube/Vimeo, modifica, eliminazione e annullamento. Provato sul server. Manca: ordine delle voci (su/giù) e la lista Garden, bloccata sul sito vecchio.
- Pulizia di sicurezza fatta: `diag*.php` cancellati, `backoffice_old` cancellata, `setup_token` svuotato, password di `noreply@arnoplast.it` cambiata.

## Da fare, in ordine
1. Inglese: verifica dello zip del sito da parte del cliente, poi indicizzazione della versione inglese (togliere `noindex`, sitemap, hreflang).
2. Proteggere `nuovo.arnoplast.it` dai motori di ricerca finché è in prova.

## Tappa 4 — prossime sezioni (una per volta)
- Download e area riservata; poi storia dell'azienda e imballaggi (testi lunghi, non voci singole). Il catalogo viene più avanti.
- **Catalogo (tappa 5)**: decisioni in `docs/backoffice-decisioni.md` (IDML/PDF con differenze, correzioni a mano che prevalgono con avviso di conflitto, esportazione CSV delle correzioni). Si fa dopo la 4b.

- **4b «Pubblica»**: fatta (scelta: FTP automatico da GitHub Actions). **Da collegare dal cliente** seguendo `docs/pubblicazione-ftp.md`: utente FTP dedicato, segreti e variabili in GitHub, chiave GitHub a permessi minimi in `config.php`, creazione del ramo `main` (unendo la pull request).

## Da ricevere dal cliente
- **Da chiedere al cliente, da ricordare alla ripresa:** i PDF di ricambi Oleo 2016, ricambi Oleo Carbonio 2016, ricambi Facilitatore Martino 2018 e corda elastica 2018. Serve sapere se contengono prezzi: se sì, vanno nell'area riservata; se no, restano pubblici. Il cliente li manda prossimamente.
- Condizioni generali del preventivo, italiano e inglese (pagina «Condizioni PDF» del backoffice).
- Contenuti già in elenco in `docs/riepilogo-per-il-cliente.md` (8 fiere passate, listini/area riservata, tutorial, banner cookie, ecc.).
- IDML del Garden 2026 per correggere i refusi; conferma sulla pagina 29 (gamma filo).

## Regole da ricordare
- Una domanda alla volta, con risposte suggerite; mostrare sempre il risultato con schermate o prove.
- Dopo ogni caricamento sul server: controllare in MultiPHP Manager che `gestione.arnoplast.it` sia su PHP 8.3 (tende a tornare a 8.0).
- Zip del backoffice: si estrae sopra la cartella `backoffice`, senza toccare `config.php` e `var`. Nel `config.php`, ogni chiave va al livello principale e dentro `return [ ... ];`.
