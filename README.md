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

Hero a tutto schermo (home, linee, Azienda, Imballaggi) e animazioni: `site/static/css/motion.css` e lo script classico
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
(nome della sezione, numeri calcolati dal catalogo, link "Scopri"). Prima di tutto vengono le **fiere future** (vedi
"Fiere nell'hero della home" qui sotto), poi le sezioni di `HERO_SLIDES` in `arnoplast/site/build.py` (Agricoltura,
Garden, Raccolta olive, Azienda); finite le fiere la prima slide torna Agricoltura. Contatore ("01 / 06"), indicatori
ed etichette "N di M" seguono il numero di slide. foto, ritaglio verticale e testo
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
  (comandi manuali attivi). Senza JS: la prima slide e i link a tutte le slide (fiere comprese).

Sostituire la foto di una slide (per esempio Garden con una foto ad alta risoluzione):

1. mettere l'originale in `content/photos/garden-giorno.jpg` (ed eventualmente `garden-notte.jpg`);
2. aggiungerlo a `PHOTOS` in `tools/hero_images.py` (fascia del ritaglio verticale) e lanciare
   `python tools/hero_images.py garden-giorno garden-notte`;
3. in `arnoplast/site/build.py`: `HEROES["garden"] = ("garden-giorno", "garden-giorno-alto", "<alt>")`, se c'e'
   la notte `NIGHT_HEROES["garden"] = ("garden-notte", "garden-notte-alto")`, e `HERO_SIZES["garden"] =
   ("120vw", "100vw")`; poi togliere con `git rm` le foto vecchie non piu' usate (e da `tools/home_images.py`).

Aggiungere una slide: una voce in `HERO_SLIDES` con la chiave della pagina (deve avere un hero in `HEROES`).

#### Fiere nell'hero della home

Ogni fiera futura di `data/fiere.json` (fine non ancora passata alla data di build) e' una slide, prima delle sezioni,
dalla piu' vicina (data di inizio crescente), senza limite di numero. Sfondo: la foto della fiera a tutto schermo
(object-fit: cover, velo scuro per la leggibilita'; sui telefoni nessun ritaglio verticale, l'inquadratura viene dal
campo facoltativo `punto_focale`, valore CSS `object-position` come `"center"` (predefinito), `"30% 50%"`, `"left top"`)
oppure, finche' la foto non c'e', un fondo grafico in CSS (nero, fascia obliqua rossa del marchio, giorni della fiera in
grande a basso contrasto: nessuna immagine ne' richiesta). Nel pannello: "Ci trovi a", nome, date, luogo e paese,
descrizione breve (`descrizione_breve`, o la prima frase di `testo` entro 140 caratteri come nella pagina Fiere: mai
inventata) e "Scopri" verso `/it/fiere/#<id>`; il sito ufficiale (`link`) resta solo nella pagina Fiere. Etichette in
`arnoplast/site/i18n.py` (`hs.fair_kicker`, `hs.fair_more`, `hs.fair`). Dati: `fair_slides()` in build.py.

Aggiungere una fiera (il cliente avvisa per ogni nuova partecipazione):

1. una voce in `data/fiere.json` (campi in "Fiere" qui sotto);
2. la foto del cliente in `content/photos/fiere/<id>.jpg` (o il nome nel campo `immagine`), se c'e' gia';
3. `python tools/fiere_images.py <id>`: scrive in `site/static/img/fiere/` le versioni delle schede e quelle dell'hero
   `<id>-hero-800/1280/1600/2000/2560.webp` (mai oltre la risoluzione nativa, qualita' 82 che scende da sola fino a
   stare sotto i 250 KB; se una larghezza non ci sta nemmeno cosi' viene saltata con un avviso). La build genera da
   sola quelle che mancano; i WebP vanno versionati;
4. `python -m arnoplast.site.build` e pubblicazione. La foto arrivata dopo si aggiunge con i passi 2-4: la slide passa
   da sola dal fondo grafico alla foto.

Prestazioni: come per le altre slide, solo la prima e' nel markup come immagine vera (`fetchpriority="high"`,
`sizes="120vw"`, dimensioni); se la prima e' una fiera senza foto non c'e' nessuna immagine prioritaria (il fondo e'
CSS) e tutte le foto arrivano dopo l'evento `load`.

