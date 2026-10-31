"""Generatore del sito statico: catalogo -> HTML + indice di ricerca."""
from __future__ import annotations

import json
import shutil
import sys
from html import escape
from pathlib import Path

from jinja2 import Environment, FileSystemLoader, select_autoescape

from arnoplast.catalog import Catalog, load_catalog, text
from arnoplast.site.i18n import t
from arnoplast.site.redirects import load_redirects, netlify_rules

ROOT = Path(__file__).resolve().parent.parent.parent
DATA_DIR = ROOT / "data"
SITE_DIR = ROOT / "site"

COMPANY_HISTORY = [
    "Nel 1975 nasce ARNOstampi sdf, officina di stampaggio di materie plastiche.",
    "Nel 1983 l'attività diventa ARNOplast s.r.l.",
    "A metà degli anni Ottanta inizia la produzione di accessori per l'agricoltura professionale, destinati soprattutto al Sud Italia.",
    "Alla fine degli anni Novanta nasce la gamma a marchio proprio per agricoltura e giardinaggio, insieme alla società di distribuzione ARNOcommerciale s.r.l.",
    "Oggi la sede e gli stabilimenti si trovano a Rignano sull'Arno, vicino a Firenze, e lavorano insieme a una squadra di terzisti toscani. I prodotti sono venduti in tutta Europa, nel Mediterraneo e in alcuni paesi d'oltremare.",
]

CONTACTS = {
    "name": "ARNO Commerciale s.r.l.",
    "address": "Via G. di Vittorio, 37, 50067 Rignano sull'Arno (FI) - ITALY",
    "phone": "+39 055 8347031/3",
    "fax": "+39 055 8347016",
    "email": "info@arnoplast.it",
    "web": "www.arnoplast.it",
}

# Dominio di produzione: serve ai canonical e alla sitemap (la fase WordPress puo' cambiarlo qui).
SITE_URL = "https://www.arnoplast.it"

# Miniature delle liste famiglia: larghezze (px) generate al build, in WebP.
THUMB_WIDTHS = (200, 400)
THUMB_SIZES = "(min-width: 1024px) 22vw, (min-width: 600px) 30vw, 46vw"
# Le prime miniature sono sopra la piega (2 colonne x 2 righe su telefono): niente lazy.
EAGER_THUMBS = 4

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

# Hero a tutto schermo (foto dei cataloghi, in site/static/img/home): pagina -> (foto, ritaglio verticale, alt).
HEROES = {
    "agricoltura": ("tunnel", "tunnel-alto", "Filari di frutteto sotto la rete antigrandine"),
    "garden": ("prato", "prato-alto", "Prato e alberi in una giornata di sole"),
    "olive": ("oliveto", "oliveto-alto", "Raccolta delle olive con l'abbacchiatore"),
    "azienda": ("sede", "sede-alto", "La sede ARNOplast a Rignano sull'Arno"),
}

SECTIONS = ["richiesta-preventivo", "contatti", "azienda", "applicazioni", "video", "area-riservata"]


def _env(lang: str) -> Environment:
    env = Environment(loader=FileSystemLoader(SITE_DIR / "templates"),
                      autoescape=select_autoescape(["html"], default=True),
                      trim_blocks=True, lstrip_blocks=True)
    env.globals.update(lang=lang, contacts=CONTACTS, t=lambda k: t(k, lang), text=lambda d: text(d, lang),
                       menu=[(f"/{lang}/", "menu.products"),
                             (f"/{lang}/applicazioni/", "menu.applications"),
                             (f"/{lang}/video/", "menu.videos"),
                             (f"/{lang}/azienda/", "menu.company"),
                             (f"/{lang}/area-riservata/", "menu.reserved"),
                             (f"/{lang}/contatti/", "menu.contacts"),
                             (f"/{lang}/richiesta-preventivo/", "menu.quote")])
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


_THUMB_CACHE: dict[tuple[str, int, int], tuple[bytes, int]] = {}  # build ripetuti nello stesso processo (test)


