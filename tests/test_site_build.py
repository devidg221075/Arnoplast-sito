import json
import re

import pytest
from pathlib import Path

from arnoplast.catalog import Article, Catalog, Family, Line, Product, load_catalog
from arnoplast.site.build import build_site, clean_dir, main as build_main
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
    for s in ["richiesta-preventivo", "contatti", "azienda", "azienda/imballaggi", "fiere", "cataloghi", "galleria",
              "video", "area-riservata", "cookie", "applicazioni"]:
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


def _idx(out):
    return json.loads((out / "it" / "search-index.json").read_text(encoding="utf-8"))


def test_search_index_one_entry_per_code_with_products_and_names(tmp_path):
    p = prod(articles=[art("D-1"), art("D-1"), art("D-2")])
    q = prod("altro", articles=[art("D-1", desc="Altra descrizione")])
    build_site(cat(p, q), tmp_path)
    idx = _idx(tmp_path)
    arts = [e for e in idx if "code" in e]
    assert [e["code"] for e in arts] == ["D-1", "D-2"]
    assert arts[0]["url"] == "/it/agricoltura/fam/copripalo/#D-1"
    assert arts[0]["products"] == ["Copripalo <Prisma>", "Copripalo <Prisma>"]
    assert set(arts[0]) == {"code", "description", "url", "products"}
    names = [(e["kind"], e["name"], e["url"]) for e in idx if "name" in e]
    assert ("family", "Famiglia", "/it/agricoltura/fam/") in names
    assert ("product", "Copripalo <Prisma>", "/it/agricoltura/fam/copripalo/") in names
    prods = [e for e in idx if e.get("kind") == "product"]
    assert "Famiglia" in prods[0]["parent"] and "Agricoltura" in prods[0]["parent"]


def test_missing_image_does_not_break_page(tmp_path):
    build_site(cat(prod(image="images/agricoltura/nonexiste.jpg")), tmp_path, )
    assert "<img" not in page(tmp_path, "agricoltura", "fam", "copripalo").split("<main", 1)[1].split("</main>")[0]


def test_i18n_falls_back_to_italian():
    assert t("menu.products", "it") == "Prodotti"
    assert t("menu.products", "en") == "Prodotti"
    assert t("chiave.inesistente", "it") == "chiave.inesistente"


def test_real_catalog_smoke(tmp_path):
    c = load_catalog(ROOT / "data" / "catalog.json")
    written = build_site(c, tmp_path)
    n_fam = sum(len(l.families) for l in c.lines)
    n_prod = sum(len(f.products) for l in c.lines for f in l.families)
    assert len(written) == 1 + len(c.lines) + n_fam + n_prod + 12  # home + pagine di servizio, /en/ e il rimando /it/applicazioni/
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
            target = re.split(r"[#?]", m.group(1), maxsplit=1)[0]
            p = tmp_path / target.lstrip("/")
            if target.endswith("/"):
                p = p / "index.html"
            if not p.is_file():
                missing.append((f.relative_to(tmp_path).as_posix(), m.group(1)))
    assert not missing, missing[:5]


def _pdf_sources(tmp_path, size=2_500_000):
    src = tmp_path / "src"
    src.mkdir()
    (src / "agricoltura-it.pdf").write_bytes(b"%PDF" + b"0" * (size - 4))
    return src


def test_line_page_links_to_catalog_pdf_when_present(tmp_path):
    src = _pdf_sources(tmp_path)
    out = tmp_path / "out"
    build_site(cat(prod()), out, sources=src)
    html = (out / "it" / "agricoltura" / "index.html").read_text(encoding="utf-8")
    assert 'href="/downloads/catalogo-agricoltura-2026-it.pdf"' in html
    assert "Scarica il catalogo (PDF, 2,5 MB)" in html
    assert (out / "downloads" / "catalogo-agricoltura-2026-it.pdf").is_file()


def test_line_page_has_no_download_link_without_pdf(tmp_path):
    out = tmp_path / "out"
    build_site(cat(prod()), out, sources=tmp_path / "vuota")
    html = (out / "it" / "agricoltura" / "index.html").read_text(encoding="utf-8")
    assert "Scarica il catalogo" not in html and "/downloads/" not in html
    assert not (out / "downloads").exists()


def test_build_writes_redirects_files(tmp_path):
    build_site(cat(prod()), tmp_path, sources=tmp_path / "vuota")
    assert (tmp_path / "redirects.csv").is_file()
    rules = (tmp_path / "_redirects").read_text(encoding="utf-8")
    assert "/catalogo-agricoltura-professionale /it/agricoltura/ 301\n" in rules


