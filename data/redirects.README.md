# Redirect dai vecchi URL (redirects.csv)
Colonne `old_path,new_path`, solo percorsi; origine: i link interni letti dalla home di www.arnoplast.it (35 righe).
NON reindirizzati: `/privacy-policy-arno-plast` (il modulo preventivo la linka: la fase WordPress deve mantenerla o ospitarla).
NON reindirizzati: `/en` e `/en/photo-gallery-accessories-installations` (fase inglese), feed, `wp-json`, `xmlrpc.php`.
NON reindirizzati: `wp-content` (asset del vecchio tema, non pagine).
Il build copia il file in `dist/redirects.csv` e genera `dist/_redirects` (formato Netlify/Cloudflare, `old new 301`).
