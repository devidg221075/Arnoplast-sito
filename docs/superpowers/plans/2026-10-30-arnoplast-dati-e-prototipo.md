# ARNOplast: dati e prototipo, piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Estrarre i tre cataloghi PDF in un archivio dati controllabile e generare un prototipo statico navigabile in italiano, con lista richiesta di preventivo per singoli codici.

**Architecture:** Script Python estraggono i PDF in `data/catalog.json` (unica fonte). Un generatore Python con jinja2 produce il sito statico in `dist/` da quel JSON. La lista richiesta e la ricerca sono JavaScript vanilla senza dipendenze, testato con `node --test`.

**Tech Stack:** Python 3.13, jinja2, pytest, poppler (`pdftotext`, `pdfimages`, `pdftoppm`), Node 22 (`node --test`), HTML/CSS/JS vanilla, Playwright/Chromium per Lighthouse-like check.

**Spec:** `docs/superpowers/specs/2026-10-30-arnoplast-restyling-design.md`

**Fuori da questo piano:** fase 3 (inglese dai cataloghi EN, non ancora forniti) e fase 4 (tema + plugin WordPress, migrazione). Hanno bisogno del prototipo approvato e dei PDF inglesi, quindi avranno un proprio piano. Il modello dati ha già i campi `it`/`en`, per non rifare nulla.

## Global Constraints

- Lingue: italiano e inglese, URL paralleli `/it/…` e `/en/…`. In questo piano solo `/it/` con testi, ma la struttura `en` esiste.
- Sito di richiesta preventivo: niente carrello, pagamenti né prezzi pubblici.
- Richiesta a `info@arnoplast.it`; il modulo chiede nome, azienda, email, telefono, paese, note, consenso privacy (GDPR).
- Quantità in confezioni; la lista si salva in `localStorage`.
- Stile "moderno e audace": rosso ARNOplast, nero, bianco; accenti rosso Agricoltura, verde Garden, terzo accento Raccolta olive; mobile first.
- Sigle materiali: FE, FV, PA, PET, PE, PP, PVC, AL.
- Obiettivo prestazioni: Lighthouse mobile ≥ 90.
- Menu: Prodotti, Applicazioni, Video e tutorial, Azienda, Area riservata, Contatti, Richiesta preventivo. URL brevi, per esempio `/it/agricoltura/copripalo/prisma`.
- Nessun PDF o immagine originale viene modificato; le sorgenti stanno in `content/source/`.

## Review Focus

1. Codice con suffisso lettera (`1807-1310GZ`) o lo stesso codice in più prodotti (`1807-0751` compare in Prisma e Qubo): la lista richiesta deve contarlo una volta sola, sommando le quantità.
2. Quantità 0, negativa, decimale o vuota: viene rifiutata, mai inviata.
3. `localStorage` non disponibile o con JSON corrotto: il sito funziona con lista vuota, senza errori.
4. Invio con lista vuota: bloccato, con messaggio chiaro.
5. Testo con `<`, `&` o virgolette (nelle note del cliente o nelle descrizioni): mai interpretato come HTML, né a schermo né nel corpo email.
6. Prodotto senza righe articolo o raccolta olive senza confezione/pallet: la pagina si genera lo stesso e non mostra colonne vuote.
7. Testo inglese mancante: ricade sull'italiano, mai stringa vuota.

---

## File structure

- `content/source/` — i tre PDF italiani (sorgenti, in sola lettura)
- `arnoplast/catalog.py` — modello dati, caricamento, validazione
- `arnoplast/extract/pdf.py` — testo e immagini da una pagina PDF
- `arnoplast/extract/articles.py` — parsing righe tabella articoli
- `arnoplast/extract/build.py` — orchestrazione: PDF → `data/catalog.json`
- `arnoplast/extract/report.py` — report HTML di controllo per l'utente
- `data/catalog.json` — archivio dati (generato, poi corretto a mano)
- `arnoplast/site/build.py` — generatore sito statico
- `arnoplast/site/i18n.py` — stringhe interfaccia IT/EN
- `site/templates/*.html`, `site/static/css/main.css`, `site/static/js/{quote,search,main}.js`
- `tests/` e `tests_js/`

## Task 1: Scaffold e sorgenti

**Files:**
- Create: `requirements.txt`, `pytest.ini`, `.gitignore`, `README.md`, `arnoplast/__init__.py`
- Create: `content/source/{agricoltura,garden,olive}-it.pdf` (copie dei PDF caricati)

**Interfaces:**
- Produces: pacchetto importabile `arnoplast`; comando `pytest` funzionante.

