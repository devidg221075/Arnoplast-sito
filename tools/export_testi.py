"""Esporta i testi fissi del sito per il backoffice: backoffice/seed/testi.json.

Contiene i gruppi (per pagina), i testi originali italiani e inglesi e l'elenco dei testi legali esclusi.
Rilanciare dopo ogni modifica a i18n.py / i18n_en.py (un test controlla che il file sia aggiornato).
Uso: python tools/export_testi.py
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from arnoplast.site import i18n, texts  # noqa: E402

# (id, nome per il cliente, a cosa serve, prefissi delle chiavi)
GROUPS = [
    ("home", "Home: testata e primo schermo", "Titolo, sottotitolo e scritte della prima schermata della home.", ("home.", "hs.")),
    ("home2", "Home: sezioni", "Le sezioni sotto la testata: ricerca, preventivo in tre passi, famiglie, azienda.", ("hv.",)),
    ("company", "Azienda", "Etichette della pagina Azienda (la storia in sé si modifica a parte).", ("co.",)),
    ("contacts", "Contatti", "Pagina Contatti e modulo di contatto.", ("ct.", "contacts.")),
    ("quote", "Preventivo e moduli", "Pagina Richiesta di preventivo, messaggi dei moduli.", ("quote.", "form.", "qty.", "card.")),
    ("fairs", "Fiere", "Testi della pagina Fiere (le singole fiere sono in «Fiere»).", ("fairs.",)),
    ("openday", "Open Day", "Testo della pagina Open Day.", ("od.",)),
    ("gallery", "Galleria", "Testi della pagina Galleria.", ("gallery.",)),
    ("videos", "Video e tutorial", "Testi della pagina Video e tutorial.", ("vid.", "video.")),
    ("catalogs", "Cataloghi e sfoglia catalogo", "Pagina Cataloghi e download, visualizzatore sfogliabile.", ("cat.", "flip.")),
    ("products", "Prodotti, famiglie e linee", "Etichette delle schede prodotto, famiglie e linee.", ("product.", "family.", "line.", "col.", "pk.")),
    ("reserved", "Area riservata", "Pagina di accesso e informazioni dell'area riservata.", ("rs.", "reserved.")),
    ("press", "Rassegna stampa e social", "Blocchi «Dicono di noi» e «Seguici».", ("press.", "so.")),
    ("footer", "Piè di pagina e menu", "Menu principale e piè di pagina.", ("footer.", "menu.", "site.")),
    ("general", "Altri testi", "Nomi delle pagine, pulsanti, ricerca, messaggi generali e descrizioni delle immagini.",
     ("page.", "action.", "lang.", "search.", "kind.", "noscript.", "soon.", "skip", "breadcrumb.", "alt.")),
]


def build() -> dict:
    it, en = i18n.STRINGS["it"], i18n.STRINGS["en"]
    keys = [k for k in it if not texts.is_legal(k)]
    groups, used = [], set()
    for gid, name, help_, prefixes in GROUPS:
        ks = [k for k in keys if k.startswith(prefixes)]
        used.update(ks)
        groups.append({"id": gid, "name": name, "help": help_, "keys": ks})
    rest = [k for k in keys if k not in used]
    if rest:
        raise SystemExit(f"Testi senza gruppo: {rest}: aggiungere il prefisso in GROUPS")
    return {"groups": groups, "texts": {k: {"it": it[k], "en": en[k]} for k in keys}, "max_len": texts.MAX_LEN,
            "legal_excluded": sorted(k for k in it if texts.is_legal(k))}


def render() -> str:
    return json.dumps(build(), ensure_ascii=False, indent=1) + "\n"


if __name__ == "__main__":
    out = ROOT / "backoffice" / "seed" / "testi.json"
    out.write_text(render(), encoding="utf-8")
    d = build()
    print(f"{len(d['texts'])} testi in {len(d['groups'])} gruppi → {out} ({len(d['legal_excluded'])} testi legali esclusi)")
