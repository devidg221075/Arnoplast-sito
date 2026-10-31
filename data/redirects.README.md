# Redirect dai vecchi URL (redirects.csv)
Colonne `old_path,new_path`, solo percorsi. Origine: i link interni della home di www.arnoplast.it (prodotti e
sezioni) piu' l'elenco delle pagine non-prodotto della mappa del sito vecchio (134 indirizzi, letti in locale):
chi siamo -> `/it/azienda/`, imballaggi -> `/it/azienda/imballaggi/`, fiere (elenchi, categorie, tag e articoli
`*.html`), open day -> `/it/fiere/`, galleria e `/applicazioni` -> `/it/galleria/`, area download, sfoglia catalogo
e pagine `/download/...` -> `/it/cataloghi/`, video e tutorial -> `/it/video/`, contatti -> `/it/contatti/`,
richiesta preventivo -> `/it/richiesta-preventivo/`, area riservata -> `/it/area-riservata/`, tutto `/en` e `/en/...`
-> `/en/` (pagina "coming soon" finche' non c'e' la versione inglese).
NON reindirizzati: `/privacy-policy-arno-plast` (il sito nuovo la linka: la fase WordPress deve mantenerla o ospitarla).
NON reindirizzati: `/arno-plast-la-nostra-storia-e-chi-siamo/notizie` (pagina "Notizie": da decidere col cliente).
NON reindirizzati: feed, `wp-json`, `xmlrpc.php`, `wp-content` (asset del vecchio tema, non pagine).
Il build copia il file in `dist/redirects.csv` e genera `dist/_redirects` (formato Netlify/Cloudflare, `old new 301`),
aggiungendo `/it/applicazioni/ /it/galleria/` (vecchio indirizzo del prototipo, che ha anche una pagina di rimando).
