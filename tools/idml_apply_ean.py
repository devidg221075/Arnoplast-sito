"""Inserisce in data/catalog.json i codici EAN letti dall'IDML di una linea.

  python tools/idml_apply_ean.py GARDEN_ITA.idml garden

Un EAN si inserisce solo se è sicuro: 13 cifre con cifra di controllo valida, e non
condiviso da due articoli diversi. Gli altri NON si inseriscono e sono elencati (un
codice a barre sbagliato è peggio di nessun codice). Non tocca altri campi.
"""
from __future__ import annotations

import json
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from arnoplast.extract import idml  # noqa: E402


def check_digit_ok(ean: str) -> bool:
    s = sum(int(d) * (3 if i % 2 else 1) for i, d in enumerate(ean[:12]))
    return (10 - s % 10) % 10 == int(ean[12])


def main(path: str, line: str) -> None:
    target = ROOT / "data" / "catalog.json"
    catalog = json.loads(target.read_text(encoding="utf-8"))
    by_code: dict[str, set[str]] = defaultdict(set)
    for r in idml.read_rows(path):
        if r.ean:
            by_code[r.code].add(r.ean)
    codes_of: dict[str, set[str]] = defaultdict(set)
    for code, eans in by_code.items():
        for e in eans:
            codes_of[e].add(code)

    held: dict[str, str] = {}
    for code, eans in by_code.items():
        if len(eans) > 1:
            held[code] = "più EAN diversi per lo stesso codice"
        else:
            e = next(iter(eans))
            if not check_digit_ok(e):
                held[code] = f"{e}: cifra di controllo non valida"
            elif len(codes_of[e]) > 1:
                held[code] = f"{e}: lo stesso EAN è su {', '.join(sorted(codes_of[e]))}"

    ln = next(x for x in catalog["lines"] if x["slug"] == line)
    n = 0
    for fam in ln["families"]:
        for p in fam["products"]:
            for a in p["articles"]:
                if a["code"] in by_code and a["code"] not in held:
                    a["ean"] = next(iter(by_code[a["code"]]))
                    n += 1
    target.write_text(json.dumps(catalog, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"{n} EAN inseriti; non inseriti ({len(held)}):")
    for code, why in sorted(held.items()):
        print(f"  {code}: {why}")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    main(*sys.argv[1:])
