"""Generatore del sito statico: catalogo -> HTML + indice di ricerca."""
from __future__ import annotations

import json
import os
import re
import shutil
import sys
from datetime import date
from html import escape
from pathlib import Path
from urllib.parse import quote

from jinja2 import Environment, FileSystemLoader, select_autoescape
from markupsafe import Markup

from arnoplast.catalog import Catalog, load_catalog, text
from arnoplast.site import fair_images as FI
from arnoplast.site.i18n import t
from arnoplast.site.redirects import load_redirects, netlify_rules

ROOT = Path(__file__).resolve().parent.parent.parent
DATA_DIR = ROOT / "data"
SITE_DIR = ROOT / "site"

# Storia aziendale: testi del cliente dalla home del sito vecchio ("Da dove siamo nati / Arno Plast - La storia" e
# "Cosa facciamo e dove vogliamo andare / Arno Plast - Oggi"), riportati senza modifiche. Tappa -> (etichetta, testo).
COMPANY_STORY = {
    "kicker": "Da dove siamo nati",
    "title": "Arno Plast - La storia",
    "steps": [
        ("1975", "ARNOplast s.r.l. nasce nel 1975 come ARNOstampi sdf. Nei primi anni l’azienda si focalizza sulla "
                 "progettazione e sulla costruzione di stampi per iniezione e soffiaggio di materie plastiche."),
        ("1983", "Nel 1983 la nuova denominazione ARNOplast s.r.l. contraddistingue il primo allineamento strategico "
                 "che l’azienda implementa con la volontà di fornire alla clientela un servizio completo, dalla "
                 "progettazione al prodotto finito."),
        ("Anni Ottanta", "A metà anni Ottanta inizia anche una limitata produzione di accessori a proprio marchio per "
                         "agricoltura professionale rivolti in prevalenza al mercato dell’Italia meridionale."),
        ("Anni Novanta", "Alla fine degli anni Novanta, l’impresa, intuendo le difficoltà cui sarebbe andata incontro "
                         "di lì a poco la subfornitura, mette in atto una seconda svolta strategica e decide di trarre "
                         "il massimo dalle proprie capacità innovative presentando una gamma di prodotti a proprio "
                         "marchio per agricoltura e giardinaggio; di questo periodo anche la creazione di una società "
                         "dedita esclusivamente al processo di distribuzione, la ARNO commerciale s.r.l.."),
    ],
    "today_kicker": "Cosa facciamo e dove vogliamo andare",
    "today_title": "Arno Plast - Oggi",
    "today": ("Oggi", "Oggi ARNOplast – con sede e stabilimenti produttivi a Rignano sull’Arno, non lontano da Firenze, "
                      "assieme ad una squadra di conto terzisti in Toscana – collegata ad ARNO commerciale ha "
                      "conquistato una posizione nel mercato italiano dei sistemi per il giardinaggio e l’agricoltura "
                      "ed è arrivata a commercializzare i propri prodotti in tutta Europa, nel Bacino del Mediterraneo "
                      "e in alcuni Paesi oltre oceano."),
    "source": "https://www.arnoplast.it/",
}

# Dati societari (dal sito vecchio, pagina Contatti e piè di pagina). Usati da footer, Contatti, home, preventivo.
CONTACTS = {
    "name": "ARNO PLAST S.r.l.",
    "street": "Via Comunale dell'Isola 65/B/C/D",
    "city": "50067 Rignano sull'Arno (FI)",
    "country": "ITALY",
    "address": "Via Comunale dell'Isola 65/B/C/D, 50067 Rignano sull'Arno (FI) - ITALY",
    "phones": [("+39 055 83 47 031", "+390558347031"), ("+39 055 83 47 033", "+390558347033")],
    "phone": "+39 055 83 47 031",
    "phone_tel": "+390558347031",
    "email": "info@arnoplast.it",
    "vat": "01987190483",
    "rea": "FI355293",
    "capital": "49.580,00 i.v.",
    "web": "www.arnoplast.it",
}
# "Tipologia utente" del modulo contatti: elenco del sito vecchio, parola per parola.
USER_TYPES = ["Privato/Altro", "Azienda Agricola", "Garden/Vivaio", "Riv. Macc. Agr./Officina", "Agraria/Consorzio Agrario",
              "Ferramenta/Fai da te", "Manutentori/Giardinieri", "Grossa Distrib. Spec.", "Ingrosso/Consulente",
              "Installatore/Importatore", "Edile", "Ente"]
# Mappa incorporata (solo dopo il clic): stessa ricerca del link "Apri in Google Maps".
MAP_EMBED = "https://www.google.com/maps?q=" + quote("ARNO PLAST, Via Comunale dell'Isola 65, 50067 Rignano sull'Arno FI") + "&output=embed"

# Indirizzo su Google Maps: solo un link esterno (nessuna mappa incorporata, nessuna richiesta a terzi).
MAPS_URL = "https://www.google.com/maps/search/?api=1&query=" + quote(
    "ARNO PLAST, Via Comunale dell'Isola 65, 50067 Rignano sull'Arno FI")
SOCIAL = [
    ("Facebook", "https://www.facebook.com/arnoplast", "facebook"),
    ("LinkedIn", "https://it.linkedin.com/company/arnoplast-s-r-l", "linkedin"),
    ("Instagram", "https://www.instagram.com/arnoplast/", "instagram"),
]
APPS = [
    ("App Store", "https://apps.apple.com/it/app/arno-plast/id1534894744", "apple"),
    ("Google Play", "https://play.google.com/store/apps/details?id=com.arnoplast.keyidea&hl=it&gl=IT", "play"),
]


def build_date() -> date:
    """Data di build: oggi, o ARNO_BUILD_DATE (AAAA-MM-GG) per i test e le anteprime. Decide fiere passate e anno."""
    env = os.environ.get("ARNO_BUILD_DATE", "").strip()
    return date.fromisoformat(env) if env else date.today()


# Dominio di produzione: serve ai canonical e alla sitemap (la fase WordPress puo' cambiarlo qui).
SITE_URL = "https://www.arnoplast.it"

# Miniature delle liste famiglia: larghezze (px) generate al build, in WebP.
THUMB_WIDTHS = (200, 400)
THUMB_SIZES = "(min-width: 1024px) 22vw, (min-width: 600px) 30vw, 46vw"
# Le prime miniature sono sopra la piega (2 colonne x 2 righe su telefono): niente lazy.
EAGER_THUMBS = 4

# Foto prodotto ritagliate dai cataloghi (data/images/<linea>/<slug>.photo.png, vedi
# arnoplast/extract/photos.py): WebP a queste larghezze, mai oltre i pixel nativi.
PHOTO_WIDTHS = (360, 640)
CARD_SIZES = "(min-width: 1240px) 290px, (min-width: 1024px) 23vw, (min-width: 600px) 31vw, calc(50vw - 22px)"
PRODUCT_PHOTO_SIZES = "(min-width: 1024px) 40vw, calc(100vw - 32px)"

