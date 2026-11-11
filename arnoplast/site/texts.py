"""Correzioni ai testi fissi del sito (data/testi.json), modificabili dal backoffice.

I testi originali stanno in `i18n.py` / `i18n_en.py`. Il file `data/testi.json` contiene solo le voci cambiate, per lingua:
{"it": {"od.text": "..."}, "en": {"od.text": "..."}}. La build le applica sopra gli originali; una chiave inesistente o un
testo non valido ferma la build con un messaggio chiaro, prima di toccare il sito.
"""
from __future__ import annotations

import json
import re
from pathlib import Path

from arnoplast.site import i18n

MAX_LEN = 400
_CONTROL = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")
# Testi legali e del banner cookie: non si cambiano da qui (li fornisce il cliente, vedi CLAUDE.md).
LEGAL_PREFIXES = ("ck.", "page.cookie")


def is_legal(key: str) -> bool:
    return key.startswith(LEGAL_PREFIXES)


def load_overrides(path: Path) -> dict[str, dict[str, str]]:
    """Legge e controlla data/testi.json. File assente = nessuna correzione."""
    path = Path(path)
    if not path.is_file():
        return {}
    data = json.loads(path.read_text(encoding="utf-8"))
    out: dict[str, dict[str, str]] = {}
    for lang in ("it", "en"):
        rows = data.get(lang, {})
        if not isinstance(rows, dict):
            raise ValueError(f"{path.name}: «{lang}» deve essere un elenco chiave → testo")
        for key, text in rows.items():
            if key not in i18n.STRINGS[lang]:
                raise ValueError(f"{path.name}: chiave sconosciuta {key!r} ({lang})")
            if is_legal(key):
                raise ValueError(f"{path.name}: {key!r} è un testo legale: non si modifica da qui")
            if not isinstance(text, str) or not text.strip():
                raise ValueError(f"{path.name}: testo vuoto o non valido per {key!r} ({lang})")
            if len(text) > MAX_LEN or _CONTROL.search(text) or "\n" in text:
                raise ValueError(f"{path.name}: testo non valido per {key!r} ({lang}): una riga, al massimo {MAX_LEN} caratteri")
        out[lang] = {k: v.strip() for k, v in rows.items()}
    return out


def apply_overrides(path: Path) -> int:
    """Carica le correzioni e le rende attive (i18n.t le usa al posto degli originali). Restituisce quante sono."""
    i18n.OVERRIDES = load_overrides(path)
    return sum(len(v) for v in i18n.OVERRIDES.values())
