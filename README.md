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

    node tools/check_behaviour.js http://localhost:8000   # lista richiesta, modulo, ricerca, carosello home, 390/1440 px, senza JS
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

Le foto della home e degli hero sono gia' in `site/static/img/home/` (WebP versionati):

- foto originali del cliente (`content/photos/*.jpg`, 4000 px, JPEG q93): hero di Agricoltura e Azienda, giorno e
  notte. Si rigenerano con `python tools/hero_images.py [nome ...]`: larghezze 800-3200 e ritaglio verticale per i
  telefoni (`-alto-420/840/1260`), ogni file <= 250 KB (la qualita' scende da sola se serve). In `PHOTOS` dello
  script: fascia del ritaglio verticale, eventuale sfocatura graduale del primo piano e qualita';
- foto estratte dai cataloghi PDF (Garden, Olive, filiera, schede linea): `python tools/home_images.py
  <cartella-temporanea>` (poppler, ImageMagick, Pillow con WebP).

Giorno e notte: dove esiste la variante serale (`NIGHT_HEROES` in `arnoplast/site/build.py`) dalle 19 alle 7, ora
locale del visitatore, si vede la foto notturna; `?luce=notte` o `?luce=giorno` nell'indirizzo forza la scelta.
Lo script in linea di `site/templates/_hero.html` sceglie prima che la foto si carichi; senza JS resta il giorno.

### Carosello dell'hero della home

Titolo, sottotitolo, pulsanti, ricerca e i quattro dati restano fissi; ruotano la foto di sfondo e una didascalia
(nome della sezione, numeri calcolati dal catalogo, link "Scopri"). Slide e ordine: `HERO_SLIDES` in
`arnoplast/site/build.py` (oggi Agricoltura, Garden, Raccolta olive, Azienda); foto, ritaglio verticale e testo
alternativo vengono da `HEROES`, la variante serale da `NIGHT_HEROES`, gli attributi `sizes` da `HERO_SIZES`: la
pagina della sezione e la slide usano sempre la stessa foto. Codice: `site/static/js/hero-slides.js` (script
classico, solo sulla home) e la sezione "Carosello" di `site/static/css/home.css`.

- Prestazioni: solo la prima foto e' un'immagine vera (`fetchpriority="high"`); le altre sono dati
  (`data-src`, `data-srcset`, `data-night-srcset`) che lo script copia negli attributi veri dopo l'evento `load`,
  a browser inattivo (o subito al primo comando). Nessun preload, `sizes` e dimensioni gia' nel markup (CLS 0).
- Comportamento: cambio ogni 6,5 s con dissolvenza e lento zoom; precedente/successiva, indicatori con
  avanzamento, pulsante pausa/riprendi, frecce da tastiera col fuoco nel carosello, swipe sui telefoni. Si ferma
  col mouse sopra, col fuoco dentro, mentre si scrive nella ricerca e con la scheda nascosta. `aria-live` e' "off"
  durante la rotazione e "polite" in pausa. Con `prefers-reduced-motion: reduce` nessuna rotazione ne' movimento
  (comandi manuali attivi). Senza JS: la prima foto e i link alle quattro sezioni.

Sostituire la foto di una slide (per esempio Garden con una foto ad alta risoluzione):

1. mettere l'originale in `content/photos/garden-giorno.jpg` (ed eventualmente `garden-notte.jpg`);
2. aggiungerlo a `PHOTOS` in `tools/hero_images.py` (fascia del ritaglio verticale) e lanciare
   `python tools/hero_images.py garden-giorno garden-notte`;
3. in `arnoplast/site/build.py`: `HEROES["garden"] = ("garden-giorno", "garden-giorno-alto", "<alt>")`, se c'e'
   la notte `NIGHT_HEROES["garden"] = ("garden-notte", "garden-notte-alto")`, e `HERO_SIZES["garden"] =
   ("120vw", "100vw")`; poi togliere con `git rm` le foto vecchie non piu' usate (e da `tools/home_images.py`).

Aggiungere una slide: una voce in `HERO_SLIDES` con la chiave della pagina (deve avere un hero in `HEROES`).
