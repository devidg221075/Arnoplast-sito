"""Homepage "Campo" su /it/ (scelta tra le tre varianti del prototipo)."""
import re
from pathlib import Path

import pytest

from arnoplast.catalog import load_catalog
from arnoplast.site.build import build_site

ROOT = Path(__file__).resolve().parent.parent


@pytest.fixture(scope="module")
def site(tmp_path_factory):
    out = tmp_path_factory.mktemp("site")
    c = load_catalog(ROOT / "data" / "catalog.json")
    # Data senza fiere future: la prima slide della home e' la foto di Agricoltura (fiere: test_hero_fairs.py).
    with pytest.MonkeyPatch.context() as mp:
        mp.setenv("ARNO_BUILD_DATE", "2026-12-01")
        build_site(c, out)
    return out, c


def html(out):
    return (out / "it" / "index.html").read_text(encoding="utf-8")


def test_single_home_without_variants(site):
    out, _ = site
    for v in "abc":
        assert not (out / "it" / f"home-{v}").exists()
        assert not (out / "static" / "css" / f"home-{v}.css").exists()
    h = html(out)
    assert "vswitch" not in h and "noindex" not in h
    assert 'class="a-hero hs"' in h


def test_home_has_header_with_quote_counter(site):
    out, _ = site
    h = html(out)
    assert 'class="quote-count"' in h and 'id="site-search"' in h
    assert 'id="search-results"' in h and 'class="menu-toggle"' in h
    assert '/static/js/main.js' in h
    assert h.count("<h1") == 1
    assert 'class="skip" href="#main"' in h and '<main id="main"' in h


def test_home_has_single_priority_hero_image(site):
    out, _ = site
    h = html(out)
    assert h.count('fetchpriority="high"') == 1
    imgs = [t for t in re.findall(r"<img\b[^>]*>", h) if "arnoplast-logo" not in t]
    assert imgs
    deferred = 0
    for img in imgs:
        assert 'alt="' in img, img
        if 'fetchpriority="high"' in img:
            continue
        if 'data-srcset="' in img:  # foto delle slide successive del carosello: solo dati, nessun download
            assert not re.search(r'\s(?:src|srcset)="', img) and 'decoding="async"' in img, img
            deferred += 1
        else:
            assert 'loading="lazy"' in img and 'decoding="async"' in img, img
    assert deferred == 3


def test_hero_image_has_dimensions_and_srcset(site):
    out, _ = site
    hero = re.search(r'<img\b[^>]*fetchpriority="high"[^>]*>', html(out)).group(0)
    assert re.search(r'\swidth="\d+"', hero) and re.search(r'\sheight="\d+"', hero), hero
    srcset = re.search(r'srcset="([^"]+)"', hero).group(1)
    assert len([s for s in srcset.split(",") if s.strip().endswith("w")]) >= 2, srcset
    assert 'sizes="' in hero
    for part in srcset.split(","):
        assert (out / part.split()[0].lstrip("/")).is_file(), part
    assert 'loading="lazy"' not in hero


def test_home_has_no_autoplay_video_or_external_scripts(site):
    out, _ = site
    h = html(out)
    low = h.lower()
    assert "<video" not in low and "autoplay" not in low and "<iframe" not in low
    for src in re.findall(r'<script\b[^>]*\ssrc="([^"]+)"', h):
        assert src.startswith("/static/js/"), src
    for ref in re.findall(r'(?:href|src|srcset)="([^"]+)"', h):
        if ref.startswith(("http://", "https://", "//")):
            assert "<a " in h  # solo link di navigazione esterni, mai risorse
    for tag in re.findall(r"<(?:link|img|script|source)\b[^>]*>", h):
        if 'rel="canonical"' in tag:
            continue  # indirizzo canonico, non una risorsa scaricata
        assert "http://" not in tag and "https://" not in tag and '="//' not in tag, tag


def test_home_css_is_only_linked_from_home(site):
    out, _ = site
    for f in out.rglob("*.html"):
        h = f.read_text(encoding="utf-8")
        linked = "/static/css/home.css" in h
        assert linked == (f.relative_to(out).as_posix() == "it/index.html"), f
    assert (out / "static" / "css" / "home.css").is_file()


def test_no_prices_or_cart_wording_on_home(site):
    out, _ = site
    low = html(out).lower()
    for w in ("carrello", "€", "prezzo", "prezzi"):
        assert w not in low, w


def test_home_numbers_come_from_catalog(site):
    out, c = site
    expected = {
        "lines": len(c.lines),
        "families": sum(len(l.families) for l in c.lines),
        "products": sum(len(f.products) for l in c.lines for f in l.families),
        "codes": len({a.code for l in c.lines for f in l.families for p in f.products for a in p.articles}),
    }
    per_line = {l.slug: sum(len(f.products) for f in l.families) for l in c.lines}
    h = html(out)
    stats = re.findall(r'data-stat="(\w+)"[^>]*>(\d+)<', h)
    for key, val in stats:
        assert int(val) == expected[key], (key, val)
    for slug, val in re.findall(r'data-count="([\w-]+)"[^>]*>(\d+)<', h):
        assert int(val) == per_line[slug], (slug, val)
    assert re.findall(r'data-count="', h)  # la home mostra i conteggi per linea
    # i numeri non marcati non devono comparire come "N prodotti/codici/articoli"
    bare = re.findall(r">(\d+)\s*(?:prodotti|codici|articoli)", h)
    assert not bare, bare
    for l in c.lines:
        for f in l.families:
            assert f.name["it"] in h.replace("&#39;", "'"), f.name


