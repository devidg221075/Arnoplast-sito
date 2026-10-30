"""Costruzione di data/catalog.json dai tre cataloghi PDF.

Uso: python -m arnoplast.extract.build

Una pagina prodotto può contenere 1-3 prodotti, impilati in verticale: ogni
prodotto parte da un titolo in maiuscolo (riconosciuto dall'altezza dei
caratteri nel `pdftotext -bbox`) e arriva fino al titolo successivo. In quella
fascia si leggono articoli (tabella), caratteristiche, dimensioni e sigle
materiale (le etichette gialle accanto al titolo). Le fasce senza righe
articolo (copertine di sezione, istruzioni di montaggio, infografiche) non
diventano prodotti.
"""
from __future__ import annotations

import re
import sys
import unicodedata
from collections.abc import Callable
from dataclasses import dataclass, field
from pathlib import Path

from arnoplast.catalog import (
    MATERIALS, Article, Catalog, Family, Line, Product, save_catalog, validate,
)
from arnoplast.extract.articles import (
    WILDCARD, strip_controls, CODE_RE, EAN_HEADER_RE, HEADER_RE, parse_article_rows, parse_ean_rows, parse_raw_rows,
)
from arnoplast.extract.layout import FAMILIES, LINES, SOURCE_DIR, family_for
from arnoplast.extract.pdf import PAGE_H, Word, page_count, page_image, page_text, page_words

ROOT = Path(__file__).resolve().parents[2]
DATA_DIR = ROOT / "data"
SOURCES: dict[str, Path] = {slug: SOURCE_DIR / f for slug, (_, _, f) in LINES.items()}

# altezza (pt) delle parole dei titoli prodotto, per catalogo
TITLE_HEIGHT = {"agricoltura": 22.4, "garden": 17.9, "olive": 22.4}
TITLE_TOL = 0.3
FOOTER_Y = 790          # sotto: piè di pagina con il nome della sezione
TAG_HEIGHT = (13.5, 19.5)  # sigle materiale: PP/PA… 18.8 pt, PET/PVC 14.2 pt

# titoli che nel PDF hanno testo sovrapposto/residuo (verificati sul render)
TITLE_OVERRIDES = {
    # garden p.53: sotto "CHIODO CUCI-RETE" è rimasto il testo "CHIODI FERMA TELO"
    ("garden", 53, "CHIODI CHIODO FERMA CUCI-RETE TELO"): "CHIODO CUCI-RETE",
}

_UNITS = {"cm", "mm", "mt", "kg", "lt", "ml"}
_SMALL = {"e", "di", "per", "in", "a", "con", "ad", "da", "del", "della", "al", "alla", "ed", "o"}


# --- nomi e slug -------------------------------------------------------------

def display_name(title: str) -> str:
    """'COPRIPALO PRISMA' -> 'Copripalo Prisma' (sigle materiale e misure invariate)."""
    out = []
    for i, w in enumerate(title.split()):
        letters = [c for c in w if c.isalpha()]
        alpha = "".join(letters).lower()
        if not letters or w != w.upper():
            out.append(w)
        elif any(c.isdigit() for c in w):
            # 8MM -> 8mm, ANCORA.30 -> Ancora.30, K4/B1/5x5 invariati
            if alpha in _UNITS:
                out.append(w.lower())
            elif len(letters) >= 3:
                k = next(j for j, c in enumerate(w) if c.isalpha())
                out.append(w[:k] + w[k].upper() + w[k + 1:].lower())
            else:
                out.append(w)
        elif i > 0 and w in MATERIALS:
            out.append(w)
        elif i > 0 and w.lower() in _SMALL:
            out.append(w.lower())
        elif w.lower().rstrip(".") in _UNITS:
            out.append(w.lower())
        elif len(letters) <= 2:  # sigle: FG, JR., K, H, S
            out.append(w)
        else:
            k = next(j for j, c in enumerate(w) if c.isalpha())
            out.append(w[:k] + w[k].upper() + w[k + 1:].lower())
    return " ".join(out)


def slugify(name: str) -> str:
    ascii_ = unicodedata.normalize("NFKD", name).encode("ascii", "ignore").decode()
    return re.sub(r"[^a-z0-9]+", "-", ascii_.lower()).strip("-")


# --- titoli e sigle ----------------------------------------------------------

@dataclass
class Title:
    text: str
    y0: float
    words: list[Word] = field(default_factory=list)


