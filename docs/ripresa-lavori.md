# Dove siamo (11 novembre 2026) e da dove si riprende

## Fatto e provato sul server vero
- Backoffice su `gestione.arnoplast.it` (PHP 8.3, MySQL): richieste dai moduli del sito, e-mail al cliente e ad ARNOplast con logo incorporato, risposte dal backoffice,
  lettura delle risposte dalla casella `richieste@arnoplast.it` (cron ogni 5 minuti attivo), preventivo con prezzi e PDF allegato.
- Sito di prova su `nuovo.arnoplast.it` con `.htaccess` e moduli collegati al backoffice.

## Fatto in locale, caricato nello zip ma da verificare sul server
- Tappa 4a (bozze, senza «Pubblica»): **Fiere**, **Testi** del sito per pagina (include l'Open Day), **Galleria**.
- Galleria: verificare che le miniature compaiano (in `config.php` ora c'è `assets_url`). Se sono rotte: aprire `https://nuovo.arnoplast.it/static/img/galleria/001-t.webp`.

## Da fare per primi, domani
1. Verifica miniature della Galleria (sopra) e prova delle schermate Fiere, Testi, Galleria sul server.
2. Pulizia di sicurezza: cancellare dal server i file `diag*.php` in `backoffice/public`; cancellare la cartella `backoffice_old` (vecchio `config.php` con password);
   svuotare `setup_token` in `config.php`; **cambiare la password della casella `noreply@arnoplast.it`** (era finita in chat).
3. Inglese: il cliente aveva chiesto di **ricordare** la verifica dello zip del sito (`arnoplast-sito-pubblicazione.zip`) per poi rendere indicizzata la versione inglese
   (togliere `noindex`, aggiungere sitemap e hreflang, rigenerare lo zip). Chiedere come è andata la verifica.
4. Proteggere `nuovo.arnoplast.it` dai motori di ricerca finché è in prova.

## Tappa 4 — prossime sezioni (una per volta)
- Video e tutorial; download e area riservata; storia dell'azienda e imballaggi (testi lunghi, non voci singole). Il catalogo viene più avanti.
- **4b «Pubblica»**: da decidere la consegna dei file sul server (FTP automatico da GitHub Actions — consigliata —, cPanel Git o zip manuale), l'accesso del backoffice a GitHub
  (chiave a permessi minimi creata dal cliente, mai in chat o nel repository) e dove vivono gli originali delle foto nuove nel repository.

## Da ricevere dal cliente
- Condizioni generali del preventivo, italiano e inglese (pagina «Condizioni PDF» del backoffice).
- Contenuti già in elenco in `docs/riepilogo-per-il-cliente.md` (8 fiere passate, listini/area riservata, tutorial, banner cookie, ecc.).
- IDML del Garden 2026 per correggere i refusi; conferma sulla pagina 29 (gamma filo).

## Regole da ricordare
- Una domanda alla volta, con risposte suggerite; mostrare sempre il risultato con schermate o prove.
- Dopo ogni caricamento sul server: controllare in MultiPHP Manager che `gestione.arnoplast.it` sia su PHP 8.3 (tende a tornare a 8.0).
- Zip del backoffice: si estrae sopra la cartella `backoffice`, senza toccare `config.php` e `var`. Nel `config.php`, ogni chiave va al livello principale e dentro `return [ ... ];`.
