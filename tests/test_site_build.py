import json
import re
from pathlib import Path

from arnoplast.catalog import Article, Catalog, Family, Line, Product, load_catalog
from arnoplast.site.build import build_site
from arnoplast.site.i18n import t

ROOT = Path(__file__).resolve().parent.parent


def art(code, desc="Descrizione", conf=10, imb=100, pallet=1000, kind="article"):
    return Article(code, {"it": desc}, conf, imb, pallet, kind)


def prod(slug="copripalo", articles=None, image=None, **kw):
    return Product(slug, {"it": "Copripalo <Prisma>"}, ["PP", "PA"], ["Resistente"],
                   ["Ø 10 cm"], image, kw.get("video_url"),
                   articles if articles is not None else [art("A-1")])


def cat(*products, line="agricoltura"):
    return Catalog([Line(line, {"it": line.title()}, "red",
                         [Family("fam", {"it": "Famiglia"}, list(products))])])


def page(out, line, fam, p):
    return (out / "it" / line / fam / p / "index.html").read_text(encoding="utf-8")


def test_builds_product_page_at_short_url(tmp_path):
    written = build_site(cat(prod()), tmp_path)
    f = tmp_path / "it" / "agricoltura" / "fam" / "copripalo" / "index.html"
    assert f.exists() and f in written
    assert (tmp_path / "it" / "index.html").exists()
    assert (tmp_path / "it" / "agricoltura" / "index.html").exists()
    assert (tmp_path / "it" / "agricoltura" / "fam" / "index.html").exists()
    for s in ["richiesta-preventivo", "contatti", "azienda", "applicazioni", "video", "area-riservata"]:
        assert (tmp_path / "it" / s / "index.html").exists()
    assert (tmp_path / "it" / "search-index.json").exists()
    assert 'data-line="agricoltura"' in page(tmp_path, "agricoltura", "fam", "copripalo")


def test_article_row_has_add_button_with_code(tmp_path):
    build_site(cat(prod(articles=[art("AB-12")])), tmp_path)
    html = page(tmp_path, "agricoltura", "fam", "copripalo")
    assert 'class="add-to-quote"' in html and 'data-code="AB-12"' in html
    assert 'class="qty"' in html and 'id="AB-12"' in html
    home = (tmp_path / "it" / "index.html").read_text(encoding="utf-8")
    assert 'class="quote-count"' in home and 'id="site-search"' in home
    assert "/it/richiesta-preventivo/" in home


def test_product_without_articles_still_builds(tmp_path):
    build_site(cat(prod(articles=[])), tmp_path)
    html = page(tmp_path, "agricoltura", "fam", "copripalo")
    assert "<table" not in html


def test_olive_page_hides_empty_pallet_column(tmp_path):
    p = prod(articles=[art("O-1", conf=None, imb=50, pallet=None, kind="article")])
    build_site(cat(p, line="olive"), tmp_path)
    html = page(tmp_path, "olive", "fam", "copripalo")
    assert "Pallet" not in html and "Conf." not in html
    assert "Imb." in html and "Codice" in html
    build_site(cat(prod()), tmp_path / "b")
    assert "Pallet" in page(tmp_path / "b", "agricoltura", "fam", "copripalo")


def test_descriptions_are_html_escaped(tmp_path):
    build_site(cat(prod(articles=[art('X"1', desc='<script>alert(1)</script> & "q"')])), tmp_path)
    html = page(tmp_path, "agricoltura", "fam", "copripalo")
    assert "<script>alert(1)</script>" not in html
    assert "&lt;script&gt;" in html
    assert 'data-code="X"1"' not in html
    assert "Copripalo <Prisma>" not in html


def test_no_price_or_cart_wording(tmp_path):
    build_site(cat(prod(), prod("altro", articles=[])), tmp_path)
    for f in tmp_path.rglob("*.html"):
        low = f.read_text(encoding="utf-8").lower()
        for w in ("carrello", "€", "prezzo"):
            assert w not in low, (f, w)


def test_search_index_deduplicates_code_url(tmp_path):
    p = prod(articles=[art("D-1"), art("D-1"), art("D-2")])
    build_site(cat(p), tmp_path)
    idx = json.loads((tmp_path / "it" / "search-index.json").read_text(encoding="utf-8"))
    assert [e["code"] for e in idx] == ["D-1", "D-2"]
    assert idx[0]["url"] == "/it/agricoltura/fam/copripalo/#D-1"
    assert set(idx[0]) == {"code", "description", "url"}


def test_missing_image_does_not_break_page(tmp_path):
    build_site(cat(prod(image="images/agricoltura/nonexiste.jpg")), tmp_path, )
    assert "<img" not in page(tmp_path, "agricoltura", "fam", "copripalo").split("<main", 1)[1]


def test_i18n_falls_back_to_italian():
    assert t("menu.products", "it") == "Prodotti"
    assert t("menu.products", "en") == "Prodotti"
    assert t("chiave.inesistente", "it") == "chiave.inesistente"


def test_real_catalog_smoke(tmp_path):
    c = load_catalog(ROOT / "data" / "catalog.json")
    written = build_site(c, tmp_path)
    n_fam = sum(len(l.families) for l in c.lines)
    n_prod = sum(len(f.products) for l in c.lines for f in l.families)
    assert len(written) == 1 + len(c.lines) + n_fam + n_prod + 6
    for f in written:
        low = f.read_text(encoding="utf-8").lower()
        for w in ("carrello", "€", "prezzo"):
            assert w not in low, (f, w)


def test_quote_page_privacy_link_points_to_live_policy(tmp_path):
    build_site(cat(prod()), tmp_path)
    html = (tmp_path / "it" / "richiesta-preventivo" / "index.html").read_text(encoding="utf-8")
    assert 'href="https://www.arnoplast.it/privacy-policy-arno-plast"' in html
    assert 'target="_blank"' in html and 'rel="noopener"' in html
    assert 'href="/it/privacy/"' not in html


def test_real_catalog_internal_links_resolve(tmp_path):
    c = load_catalog(ROOT / "data" / "catalog.json")
    written = build_site(c, tmp_path)
    missing = []
    for f in written:
        html = f.read_text(encoding="utf-8")
        for m in re.finditer(r'(?:href|src)="(/[^"]*)"', html):
            target = re.split(r"[#?]", m.group(1), 1)[0]
            p = tmp_path / target.lstrip("/")
            if target.endswith("/"):
                p = p / "index.html"
            if not p.is_file():
                missing.append((f.relative_to(tmp_path).as_posix(), m.group(1)))
    assert not missing, missing[:5]