def find_titles(words: list[Word], line: str) -> list[Title]:
    h = TITLE_HEIGHT[line]
    big = [w for w in words if abs(w.height - h) <= TITLE_TOL and w.y0 < FOOTER_Y]
    rows: list[list[Word]] = []
    for w in sorted(big, key=lambda w: (w.y0, w.x0)):
        if rows and abs(rows[-1][0].y0 - w.y0) <= 4:
            rows[-1].append(w)
        else:
            rows.append([w])
    if not rows:
        # pagina senza titoli prodotto (es. garden p.55 "ESPOSITORI", 47 pt):
        # l'intestazione di pagina, cioè la riga più grande nei primi 100 pt,
        # fa da titolo dell'intera pagina; se non ci sono articoli, la fascia
        # viene comunque scartata
        top = [w for w in words if w.y0 < 100 and w.height >= 30]
        if top:
            hmax = max(w.height for w in top)
            rows = [[w for w in top if abs(w.height - hmax) <= TITLE_TOL]]
    titles = []
    for row in rows:
        row.sort(key=lambda w: w.x0)
        texts: list[str] = []
        for w in row:
            # titoli con ombra/duplicato: stessa parola ripetuta subito a destra
            if texts and texts[-1] == w.text:
                continue
            texts.append(w.text)
        titles.append(Title(" ".join(texts), min(w.y0 for w in row), row))
    return titles


def find_materials(words: list[Word], title: Title) -> list[str]:
    title_ids = {id(w) for w in title.words}
    right = max(w.x1 for w in title.words)
    tags = [w for w in words
            if w.text in MATERIALS and id(w) not in title_ids
            and TAG_HEIGHT[0] <= w.height <= TAG_HEIGHT[1]
            and abs(w.y0 - title.y0) <= 12 and w.x0 > right]
    out: list[str] = []
    for w in sorted(tags, key=lambda w: w.x0):
        if w.text not in out:
            out.append(w.text)
    return out


# --- caratteristiche / dimensioni --------------------------------------------

def parse_sections(raw: str) -> tuple[list[str], list[str]]:
    """Elenchi puntati sotto CARATTERISTICHE e DIMENSIONI (dal testo -raw)."""
    features: list[str] = []
    dims: list[str] = []
    target: list[str] | None = None
    open_item = False
    for line in raw.splitlines():
        s = line.strip()
        up = s.upper()
        if up.startswith("CARATTERISTICHE"):
            target, open_item = features, False
        elif up.startswith("DIMENSIONI"):
            target, open_item = dims, False
        elif HEADER_RE.search(s) or EAN_HEADER_RE.search(s) or CODE_RE.match(s):
            target, open_item = None, False
        elif target is not None and s.startswith("•"):
            item = s.lstrip("•").strip()
            if item:
                target.append(item)
                open_item = True
        elif (target is not None and open_item and re.search(r"[a-zà-ú]", s)
              and not s.startswith("(")):
            target[-1] = f"{target[-1]} {s}"
        else:
            open_item = False
    return [_tidy(f) for f in features], [_tidy(d) for d in dims]


def _tidy(s: str) -> str:
    return re.sub(r"\s+", " ", s).strip()


# --- articoli ----------------------------------------------------------------

def _all_none(a: Article) -> bool:
    return a.conf is None and a.imb is None and a.pallet is None


def extract_articles(line: str, pdf: Path, page: int, box: tuple[float, float],
                     words: list[Word] | None = None) -> list[Article]:
    layout = page_text(pdf, page, box)
    if line == "olive":  # sola colonna IMB. ("2pz"): basta il testo -layout
        return parse_article_rows(layout)
    words = words if words is not None else page_words(pdf, page)
    if line == "garden":
        return _apply_columns(parse_ean_rows(layout), bbox_columns(words, box))
    rows = parse_article_rows(layout)
    # agricoltura. 1) le righe che -layout mescola con le etichette dei disegni
    # si leggono dal testo -raw (righe complete a 3 colonne), per codice; uno
    # stesso codice può comparire più volte (es. 1802-0410 a pag. 53)
    raw: dict[str, list[Article]] = {}
    for r in parse_raw_rows(page_text(pdf, page, box, raw=True), ncols=3):
        raw.setdefault(r.code, []).append(r)
    out: list[Article] = []
    used: dict[str, int] = {}
    for a in rows:
        k = used.get(a.code, 0)
        rs = raw.get(a.code, [])
        if k < len(rs):
            out.append(rs[k])
        elif not rs:
            out.append(a)
        # else: occorrenza -layout in più (testo sovrapposto): scartata
        used[a.code] = k + 1
    out += [r for code, rs in raw.items() for r in rs[used.get(code, 0):]]
    # 2) i valori CONF./IMB./PALLET si assegnano alla colonna per posizione x
    # (bbox), così una colonna vuota (es. CONF. assente) non sposta le altre
    return _apply_columns(out, bbox_columns(words, box))