def test_clean_build_removes_stale_files(tmp_path):
    out = tmp_path / "dist"
    out.mkdir()
    (out / "vecchia.html").write_text("x")
    build_main(out=out, sources=tmp_path / "vuota", catalog_path=ROOT / "data" / "catalog.json")
    assert not (out / "vecchia.html").exists()
    assert (out / "it" / "index.html").is_file()


def test_clean_build_refuses_dangerous_paths(tmp_path):
    for bad in (ROOT, Path("/"), Path.home(), ROOT.parent):
        with pytest.raises(ValueError):
            clean_dir(bad)
    (tmp_path / ".git").mkdir()
    with pytest.raises(ValueError):
        clean_dir(tmp_path)
    assert (tmp_path / ".git").exists()


def test_main_warns_on_stderr_when_images_missing(tmp_path, capsys):
    import arnoplast.site.build as sb
    c = cat(prod(image="images/agricoltura/copripalo.jpg"))
    (tmp_path / "catalog.json").write_text("{}", encoding="utf-8")
    from arnoplast.catalog import save_catalog
    save_catalog(c, tmp_path / "catalog.json")
    old = sb.DATA_DIR
    sb.DATA_DIR = tmp_path  # nessuna cartella images
    try:
        build_main(out=tmp_path / "out", sources=tmp_path / "nosrc", catalog_path=tmp_path / "catalog.json")
    finally:
        sb.DATA_DIR = old
    err = capsys.readouterr().err
    assert "ATTENZIONE" in err and "images" in err and "--images-only" in err


def test_no_image_warning_when_images_present(tmp_path, capsys):
    import arnoplast.site.build as sb
    (tmp_path / "images" / "agricoltura").mkdir(parents=True)
    (tmp_path / "images" / "agricoltura" / "copripalo.jpg").write_bytes(b"x")
    c = cat(prod(image="images/agricoltura/copripalo.jpg"))
    old = sb.DATA_DIR
    sb.DATA_DIR = tmp_path
    try:
        assert sb.missing_images(c) == 0
    finally:
        sb.DATA_DIR = old


def test_clean_dir_refuses_anything_inside_repo_except_dist(tmp_path, monkeypatch):
    import arnoplast.site.build as sb
    deleted = []
    monkeypatch.setattr(sb.shutil, "rmtree", lambda p, *a, **k: deleted.append(p))  # mai cancellare davvero
    monkeypatch.setattr(Path, "mkdir", lambda self, *a, **k: None)
    for sub in ("data", "site", "content", "arnoplast", "tests", "docs", "data/images"):
        with pytest.raises(ValueError):
            clean_dir(ROOT / sub)
    with pytest.raises(ValueError):
        build_main(out="data")
    assert not deleted
    # dist/ e cartelle di scratch fuori dal repo sono ammesse
    clean_dir(ROOT / "dist")
    clean_dir(tmp_path / "scratch")
    assert len(deleted) <= 2


# --- id unici, codice jolly, confezione/prodotto nei bottoni -------------------

def test_duplicate_codes_get_unique_ids_first_keeps_code(tmp_path):
    p = prod(articles=[art("2003-0011", conf=10), art("2003-0011", conf=20), art("2003-0011", conf=30)])
    build_site(cat(p), tmp_path)
    html = page(tmp_path, "agricoltura", "fam", "copripalo")
    ids = re.findall(r'<tr id="([^"]+)"', html)
    assert len(ids) == len(set(ids)) == 3
    assert ids[0] == "2003-0011"
    all_ids = re.findall(r'\sid="([^"]+)"', html)
    assert len(all_ids) == len(set(all_ids))


def test_button_carries_pack_and_product(tmp_path):
    build_site(cat(prod(articles=[art("AB-1", conf=40), art("AB-2", conf=None)])), tmp_path)
    html = page(tmp_path, "agricoltura", "fam", "copripalo")
    assert 'data-conf="40"' in html
    assert 'data-product="Copripalo &lt;Prisma&gt;"' in html
    assert re.search(r'data-code="AB-2"[^>]*data-conf=""', html)


def test_wildcard_code_has_no_add_button(tmp_path):
    build_site(cat(prod(articles=[art("1902-02\u2026"), art("1902-0201")])), tmp_path)
    html = page(tmp_path, "agricoltura", "fam", "copripalo")
    assert 'data-code="1902-0201"' in html
    assert 'data-code="1902-02\u2026"' not in html
    assert "su richiesta" in html.lower() and "/it/contatti/" in html
    assert html.count('class="add-to-quote"') == 1


# --- miniature famiglia ----------------------------------------------------------

def _jpeg(path, w=910, h=1228):
    from PIL import Image, ImageDraw
    path.parent.mkdir(parents=True, exist_ok=True)
    im = Image.new("RGB", (w, h), "white")  # pagina di catalogo sintetica: testo e riquadri
    d = ImageDraw.Draw(im)
    for y in range(40, h - 40, 36):
        d.text((40, y), "ARNOplast 1807-0751 Copripalo", fill="black")
        d.rectangle((40, y + 14, w - 40, y + 16), fill="#c00")
    im.save(path, "JPEG", quality=85)


