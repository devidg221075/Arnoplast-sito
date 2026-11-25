"""Lettura delle tabelle articoli dai file IDML di InDesign.

Un IDML è uno zip di XML: ogni tabella del catalogo (Cod. EAN, Art., Descrizione,
Conf., Box) è una <Table> dentro una storia. Qui si leggono le righe, senza
toccare il catalogo; l'abbinamento italiano/inglese avviene sul codice articolo.
"""
from __future__ import annotations

import re
import zipfile
from defusedxml import ElementTree as ET
from dataclasses import dataclass

CODE = re.compile(r"\d{4}-\d{4}[A-Z]?")
EAN = re.compile(r"\d{13}")

# intestazioni delle colonne, in tutte le varianti trovate nei cataloghi
_HEAD = {
    "ean": ("cod. ean", "ean"),
    "code": ("art.", "art", "item", "item no.", "code"),
    "conf": ("conf.", "conf", "pack", "pack.", "packing"),
    "box": ("box", "carton"),
}


@dataclass
class Row:
    story: str
    code: str
    ean: str
    description: str
    conf: int | None
    box: int | None
    spare: bool  # tabella «parti di ricambio»


def _text(el: ET.Element) -> str:
    return "".join(x.text or "" for x in el.iter("Content")).strip()


def _int(v: str) -> int | None:
    v = v.replace(".", "").strip()
    return int(v) if v.isdigit() else None


def _column(head: list[str], kind: str) -> int | None:
    for i, h in enumerate(head):
        if h.strip().lower() in _HEAD[kind]:
            return i
    return None


def read_rows(path: str) -> list[Row]:
    """Righe di tutte le tabelle articoli dell'IDML, nell'ordine del file."""
    rows: list[Row] = []
    with zipfile.ZipFile(path) as z:
        names = sorted(n for n in z.namelist() if re.search(r"Stories/Story_.*\.xml$", n))
        for name in names:
            root = ET.fromstring(z.read(name))
            story = name.rsplit("Story_", 1)[1][:-4]
            for tb in root.iter("Table"):
                cols = int(tb.get("ColumnCount") or 0)
                cells: dict[tuple[int, int], str] = {}
                for c in tb.findall("Cell"):
                    a, b = c.get("Name").split(":")
                    cells[(int(a), int(b))] = _text(c)
                head = [cells.get((i, 0), "") for i in range(cols)]
                ic, ie = _column(head, "code"), _column(head, "ean")
                if ic is None:
                    continue
                icf, ib = _column(head, "conf"), _column(head, "box")
                idesc = next((i for i, h in enumerate(head) if h.lower().startswith("descri")), None)
                spare = "ricambio" in " ".join(head).lower() or "spare" in " ".join(head).lower()
                for r in range(1, int(tb.get("BodyRowCount") or 0) + 1):
                    code = cells.get((ic, r), "")
                    if not CODE.fullmatch(code):
                        continue
                    ean = cells.get((ie, r), "") if ie is not None else ""
                    rows.append(Row(
                        story=story,
                        code=code,
                        ean=ean if EAN.fullmatch(ean) else "",
                        description=cells.get((idesc, r), "") if idesc is not None else "",
                        conf=_int(cells.get((icf, r), "")) if icf is not None else None,
                        box=_int(cells.get((ib, r), "")) if ib is not None else None,
                        spare=spare,
                    ))
    return rows


def pair(it_rows: list[Row], en_rows: list[Row]) -> tuple[list[tuple[Row, Row | None]], list[str]]:
    """Collega italiano e inglese riga per riga.

    I due IDML hanno la stessa struttura: la riga n dell'italiano è la riga n
    dell'inglese, e il codice articolo lo conferma. Il codice da solo non basta,
    perché i ricambi compaiono in più schede. Se l'ordine non torna, il problema
    viene segnalato e la riga resta senza inglese: niente abbinamenti a caso.
    """
    problems: list[str] = []
    if len(it_rows) != len(en_rows):
        problems.append(f"righe diverse: {len(it_rows)} in italiano, {len(en_rows)} in inglese")
    pairs: list[tuple[Row, Row | None]] = []
    for n, r in enumerate(it_rows):
        e = en_rows[n] if n < len(en_rows) else None
        if e is not None and e.code != r.code:
            problems.append(f"riga {n + 1}: {r.code} in italiano, {e.code} in inglese")
            e = None
        elif e is not None:
            for f in ("ean", "conf", "box"):
                if getattr(r, f) != getattr(e, f):
                    problems.append(f"{r.code}: {f} diverso tra le lingue ({getattr(r, f)} / {getattr(e, f)})")
        pairs.append((r, e))
    return pairs, problems


