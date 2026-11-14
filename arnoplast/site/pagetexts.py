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


# --- Altri testi e dati delle pagine di servizio: dati societari, collegamenti, tipologie utente, alt delle foto, descrizioni ---
ov = _ov
CORP = [("name", "Ragione sociale"), ("street", "Indirizzo: via e numero"), ("city", "CAP e città"), ("country", "Nazione"),
        ("phone1", "Telefono principale"), ("phone2", "Secondo telefono"), ("email", "E-mail"), ("vat", "Partita IVA"),
        ("rea", "Numero REA"), ("capital", "Capitale sociale"), ("web", "Sito web")]
LINKS = [("facebook", "Facebook: indirizzo della pagina"), ("linkedin", "LinkedIn: indirizzo della pagina"), ("instagram", "Instagram: indirizzo del profilo"),
         ("apple", "App Store: indirizzo dell'app"), ("play", "Google Play: indirizzo dell'app")]
HERO_NAMES = {"agricoltura": "Agricoltura", "garden": "Garden", "olive": "Raccolta olive", "azienda": "Azienda (sede)", "imballaggi": "Imballaggi"}
META_NAMES = {"fairs": "Fiere", "catalogs": "Cataloghi e download"}


def labels(heroes: dict | None = None, n_types: int = 0) -> dict[str, str]:
    """Etichette per il backoffice (al posto del nome tecnico della chiave)."""
    out = {f"corp.{k}": v for k, v in CORP} | {f"link.{k}": v for k, v in LINKS} | {f"meta.{k}": f"Descrizione per i motori di ricerca: {v}" for k, v in META_NAMES.items()}
    out |= {f"hero.{k}.alt": f"Testo alternativo della foto di testata: {HERO_NAMES.get(k, k)}" for k in (heroes or HERO_NAMES)}
    out |= {f"ut.{i}": f"Menu «Tipologia utente», voce {i}" for i in range(1, n_types + 1)}
    return out


def _tel(phone: str) -> str:
    import re
    digits = re.sub(r"\D", "", phone)
    return ("+" if phone.strip().startswith("+") else "") + digits


def register_site(contacts: dict, social: list, apps: list, user_types: list[str], user_types_en: dict, heroes: dict,
                  hero_alt_en: dict, meta: dict[str, tuple[str, str]]) -> None:
    """Chiavi per dati societari (corp.*), collegamenti (link.*), tipologie utente (ut.N), alt delle foto (hero.*.alt) e descrizioni (meta.*)."""
    it: dict[str, str] = {"corp.name": contacts["name"], "corp.street": contacts["street"], "corp.city": contacts["city"], "corp.country": contacts["country"],
                          "corp.phone1": contacts["phones"][0][0], "corp.phone2": contacts["phones"][1][0], "corp.email": contacts["email"],
                          "corp.vat": contacts["vat"], "corp.rea": contacts["rea"], "corp.capital": contacts["capital"], "corp.web": contacts["web"]}
    for _, url, icon in [*social, *apps]:
        it[f"link.{icon}"] = url
    en = dict(it)  # dati neutri rispetto alla lingua: lo stesso valore
    for i, u in enumerate(user_types, 1):
        it[f"ut.{i}"], en[f"ut.{i}"] = u, user_types_en.get(u, u)
    for key, vals in heroes.items():
        alt = vals[2]  # HEROES: (foto, ritaglio verticale, testo alternativo)
        it[f"hero.{key}.alt"], en[f"hero.{key}.alt"] = alt, hero_alt_en.get(alt, alt)
    for key, (d_it, d_en) in meta.items():
        it[f"meta.{key}"], en[f"meta.{key}"] = d_it, d_en
    i18n.STRINGS["it"].update(it)
    i18n.STRINGS["en"].update(en)


def apply_contacts(c: dict) -> None:
    """Dati societari con le correzioni (valgono per tutte le lingue: si legge la colonna italiana)."""
    for field in ("name", "street", "city", "country", "email", "vat", "rea", "capital", "web"):
        c[field] = _ov(f"corp.{field}", "it", c[field])
    p1 = _ov("corp.phone1", "it", c["phones"][0][0])
    p2 = _ov("corp.phone2", "it", c["phones"][1][0])
    c["phones"] = [(p1, _tel(p1)), (p2, _tel(p2))]
    c["phone"], c["phone_tel"] = p1, _tel(p1)
    c["address"] = f"{c['street']}, {c['city']} - {c['country']}"


def apply_links(items: list) -> list:
    return [(name, _ov(f"link.{icon}", "it", url), icon) for name, url, icon in items]


def apply_user_types(types: list[str], en_map: dict, lang: str) -> list[tuple[str, str]]:
    """[(valore inviato, etichetta mostrata)]: il valore è sempre quello italiano (anche corretto)."""
    out = []
    for i, u in enumerate(types, 1):
        value = _ov(f"ut.{i}", "it", u)
        out.append((value, value if lang == "it" else _ov(f"ut.{i}", "en", en_map.get(u, u))))
    return out
