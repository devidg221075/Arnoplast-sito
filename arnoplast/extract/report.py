"""Report HTML di controllo dati: catalog.json a confronto con i PDF stampati.

Uso: python -m arnoplast.extract.report   (scrive data/review.html)

Il catalogo non memorizza la pagina sorgente dei prodotti. Il report la ricava
cercando i codici articolo di ogni prodotto nel testo delle pagine dei PDF
(restando nelle pagine della famiglia, secondo `family_for`): sono indicate le
pagine dove compare almeno il 75% del massimo di codici trovato.
"""
from __future__ import annotations

import re
from collections.abc import Callable
from html import escape
from pathlib import Path

from arnoplast.catalog import Catalog, Product, load_catalog, text
from arnoplast.extract.articles import strip_controls

DATA_DIR = Path(__file__).resolve().parents[2] / "data"

_CODE_RE = re.compile(r"(?<![\d-])(\d{4}-\d{4}[A-Z]{0,3})(?![A-Za-z\d])")

# anomalie note dei sorgenti, da far valutare a chi conosce i cataloghi
KNOWN_ODDITIES = [
    "Garden pag. 53, Chiodo cuci-rete 1705-0202 / 1705-0203: CONF. e IMB. sono "
    "riportati come stampati, ma probabilmente sono invertiti.",
    "Garden pag. 26, bobine: il valore è sotto la colonna CONF.",
    "Garden pag. 53: intestazioni di colonna scambiate.",
    "Prodotti con testo non numerico nelle colonne (es. \"BOB. 200MT\", "
    "\"1 KG/600KG\", \"BLISTER/IND.\"): il valore non è stato letto e compare "
    "come \"-\".",
    "Link video: il campo è vuoto per tutti i prodotti (i QR code non sono "
    "stati decodificati).",
]


def pdf_page_codes(pdf: Path) -> list[set[str]]:
    """Insieme dei codici completi presenti in ogni pagina del PDF."""
    from arnoplast.extract.pdf import page_count, page_text
    return [set(_CODE_RE.findall(strip_controls(page_text(pdf, n))))
            for n in range(1, page_count(pdf) + 1)]


def product_pages(c: Catalog, page_codes: dict[str, list],
                  family_pages: Callable[[str, int], str | None] | None = None,
                  probable: set[str] | None = None) -> dict[str, list[int]]:
    """'linea/famiglia/prodotto' -> pagine (1-based) dove compaiono i suoi codici.

    `page_codes[linea]` è il testo o l'insieme di codici per pagina.
    `family_pages(linea, pagina)` restituisce il nome della famiglia della
    pagina (None = nessun vincolo).

    Le pagine si stimano coi soli codici unici del prodotto nella famiglia
    (viterie e riduzioni condivise con i vicini non contano). Senza codici
    unici si usano tutti i codici e la chiave finisce in `probable`.
    """
    out: dict[str, list[int]] = {}
    for line in c.lines:
        pages = [p if isinstance(p, set) else set(_CODE_RE.findall(p))
                 for p in page_codes.get(line.slug, [])]
        for fam in line.families:
            fname = text(fam.name, "it")
            count: dict[str, int] = {}
            for prod in fam.products:
                for code in {a.code for a in prod.articles}:
                    count[code] = count.get(code, 0) + 1
            for prod in fam.products:
                codes = {a.code for a in prod.articles}
                unique = {k for k in codes if count[k] == 1}
                key = f"{line.slug}/{fam.slug}/{prod.slug}"
                if unique:
                    codes = unique
                elif probable is not None:
                    probable.add(key)
                score = {}
                for n, found in enumerate(pages, 1):
                    if family_pages and family_pages(line.slug, n) != fname:
                        continue
                    k = len(codes & found)
                    if k:
                        score[n] = k
                if score:
                    best = max(score.values())
                    out[key] = sorted(
                        n for n, k in score.items() if k * 4 >= best * 3)
    return out


def _default_pages(c: Catalog) -> dict[str, list[set[str]]]:
    from arnoplast.extract.build import SOURCES
    return {slug: pdf_page_codes(pdf) for slug, pdf in SOURCES.items()
            if Path(pdf).exists() and any(l.slug == slug for l in c.lines)}


def _e(s: object) -> str:
    return escape(str(s), quote=True)


def _n(v: int | None) -> str:
    return "-" if v is None else str(v)


CSS = """
body{font:14px/1.45 system-ui,Arial,sans-serif;margin:0 auto;max-width:960px;padding:16px;color:#222}
h1{font-size:24px}h2{font-size:20px;margin-top:40px;border-bottom:3px solid #999}
h3{font-size:16px;margin-top:28px;background:#eee;padding:4px 8px}
h4{font-size:15px;margin:22px 0 4px}
.src{font-weight:normal;color:#555;font-size:13px}
table{border-collapse:collapse;width:100%;margin:6px 0}
th,td{border:1px solid #bbb;padding:3px 6px;text-align:left;vertical-align:top}
th{background:#f3f3f3}td.n,th.n{text-align:right;white-space:nowrap}
td.c{white-space:nowrap;font-family:ui-monospace,Consolas,monospace}
.mat{display:inline-block;background:#ffd84a;padding:0 6px;margin-right:4px;font-weight:bold;font-size:12px}
.warn{background:#fff3cd;border:1px solid #e0c060;padding:8px 14px;margin:16px 0}
.opt td{color:#666;font-style:italic}.nota{color:#555;font-size:13px}
nav ul{columns:2;margin:4px 0}
@media print{body{max-width:none;font-size:11px}h2{break-before:page}
table,h4{break-inside:avoid}h4{break-after:avoid}}
"""