def _apply_columns(rows: list[Article],
                   cols: dict[str, list[tuple[int | None, int | None, int | None]]]
                   ) -> list[Article]:
    """Sostituisce CONF./IMB./PALLET con quelli letti per posizione (k-esima
    occorrenza del codice), se la lettura per posizione ha almeno un numero."""
    taken: dict[str, int] = {}
    for a in rows:
        k = taken.get(a.code, 0)
        found = cols.get(a.code, [])
        if k < len(found) and any(v is not None for v in found[k]):
            a.conf, a.imb, a.pallet = found[k]
        taken[a.code] = k + 1
    return rows


COLUMN_NAMES = ("CONF.", "IMB.", "PALLET")
# intestazione -> colonna (Garden: BOX = imballo)
HEADER_COLUMNS = {"CONF.": "CONF.", "CONF": "CONF.", "IMB.": "IMB.", "BOX": "IMB.",
                  "PALLET": "PALLET"}
COLUMN_TOL = 16  # pt; le colonne distano ~30-37 pt


def bbox_columns(words: list[Word], box: tuple[float, float]
                 ) -> dict[str, list[tuple[int | None, int | None, int | None]]]:
    """Valori CONF./IMB./PALLET per codice (una tupla per occorrenza, dall'alto).

    Ogni parola della riga del codice (stessa linea e stesso corpo, così le
    etichette dei disegni sovrapposte restano fuori) va alla colonna
    d'intestazione col centro più vicino, se entro COLUMN_TOL; testi non
    numerici (es. "BOB.", "600KG") e "-" danno None.
    """
    y0, y1 = box
    inside = [w for w in words if y0 <= w.y0 < y1]
    headers: list[tuple[float, dict[str, float]]] = []
    for w in inside:
        name = HEADER_COLUMNS.get(w.text)
        if name:
            for y, cols in headers:
                if abs(y - w.y0) <= 3:
                    cols[name] = (w.x0 + w.x1) / 2
                    break
            else:
                headers.append((w.y0, {name: (w.x0 + w.x1) / 2}))
    out: dict[str, list[tuple[int | None, int | None, int | None]]] = {}
    for code in sorted(inside, key=lambda w: (w.y0, w.x0)):
        if not CODE_RE.fullmatch(code.text):
            continue
        above = [h for h in headers if h[0] < code.y0]
        if not above:
            continue
        cols = max(above, key=lambda h: h[0])[1]
        vals: dict[str, list[str]] = {}
        for w in inside:
            cx = (w.x0 + w.x1) / 2
            if (abs(w.y0 - code.y0) <= 2 and abs(w.height - code.height) <= 2
                    and w.x0 > code.x1):
                name = min(cols, key=lambda n: abs(cols[n] - cx))
                if abs(cols[name] - cx) <= COLUMN_TOL:
                    vals.setdefault(name, []).append(w.text)
        if not vals:
            continue
        nums = []
        for n in COLUMN_NAMES:
            t = " ".join(vals.get(n, []))
            nums.append(int(t) if t.isdigit() else None)
        out.setdefault(code.text, []).append((nums[0], nums[1], nums[2]))
    return out


def appendix_articles(pdf: Path, page: int) -> list[Article]:
    """Appendice 'Ricambi e viterie' (agricoltura p.68): tabella su due colonne, CONF. sola."""
    rows = parse_raw_rows(page_text(pdf, page, raw=True), ncols=1)
    return [Article(a.code, a.description, a.conf, None, None, "spare") for a in rows]


# --- costruzione -------------------------------------------------------------

@dataclass
class _Built:
    product: Product
    page: int


