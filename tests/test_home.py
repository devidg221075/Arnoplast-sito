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


def test_flipbook_base_comes_from_the_page_images():
    js = (Path(__file__).resolve().parent.parent / "site" / "static" / "js" / "flipbook.js").read_text(encoding="utf-8")
    assert ".fb-list img" in js and "getAttribute('src')" in js  # percorsi relativi anche da file://


def test_videos_page_library_with_filters_and_search(tmp_path):
    from arnoplast.site.build import DATA_DIR, load_videos
    c = load_catalog(DATA_DIR / "catalog.json")
    build_site(c, tmp_path, videos=load_videos())
    h = (tmp_path / "it" / "video" / "index.html").read_text(encoding="utf-8")
    assert "in preparazione" not in h and "videos.js" in h and "<iframe" not in h
    assert h.count('class="vt-card"') == 37 and h.count("player.vimeo.com/video/") == 10
    for key in ("all", "video", "agricoltura", "garden", "olive"):
        assert f'data-video-filter="{key}"' in h
    for yid in ("yMqD2nmIcNs", "y2fjs31LI58", "I5Yv9WzYzLo", "wGBNoeV_srQ"):
        assert f"youtube-nocookie.com/embed/{yid}" in h
    assert "GEN60" in h and "ARNOplast: la nostra azienda" in h and "1:38" in h
    assert 'id="video-search"' in h and "<dialog" in h and "data-video-empty" in h
    assert ' src="https://www.youtube' not in h and ' src="https://player.vimeo' not in h
    assert "/it/agricoltura/copripalo/copripalo-monviso/" in h


def test_tutorial_json_products_exist_in_real_catalog():
    import json
    from arnoplast.site.build import DATA_DIR
    c = load_catalog(DATA_DIR / "catalog.json")
    keys = {f"{l.slug}/{f.slug}/{p.slug}" for l in c.lines for f in l.families for p in f.products}
    for v in json.loads((DATA_DIR / "tutorial.json").read_text(encoding="utf-8"))["video"]:
        assert v.get("prodotto") is None or v["prodotto"] in keys, v


def test_contacts_page_form_fields_and_map_on_click(site):
    out, _ = site
    h = (out / "it" / "contatti" / "index.html").read_text(encoding="utf-8")
    assert "<iframe" not in h and ' src="https://www.google.com/maps' not in h and "data-map-src=" in h
    req = set(re.findall(r'<(?:input|select|textarea)[^>]* name="(\w+)"[^>]*required', h))
    assert req == {"name", "email", "phone", "type", "message", "privacy"}, req
    for opt in ("Azienda Agricola", "Riv. Macc. Agr./Officina", "Grossa Distrib. Spec.", "Ente"):
        assert f'<option value="{opt}">' in h
    for data in ("01987190483", "FI355293", "49.580,00", "+39 055 83 47 031", "info@arnoplast.it", "ARNOcommerciale"):
        assert data in h, data
    assert 'id="c-web"' in h  # trappola antispam


def test_cookie_page_lists_real_storage_and_services(site):
    out, _ = site
    h = (out / "it" / "cookie" / "index.html").read_text(encoding="utf-8")
    assert "arnoplast.quote.v2" in h and "Facebook" in h and "YouTube" in h and "Vimeo" in h and "Google Maps" in h
    assert "privacy-policy-arno-plast" in h and "in preparazione" not in h
    # coerenza con il codice: l'unica chiave di archivio locale usata dal sito e' quella elencata
    js = (Path(__file__).resolve().parent.parent / "site" / "static" / "js")
    keys = set(re.findall(r"'(arnoplast\.[\w.]+)'", (js / "quote.js").read_text(encoding="utf-8")))
    assert "arnoplast.quote.v2" in keys
    for f in js.glob("*.js"):
        assert "document.cookie" not in f.read_text(encoding="utf-8"), f.name