Scadenza: alla build le fiere finite spariscono dall'hero (e passano fra le "Fiere passate"), quindi serve la
**ricostruzione giornaliera** descritta sotto. Se il sito pubblicato non e' stato ricostruito, ogni slide fiera porta
`data-fair-end="AAAA-MM-GG"` e `hero-slides.js`, prima di avviare la rotazione, toglie quelle con fine precedente a oggi
(data locale di chi visita): contatore, indicatori ed etichette si rinumerano, e se cade la prima si attiva la seguente
caricandone subito la foto. Senza JS vale la build. Verifica: `tools/check_behaviour.js` sposta l'orologio del browser
(31/10/2026: EIMA e Interpoma + 4 sezioni; 20/11: Interpoma + 4; 1/12: solo le 4 sezioni, prima Agricoltura).

## Struttura del sito: menu, lingua, footer

Menu principale (ordine deciso dal cliente): Home · Azienda (Chi siamo, Imballaggi) · Fiere · Prodotti (Agricoltura,
Garden, Raccolta olive, Cataloghi e download) · Galleria · Video e Tutorial · Contatti; a destra il selettore IT | EN
e il link "Area riservata" (lucchetto). Voci e indirizzi: `menu_items()` in `arnoplast/site/build.py`; testi in
`arnoplast/site/i18n.py`. La voce attiva e' calcolata in build (`nav_state()`): `aria-current="page"` sulla pagina,
`aria-current="true"` sulla sezione (famiglie e prodotti ricadono sulla loro linea) e `is-active` sul genitore.

- Stile: `site/static/css/nav.css` (tutte le pagine; `main.css` resta sotto i 30 KB). Comportamento:
  `site/static/js/nav.js`, script classico che la build accoda ad `anim.js` pubblicato (una richiesta in meno).
- Desktop: tendine al passaggio del mouse, al clic e da tastiera (Invio/Spazio, frecce, Home/Fine, Esc chiude e
  torna al pulsante; il fuoco che esce chiude). Telefono: pannello con voci a fisarmonica, la sezione corrente
  parte aperta. Senza JS: telefono con tutte le voci aperte, desktop con tendine al passaggio del mouse e al fuoco.
- `/it/applicazioni/` non esiste piu': rimando a `/it/galleria/` (pagina con meta refresh e riga in `_redirects`).

Lingua: `/en/` e' per ora una sola pagina "English version coming soon" (noindex, fuori dalla sitemap) con header e
footer italiani. I template hanno gia' `lang` e un blocco `alternates` in `base.html` per i `hreflang`.

Footer (`site/templates/_footer.html`): logo, dati societari da `CONTACTS` (build.py, unica fonte anche per
Contatti, home e avvisi della richiesta), telefoni `tel:`, email, social (`SOCIAL`) e app (`APPS`) in nuova scheda
con icone SVG in linea, menu "light" a una riga (primo livello + Area riservata, Privacy policy = `PRIVACY_URL`,
Cookie = `/it/cookie/`, segnaposto) e "© <anno> ARNOplast". L'anno e le fiere usano la data di build: oggi, o
`ARNO_BUILD_DATE=AAAA-MM-GG` (test e anteprime).

### Fiere (`data/fiere.json`)

Una voce per fiera: `id` (unico; minuscole, cifre e trattini, es. `eima-2026`: e' anche l'ancora `/it/fiere/#<id>`),
`nome`, `luogo`, `paese`, `data_inizio` e `data_fine` (ISO `AAAA-MM-GG`, fine >= inizio), `anno`, `periodo` (testo da
mostrare se le date esatte non sono note, es. "Febbraio 2021"), `stand`, `testo` (breve, fedele alla fonte) e
`fonte_url_sito_vecchio`. Campi facoltativi:

