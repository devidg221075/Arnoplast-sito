"""Redirect dai vecchi URL del sito WordPress verso le nuove pagine."""
from __future__ import annotations

import csv
from pathlib import Path


def load_redirects(path: Path) -> list[tuple[str, str]]:
    """Legge data/redirects.csv (colonne old_path,new_path) come lista di coppie."""
    with open(path, newline="", encoding="utf-8") as f:
        return [(r["old_path"].strip(), r["new_path"].strip()) for r in csv.DictReader(f)]


def netlify_rules(rows: list[tuple[str, str]]) -> str:
    """Formato _redirects (Netlify/Cloudflare Pages): `old new 301` per riga."""
    return "".join(f"{old} {new} 301\n" for old, new in rows)