# Informativa privacy esistente; la fase WordPress puo' cambiarla qui.
PRIVACY_URL = "https://www.arnoplast.it/privacy-policy-arno-plast"

# PDF dei cataloghi: slug linea -> (file sorgente, nome pubblicato)
CATALOG_PDFS = {
    "agricoltura": ("agricoltura-it.pdf", "catalogo-agricoltura-2026-it.pdf"),
    "garden": ("garden-it.pdf", "catalogo-garden-2026-it.pdf"),
    "olive": ("olive-it.pdf", "catalogo-raccolta-olive-2025-it.pdf"),
}
SOURCE_DIR = ROOT / "content" / "source"

# Legenda materiali dei cataloghi (sigla -> nome), nell'ordine della legenda.
MATERIALS = {"PP": "Polipropilene", "PA": "Poliammide", "PE": "Polietilene", "PET": "Poliestere",
             "PVC": "Polivinilcloruro", "FV": "Fibra di vetro", "FE": "Ferro zincato", "AL": "Alluminio"}

HOME_IMG_DIR = SITE_DIR / "static" / "img" / "home"

# Hero a tutto schermo (in site/static/img/home): pagina -> (foto, ritaglio verticale, alt). Garden e Olive vengono
# dai cataloghi PDF (tools/home_images.py); Agricoltura e Azienda dalle foto originali del cliente (tools/hero_images.py).
HEROES = {
    "agricoltura": ("agricoltura-giorno", "agricoltura-giorno-alto", "Frutteto coperto dalla rete antigrandine tesa sui pali"),
    "garden": ("prato", "prato-alto", "Prato e alberi in una giornata di sole"),
    "olive": ("oliveto", "oliveto-alto", "Raccolta delle olive con l'abbacchiatore"),
    "azienda": ("sede-giorno", "sede-giorno-alto", "La sede ARNOplast a Rignano sull'Arno"),
    "imballaggi": ("imballaggi-giorno", "imballaggi-giorno-alto", "Filari di un frutteto sotto la rete di protezione"),
}

# Variante serale dell'hero (dalle 19 alle 7 ora locale): pagina -> (foto, ritaglio verticale). Facoltativa: senza,
# la foto diurna resta anche di sera.
NIGHT_HEROES = {
    "agricoltura": ("agricoltura-notte", "agricoltura-notte-alto"),
    "azienda": ("sede-notte", "sede-notte-alto"),
    "imballaggi": ("imballaggi-notte", "imballaggi-notte-alto"),
}

# Attributo sizes delle foto ad alta risoluzione: (foto larga, ritaglio verticale). 120vw lascia margine al lento
# zoom (Ken Burns, fino a 1,14x); il ritaglio verticale a 100vw sceglie la versione da 840 px sui telefoni a 2x
# (e' l'LCP su rete mobile). Le altre pagine usano 100vw.
HERO_SIZES = {"agricoltura": ("120vw", "100vw"), "azienda": ("120vw", "120vw"), "imballaggi": ("120vw", "100vw")}

# Carosello dell'hero della home, in quest'ordine (la prima slide e' l'identita' della home e l'unica foto caricata
# subito): pagine di HEROES/NIGHT_HEROES. Per cambiare una foto o aggiungere la variante serale si toccano solo
# quelle due tabelle (e tools/hero_images.py): pagina della linea e slide restano allineate.
HERO_SLIDES = ["agricoltura", "garden", "olive", "azienda"]
# Prima di queste, una slide per ogni fiera futura (data/fiere.json), dalla piu' vicina: foto della fiera
# (content/photos/fiere -> <id>-hero-<w>.webp, sizes 120vw) o fondo grafico senza immagine. Ogni slide fiera porta
# data-fair-end: hero-slides.js toglie quelle gia' finite se il sito non e' stato ricostruito.
FAIR_HERO_SIZES = "120vw"
FAIR_HERO_SHORT = 140  # descrizione nella slide: frasi intere entro questa lunghezza (2-3 righe nel pannello)

def menu_items(lang: str) -> list[dict]:
    """Menu principale (ordine deciso dal cliente). Voce con `children`: sottomenu a tendina (desktop) o a fisarmonica
    (telefono); il suo `href` serve al menu "light" del piè di pagina (solo primo livello)."""
    L = f"/{lang}"
    return [
        {"key": "menu.home", "href": f"{L}/"},
        {"key": "menu.company", "href": f"{L}/azienda/", "children": [
            {"key": "menu.about", "href": f"{L}/azienda/"},
            {"key": "menu.packaging", "href": f"{L}/azienda/imballaggi/"}]},
        {"key": "menu.fairs", "href": f"{L}/fiere/"},
        {"key": "menu.products", "href": f"{L}/#linee", "children": [
            {"key": "menu.agricoltura", "href": f"{L}/agricoltura/"},
            {"key": "menu.garden", "href": f"{L}/garden/"},
            {"key": "menu.olive", "href": f"{L}/olive/"},
            {"key": "menu.catalogs", "href": f"{L}/cataloghi/"}]},
        {"key": "menu.gallery", "href": f"{L}/galleria/"},
        {"key": "menu.videos", "href": f"{L}/video/"},
        {"key": "menu.contacts", "href": f"{L}/contatti/"},
    ]


def nav_state(path: str, lang: str) -> list[dict]:
    """Menu con lo stato della pagina `path` (es. "/it/azienda/imballaggi/"): `current` sulla voce della pagina,
    `active` sulla voce della sezione (anche per le sottopagine: famiglie e prodotti ricadono sulla loro linea) e sul
    genitore del sottomenu. Vince il collegamento piu' lungo che e' prefisso del percorso; la home solo se esatta."""
    items = [dict(it, children=[dict(c) for c in it.get("children", [])]) for it in menu_items(lang)]
    links = [(it, None) for it in items if not it["children"]] + [(c, it) for it in items for c in it["children"]]
    best = None
    for link, parent in links:
        h = link["href"]
        if h == path or (h != f"/{lang}/" and path.startswith(h)):
            if best is None or len(h) > len(best[0]["href"]):
                best = (link, parent)
    if best:
        link, parent = best
        link["active"] = True
        link["current"] = link["href"] == path
        if parent:
            parent["active"] = True
    return items


# --- fiere (data/fiere.json) ------------------------------------------------------

MONTHS = ["gennaio", "febbraio", "marzo", "aprile", "maggio", "giugno", "luglio", "agosto", "settembre", "ottobre",
          "novembre", "dicembre"]
FAIR_FIELDS = ("id", "nome", "luogo", "paese", "data_inizio", "data_fine", "anno", "periodo", "stand", "testo",
               "fonte_url_sito_vecchio")
# Campi facoltativi: foto (file in content/photos/fiere), descrizione per le schede, sito ufficiale (solo https).
FAIR_OPTIONAL = ("immagine", "descrizione_breve", "link", "punto_focale")
# Punto focale della foto nella slide dell'hero (object-position CSS): una o due parole chiave o percentuali.
FAIR_FOCAL = re.compile(r"^(?:left|right|top|bottom|center|\d{1,3}(?:\.\d+)?%)(?: (?:left|right|top|bottom|center|"
                        r"\d{1,3}(?:\.\d+)?%))?$")
