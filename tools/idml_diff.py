"""Anteprima dell'aggiornamento del catalogo da IDML (italiano + inglese).

Non modifica nulla: confronta gli articoli dei due IDML con data/catalog.json e
scrive un rapporto leggibile (nuovi, cambiati, EAN, inglese).

  python tools/idml_diff.py GARDEN_ITA.idml GARDEN_ENG.idml garden  rapporto.md
"""
from __future__ import annotations

import json
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from arnoplast.extract import idml  # noqa: E402


def norm(s: str) -> str:
    return " ".join(s.lower().split())


def main(it_path: str, en_path: str, line: str, out: str) -> None:
    catalog = json.loads((ROOT / "data" / "catalog.json").read_text(encoding="utf-8"))
    ln = next(x for x in catalog["lines"] if x["slug"] == line)
    known: dict[str, tuple[str, dict]] = {}
    for fam in ln["families"]:
        for p in fam["products"]:
            for a in p["articles"]:
                known.setdefault(a["code"], (p["slug"], a))
    pairs, problems = idml.pair(idml.read_rows(it_path), idml.read_rows(en_path))

    new, changed, ean_add, no_en = [], [], 0, 0
    seen = set()
    for n, (r, e) in enumerate(pairs):
        if e is None:
            no_en += 1
        if r.ean:
            ean_add += 1
        if r.code in seen:
            continue
        seen.add(r.code)
        if r.code not in known:
            # scheda proposta: quella dell'articolo noto più vicino nella stessa tabella
            near = next((known[p.code][0] for p, _ in pairs[max(0, n - 6):n + 6]
                         if p.story == r.story and p.code in known), None)
            new.append((r, e, near))
            continue
        slug, a = known[r.code]
        if norm(a["description"]["it"]) != norm(r.description):
            changed.append((r.code, slug, a["description"]["it"], r.description))
        for f, old, cur in (("conf", a.get("conf"), r.conf), ("box", a.get("imb"), r.box)):
            if (old or None) != cur:
                changed.append((r.code, slug, f"{f}: {old}", f"{f}: {cur}"))

    # schede prodotto: titolo e caratteristiche, italiano e inglese
    prods = {p["slug"]: p for fam in ln["families"] for p in fam["products"]}
    cards, card_problems = idml.cards(it_path, en_path, {k: v["name"]["it"] for k, v in prods.items()})
    feat_changed, feat_same, feat_en = [], 0, 0
    for slug, card in cards.items():
        old = [norm(x) for x in prods[slug].get("features", [])]
        if old == [norm(x) for x in card.features_it]:
            feat_same += 1
        else:
            feat_changed.append((slug, len(old), len(card.features_it)))
        if card.aligned and card.features_en:
            feat_en += 1

    gone = sorted(set(known) - seen)
    lines = [f"# Anteprima aggiornamento catalogo «{line}»", "",
             f"- Righe lette: {len(pairs)} (italiano e inglese abbinate riga per riga)",
             f"- Articoli distinti: {len(seen)}; già nel sito: {len(seen & set(known))}",
             f"- **Nuovi: {len(new)}** · **Tolti dal catalogo: {len(gone)}** · **Descrizioni o dati cambiati: {len(changed)}**",
             f"- Righe con codice EAN (oggi assente dal sito): {ean_add}",
             f"- Righe senza abbinamento inglese: {no_en}", ""]
    if problems:
        lines += ["## Da controllare (abbinamento IT/EN)", *[f"- {p}" for p in problems], ""]
    lines += ["## Schede prodotto (nomi e caratteristiche)", "",
              f"- Schede trovate nell'IDML: {len(cards)} su {len(prods)}",
              f"- Caratteristiche italiane uguali al sito: {feat_same}; **diverse: {len(feat_changed)}**",
              f"- Schede con nome e caratteristiche **inglesi** abbinate con sicurezza: {feat_en}",
              f"- Da controllare (punti diversi tra le lingue, titolo doppio: le lingue restano indipendenti): {len(card_problems)}", ""]
    lines += [f"- {p}" for p in card_problems]
    lines += ["", "Caratteristiche italiane cambiate (punti prima → dopo):", ""]
    lines += [f"- {slug}: {a} → {b}" for slug, a, b in feat_changed[:40]]
    lines += ["", "## Articoli nuovi", ""]
    for r, e, near in new:
        lines.append(f"- `{r.code}` {r.description} — EN: {e.description if e else '—'} — scheda proposta: {near or 'da decidere'}")
    lines += ["", "## Articoli del sito non più nel catalogo", ""]
    lines += [f"- `{c}` ({known[c][0]})" for c in gone] or ["- nessuno"]
    lines += ["", "## Cambiamenti (sito → IDML)", ""]
    kinds = Counter()
    for code, slug, old, cur in changed:
        kinds["dato" if old.split(":")[0] in ("conf", "box") else "descrizione"] += 1
        lines.append(f"- `{code}` ({slug}): «{old}» → «{cur}»")
    lines += ["", f"_Riepilogo cambiamenti: {dict(kinds)}_", ""]
    Path(out).write_text("\n".join(lines), encoding="utf-8")
    print(f"scritto {out}: {len(new)} nuovi, {len(gone)} tolti, {len(changed)} cambiati")


if __name__ == "__main__":
    if len(sys.argv) != 5:
        sys.exit(__doc__)
    main(*sys.argv[1:])
