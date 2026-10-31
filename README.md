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
    node tools/check_perf.js http://localhost:8000 [/altra/pagina/ ...]  # peso, LCP e CLS in emulazione mobile

## Dati del catalogo

    python -m arnoplast.extract.build                # scrive data/catalog.generated.json
    python -m arnoplast.extract.build --force        # sovrascrive data/catalog.json (e rigenera le immagini)
    python -m arnoplast.extract.build --images-only  # rigenera solo data/images/, senza toccare i cataloghi

`data/catalog.json` e' versionato e puo' contenere correzioni manuali: l'estrazione
non lo sovrascrive mai senza `--force`. Il risultato "grezzo" va in
`data/catalog.generated.json` per un confronto. Le immagini prodotto in
`data/images/` (non versionate) si generano solo con `--images-only` o `--force`:
senza questo passo il sito viene costruito senza foto (la build lo segnala su
stderr). Richiede poppler (`pdftotext`, `pdftoppm`, `pdfinfo`, `pdftohtml`) e i PDF in
`content/source/`.

Foto prodotto: lo stesso passo (`--images-only` o `--force`) scrive anche, accanto a ogni
`<slug>.jpg` (la fascia di pagina del catalogo), `<slug>.photo.png`, la sola foto del prodotto
ritagliata ai pixel nativi (`arnoplast/extract/photos.py`: immagine incorporata piu' grande della
fascia, letta con `pdftohtml -xml`, orientata come in pagina, fondo quasi bianco portato a bianco).
I dati di ogni ritaglio (pagina, zona in pt, risoluzione, eventuale motivo di controllo) vanno in
`data/photos.json`, versionato; le correzioni fatte a occhio sul foglio di contatto stanno in
`PHOTO_OVERRIDES`/`PHOTO_CHECKED` di `photos.py`. I prodotti con foto da controllare compaiono nel
report (`data/review.html`, "Foto da controllare"). Le foto non sono versionate: come le
miniature, la build del sito le converte in WebP (`dist/photos/`, larghezze 360/640 mai oltre i
pixel nativi) e, se mancano, ricade sulla miniatura della pagina del catalogo.

Video: `data/videos.json` ("linea/famiglia/prodotto" -> link YouTube letti dai QR code dei
cataloghi) e' letto dalla build e non tocca `catalog.json`; un eventuale `video_url` del catalogo
si aggiunge senza doppioni. Una chiave che non corrisponde a un prodotto ferma la build con un
errore. Nel sito ci sono solo link (nuova scheda), nessun embed: nessuna richiesta esterna. `python -m arnoplast.extract.report` scrive `data/review.html`
(controllo dei dati, con la sezione "Codice ripetuto con dati diversi").

## Build

    python -m arnoplast.site.build

Scrive il sito statico in `dist/` (non versionata; la build accetta come
destinazione solo `dist/` o cartelle fuori dal repo). Include foto e miniature WebP
(Pillow), `robots.txt` e `sitemap.xml`. Le pagine famiglia sono griglie di schede prodotto
(foto, nome, sigle materiale, codici, video; stile in `site/static/css/cards.css`, caricato solo
li'); la pagina prodotto mostra la foto ritagliata e il link "Pagina del catalogo" alla pagina
intera, con tabelle e disegni tecnici. I link sono assoluti (`/it/...`): il sito
va servito dalla radice di un dominio, ad esempio:

    (cd dist && python3 -m http.server 8000)

Per una copia da sfogliare senza server (doppio clic su `index.html`, anche da
chi non ha Python):

    python tools/offline_site.py <cartella-fuori-dal-repo>

Crea la cartella e il relativo `.zip`, con link relativi e JS in un solo script;
il sito in `dist/` non cambia.

Hero a tutto schermo (home, linee, Azienda) e animazioni: `site/static/css/motion.css` e lo script classico
`site/static/js/anim.js` (ingressi allo scroll, contatori, parallasse di riserva, transizioni tra pagine);
con `prefers-reduced-motion: reduce` o senza JS il contenuto e' subito visibile e fermo.

Le foto della home e degli hero (gia' in `site/static/img/home/`) si rigenerano con
`python tools/home_images.py <cartella-temporanea>` (poppler, ImageMagick, Pillow con WebP).