def test_home_photos_are_local_and_small(site):
    out, _ = site
    h = html(out)
    hero = h[h.index('<div class="hx-media"'):h.index('<div class="wrap a-hero-in')]
    rest = h.replace(hero, "")
    # Foto dell'hero (carosello, fino a 3200 px): <= 250 KB come gli altri hero; le altre foto della home <= 150 KB.
    for part_html, limit in ((hero, 250), (rest, 150)):
        tags = " ".join(t for t in re.findall(r"<(?:img|source)\b[^>]*>", part_html) if "arnoplast-logo" not in t)
        srcs = re.findall(r'(?:src|srcset)="([^"]+)"', tags)  # anche data-src, data-srcset, data-night-srcset
        assert srcs
        for src in srcs:
            for part in src.split(","):
                path = part.split()[0]
                assert path.startswith("/static/img/home/"), path
                f = out / path.lstrip("/")
                assert f.is_file() and f.stat().st_size <= limit * 1024, path


def test_image_dimensions_match_files(site):
    from PIL import Image

    def size(path):
        with Image.open(out / path.lstrip("/")) as im:
            return im.size

    out, _ = site
    seen = set()
    for tag in (t for t in re.findall(r"<(?:img|source)\b[^>]*>", html(out)) if "arnoplast-logo" not in t):
        srcset = re.search(r'srcset="([^"]+)"', tag).group(1)
        largest = max((p.split() for p in srcset.split(",")), key=lambda p: int(p[1][:-1]))[0]
        w, h = size(largest)
        assert f'width="{w}"' in tag and f'height="{h}"' in tag, tag
        for path, desc in (p.split() for p in srcset.split(",")):
            assert size(path)[0] == int(desc[:-1]), path
        seen.add(largest)
    garden = [s for s in seen if "linea-garden" in s]
    assert garden, seen
    for g in garden:
        assert (out / g.lstrip("/")).stat().st_size <= 90 * 1024
        assert size(g) == (960, 640)


def test_social_section_loads_facebook_only_after_click(site):
    out, _ = site
    h = (out / "it" / "index.html").read_text(encoding="utf-8")
    assert 'id="a-social-t"' in h and "I nostri post social" in h
    assert "social.js" in h
    # nessun iframe e nessuna richiesta a terzi nel markup: l'indirizzo e' un dato letto dallo script al clic
    assert "<iframe" not in h and ' src="https://www.facebook.com' not in h
    assert "data-fb-src=\"https://www.facebook.com/plugins/page.php?href=https%3A%2F%2Fwww.facebook.com%2Farnoplast" in h
    for url in ("facebook.com/arnoplast", "linkedin.com/company/arnoplast-s-r-l", "instagram.com/arnoplast"):
        assert url in h.split('id="a-social-t"')[1].split("</section>")[0], url
    js = (Path(__file__).resolve().parent.parent / "site" / "static" / "js" / "social.js").read_text(encoding="utf-8")
    assert "createElement('iframe')" in js and "preventDefault" in js


def test_gallery_page_lists_photos_with_lightbox(site):
    out, _ = site
    h = (out / "it" / "galleria" / "index.html").read_text(encoding="utf-8")
    from arnoplast.site.build import load_gallery
    photos = load_gallery()
    assert photos and h.count('class="gl-item"') == len(photos)
    assert "gallery.js" in h and "gallery.css" in h and "in preparazione" not in h
    for ph in photos:
        assert (out / "static" / "img" / "galleria" / f"{ph['id']}.webp").is_file() or \
            (Path(__file__).resolve().parent.parent / "site" / "static" / "img" / "galleria" / f"{ph['id']}.webp").is_file()


def test_hero_titles_share_the_same_heavy_weight():
    css = (Path(__file__).resolve().parent.parent / "site" / "static" / "css" / "motion.css").read_text(encoding="utf-8")
    assert re.search(r"\.hx-title \{ font-weight: 900;", css)


def test_catalog_flipbook_pages(site):
    out, _ = site
    for slug, n in (("agricoltura", 72), ("garden", 56), ("olive", 12)):
        h = (out / "it" / "cataloghi" / slug / "index.html").read_text(encoding="utf-8")
        assert f'data-n="{n}"' in h and h.count("<li><img") == n and "flipbook.js" in h
        assert f"/it/cataloghi/{slug}/" in (out / "it" / "cataloghi" / "index.html").read_text(encoding="utf-8")
