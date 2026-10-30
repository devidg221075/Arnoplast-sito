"""Generatore del sito statico: catalogo -> HTML + indice di ricerca."""
from __future__ import annotations

import json
import shutil
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

# Informativa privacy esistente; la fase WordPress puo' cambiarla qui.
PRIVACY_URL = "https://www.arnoplast.it/privacy-policy-arno-plast"

# PDF dei cataloghi: slug linea -> (file sorgente, nome pubblicato)
CATALOG_PDFS = {
    "agricoltura": ("agricoltura-it.pdf", "catalogo-agricoltura-2026-it.pdf"),
    "garden": ("garden-it.pdf", "catalogo-garden-2026-it.pdf"),
    "olive": ("olive-it.pdf", "catalogo-raccolta-olive-2025-it.pdf"),
}
SOURCE_DIR = ROOT / "content" / "source"

SECTIONS = ["richiesta-preventivo", "contatti", "azienda", "applicazioni", "video", "area-riservata"]


def _env(lang: str) -> Environment:
    env = Environment(loader=FileSystemLoader(SITE_DIR / "templates"),
                      autoescape=select_autoescape(["html"], default=True),
                      trim_blocks=True, lstrip_blocks=True)
    env.globals.update(lang=lang, t=lambda k: t(k, lang), text=lambda d: text(d, lang),
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


def build_site(c: Catalog, out: Path, lang: str = "it", sources: Path | None = None) -> list[Path]:
    out = Path(out)
    sources = SOURCE_DIR if sources is None else Path(sources)
    env = _env(lang)
    written: list[Path] = []
    base = out / lang

    def render(tpl: str, dest: Path, **ctx) -> None:
        _write(dest / "index.html", env.get_template(tpl).render(**ctx), written)

    render("home.html", base, lines=c.lines, line=None,
           counts={l.slug: sum(len(f.products) for f in l.families) for l in c.lines}, title=t("home.title", lang))

    search: list[dict] = []
    for line in c.lines:
        pdf = None
        if line.slug in CATALOG_PDFS:
            src_name, pub_name = CATALOG_PDFS[line.slug]
            if (sources / src_name).is_file():
                (out / "downloads").mkdir(parents=True, exist_ok=True)
                shutil.copyfile(sources / src_name, out / "downloads" / pub_name)
                mb = f"{(sources / src_name).stat().st_size / 1e6:.1f}".replace(".", ",")
                pdf = {"url": f"/downloads/{pub_name}", "mb": mb}
        render("line.html", base / line.slug, line=line, title=text(line.name, lang), pdf=pdf)
        for fam in line.families:
            render("family.html", base / line.slug / fam.slug, line=line, family=fam,
                   title=text(fam.name, lang),
                   thumbs={p.slug: _image_url(p) for p in fam.products})
            for prod in fam.products:
                url = f"/{lang}/{line.slug}/{fam.slug}/{prod.slug}/"
                arts = prod.articles
                has_image = _image_url(prod) is not None
                render("product.html", base / line.slug / fam.slug / prod.slug,
                       line=line, family=fam, product=prod, title=text(prod.name, lang),
                       show_conf=any(a.conf is not None for a in arts),
                       show_imb=any(a.imb is not None for a in arts),
                       show_pallet=any(a.pallet is not None for a in arts),
                       image_url=f"/{prod.image}" if has_image else None,
                       image_size=_image_size(DATA_DIR / prod.image) if has_image else None)
                if has_image:
                    dest = out / prod.image
                    dest.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(DATA_DIR / prod.image, dest)
                seen: set[str] = set()
                for a in arts:
                    if a.code in seen:
                        continue
                    seen.add(a.code)
                    search.append({"code": a.code, "description": text(a.description, lang),
                                   "url": f"{url}#{a.code}"})

    render("quote.html", base / "richiesta-preventivo", line=None, title=t("quote.title", lang),
           privacy_url=PRIVACY_URL)
    render("page.html", base / "contatti", line=None, title=t("page.contacts", lang),
           kind="contacts", contacts=CONTACTS)
    render("page.html", base / "azienda", line=None, title=t("page.company", lang),
           kind="company", paragraphs=COMPANY_HISTORY)
    render("page.html", base / "applicazioni", line=None, title=t("page.applications", lang),
           kind="placeholder")
    render("page.html", base / "video", line=None, title=t("page.videos", lang),
           kind="placeholder")
    render("page.html", base / "area-riservata", line=None, title=t("page.reserved", lang),
           kind="reserved")

    (base / "search-index.json").write_text(
        json.dumps(search, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")

    static = SITE_DIR / "static"
    if static.is_dir():
        shutil.copytree(static, out / "static", dirs_exist_ok=True)

    redirects = DATA_DIR / "redirects.csv"
    if redirects.is_file():
        shutil.copyfile(redirects, out / "redirects.csv")
        (out / "_redirects").write_text(netlify_rules(load_redirects(redirects)), encoding="utf-8")
    return written


def clean_dir(out: Path) -> None:
    """Svuota e ricrea `out`; rifiuta percorsi pericolosi (radice del repo o suoi antenati, /, home, cartelle con .git)."""
    out = Path(out).resolve()
    if (out == Path("/") or out == Path.home().resolve() or out == ROOT or out in ROOT.parents
            or (out / ".git").exists()):
        raise ValueError(f"rifiuto di cancellare {out}")
    if out.exists():
        shutil.rmtree(out)
    out.mkdir(parents=True)


def main(out: Path | None = None, sources: Path | None = None, catalog_path: Path | None = None) -> None:
    c = load_catalog(catalog_path or DATA_DIR / "catalog.json")
    out = Path(out) if out else ROOT / "dist"
    clean_dir(out)
    written = build_site(c, out, sources=sources)
    print(f"{len(written)} pagine scritte in {out}")


if __name__ == "__main__":
    main()