FAIR_ID = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
SHORT_MAX = 170  # descrizione breve ricavata dal testo: frasi intere entro questa lunghezza


def load_gallery(path: Path | None = None) -> list[dict]:
    """data/galleria.json (scritto da tools/galleria_images.py) -> foto della Galleria. Errore chiaro se manca un file."""
    path = DATA_DIR / "galleria.json" if path is None else Path(path)
    photos = json.loads(path.read_text(encoding="utf-8"))["foto"] if path.is_file() else []
    for ph in photos:
        for suffix in ("", "-t"):
            f = ROOT / "site" / "static" / "img" / "galleria" / f"{ph['id']}{suffix}.webp"
            if not f.is_file():
                raise ValueError(f"data/galleria.json: manca {f.name}; rilanciare tools/galleria_images.py")
    return photos


def load_fairs(path: Path | None = None) -> list[dict]:
    """data/fiere.json -> lista di fiere. Errore chiaro per campi mancanti, id doppi o non validi, date non ISO o
    incoerenti (fine prima dell'inizio), link non https, foto indicata ma assente in content/photos/fiere."""
    path = DATA_DIR / "fiere.json" if path is None else Path(path)
    fairs = json.loads(path.read_text(encoding="utf-8"))["fiere"] if path.is_file() else []
    seen = set()

    def bad(f: dict, why: str) -> ValueError:
        return ValueError(f"data/fiere.json: {f.get('id')!r} {why}")

    for f in fairs:
        missing = [k for k in FAIR_FIELDS if k not in f]
        if missing:
            raise bad(f, f"senza {', '.join(missing)}")
        if not FAIR_ID.match(str(f["id"])):
            raise bad(f, "id non valido: solo minuscole, cifre e trattini (es. eima-2026)")
        if f["id"] in seen:
            raise ValueError(f"data/fiere.json: id ripetuto {f['id']!r}")
        seen.add(f["id"])
        for k in ("data_inizio", "data_fine"):
            if f[k]:
                try:
                    date.fromisoformat(f[k])
                except ValueError:
                    raise bad(f, f"{k} non valida: {f[k]!r} (formato AAAA-MM-GG)") from None
        if f["data_inizio"] and f["data_fine"] and f["data_fine"] < f["data_inizio"]:
            raise bad(f, f"data_fine {f['data_fine']} precedente a data_inizio {f['data_inizio']}")
        link = f.get("link") or ""
        if link and not re.match(r"^https://[^\s/]+\.[^\s]+$", link):
            raise bad(f, f"link non valido: {link!r} (solo indirizzi https://)")
        focal = f.get("punto_focale") or ""
        if focal and not FAIR_FOCAL.match(focal):
            raise bad(f, f"punto_focale non valido: {focal!r} (es. \"center\", \"30% 50%\", \"left top\")")
        img = f.get("immagine") or ""
        if img and (Path(img).name != img or not (FI.SRC_DIR / img).is_file()):
            raise bad(f, f"immagine {img!r} non trovata in {FI.SRC_DIR}")
    return fairs


def fair_dates(f: dict) -> str:
    """Date leggibili: "10-14 novembre 2026", "30 novembre - 3 dicembre 2023"; senza date il periodo o l'anno."""
    if not f["data_inizio"]:
        return f["periodo"] or str(f["anno"])
    a = date.fromisoformat(f["data_inizio"])
    b = date.fromisoformat(f["data_fine"]) if f["data_fine"] else a
    if a == b:
        return f"{a.day} {MONTHS[a.month - 1]} {a.year}"
    if (a.year, a.month) == (b.year, b.month):
        return f"{a.day}–{b.day} {MONTHS[a.month - 1]} {a.year}"
    if a.year == b.year:
        return f"{a.day} {MONTHS[a.month - 1]} – {b.day} {MONTHS[b.month - 1]} {a.year}"
    return f"{a.day} {MONTHS[a.month - 1]} {a.year} – {b.day} {MONTHS[b.month - 1]} {b.year}"


def fair_short(f: dict, limit: int = SHORT_MAX) -> str:
    """Descrizione per le schede: `descrizione_breve`, o la prima frase di `testo` se finisce entro `limit` caratteri
    (o tutto il testo, se e' breve e senza frasi). Altrimenti taglio all'ultima parola intera con "…". Mai parole
    aggiunte: si toglie soltanto."""
    if (f.get("descrizione_breve") or "").strip():
        return " ".join(f["descrizione_breve"].split())
    s = " ".join((f.get("testo") or "").split())
    ends = [m.end() for m in re.finditer(r"(?<![.])[.!?](?=\s+[A-ZÀ-ÖØ-Þ\"«“]|$)", s)]
    if ends and ends[0] <= limit:
        return s[:ends[0]]
    if len(s) <= limit and not s.endswith(("…", "...")):
        return s
    cut = s[:limit].rstrip(" .…")
    if len(cut) < len(s.rstrip(" .…")) and " " in cut:
        cut = cut[:cut.rfind(" ")]
    return cut.rstrip(" ,;:-–(") + "…"


def _fair_end(f: dict) -> date:
    for k in ("data_fine", "data_inizio"):
        if f[k]:
            return date.fromisoformat(f[k])
    return date(int(f["anno"]), 12, 31)  # senza date: conta solo l'anno


def fair_photos(fairs: list[dict], make: bool = True) -> dict[str, dict]:
    """Foto di anteprima per id (src, srcset, width, height). Con `make`, genera i WebP che mancano dalle foto in
    content/photos/fiere (per rigenerarli: tools/fiere_images.py)."""
    for f in fairs:
        src = FI.source_for(f, FI.SRC_DIR)
        if make and src is not None:
            for have, maker in ((FI.outputs, FI.make), (FI.hero_outputs, FI.make_hero)):
                if not have(f["id"], FI.OUT_DIR):
                    made = maker(src, f["id"], FI.OUT_DIR)
                    print(f"Foto fiera {f['id']}: generati {len(made)} WebP in {FI.OUT_DIR} (da versionare)",
                          file=sys.stderr)
    imgs = home_images(FI.OUT_DIR, FI.URL_PREFIX)
    return {f["id"]: imgs[f["id"]] for f in fairs if f["id"] in imgs}


def fair_hero_photos(fairs: list[dict]) -> dict[str, dict]:
    """Foto per la slide dell'hero della home (<id>-hero-<w>.webp) per id; generate da fair_photos."""
    imgs = home_images(FI.OUT_DIR, FI.URL_PREFIX)
    return {f["id"]: imgs[f"{f['id']}-{FI.HERO}"] for f in fairs if f"{f['id']}-{FI.HERO}" in imgs}


