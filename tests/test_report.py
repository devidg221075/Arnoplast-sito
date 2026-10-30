"""Test del report di controllo dati."""
from pathlib import Path

from arnoplast.catalog import Article, Catalog, Family, Line, Product, load_catalog
from arnoplast.extract.report import render_report, product_pages

ROOT = Path(__file__).resolve().parents[1]


def _cat(name="Prodotto <b>x</b>", code="1111-0001", conf=10):
    arts = [Article(code, {"it": "Desc & più"}, conf, None, 5, "article")]
    prod = Product("p", {"it": name}, ["PP"], ["caratt <i>"], [], None, None, arts)
    return Catalog([Line("agricoltura", {"it": "Agricoltura"}, "red",
                         [Family("f", {"it": "Fam"}, [prod])])])


def test_report_lists_every_article_code(tmp_path):
    c = load_catalog(ROOT / "data" / "catalog.json")
    out = tmp_path / "r.html"
    render_report(c, out)
    html = out.read_text(encoding="utf-8")
    for l in c.lines:
        for f in l.families:
            for p in f.products:
                for a in p.articles:
                    assert a.code in html
    assert "Da controllare" in html


def test_report_escapes_html(tmp_path):
    out = tmp_path / "r.html"
    render_report(_cat(), out)
    html = out.read_text(encoding="utf-8")
    assert "<b>x</b>" not in html and "&lt;b&gt;x&lt;/b&gt;" in html
    assert "Prodotto <b>" not in html
    assert "Desc &amp; più" in html


def test_report_shows_dash_for_none(tmp_path):
    out = tmp_path / "r.html"
    render_report(_cat(), out)
    assert "<td class=\"n\">-</td>" in out.read_text(encoding="utf-8")


def test_da_controllare_section_with_suspect_row(tmp_path):
    out = tmp_path / "r.html"
    render_report(_cat(code="1902-02…"), out)
    html = out.read_text(encoding="utf-8")
    assert 'id="da-controllare"' in html
    assert "1902-02…" in html


def test_product_pages_from_codes():
    c = _cat()
    pages = {"agricoltura": ["niente", "qui 1111-0001", "altro 1111-0001"]}
    # famiglia "Fam" non esiste nel layout: nessun vincolo di intervallo
    assert product_pages(c, pages, family_pages=None)["agricoltura/f/p"] == [2, 3]


def _two_products(codes_a, codes_b):
    def prod(slug, codes):
        return Product(slug, {"it": slug}, [], [], [], None, None,
                       [Article(c, {"it": "d"}, 1, 1, 1, "article") for c in codes])
    return Catalog([Line("agricoltura", {"it": "A"}, "red", [Family(
        "f", {"it": "F"}, [prod("latina", codes_a), prod("omega", codes_b)])])])


def test_shared_codes_do_not_pull_neighbour_page():
    c = _two_products(["1111-0001", "1111-0002", "1111-0003", "1111-0004"],
                      ["1111-0002", "1111-0003", "1111-0004", "1111-0009"])
    pages = {"agricoltura": [{"1111-0001", "1111-0002", "1111-0003", "1111-0004"},
                             {"1111-0009", "1111-0002"}]}
    probable = set()
    got = product_pages(c, pages, None, probable)
    assert got["agricoltura/f/omega"] == [2]
    assert got["agricoltura/f/latina"] == [1]
    assert not probable


def test_probable_fallback_when_no_unique_codes(tmp_path):
    c = _two_products(["1111-0001"], ["1111-0001"])
    probable = set()
    got = product_pages(c, {"agricoltura": [{"1111-0001"}]}, None, probable)
    assert probable == {"agricoltura/f/latina", "agricoltura/f/omega"}
    out = tmp_path / "r.html"
    render_report(c, out, got, probable)
    assert "pag. 1 (probabile)" in out.read_text(encoding="utf-8")


def test_report_states_pages_are_estimated(tmp_path):
    out = tmp_path / "r.html"
    render_report(_cat(), out)
    assert "I numeri di pagina sono stimati automaticamente: confermali sempre sul PDF." \
        in out.read_text(encoding="utf-8")