- [ ] **Step 1:** Crea `requirements.txt` con `jinja2==3.1.6` e `pytest`; `pip install -r requirements.txt`.
- [ ] **Step 2:** Copia i PDF da `/root/.claude/uploads/8ddc4bf9-c6ce-5333-9dde-2e2b7adea772/` in `content/source/` con i nomi sopra. `.gitignore` esclude `dist/`, `__pycache__/`, `data/images/` ma non i PDF.
- [ ] **Step 3:** Test `tests/test_smoke.py::test_package_importable` (`import arnoplast`). Run: `pytest -q` → PASS.
- [ ] **Step 4:** Commit `chore: scaffold progetto e sorgenti cataloghi`.

## Task 2: Modello dati e validazione

**Files:**
- Create: `arnoplast/catalog.py`
- Test: `tests/test_catalog.py`

**Interfaces:**
- Produces:
  - `@dataclass Article(code: str, description: dict[str,str], conf: int|None, imb: int|None, pallet: int|None, kind: str)` con `kind` in `{"article","spare","optional"}`
  - `@dataclass Product(slug: str, name: dict, materials: list[str], features: list[str], dimensions: list[str], image: str|None, video_url: str|None, articles: list[Article])`
  - `@dataclass Family(slug: str, name: dict, products: list[Product])`
  - `@dataclass Line(slug: str, name: dict, accent: str, families: list[Family])`
  - `@dataclass Catalog(lines: list[Line])`
  - `load_catalog(path: Path) -> Catalog`, `save_catalog(c: Catalog, path: Path) -> None`
  - `validate(c: Catalog) -> list[str]` (messaggi di errore, vuota se ok)
  - `text(d: dict, lang: str) -> str` (ricade su `it` se `lang` manca o è vuoto)

- [ ] **Step 1:** Test `test_text_falls_back_to_italian` (`text({"it":"Prisma"},"en") == "Prisma"`), `test_validate_flags_bad_material` (`"XX"` non nelle sigle → errore), `test_validate_flags_duplicate_slug_in_family`, `test_validate_accepts_product_without_articles`, `test_roundtrip_save_load`.
- [ ] **Step 2:** Run `pytest tests/test_catalog.py -v` → FAIL (modulo mancante).
- [ ] **Step 3:** Implementa in `arnoplast/catalog.py`; serializzazione con `dataclasses.asdict` e ricostruzione esplicita.
- [ ] **Step 4:** Run → PASS. Commit `feat: modello dati catalogo`.

## Task 3: Parser righe articolo

**Files:**
- Create: `arnoplast/extract/__init__.py`, `arnoplast/extract/articles.py`
- Test: `tests/test_articles.py`

**Interfaces:**
- Consumes: `Article` da Task 2.
- Produces: `parse_article_rows(text: str) -> list[Article]`; `kind` è `"optional"` se la descrizione contiene `(optional)`, altrimenti `"article"`.

