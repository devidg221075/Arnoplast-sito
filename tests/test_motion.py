"""Hero a tutto schermo (home, linee, azienda) e animazioni: struttura, immagini, accessibilita', vincoli."""
import re
from pathlib import Path

import pytest

from arnoplast.catalog import load_catalog
from arnoplast.site.build import build_site

ROOT = Path(__file__).resolve().parent.parent
CSS = ROOT / "site" / "static" / "css"
JS = ROOT / "site" / "static" / "js"
HERO_PAGES = ["it", "it/agricoltura", "it/garden", "it/olive", "it/azienda"]
COMPACT_PAGES = ["it/agricoltura/copripalo", "it/agricoltura/copripalo/copripalo-prisma", "it/garden/testine",
                 "it/contatti", "it/richiesta-preventivo"]


@pytest.fixture(scope="module")
def site(tmp_path_factory):
    out = tmp_path_factory.mktemp("site")
    c = load_catalog(ROOT / "data" / "catalog.json")
    build_site(c, out)
    return out, c


def html(out, rel):
    return (out / rel / "index.html").read_text(encoding="utf-8")


@pytest.mark.parametrize("rel", HERO_PAGES)
def test_hero_pages_have_full_screen_hero_with_one_priority_image(site, rel):
    out, _ = site
    h = html(out, rel)
    assert re.search(r'<section class="(a-hero|hx [^"]*)"', h), rel
    assert 'class="hx-media" data-parallax' in h
    prio = re.findall(r'<img\b[^>]*fetchpriority="high"[^>]*>', h)
    assert len(prio) == 1, rel
    img = prio[0]
    assert re.search(r'\swidth="\d+"', img) and re.search(r'\sheight="\d+"', img), img
    assert 'loading="lazy"' not in img and 'sizes="' in img
    srcset = re.search(r'srcset="([^"]+)"', img).group(1)
    assert len(srcset.split(",")) >= 2, srcset
    for tag in re.findall(r"<(?:img|source)\b[^>]*>", h):
        if "srcset=" not in tag or "arnoplast-logo" in tag:
            continue
        assert re.search(r'\swidth="\d+"', tag) and re.search(r'\sheight="\d+"', tag), tag
        for part in re.search(r'srcset="([^"]+)"', tag).group(1).split(","):
            f = out / part.split()[0].lstrip("/")
            assert f.is_file() and f.suffix == ".webp" and f.stat().st_size <= 250 * 1024, part
    for other in re.findall(r"<img\b[^>]*>", h):
        if other != img and "arnoplast-logo" not in other:
            assert 'loading="lazy"' in other, other


@pytest.mark.parametrize("rel", COMPACT_PAGES)
def test_families_and_products_keep_compact_opening(site, rel):
    out, _ = site
    h = html(out, rel)
    assert 'class="page-head"' in h or '<article class="product">' in h
    assert "hx-media" not in h and "a-hero" not in h and 'class="hx ' not in h
    assert h.count('fetchpriority="high"') <= 1  # al piu' la foto del prodotto, mai una foto d'apertura


@pytest.mark.parametrize("rel", HERO_PAGES)
def test_split_title_stays_readable_for_screen_readers(site, rel):
    out, _ = site
    h = html(out, rel)
    assert h.count("<h1") == 1
    h1 = re.search(r"<h1[^>]*>(.*?)</h1>", h, re.S).group(1)
    full = re.search(r'<span class="sr-only">([^<]+)</span>', h1).group(1)
    split = re.search(r'<span class="hx-split[^"]*" aria-hidden="true"[^>]*>(.*)</span>$', h1, re.S).group(1)
    assert re.sub(r"<[^>]+>", "", split) == full
    assert full.strip()


def test_line_hero_numbers_come_from_catalog(site):
    out, c = site
    for line in c.lines:
        h = html(out, f"it/{line.slug}")
        nums = [int(n) for n in re.findall(r"data-countup>(\d+)<", h)]
        codes = {a.code for f in line.families for p in f.products for a in p.articles}
        assert nums == [len(line.families), sum(len(f.products) for f in line.families), len(codes)], line.slug
        assert 'id="famiglie"' in h and 'href="#famiglie"' in h


