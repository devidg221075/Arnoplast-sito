"""Varianti della homepage (Task 11): A "Campo", B "Tecnica", C "Scura"; /it/ replica la A."""
import re
from pathlib import Path

import pytest

from arnoplast.catalog import load_catalog
from arnoplast.site.build import build_site

ROOT = Path(__file__).resolve().parent.parent
VARIANTS = ("a", "b", "c")


@pytest.fixture(scope="module")
def site(tmp_path_factory):
    out = tmp_path_factory.mktemp("site")
    c = load_catalog(ROOT / "data" / "catalog.json")
    build_site(c, out)
    return out, c


def html(out, v=None):
    p = out / "it" / (f"home-{v}" if v else "") / "index.html"
    return p.read_text(encoding="utf-8")


def strip_switch(h):
    return re.sub(r'<nav class="vswitch".*?</nav>\s*', "", h, flags=re.S)


def test_three_variants_built(site):
    out, _ = site
    for v in VARIANTS:
        assert (out / "it" / f"home-{v}" / "index.html").is_file()
    a = html(out, "a")
    assert 'class="vswitch"' in a  # barra di confronto solo sulle varianti
    assert 'class="vswitch"' not in html(out)
    norm = lambda x: re.sub(r"\s+", " ", x)
    seo = lambda x: re.sub(r'<(?:meta name="(?:robots|description)"|link rel="canonical")[^>]*>', "", x)
    assert norm(strip_switch(seo(a))) == norm(seo(html(out)))
    assert html(out, "b") != a and html(out, "c") != a


def test_variants_share_header_with_quote_counter(site):
    out, _ = site
    for v in VARIANTS:
        h = html(out, v)
        assert 'class="quote-count"' in h and 'id="site-search"' in h
        assert 'id="search-results"' in h and 'class="menu-toggle"' in h
        assert '/static/js/main.js' in h
        assert h.count("<h1") == 1, v
        assert 'class="skip" href="#main"' in h and '<main id="main"' in h


def test_each_variant_has_single_priority_hero_image(site):
    out, _ = site
    for v in VARIANTS:
        h = html(out, v)
        assert h.count('fetchpriority="high"') == 1, v
        imgs = re.findall(r"<img\b[^>]*>", h)
        assert imgs, v
        for img in imgs:
            assert 'alt="' in img, img
            if 'fetchpriority="high"' not in img:
                assert 'loading="lazy"' in img and 'decoding="async"' in img, img


def test_hero_image_has_dimensions_and_srcset(site):
    out, _ = site
    for v in VARIANTS:
        hero = re.search(r'<img\b[^>]*fetchpriority="high"[^>]*>', html(out, v)).group(0)
        assert re.search(r'\swidth="\d+"', hero) and re.search(r'\sheight="\d+"', hero), hero
        srcset = re.search(r'srcset="([^"]+)"', hero).group(1)
        assert len([s for s in srcset.split(",") if s.strip().endswith("w")]) >= 2, srcset
        assert 'sizes="' in hero
        for part in srcset.split(","):
            assert (out / part.split()[0].lstrip("/")).is_file(), part
        assert 'loading="lazy"' not in hero


def test_variants_have_no_autoplay_video_or_external_scripts(site):
    out, _ = site
    for v in VARIANTS:
        h = html(out, v)
        low = h.lower()
        assert "<video" not in low and "autoplay" not in low and "<iframe" not in low
        for src in re.findall(r'<script\b[^>]*\ssrc="([^"]+)"', h):
            assert src.startswith("/static/js/"), src
        for ref in re.findall(r'(?:href|src|srcset)="([^"]+)"', h):
            if ref.startswith(("http://", "https://", "//")):
                assert "<a " in h  # solo link di navigazione esterni, mai risorse
        for tag in re.findall(r"<(?:link|img|script|source)\b[^>]*>", h):
            assert "http://" not in tag and "https://" not in tag and '="//' not in tag, tag


