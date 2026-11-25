"""I codici EAN del catalogo: solo codici sicuri (13 cifre, cifra di controllo valida, uno per articolo)."""
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))

import idml_apply_ean as tool  # noqa: E402


def _articles():
    c = json.loads((ROOT / "data" / "catalog.json").read_text(encoding="utf-8"))
    return [a for ln in c["lines"] for fam in ln["families"] for p in fam["products"] for a in p["articles"]]


def test_check_digit():
    assert tool.check_digit_ok("8025721033412")
    assert not tool.check_digit_ok("8025721034772")


def test_every_ean_in_the_catalog_is_safe():
    eans = [(a["code"], a["ean"]) for a in _articles() if a.get("ean")]
    assert len(eans) > 200
    for code, e in eans:
        assert e.isdigit() and len(e) == 13 and tool.check_digit_ok(e), f"{code}: EAN {e} non valido"
    per_ean: dict[str, set[str]] = {}
    for code, e in eans:
        per_ean.setdefault(e, set()).add(code)
    shared = {e: c for e, c in per_ean.items() if len(c) > 1}
    assert not shared, f"EAN su più articoli: {shared}"