def test_family_page_uses_small_webp_thumbs_with_dimensions(tmp_path, monkeypatch):
    import arnoplast.site.build as sb
    data = tmp_path / "data"
    _jpeg(data / "images" / "agricoltura" / "p0.jpg")
    monkeypatch.setattr(sb, "DATA_DIR", data)
    prods = [prod(f"p{i}", image="images/agricoltura/p0.jpg") for i in range(6)]
    out = tmp_path / "out"
    build_site(cat(*prods), out)
    html = (out / "it" / "agricoltura" / "fam" / "index.html").read_text(encoding="utf-8")
    assert "/images/agricoltura/" not in html  # niente pagina intera 910x1228 nella lista
    m = re.search(r'<img src="(/thumbs/[^"]+-400\.webp)" srcset="([^"]+)" sizes="[^"]+" width="400" height="(\d+)"', html)
    assert m, html[html.index("<ul"):][:600]
    f = out / m.group(1).lstrip("/")
    from PIL import Image
    with Image.open(f) as im:
        assert im.size == (400, int(m.group(3))) and im.format == "WEBP"
    assert f.stat().st_size < 40_000
    for u in re.findall(r"(/thumbs/\S+?) \d+w", m.group(2)):
        assert (out / u.lstrip("/")).is_file()
    imgs = re.findall(r"<img [^>]*>", html.split("<main", 1)[1].split("</main>")[0])
    assert len(imgs) == 6
    assert sum('loading="lazy"' in i for i in imgs) >= 2 and 'loading="lazy"' not in imgs[0]
    assert all('width="' in i and 'height="' in i for i in imgs)


# --- senza JavaScript --------------------------------------------------------------

def test_noscript_alternative_on_product_and_quote_pages(tmp_path):
    build_site(cat(prod()), tmp_path)
    for html in (page(tmp_path, "agricoltura", "fam", "copripalo"),
                 (tmp_path / "it" / "richiesta-preventivo" / "index.html").read_text(encoding="utf-8")):
        ns = re.findall(r"<noscript>(.*?)</noscript>", html, re.S)
        joined = " ".join(ns)
        assert 'href="mailto:info@arnoplast.it' in joined and "+39 055 83 47 031" in joined
    q = (tmp_path / "it" / "richiesta-preventivo" / "index.html").read_text(encoding="utf-8")
    assert 'action="#"' not in q


# --- SEO ----------------------------------------------------------------------------

def test_meta_description_and_canonical(tmp_path):
    build_site(cat(prod()), tmp_path)
    html = page(tmp_path, "agricoltura", "fam", "copripalo")
    d = re.search(r'<meta name="description" content="([^"]+)"', html)
    assert d and "Copripalo" in d.group(1) and len(d.group(1)) <= 200
    assert '<link rel="canonical" href="https://www.arnoplast.it/it/agricoltura/fam/copripalo/">' in html
    home = (tmp_path / "it" / "index.html").read_text(encoding="utf-8")
    assert '<link rel="canonical" href="https://www.arnoplast.it/it/">' in home
    assert "noindex" not in home


def test_robots_and_sitemap(tmp_path):
    build_site(cat(prod()), tmp_path)
    robots = (tmp_path / "robots.txt").read_text(encoding="utf-8")
    assert "Sitemap: https://www.arnoplast.it/sitemap.xml" in robots
    sm = (tmp_path / "sitemap.xml").read_text(encoding="utf-8")
    locs = re.findall(r"<loc>([^<]+)</loc>", sm)
    assert "https://www.arnoplast.it/it/" in locs
    assert "https://www.arnoplast.it/it/agricoltura/fam/copripalo/" in locs
    assert "https://www.arnoplast.it/it/richiesta-preventivo/" in locs
    assert not any("home-" in u for u in locs)
    assert all(u.startswith("https://www.arnoplast.it/it/") for u in locs)
    assert len(locs) == len(set(locs))


# --- CSS ----------------------------------------------------------------------------

def test_main_css_within_budget_and_product_css_loaded_only_on_product_pages(tmp_path):
    assert (ROOT / "site/static/css/main.css").stat().st_size < 30_000
    build_site(cat(prod()), tmp_path)
    assert 'href="/static/css/product.css"' in page(tmp_path, "agricoltura", "fam", "copripalo")
    assert "product.css" not in (tmp_path / "it" / "contatti" / "index.html").read_text(encoding="utf-8")
    assert "max-width" in (ROOT / "site/static/css/product.css").read_text(encoding="utf-8")