def build_line(slug: str, pdf: Path, images_dir: Path | None = None) -> Line:
    name, accent, _ = LINES[slug]
    families = {fname: Family(slugify(fname), {"it": fname}) for _, fname in FAMILIES[slug]}
    last: dict[str, _Built] = {}
    for page in range(1, page_count(pdf) + 1):
        fname = family_for(slug, page)
        if fname is None:
            continue
        fam = families[fname]
        words = page_words(pdf, page)
        titles = find_titles(words, slug)
        for i, t in enumerate(titles):
            y_end = titles[i + 1].y0 - 4 if i + 1 < len(titles) else PAGE_H
            box = (max(0.0, t.y0 - 6), y_end)
            title = TITLE_OVERRIDES.get((slug, page, t.text), t.text)
            if slug == "agricoltura" and title == "RICAMBI E VITERIE":
                articles = appendix_articles(pdf, page)
            else:
                articles = extract_articles(slug, pdf, page, box, words)
            if not articles:
                continue
            pname = display_name(title)
            prev = last.get(fname)
            if prev and prev.product.name["it"] == pname and prev.page == page - 1:
                # stesso titolo sulla pagina successiva: continuazione
                prev.product.articles.extend(articles)
                prev.page = page
                continue
            features, dims = parse_sections(page_text(pdf, page, box, raw=True))
            pslug = slugify(pname)
            taken = {p.slug for p in fam.products}
            n = 2
            base = pslug
            while pslug in taken:
                pslug, n = f"{base}-{n}", n + 1
            image = f"images/{slug}/{pslug}.jpg"
            if images_dir is not None:
                page_image(pdf, page, Path(images_dir) / slug / f"{pslug}.jpg", box)
            product = Product(pslug, {"it": pname}, find_materials(words, t), features, dims,
                              image, None, articles)
            fam.products.append(product)
            last[fname] = _Built(product, page)
    return Line(slug, {"it": name}, accent, [f for f in families.values() if f.products])


def build_catalog(sources: dict[str, Path], images_dir: Path | None = None) -> Catalog:
    """Catalogo completo dai PDF (`sources`: agricoltura, garden, olive)."""
    return Catalog([build_line(slug, Path(sources[slug]), images_dir)
                    for slug in LINES if slug in sources])


def suspect_rows(catalog: Catalog) -> list[tuple[str, str]]:
    """(nome prodotto, codice) delle righe da rivedere: nessun valore CONF./IMB./PALLET
    leggibile, oppure codice "jolly" non ordinabile (es. "1902-02…")."""
    return [(p.name["it"], a.code)
            for l in catalog.lines for f in l.families for p in f.products
            for a in p.articles if _all_none(a) or WILDCARD in a.code]


FULL_CODE_RE = re.compile(r"(?<![\d-])(\d{4}-\d{4}[A-Z]{0,3})(?![A-Za-z\d])")


def _pdf_pages(pdf: Path) -> list[str]:
    return [page_text(pdf, n) for n in range(1, page_count(pdf) + 1)]


def uncovered_codes(catalog: Catalog, sources: dict[str, Path],
                    pages: Callable[[Path], list[str]] | None = None
                    ) -> list[tuple[str, int, str]]:
    """(chiave PDF, pagina, codice) di ogni codice completo presente nel testo
    di una pagina sorgente ma assente dalla linea corrispondente del catalogo.

    `pages` (pdf -> testo per pagina) serve ai test; di default pdftotext.
    """
    pages = pages or _pdf_pages
    out: list[tuple[str, int, str]] = []
    for key, pdf in sources.items():
        line = next((l for l in catalog.lines if l.slug == key), None)
        have = {a.code for f in (line.families if line else []) for p in f.products
                for a in p.articles}
        for n, txt in enumerate(pages(Path(pdf)), 1):
            for code in dict.fromkeys(FULL_CODE_RE.findall(strip_controls(txt))):
                if code not in have:
                    out.append((key, n, code))
    return out


def main() -> int:
    catalog = build_catalog(SOURCES, DATA_DIR / "images")
    errors = validate(catalog)
    for e in errors:
        print("ERRORE:", e, file=sys.stderr)
    save_catalog(catalog, DATA_DIR / "catalog.json")
    for l in catalog.lines:
        prods = [p for f in l.families for p in f.products]
        print(f"{l.slug}: {len(l.families)} famiglie, {len(prods)} prodotti, "
              f"{sum(len(p.articles) for p in prods)} articoli")
    for name, code in suspect_rows(catalog):
        print(f"SOSPETTA: {name} {code}")
    for key, page, code in uncovered_codes(catalog, SOURCES):
        print(f"NON COPERTO: {key} p.{page} {code}")
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