def test_variant_css_is_only_linked_from_its_own_page(site):
    out, _ = site
    for f in out.rglob("*.html"):
        h = f.read_text(encoding="utf-8")
        linked = set(re.findall(r'href="/static/css/home-([abc])\.css"', h))
        rel = f.relative_to(out).as_posix()
        if rel.startswith("it/home-"):
            assert linked == {rel[len("it/home-")]}, (rel, linked)
        elif rel == "it/index.html":
            assert linked == {"a"}, linked
        else:
            assert not linked, (rel, linked)
    for v in VARIANTS:
        assert (out / "static" / "css" / f"home-{v}.css").is_file()


def test_no_prices_or_cart_wording_on_variants(site):
    out, _ = site
    for v in VARIANTS:
        low = html(out, v).lower()
        for w in ("carrello", "€", "prezzo", "prezzi"):
            assert w not in low, (v, w)


def test_home_numbers_come_from_catalog(site):
    out, c = site
    expected = {
        "lines": len(c.lines),
        "families": sum(len(l.families) for l in c.lines),
        "products": sum(len(f.products) for l in c.lines for f in l.families),
        "codes": len({a.code for l in c.lines for f in l.families for p in f.products for a in p.articles}),
    }
    per_line = {l.slug: sum(len(f.products) for f in l.families) for l in c.lines}
    for v in VARIANTS:
        h = html(out, v)
        stats = re.findall(r'data-stat="(\w+)"[^>]*>(\d+)<', h)
        for key, val in stats:
            assert int(val) == expected[key], (v, key, val)
        for slug, val in re.findall(r'data-count="([\w-]+)"[^>]*>(\d+)<', h):
            assert int(val) == per_line[slug], (v, slug, val)
        assert re.findall(r'data-count="', h), v  # ogni variante mostra i conteggi per linea
        # i numeri non marcati non devono comparire come "N prodotti/codici/articoli"
        bare = re.findall(r">(\d+)\s*(?:prodotti|codici|articoli)", h)
        assert not bare, (v, bare)
        for l in c.lines:
            for f in l.families:
                assert f.name["it"] in h.replace("&#39;", "'"), (v, f.name)
    assert re.findall(r'data-stat="', html(out, "b"))


def test_variant_photos_are_local_and_small(site):
    out, _ = site
    for v in VARIANTS:
        tags = " ".join(re.findall(r"<(?:img|source)\b[^>]*>", html(out, v)))
        for src in re.findall(r'(?:src|srcset)="([^"]+)"', tags):
            for part in src.split(","):
                path = part.split()[0]
                assert path.startswith("/static/img/home/"), path
                f = out / path.lstrip("/")
                assert f.is_file() and f.stat().st_size <= 150 * 1024, path


def test_variant_switch_is_in_normal_flow_and_styled_in_one_place(site):
    out, _ = site
    for v in VARIANTS:
        h = html(out, v)
        nav = re.search(r'<nav class="vswitch".*?</nav>', h, re.S).group(0)
        assert h.index('<nav class="vswitch"') < h.index("<header"), v  # striscia sopra la testata
        for rule in re.findall(r"\.vswitch[^{]*\{([^}]*)\}", nav):
            assert not re.search(r"position\s*:\s*(fixed|absolute|sticky)", rule), rule
        css = (out / "static" / "css" / f"home-{v}.css").read_text(encoding="utf-8")
        assert "vswitch" not in css, v
    assert "vswitch" not in (ROOT / "site" / "static" / "css" / "main.css").read_text(encoding="utf-8")


def test_image_dimensions_match_files(site):
    from PIL import Image
    out, _ = site
    seen = set()
    for v in VARIANTS:
        for tag in re.findall(r"<(?:img|source)\b[^>]*>", html(out, v)):
            srcset = re.search(r'srcset="([^"]+)"', tag).group(1)
            largest = max((p.split() for p in srcset.split(",")), key=lambda p: int(p[1][:-1]))[0]
            w, h = Image.open(out / largest.lstrip("/")).size
            assert f'width="{w}"' in tag and f'height="{h}"' in tag, (v, tag)
            for path, desc in (p.split() for p in srcset.split(",")):
                assert Image.open(out / path.lstrip("/")).size[0] == int(desc[:-1]), path
            seen.add(largest)
    garden = [s for s in seen if "linea-garden" in s]
    assert garden, seen
    for g in garden:
        f = out / g.lstrip("/")
        assert f.stat().st_size <= 90 * 1024
        assert Image.open(f).size == (960, 640)