- [ ] **Step 1:** Test con righe reali dal catalogo, come stringhe:
  - `"1807-1351   Coprip Prisma 7x7 (senza viteria)     40   40   640"` → code `1807-1351`, conf 40, imb 40, pallet 640
  - `"1807-1357   Viteria per Cp (4pz.)    40   200   -"` → pallet `None`
  - `"1807-1310GZ Viteria GZ     40    200    -"` → code `1807-1310GZ`
  - `"2008-0016   Dado a griffe M6 (optional)   32  32  -"` → kind `optional`
  - `"2801-0404   Agevolatore EXCELSO ...   2pz"` (olive) → conf `None`, imb 2
  - riga di intestazione (`COD. DESCRIZIONE CONF. IMB. PALLET`) e righe di testo libero → ignorate.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implementa con regex sul codice `\d{4}-\d{4}[A-Z]{0,3}` seguito da descrizione e 0–3 numeri finali; descrizioni su più righe si uniscono se la riga successiva non inizia con un codice. **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat: parser tabelle articoli`.

## Task 4: Estrazione da PDF e costruzione archivio

**Files:**
- Create: `arnoplast/extract/pdf.py`, `arnoplast/extract/build.py`
- Create: `data/catalog.json` (generato), `data/images/…`
- Test: `tests/test_extract_build.py`

**Interfaces:**
- Consumes: `parse_article_rows`, modello Task 2.
- Produces:
  - `page_text(pdf: Path, page: int) -> str` (usa `pdftotext -layout -f N -l N`)
  - `page_image(pdf: Path, page: int, out: Path) -> Path` (usa `pdftoppm -r 110 -jpeg -f N -l N`)
  - `build_catalog(sources: dict[str, Path]) -> Catalog` con chiavi `agricoltura`, `garden`, `olive`.
  - Un file `arnoplast/extract/layout.py` con la mappa delle famiglie alle pagine, dai due indici del catalogo. Agricoltura: Copripalo 8, Placchette 26, Ferma-teli e reti 40, Moschettoni e tiranti 48, Ancoraggi/ganci/chiodi 54, Sistemi di legatura 62, Accessori/PET/ricambi 66. Garden: Testine 8, Filo nylon 22, Lame 30, Visiere 36, Abbigliamento 40, Accessori 44, Ancoraggi 50, Espositori 56. Olive: una sola famiglia, "Agevolatori e macchine".

- [ ] **Step 1:** Test `test_copripalo_prisma_has_expected_articles` (su `build_catalog`: il prodotto "Copripalo Prisma" contiene `1807-1351` con pallet 640 e `1807-1310GZ`), `test_every_line_has_families`, `test_validate_returns_no_errors_on_real_catalog`, `test_olive_articles_have_no_pallet`. Skippati se i PDF mancano.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implementa. Un prodotto = pagina con titolo in maiuscolo e almeno una riga articolo; materiali dalle sigle gialle (`PP`, `PA`…) del testo, caratteristiche e dimensioni dalle sezioni `CARATTERISTICHE` / `DIMENSIONI`. Immagine prodotto = render della pagina, ritagliata a mano in seguito se serve.
- [ ] **Step 4:** Esegui `python -m arnoplast.extract.build` per generare `data/catalog.json`. Run test → PASS.
- [ ] **Step 5:** Commit `feat: estrazione cataloghi in catalog.json`.

## Task 5: Report di controllo dati

**Files:**
- Create: `arnoplast/extract/report.py`, `data/review.html` (generato)
- Test: `tests/test_report.py`

**Interfaces:**
- Produces: `render_report(c: Catalog, out: Path) -> None`: una pagina HTML con, per ogni prodotto, la tabella estratta e il numero di pagina sorgente, per il confronto con il PDF.

- [ ] **Step 1:** Test `test_report_lists_every_article_code` e `test_report_escapes_html`. **Step 2:** Run → FAIL. **Step 3:** Implementa. **Step 4:** Run → PASS.
- [ ] **Step 5:** **Fermati e chiedi all'utente** di controllare `data/review.html`; correzioni direttamente in `data/catalog.json`. Commit `feat: report di controllo dati`.

## Task 6: Lista richiesta (JS)

**Files:**
- Create: `site/static/js/quote.js`
- Test: `tests_js/quote.test.js` (`node --test tests_js/`)

**Interfaces:**
- Produces (ES module):
  - `addItem(list: Item[], item: {code,description,qty}) -> Item[]`: unisce per `code` sommando `qty`
  - `setQty(list, code, qty) -> Item[]`, `removeItem(list, code) -> Item[]`
  - `isValidQty(n: unknown) -> boolean` (intero ≥ 1)
  - `load(storage) -> Item[]`, `save(storage, list) -> void` (tollerano storage assente o JSON corrotto → `[]`)
  - `emailBody(list: Item[], form: {name,company,email,phone,country,notes}) -> string` (testo semplice, una riga per codice)

- [ ] **Step 1:** Test: `addItem_merges_same_code`, `addItem_rejects_zero_negative_decimal_empty`, `load_returns_empty_on_corrupt_json`, `load_returns_empty_when_storage_throws`, `emailBody_keeps_html_chars_as_plain_text` (note con `<b>&"` restano invariate, non interpretate), `emailBody_one_line_per_code`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implementa. **Step 4:** Run → PASS. **Step 5:** Commit `feat: logica lista richiesta`.

## Task 7: Ricerca per codice (JS)

**Files:**
- Create: `site/static/js/search.js`
- Test: `tests_js/search.test.js`

**Interfaces:**
- Produces: `buildIndex(articles: {code,description,url}[]) -> Index`; `search(index, query: string, limit = 20) -> Result[]`. Ricerca senza distinzione maiuscole, senza trattino (`18071351` trova `1807-1351`), per parole nella descrizione.

- [ ] **Step 1:** Test `finds_code_with_and_without_dash`, `finds_by_description_word`, `empty_query_returns_empty`, `respects_limit`. **Step 2:** Run → FAIL. **Step 3:** Implementa. **Step 4:** Run → PASS. **Step 5:** Commit `feat: ricerca codici`.

## Task 8: Generatore del sito statico