def _thumb_bytes(src: Path, width: int) -> tuple[bytes, int]:
    """WebP della pagina `src` a larghezza `width` (e la sua altezza)."""
    from io import BytesIO
    from PIL import Image
    key = (str(src), src.stat().st_mtime_ns, width)
    if key not in _THUMB_CACHE:
        with Image.open(src) as im:
            im = im.convert("RGB")
            h = max(1, round(im.height * width / im.width))
            buf = BytesIO()
            im.resize((width, h), Image.LANCZOS).save(buf, "WEBP", quality=68, method=6)
        _THUMB_CACHE[key] = (buf.getvalue(), h)
    return _THUMB_CACHE[key]


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


def home_images(folder: Path = HOME_IMG_DIR) -> dict[str, dict]:
    """Foto della home per nome: src (la piu' grande), srcset, larghezza e altezza intrinseche."""
    found: dict[str, list[tuple[int, str, tuple[int, int]]]] = {}
    for f in sorted(folder.glob("*.webp")) if folder.is_dir() else []:
        name, _, w = f.stem.rpartition("-")
        size = _webp_size(f)
        if name and w.isdigit() and size:
            found.setdefault(name, []).append((int(w), f"/static/img/home/{f.name}", size))
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
    return {"img": imgs[name], "portrait": imgs.get(portrait) if portrait else None, "alt": alt}


def home_context(c: Catalog, lang: str) -> dict:
    """Dati della home, tutti calcolati dal catalogo."""
    counts = {l.slug: sum(len(f.products) for f in l.families) for l in c.lines}
    articles = [a for l in c.lines for f in l.families for p in f.products for a in p.articles]
    stats = {"lines": len(c.lines), "families": sum(len(l.families) for l in c.lines),
             "products": sum(counts.values()), "codes": len({a.code for a in articles})}
    return {"lines": c.lines, "line": None, "counts": counts, "stats": stats, "imgs": home_images(),
            "contacts": CONTACTS}


def build_site(c: Catalog, out: Path, lang: str = "it", sources: Path | None = None) -> list[Path]:
    out = Path(out)
    sources = SOURCE_DIR if sources is None else Path(sources)
    env = _env(lang)
    written: list[Path] = []
    base = out / lang
    urls: list[str] = []
    imgs = home_images()

    def render(tpl: str, dest: Path, description: str | None = None, **ctx) -> None:
        rel = dest.relative_to(out).as_posix()
        canonical = f"{SITE_URL}/{rel}/"
        urls.append(canonical)
        _write(dest / "index.html", env.get_template(tpl).render(
            description=_clip(description, 200) if description else None, canonical=canonical, **ctx), written)

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
            render("family.html", base / line.slug / fam.slug, line=line, family=fam,
                   title=text(fam.name, lang),
                   description=f"{text(fam.name, lang)} ({text(line.name, lang)}): "
                               f"{len(fam.products)} prodotti, tra cui {names}.",
                   thumbs={p.slug: make_thumbs(p, out) for p in fam.products},
                   eager_thumbs=EAGER_THUMBS, thumb_sizes=THUMB_SIZES)
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
                       image_size=_image_size(DATA_DIR / prod.image) if has_image else None)
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
    render("page.html", base / "contatti", line=None, title=t("page.contacts", lang),
           description=f"{CONTACTS['name']}, {CONTACTS['address']}. Tel. {CONTACTS['phone']}, {CONTACTS['email']}.",
           kind="contacts", contacts=CONTACTS)
    render("company.html", base / "azienda", line=None, title=t("page.company", lang),
           description=" ".join(COMPANY_HISTORY[:2]), paragraphs=COMPANY_HISTORY,
           hero=_hero("azienda", imgs), imgs=imgs)
    render("page.html", base / "applicazioni", line=None, title=t("page.applications", lang),
           kind="placeholder")
    render("page.html", base / "video", line=None, title=t("page.videos", lang),
           kind="placeholder")
    render("page.html", base / "area-riservata", line=None, title=t("page.reserved", lang),
           kind="reserved")

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

    redirects = DATA_DIR / "redirects.csv"
    if redirects.is_file():
        shutil.copyfile(redirects, out / "redirects.csv")
        (out / "_redirects").write_text(netlify_rules(load_redirects(redirects)), encoding="utf-8")
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
    clean_dir(out)
    written = build_site(c, out, sources=sources)
    print(f"{len(written)} pagine scritte in {out}")


if __name__ == "__main__":
    main()
