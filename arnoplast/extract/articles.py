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


EAN_RE = re.compile(r"\s*-?\s*\bCo?d\.?\s*(?:ean\s*)?\d{9,}", re.IGNORECASE)
NUM_TOKEN_RE = re.compile(r"^(?:\d+|-)$")
EAN_HEADER_RE = re.compile(r"\bCOD\.?\s+EAN\b", re.IGNORECASE)
EAN_ROW_RE = re.compile(r"^\s*(?:(\d{12,13})\s+)?(\d{4}-\d{4}[A-Z]{0,3})(?=\s)(.*)$")


def strip_ean(s: str) -> str:
    """Toglie i codici EAN ("Cod. 8025…", "Cod ean 8025…") da una descrizione."""
    return re.sub(r"\s+", " ", EAN_RE.sub("", s)).strip()


def _clean(s: str) -> str:
    return strip_ean(re.sub(r"\s+", " ", s))


def _kind(desc: str) -> str:
    low = desc.lower()
    return "optional" if "(optional)" in low or "(opt.)" in low else "article"


def _num(tok: str) -> int | None:
    return None if tok == "-" else int(tok)


def parse_article_rows(text: str) -> list[Article]:
    lines = text.splitlines()
    out: list[Article] = []
    i = 0
    used = 0  # righe prima di questo indice già assegnate a un articolo
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
        # layout olive: (inizio della) descrizione sulle righe immediatamente
        # precedenti; se la riga del codice ha già testo, solo righe allineate
        j = i - 2
        prev: list[str] = []
        while (j >= used and lines[j].strip() and not CODE_RE.match(lines[j])
               and not HEADER_RE.search(lines[j])):
            indent = len(lines[j]) - len(lines[j].lstrip())
            if desc and (abs(indent - desc_col) > INDENT_TOL
                         or len(GAP_RE.split(lines[j].strip())) > 1):
                break
            prev.insert(0, lines[j])
            j -= 1
        if prev:
            head = " ".join(GAP_RE.split(p.strip())[0] for p in prev)
            if not desc:
                desc_col = len(prev[0]) - len(prev[0].lstrip())
            desc = f"{head} {desc}".strip()
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
        used = i
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
        out.append(Article(code, {"it": desc}, conf, imb, pallet, _kind(desc)))
    return out


def parse_ean_rows(text: str) -> list[Article]:
    """Tabelle del catalogo Garden: COD. EAN / ART. / DESCRIZIONE / [CONF.] / BOX.

    L'EAN viene scartato; CONF. -> conf, BOX -> imb (il Garden non ha PALLET).
    Le righe sotto un'intestazione "PARTI DI RICAMBIO" sono `spare`.
    Si leggono solo righe successive a un'intestazione (i codici sparsi nei
    disegni esplosi vengono ignorati).
    """
    out: list[Article] = []
    names: list[str] | None = None
    spare = False
    for line in text.splitlines():
        if EAN_HEADER_RE.search(line):
            spare = "RICAMBIO" in line.upper()
            names = re.findall(r"\b(CONF|BOX)\b", line.upper())
            continue
        if names is None:
            continue
        m = EAN_ROW_RE.match(line)
        if not m:
            continue
        chunks = [c for c in GAP_RE.split(m.group(3).strip()) if c]
        nums: list[int | None] = []
        while chunks and NUM_TOKEN_RE.match(chunks[-1]):
            nums.insert(0, _num(chunks.pop()))
        desc = _clean(" ".join(chunks))
        if not desc:
            continue
        cols = dict(zip(names[-len(nums):] if nums else [], nums))
        kind = "spare" if spare else _kind(desc)
        out.append(Article(m.group(2), {"it": desc}, cols.get("CONF"), cols.get("BOX"), None, kind))
    return out


def parse_raw_rows(text: str, ncols: int) -> list[Article]:
    """Righe articolo dal testo `pdftotext -raw` (ordine del flusso PDF, senza colonne).

    Forme riconosciute: `COD descrizione n n n` su una riga, oppure codice da
    solo, descrizione su una o più righe e numeri su una riga a sé. Serve a
    recuperare righe che `-layout` mescola con le etichette dei disegni.
    """
    lines = [l.strip() for l in text.splitlines()]
    out: list[Article] = []
    i = 0
    while i < len(lines):
        m = CODE_RE.match(lines[i])
        i += 1
        if not m:
            continue
        code = m.group(1)
        toks = lines[i - 1][m.end():].split()
        if len(toks) > ncols and all(NUM_TOKEN_RE.match(t) for t in toks[-ncols:]):
            desc, nums = " ".join(toks[:-ncols]), [_num(t) for t in toks[-ncols:]]
        else:
            parts, nums = [" ".join(toks)], None
            j = i
            while j < len(lines) and j < i + 4 and not CODE_RE.match(lines[j]):
                t = lines[j].split()
                if t and len(t) == ncols and all(NUM_TOKEN_RE.match(x) for x in t):
                    nums = [_num(x) for x in t]
                    break
                parts.append(lines[j])
                j += 1
            if nums is None:
                continue
            desc = " ".join(parts)
            i = j + 1
        desc = _clean(desc)
        if not desc:
            continue
        nums = nums + [None] * (3 - len(nums))
        out.append(Article(code, {"it": desc}, nums[0], nums[1], nums[2], _kind(desc)))
    return out
