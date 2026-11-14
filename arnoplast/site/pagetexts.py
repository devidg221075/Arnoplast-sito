"""Testi lunghi delle pagine Azienda (la storia) e Imballaggi, modificabili dal backoffice come gli altri testi fissi.

I dati restano dove sono (COMPANY_STORY e data/imballaggi.json, più le versioni inglesi in data/en_*.json). Qui ogni voce
riceve una chiave (`story.s1.text`, `pack.p2`, `pack.r3.codice`, ...) che `register()` aggiunge a `i18n.STRINGS`: così
data/testi.json, il controllo delle chiavi e il backoffice funzionano come per tutti gli altri testi. Le correzioni si applicano
sopra i dati con `apply_story()` e `apply_pack()`. Senza correzioni il sito resta identico.
"""
from __future__ import annotations

from arnoplast.site import i18n

# Chiavi dei testi lunghi (paragrafi): possono superare il limite di una riga breve.
LONG_PREFIXES = ("story.", "pack.")
LONG_MAX = 3000
PACK_FIELDS = ("imballaggio", "codice", "raccolta")


def _ov(key: str, lang: str, default: str) -> str:
    return i18n.OVERRIDES.get(lang, {}).get(key) or default


def story_texts(story: dict) -> dict[str, str]:
    """Nell'ordine della pagina: titoli, tappe, poi «Oggi»."""
    out = {"story.kicker": story["kicker"], "story.title": story["title"]}
    for i, (label, text) in enumerate(story["steps"], 1):
        out[f"story.s{i}.label"], out[f"story.s{i}.text"] = label, text
    out.update({"story.today_kicker": story["today_kicker"], "story.today_title": story["today_title"],
                "story.today.label": story["today"][0], "story.today.text": story["today"][1]})
    return out


def pack_texts(pack: dict) -> dict[str, str]:
    out = {"pack.title": pack["titolo"], "pack.section": pack["sezione"], "pack.note": pack["nota"]}
    for i, p in enumerate(pack["paragrafi"], 1):
        out[f"pack.p{i}"] = p
    for i, r in enumerate(pack["tabella"], 1):
        for f in PACK_FIELDS:
            out[f"pack.r{i}.{f}"] = r.get(f, "")
    return out


def register(story_it: dict, story_en: dict | None, pack_it: dict, pack_en: dict) -> None:
    """Aggiunge le chiavi a i18n.STRINGS (italiano e inglese; senza testo inglese vale l'italiano)."""
    it = {**story_texts(story_it), **pack_texts(pack_it)}
    en_story = story_texts(story_en) if story_en else {}
    en_pack = pack_texts(pack_en) if pack_en.get("paragrafi") else {}
    en = {k: {**en_story, **en_pack}.get(k) or v for k, v in it.items()}
    i18n.STRINGS["it"].update({k: v for k, v in it.items() if v != ""})
    i18n.STRINGS["en"].update({k: en[k] for k in it if it[k] != ""})


def apply_story(story: dict, lang: str) -> dict:
    """La storia con le correzioni di data/testi.json (se ce ne sono)."""
    steps = [(_ov(f"story.s{i}.label", lang, label), _ov(f"story.s{i}.text", lang, text)) for i, (label, text) in enumerate(story["steps"], 1)]
    return {**story, "kicker": _ov("story.kicker", lang, story["kicker"]), "title": _ov("story.title", lang, story["title"]), "steps": steps,
            "today_kicker": _ov("story.today_kicker", lang, story["today_kicker"]), "today_title": _ov("story.today_title", lang, story["today_title"]),
            "today": (_ov("story.today.label", lang, story["today"][0]), _ov("story.today.text", lang, story["today"][1]))}


def apply_pack(pack: dict, lang: str, kinds: dict[str, str] | None = None) -> dict:
    """Imballaggi con le correzioni. `kinds` (raccolta → colore): in italiano il colore segue la raccolta corretta."""
    rows = []
    for i, r in enumerate(pack["tabella"], 1):
        row = {**r, **{f: _ov(f"pack.r{i}.{f}", lang, r.get(f, "")) for f in PACK_FIELDS}}
        if kinds is not None:
            row["tipo"] = kinds.get(row["raccolta"].strip().lower(), "altro")
        rows.append(row)
    return {**pack, "titolo": _ov("pack.title", lang, pack["titolo"]), "sezione": _ov("pack.section", lang, pack["sezione"]),
            "nota": _ov("pack.note", lang, pack["nota"]),
            "paragrafi": [_ov(f"pack.p{i}", lang, p) for i, p in enumerate(pack["paragrafi"], 1)], "tabella": rows}
