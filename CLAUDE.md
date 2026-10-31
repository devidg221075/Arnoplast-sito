# Regole di lavoro per questo progetto (ARNOplast, restyling del sito)

Le ha dettate il cliente: valgono per questo sito e vanno applicate anche a qualsiasi altro sito che si faccia con lui.

## Come lavorare con il cliente
- Rispondere in italiano.
- Fare **una domanda alla volta**, suggerendo le risposte (la consigliata per prima).
- Il cliente guarda le pagine nel browser e annota: mostrare sempre il risultato con schermate o con la copia sfogliabile (`tools/offline_site.py`) prima di dare un lavoro per concluso.

## Contenuti del sito vecchio (regola fissa)
- Prima di progettare o riordinare qualsiasi pagina, **leggere con attenzione tutti i contenuti del sito vecchio**, esclusa la sezione prodotti (che viene dai cataloghi PDF): pagine, articoli, fiere, eventi, gallerie, video, download, moduli, testi del piè di pagina e delle pagine di servizio, versione inglese compresa.
- **Nessun contenuto del sito vecchio va perso**: va riportato nel sito nuovo, anche migliorandone disposizione e gestione. Le voci che non trovano posto vanno elencate al cliente, non scartate in silenzio.
- Poi si affronta **pagina per pagina** con il cliente. L'inventario dei contenuti e il loro stato (riportato / da decidere / bloccato) sta in `docs/inventario-contenuti-sito-vecchio.md`.
- Non aggirare mai i blocchi anti-bot del sito vecchio: niente intestazioni false, niente scansioni aggressive. Le pagine bloccate si chiedono al cliente.

## Contenuti e dati
- Non inventare testi, numeri, clienti o certificazioni. Testi legali (privacy, cookie): collegare i documenti ufficiali del cliente, non scriverli.
- Le correzioni del cliente ai dati stanno in `data/catalog.json`: non va mai sovrascritto dall'estrazione (vedi README).
- Foto: solo quelle del cliente. Quelle incollate nella chat sono ridotte a 2000 px: per l'alta risoluzione il cliente le allega come file (anche zip).
- Rispetto di velocita' e accessibilita': `main.css` sotto i 30 KB, CLS 0, nessuna richiesta esterna, nessun font web, `prefers-reduced-motion` rispettato.