def fair_days(f: dict) -> str:
    """Numeri grandi del fondo grafico della slide: "10–14" (stesso mese), "30.11–3.12", "10"; senza date l'anno."""
    if not f["data_inizio"]:
        return str(f["anno"])
    a = date.fromisoformat(f["data_inizio"])
    b = date.fromisoformat(f["data_fine"]) if f["data_fine"] else a
    if a == b:
        return str(a.day)
    if (a.year, a.month) == (b.year, b.month):
        return f"{a.day}–{b.day}"
    return f"{a.day}.{a.month}–{b.day}.{b.month}"


def split_fairs(fairs: list[dict], today: date,
                photos: dict | None = None) -> tuple[list[dict], list[tuple[int, list[dict]]]]:
    """(prossime, passate per anno). Passata = fine (o inizio, o 31/12 dell'anno) precedente a `today`. Prossime dalla
    piu' vicina; passate dalla piu' recente, raggruppate per anno decrescente. Ogni fiera riceve anche le date
    leggibili, la fine ISO (per lo script che nasconde le schede scadute), luogo, descrizione breve e foto."""
    photos = photos or {}
    view = [dict(f, date_label=fair_dates(f), end=_fair_end(f), end_iso=_fair_end(f).isoformat(),
                 place=", ".join(x for x in (f["luogo"], f["paese"]) if x), short=fair_short(f),
                 link=f.get("link") or "", img=photos.get(f["id"])) for f in fairs]
    upcoming = sorted((f for f in view if f["end"] >= today), key=lambda f: (f["data_inizio"] or "9999", f["id"]))
    past = sorted((f for f in view if f["end"] < today), key=lambda f: (f["end"], f["id"]), reverse=True)
    years: dict[int, list[dict]] = {}
    for f in past:
        years.setdefault(int(f["anno"]), []).append(f)
    return upcoming, sorted(years.items(), reverse=True)


# Tipo di raccolta -> classe della scheda nella tabella degli imballaggi (colore).
PACK_KINDS = {"raccolta carta": "carta", "raccolta plastica": "plastica", "indifferenziata": "indif",
              "raccolta isola ecologica": "isola"}


def load_packaging(path: Path | None = None) -> dict:
    """data/imballaggi.json: testo e tabella della pagina Imballaggi (vuoti: la pagina mostra "contenuto in arrivo").

    Nei paragrafi `**parola**` diventa grassetto (filtro `rich`); ogni riga della tabella riceve `tipo`, dalla raccolta.
    """
    path = DATA_DIR / "imballaggi.json" if path is None else Path(path)
    data = json.loads(path.read_text(encoding="utf-8")) if path.is_file() else {}
    rows = [{**r, "tipo": PACK_KINDS.get(r.get("raccolta", "").strip().lower(), "altro")}
            for r in data.get("tabella", []) if r.get("imballaggio", "").strip()]
    return {"titolo": data.get("titolo") or "Imballaggi", "sezione": data.get("sezione") or "",
            "paragrafi": [p for p in data.get("paragrafi", []) if p.strip()], "tabella": rows,
            "nota": data.get("nota") or ""}


