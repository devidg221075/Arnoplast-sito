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