def test_motion_assets_linked_on_every_page(site):
    out, _ = site
    for f in out.rglob("*.html"):
        h = f.read_text(encoding="utf-8")
        assert '<link rel="stylesheet" href="/static/css/motion.css">' in h, f
        tag = re.search(r'<script\b[^>]*src="/static/js/anim\.js"[^>]*>', h).group(0)
        assert "defer" in tag and 'type="module"' not in tag, tag
    assert (out / "static" / "css" / "motion.css").is_file() and (out / "static" / "js" / "anim.js").is_file()


def test_anim_js_is_a_classic_script_without_dependencies():
    src = (JS / "anim.js").read_text(encoding="utf-8")
    assert not re.search(r"^\s*(import|export)\b", src, re.M)
    assert "fetch(" not in src and "http" not in src
    assert "prefers-reduced-motion: reduce" in src
    assert "{ passive: true }" in src and "requestAnimationFrame" in src


def test_motion_css_rules():
    css = (CSS / "motion.css").read_text(encoding="utf-8")
    rules = re.sub(r"@keyframes [\w-]+ \{.*?\}\s*\n", "", css)
    assert "@view-transition { navigation: auto; }" in css
    assert "@media (prefers-reduced-motion: reduce)" in css and "navigation: none" in css
    assert "@media (prefers-reduced-motion: no-preference)" in css
    assert "@supports (animation-timeline: view())" in css
    assert "url(" not in css and "@import" not in css and "@font-face" not in css
    # Solo transform e opacity nei fotogrammi.
    for body in re.findall(r"@keyframes [\w-]+ \{(.*?)\}\s*\n", css):
        for prop in re.findall(r"([\w-]+)\s*:", body):
            assert prop in ("transform", "opacity"), (prop, body)
    # Stati nascosti solo sotto html.js (esclusi i decori ::before/::after che compaiono al passaggio).
    for sel, block in re.findall(r"([^{}]+)\{([^{}]*opacity:\s*0[;\s][^{}]*)\}", rules):
        if not re.search(r"::(before|after)\s*$", sel.strip()):
            assert sel.strip().startswith(".js "), sel


def test_home_hero_photo_is_not_faded_in():
    css = (CSS / "motion.css").read_text(encoding="utf-8") + (CSS / "home.css").read_text(encoding="utf-8")
    kb = re.search(r"@keyframes hx-kb \{(.*?)\}\s*\n", css).group(1)
    assert "opacity" not in kb


def test_main_css_budget():
    assert (CSS / "main.css").stat().st_size < 30_000


def test_main_css_reduced_motion_rule_present():
    assert "@media (prefers-reduced-motion: reduce)" in (CSS / "main.css").read_text(encoding="utf-8")


def test_hero_photos_from_catalog_folder_only(site):
    out, _ = site
    for rel in HERO_PAGES:
        for src in re.findall(r'(?:src|srcset)="(/static/img/[^"]+)"', html(out, rel)):
            for part in src.split(","):
                assert part.split()[0].startswith(("/static/img/home/", "/static/img/arnoplast-logo")), part


def test_company_hero_has_night_variant_chosen_in_page(site):
    out, _ = site
    h = html(out, "it/azienda")
    assert 'src="/static/img/home/sede-giorno-' in h and "sede-notte" not in re.search(r"<img\b[^>]*fetchpriority[^>]*>", h).group(0).split("data-night-srcset")[0]
    night = re.findall(r'data-night-srcset="([^"]+)"', h)
    assert len(night) == 2 and all("sede-notte" in n for n in night)  # foto larga + ritaglio per i telefoni
    for srcset in night:
        for part in srcset.split(","):
            f = out / part.split()[0].lstrip("/")
            assert f.is_file() and f.suffix == ".webp" and f.stat().st_size <= 250 * 1024, part
    assert "getHours()" in h and "luce=(notte|giorno)" in h
    for rel in ("it", "it/agricoltura", "it/garden", "it/olive"):
        assert "data-night-srcset" not in html(out, rel), rel
