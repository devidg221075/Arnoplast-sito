# ARNOplast - catalogo e prototipo sito

Progetto per la ristrutturazione del sito ARNOplast: dati estratti dai
cataloghi PDF (agricoltura, garden, olive) e generatore di sito statico.

## Struttura

- `content/source/` - PDF sorgente dei cataloghi
- `arnoplast/` - pacchetto Python (dati e generatore)
- `tests/` - test automatici
- `docs/` - specifiche e piani

## Test

    pip install -r requirements.txt
    python3 -m pytest -q        # Python
    npm run test:js             # JavaScript (node --test, senza dipendenze)

Controlli nel browser (richiedono Playwright con Chromium e un server che
serva la cartella costruita, vedi "Build"):

    node tools/check_behaviour.js http://localhost:8000   # lista richiesta, modulo, ricerca, 390 px, senza JS
    node tools/check_perf.js http://localhost:8000        # peso, LCP e CLS in emulazione mobile

## Dati del catalogo

    python -m arnoplast.extract.build                # scrive data/catalog.generated.json
    python -m arnoplast.extract.build --force        # sovrascrive data/catalog.json (e rigenera le immagini)
    python -m arnoplast.extract.build --images-only  # rigenera solo data/images/, senza toccare i cataloghi

`data/catalog.json` e' versionato e puo' contenere correzioni manuali: l'estrazione
non lo sovrascrive mai senza `--force`. Il risultato "grezzo" va in
`data/catalog.generated.json` per un confronto. Le immagini prodotto in
`data/images/` (non versionate) si generano solo con `--images-only` o `--force`:
senza questo passo il sito viene costruito senza foto (la build lo segnala su
stderr). Richiede poppler (`pdftotext`, `pdftoppm`, `pdfinfo`) e i PDF in
`content/source/`. `python -m arnoplast.extract.report` scrive `data/review.html`
(controllo dei dati, con la sezione "Codice ripetuto con dati diversi").

## Build

    python -m arnoplast.site.build

Scrive il sito statico in `dist/` (non versionata; la build accetta come
destinazione solo `dist/` o cartelle fuori dal repo). Include miniature WebP
(Pillow), `robots.txt` e `sitemap.xml`. I link sono assoluti (`/it/...`): il sito
va servito dalla radice di un dominio, ad esempio:

    (cd dist && python3 -m http.server 8000)

Le foto della home (gia' in `site/static/img/home/`) si rigenerano con
`python tools/home_images.py <cartella-temporanea>` (poppler, ImageMagick, Pillow con WebP).