# --- pagine e schede prodotto -------------------------------------------------

@dataclass
class Frame:
    page: str
    x: float
    y: float
    text: str
    paras: list[str]
    table: bool


def _paras(root) -> list[str]:
    """Paragrafi di una storia, escluse le tabelle (i <Br/> separano i paragrafi)."""
    out: list[list[str]] = [[]]

    def walk(e) -> None:
        for c in e:
            if c.tag == "Table":
                continue
            if c.tag == "Content":
                out[-1].append(c.text or "")
            elif c.tag == "Br":
                out.append([])
            else:
                walk(c)

    walk(root)
    return [t for t in ("".join(x).strip() for x in out) if t]


def _nums(s: str) -> list[float]:
    return [float(v) for v in s.split()]


def read_frames(path: str) -> list[Frame]:
    """Tutti i riquadri di testo di ogni pagina, con posizione e paragrafi."""
    frames: list[Frame] = []
    with zipfile.ZipFile(path) as z:
        names = set(z.namelist())
        design = ET.fromstring(z.read("designmap.xml"))
        spreads = [e.get("src") for e in design.iter()
                   if e.tag.endswith("Spread") and (e.get("src") or "").startswith("Spreads/")]
        stories: dict[str, object] = {}
        for src in spreads:
            sp = ET.fromstring(z.read(src)).find("Spread")
            pages = []
            for pg in sp.findall("Page"):
                t, b = _nums(pg.get("ItemTransform")), _nums(pg.get("GeometricBounds"))
                pages.append((pg.get("Name"), t[4], t[4] + b[3] - b[1]))
            for tf in sp.iter("TextFrame"):
                t = _nums(tf.get("ItemTransform"))
                pts = [tuple(map(float, p.get("Anchor").split())) for p in tf.iter("PathPointType")]
                if not pts:
                    continue
                x, y = t[4] + min(p[0] for p in pts), t[5] + min(p[1] for p in pts)
                page = next((n for n, a, b in pages if a - 5 <= x <= b), None)
                sid = tf.get("ParentStory")
                fn = f"Stories/Story_{sid}.xml"
                if page is None or fn not in names:
                    continue
                root = stories.get(sid) or stories.setdefault(sid, ET.fromstring(z.read(fn)))
                text = _text(root)
                if text:
                    frames.append(Frame(page, x, y, text, _paras(root), root.find(".//Table") is not None))
    return frames


def _norm(s: str) -> str:
    return " ".join(re.sub(r"\W+", " ", s.lower()).split())


@dataclass
class Card:
    page: str
    title_en: str | None
    features_it: list[str]
    features_en: list[str]
    aligned: bool  # stesso numero di punti in italiano e inglese


def cards(it_path: str, en_path: str, names: dict[str, str]) -> tuple[dict[str, Card], list[str]]:
    """Scheda di ogni prodotto: titolo e caratteristiche in italiano e inglese.

    `names` = {codice scheda: nome italiano}. Il titolo si trova per nome sulla
    pagina; la caratteristiche sono il riquadro «CARATTERISTICHE» più vicino sotto
    il titolo; l'inglese è il riquadro nella stessa posizione dello stesso foglio.
    """
    it, en = read_frames(it_path), read_frames(en_path)
    problems: list[str] = []

    def twin(f: Frame) -> Frame | None:
        return next((e for e in en if e.page == f.page and abs(e.x - f.x) < 1 and abs(e.y - f.y) < 1), None)

    out: dict[str, Card] = {}
    for slug, name in names.items():
        hits = [f for f in it if not f.table and _norm(f.text) == _norm(name)]
        if not hits:
            problems.append(f"{slug}: titolo «{name}» non trovato nell'IDML")
            continue
        if len(hits) > 1:
            problems.append(f"{slug}: titolo presente {len(hits)} volte, uso la prima pagina")
        t = hits[0]
        feats = [f for f in it if f.page == t.page and not f.table
                 and f.text.upper().startswith("CARATTERISTICHE") and f.y >= t.y - 5]
        feat = min(feats, key=lambda f: f.y - t.y) if feats else None
        te, fe = twin(t), (twin(feat) if feat else None)
        fi = feat.paras[1:] if feat else []
        fn = fe.paras[1:] if fe else []
        aligned = bool(fe) and len(fi) == len(fn)
        if feat and not aligned:
            problems.append(f"{slug}: {len(fi)} punti in italiano, {len(fn)} in inglese (da abbinare a mano)")
        out[slug] = Card(t.page, te.text if te else None, fi, fn, aligned)
    return out, problems