- `immagine`: nome del file della foto in `content/photos/fiere/` (es. `"eima-2026.jpg"`; senza il campo vale
  `<id>.jpg|.jpeg|.png|.webp` se c'e'). La build genera da sola i WebP mancanti in `site/static/img/fiere/`
  (`<id>-480/800/1200.webp`, mai oltre la risoluzione nativa, qualita' 82, ogni file <= 150 KB; piu' le versioni per
  l'hero della home `<id>-hero-<larghezza>.webp`, vedi sopra; da versionare).
  Per rigenerarli dopo aver sostituito una foto: `python tools/fiere_images.py <id>`. Senza foto la scheda mostra una
  locandina tipografica (fondo scuro con il motivo rosso del marchio, date, nome, citta') delle stesse dimensioni;
- `descrizione_breve`: testo per le schede (anteprima, futura slide della home). Se manca si usa la prima frase di
  `testo` entro 170 caratteri, o il testo tagliato all'ultima parola intera con "…" (mai parole aggiunte);
- `link`: sito ufficiale della fiera, solo `https://` (nuova scheda, `rel="noopener noreferrer"`);
- `punto_focale`: inquadratura della foto nella slide dell'hero (`object-position`, es. `"30% 50%"`; predefinito
  `"center"`).

Campo non noto = stringa vuota: mai inventare. Per aggiungere una fiera basta una nuova voce (piu' l'eventuale foto in
`content/photos/fiere/`) e rilanciare la build: finche' `data_fine` non e' passata compare in "Prossime fiere"
(dalla piu' vicina, con foto o locandina, date, luogo, stand, testo, "Scrivici" e sito della fiera); poi passa da sola in
"Fiere passate", nell'anno giusto (dalla piu' recente). La build si ferma con un errore chiaro per campi mancanti, `id`
ripetuti o non validi, date non valide o incoerenti, link non https, foto indicata ma assente. Pagina:
`site/templates/fairs.html`, stile in `site/static/css/pages.css`.

Scadenza: il sito e' statico, quindi il passaggio fra le passate avviene alla build. Sul hosting statico serve una
**ricostruzione giornaliera programmata** (es. ogni notte `python -m arnoplast.site.build` e pubblicazione); in WordPress
sara' automatico (query sulle date). Nel frattempo le schede portano `data-fair-end="AAAA-MM-GG"` e
`site/static/js/fairs.js` (script classico che la build accoda ad `anim.js`, tra animazioni e menu: nessuna richiesta
in piu') nasconde con `hidden` quelle gia' finite secondo la data locale di chi visita; se non ne resta nessuna mostra
"Nessuna fiera in programma al momento.". Non sposta nulla fra le passate (lo fa la build successiva). Senza JS vale la
build. Per la home: le slide dell'hero (vedi "Fiere nell'hero della home"), che portano anch'esse `data-fair-end`.

### Imballaggi

`/it/azienda/imballaggi/`: hero giorno/notte con le foto del cliente (`imballaggi-giorno`/`-notte` in
`tools/hero_images.py`, `HEROES`/`NIGHT_HEROES` in build.py; larghezze fino a 2000 px e primo piano sfocato per
restare sotto i 250 KB). Il testo non esiste ancora: si scrive in `data/imballaggi.json` (`"paragrafi": ["...", ...]`)
senza toccare i template; finche' l'elenco e' vuoto la pagina mostra "Contenuto in arrivo" con i link a Contatti e
alla richiesta di preventivo.

## Sicurezza (intestazioni HTTP)
La build scrive `_headers` (formato Netlify / Cloudflare Pages): Content-Security-Policy con impronte sha256 degli script e dello stile inline, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS. Gli unici domini esterni ammessi (come frame, e solo dopo un clic) sono YouTube senza cookie, Vimeo, Facebook e Google Maps. Su un altro hosting (Apache/nginx, WordPress) le stesse intestazioni vanno riprodotte dal server. Controllo: `node tools/check_csp.js <cartella-costruita>` serve il sito con quelle intestazioni e prova tutte le funzioni con risorse esterne; esce con errore se c'e' una violazione.