def test_home_video_section_loads_player_only_on_click(tmp_path):
    from arnoplast.site.build import DATA_DIR, load_videos
    c = load_catalog(DATA_DIR / "catalog.json")
    build_site(c, tmp_path, videos=load_videos())
    h = (tmp_path / "it" / "index.html").read_text(encoding="utf-8")
    assert 'id="a-video-t"' in h and "home-video.js" in h and "<iframe" not in h
    assert 'data-hv-src="https://www.youtube-nocookie.com/embed/6t_hUfHWVwQ' in h
    assert h.index('id="a-idea-t"') < h.index('id="a-video-t"') < h.index('id="a-steps-t"')


def test_header_is_sticky_with_shadow_hook_and_app_band_on_home(site):
    out, _ = site
    root = Path(__file__).resolve().parent.parent / "site" / "static"
    assert re.search(r"\.site-header \{[^}]*position: sticky; top: 0;", (root / "css" / "main.css").read_text(encoding="utf-8"))
    assert "is-stuck" in (root / "css" / "nav.css").read_text(encoding="utf-8") and "is-stuck" in (root / "js" / "nav.js").read_text(encoding="utf-8")
    h = (out / "it" / "index.html").read_text(encoding="utf-8")
    band = h.split('id="a-app-t"')[1].split("</section>")[0]
    assert "apps.apple.com" in band and "play.google.com" in band
    assert h.index('id="a-steps-t"') < h.index('id="a-app-t"') < h.index('id="a-contact-t"')


def test_seo_open_graph_jsonld_and_unique_titles(tmp_path):
    import json
    from arnoplast.site.build import DATA_DIR, load_videos, SITE_URL
    c = load_catalog(DATA_DIR / "catalog.json")
    build_site(c, tmp_path, videos=load_videos())
    titles, descs = {}, {}
    for f in tmp_path.glob("it/**/index.html"):
        h = f.read_text(encoding="utf-8")
        rel = f.relative_to(tmp_path).as_posix()
        if "applicazioni" in rel:  # rimando alla Galleria (noindex, canonical sulla Galleria)
            continue
        t = re.search(r"<title>(.*?)</title>", h, re.S).group(1)
        assert t not in titles, (rel, titles.get(t))
        titles[t] = rel
        d = re.search(r'<meta name="description" content="([^"]+)"', h)
        assert d and d.group(1) not in descs, rel
        descs[d.group(1)] = rel
        assert h.count("<h1") == 1, rel
        for p in ("og:title", "og:description", "og:url", "og:image", "twitter:card"):
            assert f'"{p}"' in h, (rel, p)
        assert f'content="{SITE_URL}/static/img/home/sede-giorno-1280.webp"' in h
    home = (tmp_path / "it" / "index.html").read_text(encoding="utf-8")
    ld = json.loads(re.search(r'<script type="application/ld\+json">(.*?)</script>', home, re.S).group(1))
    assert ld["@type"] == "Organization" and ld["telephone"] == "+390558347031" and ld["address"]["postalCode"] == "50067"
    assert len(ld["sameAs"]) == 3


def test_security_headers_file_with_inline_hashes(tmp_path):
    import base64
    import hashlib
    c = load_catalog(Path(__file__).resolve().parent.parent / "data" / "catalog.json")
    build_site(c, tmp_path)
    hdr = (tmp_path / "_headers").read_text(encoding="utf-8")
    for needle in ("Content-Security-Policy:", "X-Content-Type-Options: nosniff", "Referrer-Policy:", "Permissions-Policy:",
                   "frame-ancestors 'none'", "object-src 'none'", "Strict-Transport-Security:"):
        assert needle in hdr, needle
    assert "unsafe-inline'" not in hdr.split("script-src")[1].split(";")[0]  # nessun script inline generico
    for f in tmp_path.rglob("*.html"):
        for m in re.finditer(r"<script(?![^>]*\ssrc=)(?![^>]*ld\+json)[^>]*>(.*?)</script>", f.read_text(encoding="utf-8"), re.S):
            digest = base64.b64encode(hashlib.sha256(m.group(1).encode()).digest()).decode()
            assert f"'sha256-{digest}'" in hdr, f
    for host in ("youtube-nocookie.com", "player.vimeo.com", "www.facebook.com", "www.google.com"):
        assert host in hdr
