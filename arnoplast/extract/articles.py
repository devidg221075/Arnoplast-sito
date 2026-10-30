"""Parser delle righe articolo dal testo `pdftotext -layout` di una pagina catalogo."""
from __future__ import annotations

import re

from arnoplast.catalog import Article

HEADER_RE = re.compile(r"\bCOD\.?\s+DESCRIZIONE\b", re.IGNORECASE)
CODE_RE = re.compile(r"^\s*(\d{4}-\d{4}[A-Z]{0,3})(?=\s|$)")
COLUMN_RE = re.compile(r"^(?:(\d+)\s*(?:pz\.?)?|-)$", re.IGNORECASE)
GAP_RE = re.compile(r"\s{2,}")
# tolleranza (colonne) per riconoscere una riga di continuazione della descrizione
INDENT_TOL = 2


def _split_columns(rest: str) -> tuple[str, list[int | None]]:
    """Separa descrizione e colonne numeriche (separate da 2+ spazi)."""
    chunks = [c for c in GAP_RE.split(rest.strip()) if c]
    if not chunks:
        return "", []
    first_is_col = re.match(r"^\d+\s*pz\.?$", chunks[0], re.IGNORECASE)
    desc = [] if first_is_col else [chunks[0]]
    cols: list[int | None] = []
    for ch in (chunks if first_is_col else chunks[1:]):
        m = COLUMN_RE.match(ch)
        if m:
            cols.append(int(m.group(1)) if m.group(1) else None)
        elif not cols:
            desc.append(ch)
        # testo dopo le colonne (disegni, note): ignorato
    return " ".join(desc), cols


def _clean(s: str) -> str:
    return re.sub(r"\s+", " ", s).strip()


def parse_article_rows(text: str) -> list[Article]:
    lines = text.splitlines()
    out: list[Article] = []
    i = 0
    while i < len(lines):
        line = lines[i]
        m = CODE_RE.match(line)
        i += 1
        if not m:
            continue
        code = m.group(1)
        rest = line[m.end():]
        desc_col = m.end() + (len(rest) - len(rest.lstrip()))
        # codice solo su una riga: descrizione e colonne sulla successiva
        if not rest.strip() and i < len(lines) and lines[i].strip() and not CODE_RE.match(lines[i]):
            rest = lines[i]
            desc_col = len(rest) - len(rest.lstrip())
            i += 1
        desc, cols = _split_columns(rest)
        if not desc:
            # layout olive: descrizione sulle righe immediatamente precedenti
            j = i - 2
            prev: list[str] = []
            while j >= 0 and lines[j].strip() and not CODE_RE.match(lines[j]) and not HEADER_RE.search(lines[j]):
                prev.insert(0, lines[j])
                j -= 1
            if prev:
                desc_col = len(prev[0]) - len(prev[0].lstrip())
                desc = " ".join(GAP_RE.split(p.strip())[0] for p in prev)
        # continuazioni contigue, allineate alla colonna della descrizione
        while i < len(lines):
            nxt = lines[i]
            if not nxt.strip() or CODE_RE.match(nxt):
                break
            indent = len(nxt) - len(nxt.lstrip())
            if abs(indent - desc_col) > INDENT_TOL:
                break
            extra = GAP_RE.split(nxt.strip())[0]
            desc = f"{desc} {extra}"
            i += 1
        desc = _clean(desc)
        if not desc and not cols:
            continue
        if len(cols) >= 3:
            conf, imb, pallet = cols[0], cols[1], cols[2]
        elif len(cols) == 2:
            conf, imb, pallet = cols[0], cols[1], None
        elif len(cols) == 1:
            conf, imb, pallet = None, cols[0], None
        else:
            conf = imb = pallet = None
        kind = "optional" if "(optional)" in desc.lower() else "article"
        out.append(Article(code, {"it": desc}, conf, imb, pallet, kind))
    return out