**Files:**
- Create: `arnoplast/site/{__init__,build,i18n}.py`, `site/templates/{base,home,line,family,product,quote,page}.html`
- Test: `tests/test_site_build.py`

**Interfaces:**
- Consumes: `Catalog`, `text()`.
- Produces: `build_site(c: Catalog, out: Path, lang: str = "it") -> list[Path]`; scrive `/it/`, `/it/<linea>/<famiglia>/<prodotto>/index.html`, `/it/richiesta-preventivo/`, `/it/contatti/`, `/it/azienda/`, `/it/applicazioni/`, `/it/video/`, `/it/area-riservata/`, `search-index.json`. `i18n.t(key: str, lang: str) -> str` (ricade su `it`).
- Ogni riga articolo ha un pulsante con `data-code`, `data-description`; contatore nel header.

- [ ] **Step 1:** Test su catalogo piccolo di fixture: `test_builds_product_page_at_short_url`, `test_article_row_has_add_button_with_code`, `test_product_without_articles_still_builds`, `test_olive_page_hides_empty_pallet_column`, `test_descriptions_are_html_escaped`, `test_no_price_or_cart_wording` (assenza di "carrello", "€", "prezzo" nelle pagine generate).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implementa con jinja2 `autoescape=True`. **Step 4:** Run → PASS. **Step 5:** Commit `feat: generatore sito statico`.

## Task 9: Stile "moderno e audace" e comportamento pagina

**Files:**
- Create: `site/static/css/main.css`, `site/static/js/main.js`
- Modify: `site/templates/*.html` per classi e script

**Interfaces:**
- Consumes: `quote.js`, `search.js`, markup Task 8.
- Produces: variabili CSS `--red`, `--black`, `--white`, `--accent` (per linea via `data-line`); `main.js` collega i pulsanti `data-code`, aggiorna il contatore, gestisce il modulo e la ricerca.

- [ ] **Step 1:** Definisci token e layout mobile first: grandi foto a tutto schermo in home, titoli maiuscoli forti, etichette gialle per i materiali, animazioni solo `opacity/transform` e disattivate con `prefers-reduced-motion`.
- [ ] **Step 2:** Il modulo blocca l'invio se la lista è vuota o manca il consenso privacy; in prototipo l'invio genera un link `mailto:info@arnoplast.it` con `emailBody` (l'invio vero è nel piano WordPress).
- [ ] **Step 3:** Verifica a mano `python -m arnoplast.site.build` e apertura di `dist/it/` con `python -m http.server`: aggiunta di due codici, ricarica pagina (la lista resta), invio con lista vuota (bloccato).
- [ ] **Step 4:** Commit `feat: stile e comportamento prototipo`.

## Task 10: Redirect, prestazioni, controllo finale

**Files:**
- Create: `data/redirects.csv`, `tools/check_perf.js`
- Test: `tests/test_redirects.py`

**Interfaces:**
- Produces: `redirects.csv` con colonne `old_path,new_path`, per i 40 e più URL letti dalla home (es. `/catalogo-agricoltura-professionale/copripalo-arno-plast` → `/it/agricoltura/copripalo/`).

- [ ] **Step 1:** Test `test_every_redirect_target_exists_in_dist` e `test_no_redirect_loops`. **Step 2:** Run → FAIL. **Step 3:** Compila `redirects.csv`. **Step 4:** Run → PASS.
- [ ] **Step 5:** `tools/check_perf.js` apre le pagine home e un prodotto con Playwright/Chromium in emulazione mobile e riporta peso totale e tempo di caricamento; la soglia Lighthouse ≥ 90 si verifica con `npx lighthouse` solo se disponibile, altrimenti si riporta cosa non è stato misurato.
- [ ] **Step 6:** Commit `feat: redirect e controllo prestazioni`. Pubblica il prototipo per l'approvazione dell'utente.

---

## Self-review

- **Copertura spec:** struttura sito e menu (T8), richiesta preventivo (T6, T9), stile (T9), dati (T2–T5), redirect (T10), prestazioni (T10). Bilingue: modello e `i18n` pronti, testi inglesi e WordPress rimandati ai piani successivi, dichiarato in testa.
- **Coerenza tipi:** `Article`, `text()`, `parse_article_rows`, `build_site`, `addItem` usati con gli stessi nomi in tutti i task.
- **Review Focus:** ogni voce ha il test nel task proprietario (1→T6, 2→T6, 3→T6, 4→T9, 5→T6/T8, 6→T8, 7→T2).
- **Rischio nota:** il parsing delle tabelle (T3–T4) è la parte più fragile; per questo T5 chiede il controllo dell'utente prima di proseguire.
