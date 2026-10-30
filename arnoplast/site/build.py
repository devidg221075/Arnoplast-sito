"""Generatore del sito statico: catalogo -> HTML + indice di ricerca."""
from __future__ import annotations

import json
import shutil
from pathlib import Path

from jinja2 import Environment, FileSystemLoader, select_autoescape

from arnoplast.catalog import Catalog, load_catalog, text
from arnoplast.site.i18n import t

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


def build_site(c: Catalog, out: Path, lang: str = "it") -> list[Path]:
    out = Path(out)
    env = _env(lang)
    written: list[Path] = []
    base = out / lang

    def render(tpl: str, dest: Path, **ctx) -> None:
        _write(dest / "index.html", env.get_template(tpl).render(**ctx), written)

    render("home.html", base, lines=c.lines, line=None,
           counts={l.slug: sum(len(f.products) for f in l.families) for l in c.lines}, title=t("home.title", lang))

    search: list[dict] = []
    for line in c.lines:
        render("line.html", base / line.slug, line=line, title=text(line.name, lang))
        for fam in line.families:
            render("family.html", base / line.slug / fam.slug, line=line, family=fam,
                   title=text(fam.name, lang))
            for prod in fam.products:
                url = f"/{lang}/{line.slug}/{fam.slug}/{prod.slug}/"
                arts = prod.articles
                has_image = bool(prod.image) and (DATA_DIR / prod.image).is_file()
                render("product.html", base / line.slug / fam.slug / prod.slug,
                       line=line, family=fam, product=prod, title=text(prod.name, lang),
                       show_conf=any(a.conf is not None for a in arts),
                       show_imb=any(a.imb is not None for a in arts),
                       show_pallet=any(a.pallet is not None for a in arts),
                       image_url=f"/{prod.image}" if has_image else None)
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
    return written


def main() -> None:
    c = load_catalog(DATA_DIR / "catalog.json")
    out = ROOT / "dist"
    written = build_site(c, out)
    print(f"{len(written)} pagine scritte in {out}")


if __name__ == "__main__":
    main()