def rich(s: str) -> Markup:
    """Testo del cliente con `**grassetto**`: tutto il resto e' escapato."""
    return Markup(re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", str(escape(s))))


def _env(lang: str) -> Environment:
    env = Environment(loader=FileSystemLoader(SITE_DIR / "templates"),
                      autoescape=select_autoescape(["html"], default=True),
                      trim_blocks=True, lstrip_blocks=True)
    env.filters["rich"] = rich
    env.globals.update(lang=lang, contacts=CONTACTS, t=lambda k: t(k, lang), text=lambda d: text(d, lang),
                       menu=menu_items(lang), nav_state=lambda path: nav_state(path, lang), social=SOCIAL, apps=APPS,
                       privacy_url=PRIVACY_URL, year=build_date().year, maps_url=MAPS_URL)
    return env


def _write(path: Path, html: str, written: list[Path]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(html, encoding="utf-8")
    written.append(path)


def _image_url(prod) -> str | None:
    """URL dell'immagine prodotto se il file esiste (altrimenti None)."""
    if prod.image and (DATA_DIR / prod.image).is_file():
        return f"/{prod.image}"
    return None


def _image_size(path: Path) -> tuple[int, int] | None:
    """Larghezza e altezza di un JPEG (marcatore SOF) o PNG, senza dipendenze; None se ignoto."""
    try:
        data = path.read_bytes()
    except OSError:
        return None
    if data[:8] == b"\x89PNG\r\n\x1a\n" and len(data) >= 24:
        return int.from_bytes(data[16:20], "big"), int.from_bytes(data[20:24], "big")
    if data[:2] != b"\xff\xd8":
        return None
    i = 2
    while i + 9 < len(data):
        if data[i] != 0xFF:
            i += 1
            continue
        marker = data[i + 1]
        if marker in (0xD8, 0x01) or 0xD0 <= marker <= 0xD7 or marker == 0xFF:
            i += 1 if marker == 0xFF else 2
            continue
        seg = int.from_bytes(data[i + 2:i + 4], "big")
        if marker in (0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF):
            h = int.from_bytes(data[i + 5:i + 7], "big")
            w = int.from_bytes(data[i + 7:i + 9], "big")
            return (w, h) if w and h else None
        i += 2 + seg
    return None


_THUMB_CACHE: dict[tuple[str, int, int, int], tuple[bytes, int]] = {}  # build ripetuti nello stesso processo (test)


def _thumb_bytes(src: Path, width: int, quality: int = 68) -> tuple[bytes, int]:
    """WebP dell'immagine `src` a larghezza `width` (e la sua altezza)."""
    from io import BytesIO
    from PIL import Image
    key = (str(src), src.stat().st_mtime_ns, width, quality)
    if key not in _THUMB_CACHE:
        with Image.open(src) as im:
            im = im.convert("RGB")
            h = max(1, round(im.height * width / im.width))
            buf = BytesIO()
            if (width, h) != im.size:
                im = im.resize((width, h), Image.LANCZOS)
            im.save(buf, "WEBP", quality=quality, method=6)
        _THUMB_CACHE[key] = (buf.getvalue(), h)
    return _THUMB_CACHE[key]


def photo_source(prod) -> Path | None:
    """Foto ritagliata del prodotto in data/ (accanto alla fascia di pagina `image`), se esiste."""
    if not prod.image:
        return None
    src = DATA_DIR / Path(prod.image).with_suffix(".photo.png")
    return src if src.is_file() else None


def photo_widths(native: int, targets: tuple[int, ...] = PHOTO_WIDTHS) -> list[int]:
    """Larghezze da generare: quelle sotto la larghezza nativa, piu' la nativa (al massimo
    la piu' grande richiesta). Mai ingrandimenti."""
    top = min(native, max(targets))
    return sorted({w for w in targets if w < top} | {top})


def make_photo(prod, out: Path) -> dict | None:
    """Foto ritagliata in WebP (out/photos/<linea>/<slug>-<w>.webp) -> src, srcset, width, height.

    None se la foto non c'e' (la build ricade sulla miniatura della pagina del catalogo)."""
    src = photo_source(prod)
    if src is None:
        return None
    try:
        size = _image_size(src)
        rel = Path(*Path(prod.image).with_suffix("").parts[1:])
        made = []
        for w in photo_widths(size[0]):
            data, h = _thumb_bytes(src, w, quality=78)
            dest = out / "photos" / rel.parent / f"{rel.name}-{w}.webp"
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(data)
            made.append((w, h, f"/photos/{rel.parent.as_posix()}/{rel.name}-{w}.webp"))
    except Exception as e:  # noqa: BLE001 - la foto e' accessoria
        print(f"ATTENZIONE: foto non generata per {prod.image}: {e}", file=sys.stderr)
        return None
    w, h, url = made[-1]
    return {"src": url, "width": w, "height": h, "srcset": ", ".join(f"{u} {x}w" for x, _, u in made)}


# --- video YouTube (dai QR code dei cataloghi) ------------------------------------

_YT_ID = re.compile(r"^https://(?:youtu\.be/|(?:www\.)?youtube\.com/(?:watch\?v=|shorts/|embed/))([\w-]{6,})")


def _video_id(url: str) -> str:
    m = _YT_ID.match(url)
    return m.group(1) if m else url


def load_videos(path: Path | None = None) -> dict:
    """data/videos.json: "linea/famiglia/prodotto" -> {"videos": [url...], "source": [...]}."""
    path = DATA_DIR / "videos.json" if path is None else Path(path)
    return json.loads(path.read_text(encoding="utf-8")) if path.is_file() else {}


def _yt_id(url: str) -> str | None:
    m = re.search(r"(?:youtu\.be/|[?&]v=|/embed/)([\w-]{6,})", url)
    return m.group(1) if m else None


def video_sections(c: Catalog, lang: str, vids: dict[str, list[str]]) -> list[dict]:
    """Pagina Video e Tutorial (data/tutorial.json + video delle schede): sezioni con schede video.
    Ogni scheda: titolo, id, servizio ('vimeo'|'youtube'), link esterno e, se noto, la scheda prodotto collegata."""
    path = DATA_DIR / "tutorial.json"
    data = json.loads(path.read_text(encoding="utf-8")) if path.is_file() else {}
    prods = {f"{l.slug}/{f.slug}/{p.slug}": (l, f, p) for l in c.lines for f in l.families for p in f.products}
    by_id: dict[str, str] = {}
    for key, urls in vids.items():
        for u in urls:
            if _yt_id(u):
                by_id.setdefault(_yt_id(u), key)

    def link(key: str | None):
        if not key:
            return None
        if key not in prods:  # catalogo ridotto (test): nessun collegamento; il test sul catalogo vero li controlla
            return None
        l, f, p = prods[key]
        return {"href": f"/{lang}/{l.slug}/{f.slug}/{p.slug}/", "name": text(p.name, lang)}

    def card(title, service, vid, key=None):
        url = f"https://vimeo.com/{vid}" if service == "vimeo" else f"https://www.youtube.com/watch?v={vid}"
        src = (f"https://player.vimeo.com/video/{vid}?autoplay=1" if service == "vimeo"
               else f"https://www.youtube-nocookie.com/embed/{vid}?autoplay=1&rel=0")
        return {"title": title, "id": vid, "service": service, "url": url, "src": src, "product": link(key)}

    out = []
    n = 0
    items = []
    for v in data.get("video", []):
        n += 1
        items.append(card(v.get("titolo") or f"{t('vid.untitled', lang)} {n}", "vimeo", v["vimeo"]))
    out.append({"key": "video", "title": t("vid.company", lang), "intro": t("vid.company_intro", lang), "cards": items})
    items = [card(v["titolo"], "youtube", v["youtube"], v.get("prodotto") or by_id.get(v["youtube"]))
             for v in data.get("agricoltura", [])]
    out.append({"key": "agricoltura", "title": t("vid.agri", lang), "intro": t("vid.agri_intro", lang), "cards": items})
    items, seen = [], {c_["id"] for c_ in out[1]["cards"]}
    for key, (l, f, p) in prods.items():
        if l.slug != "garden":
            continue
        for i, u in enumerate(vids.get(key, []), 1):
            yid = _yt_id(u)
            if yid and yid not in seen:
                seen.add(yid)
                suffix = f" {i}" if len(vids[key]) > 1 else ""
                items.append(card(f"{text(p.name, lang)}{suffix}", "youtube", yid, key))
    if items:
        out.append({"key": "garden", "title": t("vid.garden", lang), "intro": t("vid.garden_intro", lang), "cards": items})
    return out


def product_videos(c: Catalog, videos: dict | None) -> dict[str, list[str]]:
    """Video per prodotto ("linea/famiglia/prodotto" -> URL): quelli di videos.json piu'
    l'eventuale `video_url` del catalogo, senza doppioni (stesso video YouTube).

    Una chiave di videos.json che non corrisponde a nessun prodotto, o un URL non https,
    e' un errore (ValueError): catalog.json e videos.json vanno tenuti allineati."""
    videos = videos or {}
    keys = {f"{l.slug}/{f.slug}/{p.slug}": p for l in c.lines for f in l.families for p in f.products}
    unknown = sorted(set(videos) - set(keys))
    if unknown:
        raise ValueError("data/videos.json: prodotti inesistenti in catalog.json: " + ", ".join(unknown))
    out: dict[str, list[str]] = {}
    for key, prod in keys.items():
        urls = list(videos.get(key, {}).get("videos", []))
        if prod.video_url:
            urls.append(prod.video_url)
        seen, merged = set(), []
        for u in urls:
            if not str(u).startswith("https://"):
                raise ValueError(f"data/videos.json: URL non valido per {key}: {u!r}")
            if _video_id(u) not in seen:
                seen.add(_video_id(u))
                merged.append(u)
        if merged:
            out[key] = merged
    return out


def make_thumbs(prod, out: Path) -> dict | None:
    """Miniature WebP del prodotto in out/thumbs/ -> src (la piu' grande), srcset, width, height.

    Senza Pillow o con un file illeggibile ricade sull'immagine originale (se esiste)."""
    if not prod.image or not (DATA_DIR / prod.image).is_file():
        return None
    try:
        rel = Path(*Path(prod.image).with_suffix("").parts[1:])  # senza il prefisso images/
        made = []
        for w in THUMB_WIDTHS:
            data, h = _thumb_bytes(DATA_DIR / prod.image, w)
            dest = out / "thumbs" / rel.parent / f"{rel.name}-{w}.webp"
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(data)
            made.append((w, h, f"/thumbs/{rel.parent.as_posix()}/{rel.name}-{w}.webp"))
        w, h, url = made[-1]
        return {"src": url, "width": w, "height": h,
                "srcset": ", ".join(f"{u} {x}w" for x, _, u in made)}
    except Exception as e:  # noqa: BLE001 - la miniatura e' accessoria
        print(f"ATTENZIONE: miniatura non generata per {prod.image}: {e}", file=sys.stderr)
        size = _image_size(DATA_DIR / prod.image)
        return {"src": f"/{prod.image}", "srcset": None,
                "width": size[0] if size else None, "height": size[1] if size else None}


def _unique_ids(codes: list[str]) -> list[str]:
    """Id HTML per riga: il primo uso del codice resta il codice (per i frammenti #codice), i
    successivi diventano codice-2, codice-3..."""
    seen: dict[str, int] = {}
    ids = []
    for c in codes:
        seen[c] = seen.get(c, 0) + 1
        ids.append(c if seen[c] == 1 else f"{c}-{seen[c]}")
    return ids


def _clip(s: str, n: int = 160) -> str:
    s = " ".join(s.split())
    return s if len(s) <= n else s[:n - 1].rstrip(" ,;:.") + "…"


def _webp_size(path: Path) -> tuple[int, int] | None:
    """Larghezza e altezza di un file WebP (VP8, VP8L, VP8X)."""
    d = path.read_bytes()[:30]
    if d[:4] != b"RIFF" or d[8:12] != b"WEBP":
        return None
    kind = d[12:16]
    if kind == b"VP8 ":
        return int.from_bytes(d[26:28], "little") & 0x3FFF, int.from_bytes(d[28:30], "little") & 0x3FFF
    if kind == b"VP8L":
        b = int.from_bytes(d[21:25], "little")
        return (b & 0x3FFF) + 1, ((b >> 14) & 0x3FFF) + 1
    if kind == b"VP8X":
        return int.from_bytes(d[24:27], "little") + 1, int.from_bytes(d[27:30], "little") + 1
    return None


def home_images(folder: Path = HOME_IMG_DIR, prefix: str = "/static/img/home/") -> dict[str, dict]:
    """Foto della home (o di un'altra cartella di WebP <nome>-<larghezza>) per nome: src (la piu' grande), srcset,
    larghezza e altezza intrinseche."""
    found: dict[str, list[tuple[int, str, tuple[int, int]]]] = {}
    for f in sorted(folder.glob("*.webp")) if folder.is_dir() else []:
        name, _, w = f.stem.rpartition("-")
        size = _webp_size(f)
        if name and w.isdigit() and size:
            found.setdefault(name, []).append((int(w), f"{prefix}{f.name}", size))
    out = {}
    for name, items in found.items():
        items.sort()
        out[name] = {"src": items[-1][1], "width": items[-1][2][0], "height": items[-1][2][1],
                     "srcset": ", ".join(f"{u} {w}w" for w, u, _ in items)}
    return out


def _hero(key: str, imgs: dict) -> dict | None:
    """Foto dell'hero di una pagina (None se i file mancano: la pagina mostra l'apertura compatta)."""
    name, portrait, alt = HEROES[key]
    if name not in imgs:
        return None
    sizes, portrait_sizes = HERO_SIZES.get(key, ("100vw", None))
    hero = {"img": imgs[name], "portrait": imgs.get(portrait) if portrait else None, "alt": alt,
            "sizes": sizes, "portrait_sizes": portrait_sizes}
    night = NIGHT_HEROES.get(key)  # variante serale, scelta in pagina dall'ora di chi visita
    if night and night[0] in imgs:
        hero["night"], hero["night_portrait"] = imgs[night[0]], imgs.get(night[1])
    return hero


def fair_slides(lang: str, today: date | None = None, fairs: list[dict] | None = None) -> list[dict]:
    """Slide delle fiere future alla data di build (tutte, dalla piu' vicina): etichetta, link alla scheda nella
    pagina Fiere, date, luogo, descrizione breve (mai inventata: fair_short), fine ISO e foto (None = fondo grafico).
    Il sito ufficiale (`link`) resta nella pagina Fiere."""
    fairs = load_fairs() if fairs is None else fairs
    photos = fair_photos(fairs)
    heroes = fair_hero_photos(fairs)
    slides = []
    for f in split_fairs(fairs, build_date() if today is None else today, photos)[0]:
        img = heroes.get(f["id"])
        slides.append({
            "key": f"fiera-{f['id']}", "fair": True, "href": f"/{lang}/fiere/#{f['id']}", "label": f["nome"],
            "aria": f"{t('hs.fair', lang)}: {f['nome']}", "nums": [],
            "id": f["id"], "dates": f["date_label"], "start": f["data_inizio"], "end": f["end_iso"],
            "place": f["place"], "short": fair_short(f, FAIR_HERO_SHORT), "days": fair_days(f),
            # Nessun ritaglio verticale: sui telefoni la foto larga e' inquadrata da object-position (punto_focale).
            "hero": {"img": img, "alt": "", "sizes": FAIR_HERO_SIZES, "focal": f.get("punto_focale") or "center",
                     "portrait": None, "night": None, "night_portrait": None, "portrait_sizes": None}
            if img else None})
    return slides


def hero_slides(c: Catalog, lang: str, imgs: dict, stats: dict, fairs: list[dict] | None = None) -> list[dict]:
    """Slide del carosello della home: prima le fiere future (fair_slides), poi HERO_SLIDES con foto, link alla
    pagina, etichetta e una riga di numeri calcolati dal catalogo. Le pagine senza foto sono saltate."""
    lines = {l.slug: l for l in c.lines}
    slides = fair_slides(lang, fairs=fairs)
    for key in HERO_SLIDES:
        hero = _hero(key, imgs)
        if hero is None:
            continue
        if key in lines:
            l = lines[key]
            nf, np_ = len(l.families), sum(len(f.products) for f in l.families)
            label = text(l.name, lang)
            nums = [("families", nf, t("hv.family" if nf == 1 else "hv.families", lang)),
                    ("products", np_, t("hv.products", lang))]
        else:  # Azienda: l'intero catalogo
            label = t("page.company", lang)
            nums = [("lines", stats["lines"], t("hv.lines", lang)), ("codes", stats["codes"], t("hv.codes", lang))]
        slides.append({"key": key, "fair": False, "href": f"/{lang}/{key}/", "label": label, "aria": label,
                       "title": t(f"hs.title.{key}", lang), "lead": t(f"hs.lead.{key}", lang), "nums": nums, "hero": hero})
    return slides


def home_context(c: Catalog, lang: str) -> dict:
    """Dati della home, tutti calcolati dal catalogo."""
    counts = {l.slug: sum(len(f.products) for f in l.families) for l in c.lines}
    articles = [a for l in c.lines for f in l.families for p in f.products for a in p.articles]
    stats = {"lines": len(c.lines), "families": sum(len(l.families) for l in c.lines),
             "products": sum(counts.values()), "codes": len({a.code for a in articles})}
    imgs = home_images()
    return {"lines": c.lines, "line": None, "counts": counts, "stats": stats, "imgs": imgs,
            "slides": hero_slides(c, lang, imgs, stats), "contacts": CONTACTS}


def upcoming_fairs(limit: int | None = None) -> list[dict]:
    """Prossime fiere alla data di build, dalla piu' vicina, con date leggibili, luogo, descrizione breve (`short`),
    fine ISO (`end_iso`), sito (`link`) e foto (`img`, None = scheda tipografica). Le slide della home usano
    fair_slides."""
    fairs = load_fairs()
    return split_fairs(fairs, build_date(), fair_photos(fairs))[0][:limit]


def build_site(c: Catalog, out: Path, lang: str = "it", sources: Path | None = None,
               videos: dict | None = None) -> list[Path]:
    """Scrive il sito in `out`. `videos`: contenuto di data/videos.json (vedi load_videos);
    None = nessun video oltre ai `video_url` del catalogo."""
    out = Path(out)
    sources = SOURCE_DIR if sources is None else Path(sources)
    env = _env(lang)
    env.globals["materials_names"] = MATERIALS
    vids = product_videos(c, videos)
    written: list[Path] = []
    base = out / lang
    urls: list[str] = []
    imgs = home_images()

    def render(tpl: str, dest: Path, description: str | None = None, sitemap: bool = True, **ctx) -> None:
        rel = dest.relative_to(out).as_posix()
        canonical = f"{SITE_URL}/{rel}/"
        if sitemap:
            urls.append(canonical)
        ctx.setdefault("nav", env.globals["nav_state"](f"/{rel}/"))
        _write(dest / "index.html", env.get_template(tpl).render(
            description=_clip(description, 200) if description else None, canonical=canonical, path=f"/{rel}/",
            **ctx), written)

    render("home.html", base, description=t("home.tagline", lang), title=t("home.title", lang),
           **home_context(c, lang))

    # Ricerca: un articolo per codice (con i prodotti in cui compare) + una voce per famiglia e prodotto.
    search_arts: dict[str, dict] = {}
    search_pages: list[dict] = []
    for line in c.lines:
        pdf = None
        if line.slug in CATALOG_PDFS:
            src_name, pub_name = CATALOG_PDFS[line.slug]
            if (sources / src_name).is_file():
                (out / "downloads").mkdir(parents=True, exist_ok=True)
                shutil.copyfile(sources / src_name, out / "downloads" / pub_name)
                mb = f"{(sources / src_name).stat().st_size / 1e6:.1f}".replace(".", ",")
                pdf = {"url": f"/downloads/{pub_name}", "mb": mb}
        n_prod = sum(len(f.products) for f in line.families)
        n_codes = len({a.code for f in line.families for p in f.products for a in p.articles})
        render("line.html", base / line.slug, line=line, title=text(line.name, lang), pdf=pdf,
               hero=_hero(line.slug, imgs), line_index=c.lines.index(line) + 1,
               line_stats={"families": len(line.families), "products": n_prod, "codes": n_codes},
               description=f"{text(line.name, lang)}: {len(line.families)} famiglie e {n_prod} prodotti "
                           "del catalogo ARNOplast.")
        for fam in line.families:
            fam_url = f"/{lang}/{line.slug}/{fam.slug}/"
            search_pages.append({"name": text(fam.name, lang), "kind": "family",
                                 "parent": text(line.name, lang), "url": fam_url})
            names = ", ".join(text(p.name, lang) for p in fam.products[:3])
            photos = {p.slug: make_photo(p, out) for p in fam.products}
            render("family.html", base / line.slug / fam.slug, line=line, family=fam,
                   title=text(fam.name, lang),
                   description=f"{text(fam.name, lang)} ({text(line.name, lang)}): "
                               f"{len(fam.products)} prodotti, tra cui {names}.",
                   photos=photos,
                   thumbs={p.slug: make_thumbs(p, out) for p in fam.products if not photos[p.slug]},
                   videos={p.slug: vids.get(f"{line.slug}/{fam.slug}/{p.slug}", []) for p in fam.products},
                   eager_thumbs=EAGER_THUMBS, thumb_sizes=THUMB_SIZES, card_sizes=CARD_SIZES)
            for prod in fam.products:
                url = f"/{lang}/{line.slug}/{fam.slug}/{prod.slug}/"
                arts = prod.articles
                has_image = _image_url(prod) is not None
                mats = ", ".join(MATERIALS.get(m, m) for m in prod.materials)
                render("product.html", base / line.slug / fam.slug / prod.slug,
                       line=line, family=fam, product=prod, title=text(prod.name, lang),
                       description=f"{text(prod.name, lang)}, {text(fam.name, lang)} ({text(line.name, lang)}): "
                                   f"{len(arts)} codici articolo" + (f". Materiali: {mats}" if mats else "") + ".",
                       row_ids=_unique_ids([a.code for a in arts]),
                       show_conf=any(a.conf is not None for a in arts),
                       show_imb=any(a.imb is not None for a in arts),
                       show_pallet=any(a.pallet is not None for a in arts),
                       image_url=f"/{prod.image}" if has_image else None,
                       image_size=_image_size(DATA_DIR / prod.image) if has_image else None,
                       photo=photos[prod.slug], photo_sizes=PRODUCT_PHOTO_SIZES,
                       videos=vids.get(f"{line.slug}/{fam.slug}/{prod.slug}", []))
                if has_image:
                    dest = out / prod.image
                    dest.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(DATA_DIR / prod.image, dest)
                search_pages.append({"name": text(prod.name, lang), "kind": "product",
                                     "parent": f"{text(fam.name, lang)} · {text(line.name, lang)}", "url": url})
                seen: set[str] = set()
                for a in arts:
                    if a.code in seen:
                        continue
                    seen.add(a.code)
                    e = search_arts.setdefault(a.code, {"code": a.code, "description": text(a.description, lang),
                                                        "url": f"{url}#{a.code}", "products": []})
                    e["products"].append(text(prod.name, lang))

    render("quote.html", base / "richiesta-preventivo", line=None, title=t("quote.title", lang),
           description=t("quote.intro", lang), privacy_url=PRIVACY_URL)
    render("contacts.html", base / "contatti", line=None, title=t("page.contacts", lang),
           description=f"{CONTACTS['name']}, {CONTACTS['address']}. Tel. {CONTACTS['phone']}, {CONTACTS['email']}.",
           hero=_hero("azienda", imgs), imgs=imgs, user_types=USER_TYPES, map_embed=MAP_EMBED, privacy_url=PRIVACY_URL)
    story = COMPANY_STORY
    render("company.html", base / "azienda", line=None, title=t("page.company", lang),
           description=" ".join(text for _, text in story["steps"][:2]), story=story,
           hero=_hero("azienda", imgs), imgs=imgs)
    pack = load_packaging()
    render("packaging.html", base / "azienda" / "imballaggi", line=None, title=pack["titolo"], pack=pack,
           hero=_hero("imballaggi", imgs),
           description=re.sub(r"\*\*", "", " ".join(pack["paragrafi"]))[:200] or f"{pack['titolo']} - {CONTACTS['name']}, Rignano sull'Arno.")
    fairs = load_fairs()
    upcoming, past = split_fairs(fairs, build_date(), fair_photos(fairs))
    render("fairs.html", base / "fiere", line=None, title=t("page.fairs", lang), upcoming=upcoming, past=past,
           description="Le fiere a cui partecipa ARNOplast: prossimi appuntamenti e archivio delle fiere passate.")
    pdfs = []
    for slug, (src_name, pub_name) in CATALOG_PDFS.items():
        f = out / "downloads" / pub_name
        if f.is_file():
            l = next((l for l in c.lines if l.slug == slug), None)
            pdfs.append({"slug": slug, "name": text(l.name, lang) if l else slug.title(), "url": f"/downloads/{pub_name}",
                         "year": re.search(r"(\d{4})", pub_name).group(1),
                         "mb": f"{f.stat().st_size / 1e6:.1f}".replace(".", ",")})
    render("catalogs.html", base / "cataloghi", line=None, title=t("page.catalogs", lang), pdfs=pdfs,
           description="Cataloghi ARNOplast in PDF da scaricare (Agricoltura, Garden, Raccolta olive) e app ARNOplast.")
    book = json.loads((DATA_DIR / "cataloghi.json").read_text(encoding="utf-8")) if (DATA_DIR / "cataloghi.json").is_file() else {}
    for p in pdfs:
        b = book.get(p["slug"])
        if not b:
            continue
        for i in range(1, b["pagine"] + 1):
            if not (ROOT / "site" / "static" / "img" / "cataloghi" / p["slug"] / f"{i:03d}.webp").is_file():
                raise ValueError(f"data/cataloghi.json: manca la pagina {i} di {p['slug']}; rilanciare tools/catalog_pages.py")
        render("flipbook.html", base / "cataloghi" / p["slug"], line=None, slug=p["slug"], n=b["pagine"], w=b["w"], h=b["h"],
               title=f"{t('cat.catalog', lang)} {p['name']} {p['year']}", pdf_url=p["url"],
               base=f"/static/img/cataloghi/{p['slug']}/",
               description=f"Sfoglia online il {t('cat.catalog', lang).lower()} ARNOplast {p['name']} {p['year']}.")
    render("gallery.html", base / "galleria", line=None, title=t("page.gallery", lang), photos=load_gallery(),
           description=t("gallery.lead", lang))
    render("videos.html", base / "video", line=None, title=t("page.videos", lang), sections=video_sections(c, lang, vids),
           description=t("vid.lead", lang))
    render("page.html", base / "area-riservata", line=None, title=t("page.reserved", lang), kind="reserved")
    render("page.html", base / "cookie", line=None, title=t("page.cookie", lang), kind="cookie")
    # Vecchio indirizzo del prototipo: rimanda alla Galleria (anche in _redirects), fuori dalla sitemap.
    render("moved.html", base / "applicazioni", line=None, title=t("page.gallery", lang), sitemap=False,
           target=f"/{lang}/galleria/", canonical_override=f"{SITE_URL}/{lang}/galleria/")
    # Inglese: per ora una sola pagina "in arrivo" (noindex, fuori dalla sitemap), con lo stesso header e footer.
    # Header e footer restano quelli italiani (le pagine inglesi non esistono ancora); il contenuto e' lang="en".
    render("en.html", out / "en", line=None, title="English version coming soon", sitemap=False, noindex=True,
           page_lang="en", nav=nav_state("/en/", lang))

    (base / "search-index.json").write_text(
        json.dumps(list(search_arts.values()) + search_pages, ensure_ascii=False, separators=(",", ":")),
        encoding="utf-8")
    (out / "sitemap.xml").write_text(
        '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
        + "".join(f"  <url><loc>{escape(u)}</loc></url>\n" for u in dict.fromkeys(urls))
        + "</urlset>\n", encoding="utf-8")
    (out / "robots.txt").write_text(f"User-agent: *\nAllow: /\n\nSitemap: {SITE_URL}/sitemap.xml\n",
                                    encoding="utf-8")

    static = SITE_DIR / "static"
    if static.is_dir():
        shutil.copytree(static, out / "static", dirs_exist_ok=True)
        # Menu (nav.js) in coda ad anim.js: entrambi script classici in ogni pagina; separati costavano una
        # connessione in piu' prima delle immagini (LCP delle famiglie peggiore di ~300 ms in Fast 3G).
        # In mezzo fairs.js (nasconde le schede delle fiere gia' finite se il sito non e' stato ricostruito).
        js = out / "static" / "js"
        if (js / "nav.js").is_file():
            parts = [(static / "js" / n).read_text(encoding="utf-8").rstrip() + "\n;\n"
                     for n in ("anim.js", "fairs.js") if (static / "js" / n).is_file()]
            (js / "anim.js").write_text("".join(parts) + (static / "js" / "nav.js").read_text(encoding="utf-8"),
                                        encoding="utf-8")

    redirects = DATA_DIR / "redirects.csv"
    if redirects.is_file():
        shutil.copyfile(redirects, out / "redirects.csv")
        rows = load_redirects(redirects) + [(f"/{lang}/applicazioni/", f"/{lang}/galleria/")]
        (out / "_redirects").write_text(netlify_rules(rows), encoding="utf-8")
    return written


def clean_dir(out: Path) -> None:
    """Svuota e ricrea `out`. Ammessi solo dist/ (anche sottocartelle) e cartelle fuori dal repo.

    Rifiuta ogni altro percorso dentro il repo (data/, site/, ...), il repo stesso e i suoi
    antenati, /, la home e le cartelle con .git."""
    out = Path(out).resolve()
    dist = ROOT / "dist"
    inside = out == ROOT or ROOT in out.parents
    allowed = out == dist or dist in out.parents
    if (out == Path("/") or out == Path.home().resolve() or out in ROOT.parents
            or (inside and not allowed) or (out / ".git").exists()):
        raise ValueError(f"rifiuto di cancellare {out}")
    if out.exists():
        shutil.rmtree(out)
    out.mkdir(parents=True)


def missing_images(c: Catalog) -> int:
    """Numero di prodotti il cui file immagine non esiste in data/ (data/images e' non versionata)."""
    return sum(1 for l in c.lines for f in l.families for p in f.products
               if p.image and not (DATA_DIR / p.image).is_file())


def main(out: Path | None = None, sources: Path | None = None, catalog_path: Path | None = None) -> None:
    c = load_catalog(catalog_path or DATA_DIR / "catalog.json")
    out = Path(out) if out else ROOT / "dist"
    n = missing_images(c)
    if n:
        d = DATA_DIR / "images"
        why = "manca" if not d.is_dir() else "e' vuota o incompleta" if not any(d.rglob("*.jpg")) else "e' incompleta"
        print(f"ATTENZIONE: {n} prodotti senza immagine: {d} {why}. Le pagine saranno "
              "generate senza foto. Rigenerale con: python -m arnoplast.extract.build --images-only",
              file=sys.stderr)
    videos = load_videos()
    product_videos(c, videos)  # chiavi di videos.json non valide: errore prima di cancellare `out`
    clean_dir(out)
    written = build_site(c, out, sources=sources, videos=videos)
    print(f"{len(written)} pagine scritte in {out}")


if __name__ == "__main__":
    main()