def _product_html(prod: Product, pdf_name: str, pages: list[int] | None,
                  probable: bool = False) -> str:
    h = [f'<h4 id="p-{_e(prod.slug)}">{_e(text(prod.name, "it"))}']
    if pages:
        label = ", ".join(str(p) for p in pages)
        tag = " (probabile)" if probable else ""
        h.append(f' <span class="src">({_e(pdf_name)}, pag. {_e(label)}{tag})</span>')
    h.append("</h4>")
    if prod.materials:
        h.append("<p>Materiali: " + "".join(
            f'<span class="mat">{_e(m)}</span>' for m in prod.materials) + "</p>")
    h.append('<table><tr><th>Codice</th><th>Descrizione</th><th class="n">Conf.</th>'
             '<th class="n">Imb.</th><th class="n">Pallet</th></tr>')
    for a in prod.articles:
        cls = ' class="opt"' if a.kind != "article" else ""
        kind = "" if a.kind == "article" else f' [{_e(a.kind)}]'
        h.append(f'<tr{cls}><td class="c">{_e(a.code)}</td>'
                 f'<td>{_e(text(a.description, "it"))}{kind}</td>'
                 f'<td class="n">{_n(a.conf)}</td><td class="n">{_n(a.imb)}</td>'
                 f'<td class="n">{_n(a.pallet)}</td></tr>')
    h.append("</table>")
    return "".join(h)


def render_report(c: Catalog, out: Path,
                  pages: dict[str, list[int]] | None = None,
                  probable: set[str] | None = None) -> None:
    """Scrive la pagina di controllo in `out`.

    `pages`: mappa 'linea/famiglia/prodotto' -> pagine sorgente (se None non
    viene mostrata la pagina).
    """
    from arnoplast.extract.build import suspect_rows
    from arnoplast.extract.layout import LINES
    pages = pages or {}
    probable = probable or set()
    n_prod = sum(len(f.products) for l in c.lines for f in l.families)
    n_art = sum(len(p.articles) for l in c.lines for f in l.families
                for p in f.products)
    h = ['<!doctype html><html lang="it"><head><meta charset="utf-8">'
         '<meta name="viewport" content="width=device-width,initial-scale=1">'
         f"<title>Controllo dati catalogo ARNOplast</title><style>{CSS}</style></head><body>",
         "<h1>Controllo dati catalogo ARNOplast</h1>",
         f"<p>{len(c.lines)} linee, {n_prod} prodotti, {n_art} articoli. "
         "Confronta ogni tabella con la pagina indicata del PDF stampato.</p>",
         "<p><b>I numeri di pagina sono stimati automaticamente: "
         "confermali sempre sul PDF.</b></p>"]
    # da controllare
    h.append('<section id="da-controllare"><h2>Da controllare</h2>')
    suspects = suspect_rows(c)
    if suspects:
        h.append('<div class="warn"><p><b>Righe sospette</b> (nessun valore '
                 'leggibile o codice jolly non ordinabile):</p><ul>')
        for name, code in suspects:
            h.append(f"<li>{_e(name)}: <code>{_e(code)}</code></li>")
        h.append("</ul></div>")
    h.append('<div class="warn"><p><b>Anomalie note dei sorgenti</b>, da valutare:</p><ul>')
    h += [f"<li>{_e(o)}</li>" for o in KNOWN_ODDITIES]
    h.append("</ul></div></section>")
    # indice
    h.append("<nav><h2>Indice</h2><ul>")
    for l in c.lines:
        h.append(f'<li><a href="#l-{_e(l.slug)}">{_e(text(l.name, "it"))}</a><ul>')
        for f in l.families:
            h.append(f'<li><a href="#f-{_e(l.slug)}-{_e(f.slug)}">'
                     f'{_e(text(f.name, "it"))}</a> ({len(f.products)})</li>')
        h.append("</ul></li>")
    h.append("</ul></nav>")
    for l in c.lines:
        pdf_name = LINES[l.slug][2] if l.slug in LINES else l.slug
        h.append(f'<h2 id="l-{_e(l.slug)}">{_e(text(l.name, "it"))}</h2>')
        for f in l.families:
            h.append(f'<h3 id="f-{_e(l.slug)}-{_e(f.slug)}">{_e(text(f.name, "it"))}</h3>')
            for p in f.products:
                k = f"{l.slug}/{f.slug}/{p.slug}"
                h.append(_product_html(p, pdf_name, pages.get(k), k in probable))
    h.append("</body></html>")
    Path(out).write_text("".join(h), encoding="utf-8")


def main() -> int:
    from arnoplast.extract.layout import family_for
    c = load_catalog(DATA_DIR / "catalog.json")
    fp = lambda slug, n: family_for(slug, n)
    probable: set[str] = set()
    pages = product_pages(c, _default_pages(c), fp, probable)
    render_report(c, DATA_DIR / "review.html", pages, probable)
    print(f"scritto {DATA_DIR / 'review.html'} ({len(pages)} prodotti con pagina)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
