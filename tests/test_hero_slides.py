"""Carosello dell'hero della home: struttura, foto rinviate, giorno/notte, numeri dal catalogo, accessibilita'.
Qui la build e' a una data senza fiere future (solo le 4 sezioni); le slide delle fiere sono in test_hero_fairs.py."""
import re
from pathlib import Path

import pytest
from PIL import Image

from arnoplast.catalog import load_catalog
from arnoplast.site.build import HERO_SLIDES, HEROES, NIGHT_HEROES, build_site
from arnoplast.site.i18n import t

ROOT = Path(__file__).resolve().parent.parent
CSS = ROOT / "site" / "static" / "css"
JS = ROOT / "site" / "static" / "js" / "hero-slides.js"
LIMIT = 250 * 1024


NO_FAIRS = "2026-12-01"  # dopo l'ultima fiera di data/fiere.json


@pytest.fixture(scope="module")
def site(tmp_path_factory):
    out = tmp_path_factory.mktemp("site")
    c = load_catalog(ROOT / "data" / "catalog.json")
    with pytest.MonkeyPatch.context() as mp:
        mp.setenv("ARNO_BUILD_DATE", NO_FAIRS)
        build_site(c, out)
    return out, c, (out / "it" / "index.html").read_text(encoding="utf-8")


def hero(h):
    return h[h.index('<section class="a-hero hs"'):h.index('<ul class="wrap a-facts">')]


def pics(h):
    """Markup della foto di ogni slide, nell'ordine."""
    return re.findall(r'<div class="hs-pic[^"]*"[^>]*>(.*?)</picture>', hero(h), re.S)


def caps(h):
    return re.findall(r'<div class="hs-cap[^"]*" role="group"[^>]*>.*?</a>', hero(h), re.S)


def test_four_slides_in_order_with_links_to_sections(site):
    _, c, h = site
    assert HERO_SLIDES == ["agricoltura", "garden", "olive", "azienda"]
    names = {l.slug: l.name["it"] for l in c.lines}
    labels = [names.get(k) or t("page.company", "it") for k in HERO_SLIDES]
    cs = caps(h)
    assert len(pics(h)) == 4 and len(cs) == 4
    for i, (cap, key, label) in enumerate(zip(cs, HERO_SLIDES, labels), 1):
        assert f'aria-roledescription="slide" aria-label="{i} di 4: {label}"' in cap, cap
        assert f'<a class="hs-more" href="/it/{key}/">' in cap
        assert f'<p class="hs-label">{label}</p>' in cap and f'data-label="{label}"' in cap
    assert 'is-active' in cs[0] and not any('is-active' in x.split(">")[0] for x in cs[1:])
    hs = hero(h)
    assert 'aria-roledescription="carousel"' in hs and 'id="hs-caps" aria-live="off"' in hs
    assert 'data-of="di"' in hs and "data-fair-end" not in hs
    assert '<p class="hs-count" aria-hidden="true"><b class="hs-cur">01</b> / <span class="hs-tot">04</span></p>' in hs
    # Senza JS: i link a tutte le sezioni restano visibili (noscript), i comandi no (home.css).
    nos = re.search(r"<noscript>(.*?)</noscript>", hs, re.S).group(1)
    assert re.findall(r'href="([^"]+)"', nos) == [f"/it/{k}/" for k in HERO_SLIDES]
    home_css = (CSS / "home.css").read_text(encoding="utf-8")
    assert "html:not(.js) .hs-caps, html:not(.js) .hs-ctrl { display: none; }" in home_css


def test_controls_are_accessible_buttons(site):
    _, _, h = site
    hs = hero(h)
    for cls, key in (("hs-prev", "hs.prev"), ("hs-next", "hs.next"), ("hs-pause", "hs.pause")):
        tag = re.search(rf'<button type="button" class="hs-btn {cls}"[^>]*>', hs).group(0)
        assert f'aria-label="{t(key, "it")}"' in tag, tag
    pause = re.search(r'<button[^>]*class="hs-btn hs-pause"[^>]*>', hs).group(0)
    assert f'data-play="{t("hs.play", "it")}"' in pause
    dots = re.findall(r'<button type="button" class="hs-dot"[^>]*>', hs)
    assert len(dots) == 4 and 'aria-current="true"' in dots[0] and all("aria-current" not in d for d in dots[1:])
    assert all(re.search(r'aria-label="\d di 4: ', d) for d in dots)


def test_only_first_photo_is_downloaded_with_priority(site):
    _, _, h = site
    assert h.count('fetchpriority="high"') == 1
    first, *rest = pics(h)
    assert 'fetchpriority="high"' in first and re.search(r'\ssrcset="', first) and re.search(r'\ssrc="', first)
    assert "agricoltura-giorno" in first
    for p in rest:  # solo dati: niente src/srcset veri, niente preload, niente lazy da sbloccare
        assert not re.search(r'\s(?:src|srcset)="', p), p
        assert 'data-srcset="' in p and 'data-src="' in p and 'loading=' not in p and "fetchpriority" not in p
    assert 'rel="preload"' not in h
    js = JS.read_text(encoding="utf-8")
    assert "addEventListener('load'" in js and "requestIdleCallback" in js and "setTimeout" in js


def test_slide_images_exist_are_small_and_have_matching_dimensions(site):
    out, _, h = site
    tags = re.findall(r"<(?:img|source)\b[^>]*>", "".join(pics(h)))
    assert len(tags) == 8  # foto larga + ritaglio verticale per slide
    for tag in tags:
        w, ht = int(re.search(r'\swidth="(\d+)"', tag).group(1)), int(re.search(r'\sheight="(\d+)"', tag).group(1))
        assert re.search(r'\ssizes="[^"]+"', tag), tag
        for attr in ("srcset", "data-srcset", "data-night-srcset"):
            m = re.search(rf'\s{attr}="([^"]+)"', tag)
            if not m:
                continue
            parts = [p.split() for p in m.group(1).split(",")]
            for path, desc in parts:
                f = out / path.lstrip("/")
                assert f.is_file() and f.suffix == ".webp" and f.stat().st_size <= LIMIT, path
                with Image.open(f) as im:
                    assert im.width == int(desc[:-1]), path
                    assert abs(im.height / im.width - ht / w) < 0.01, path  # stesse proporzioni: CLS 0
            with Image.open(out / max(parts, key=lambda p: int(p[1][:-1]))[0].lstrip("/")) as im:
                assert im.size == (w, ht), tag
    for tag in tags:
        if tag.startswith("<img") and ("agricoltura-" in tag or "sede-" in tag):
            assert 'sizes="120vw"' in tag, tag  # margine per lo zoom lento, come l'hero di Azienda


def test_night_variant_on_every_slide_that_has_one(site):
    _, _, h = site
    for key, p in zip(HERO_SLIDES, pics(h)):
        night = re.findall(r'data-night-srcset="([^"]+)"', p)
        if key in NIGHT_HEROES:
            photo, portrait = NIGHT_HEROES[key]
            assert len(night) == 2 and portrait in night[0] and f"{photo}-3200" in night[1], key
        else:
            assert not night, key
    # Scelta prima del caricamento per la prima foto (script in linea), dopo per le altre (hero-slides.js).
    first = hero(h)[:hero(h).index("</script>")]
    assert "getHours()" in first and "luce=(notte|giorno)" in first
    js = JS.read_text(encoding="utf-8")
    assert "getHours()" in js and "luce=(notte|giorno)" in js and "data-night-srcset" in js


def test_caption_numbers_come_from_catalog(site):
    _, c, h = site
    lines = {l.slug: l for l in c.lines}
    codes = {a.code for l in c.lines for f in l.families for p in f.products for a in p.articles}
    expected = {"azienda-lines": len(c.lines), "azienda-codes": len(codes)}
    for slug, l in lines.items():
        expected[f"{slug}-families"] = len(l.families)
        expected[f"{slug}-products"] = sum(len(f.products) for f in l.families)
    got = dict(re.findall(r'data-slide-stat="([\w-]+)">(\d+)<', h))
    assert got.keys() == {k for k in expected if k.split("-")[0] in HERO_SLIDES}
    for k, v in got.items():
        assert int(v) == expected[k], k
    olive = caps(h)[2]
    assert f"</b> {t('hv.family', 'it')} · " in olive  # singolare con una sola famiglia
    # Nessun conteggio scritto a mano nei modelli della home.
    for tpl in ("home.html", "_home.html"):
        src = (ROOT / "site" / "templates" / tpl).read_text(encoding="utf-8")
        assert not re.search(r"\d+\s*(?:famigli|prodott|codic|line[ae])", src), tpl


def test_reduced_motion_and_motion_rules():
    css = (CSS / "home.css").read_text(encoding="utf-8")
    nopref = css[css.index("@media (prefers-reduced-motion: no-preference) {\n  .hs-pic"):]
    nopref = nopref[:nopref.index("\n}\n")]
    for rule in (".hs-pic { transition: opacity", "hs-prog 6.5s linear", ".hs.is-held .hs-dot[aria-current] .hs-fill { animation-play-state: paused; }"):
        assert rule in nopref, rule
    assert "animation" not in css.replace(nopref, "").split("/* Carosello")[1].split("@keyframes hs-prog")[0]
    kf = re.search(r"@keyframes hs-prog \{(.*?)\}\s*\n", css).group(1)
    assert set(re.findall(r"([\w-]+)\s*:", kf)) == {"transform"}
    js = JS.read_text(encoding="utf-8")
    assert "prefers-reduced-motion: reduce" in js and "paused = calm" in js
    assert not re.search(r"^\s*(import|export)\b", js, re.M) and "fetch(" not in js and "http" not in js
    # Pause: pulsante, mouse, fuoco, ricerca in uso, scheda nascosta.
    for k in ("'hover'", "'focus'", "'search'", "'hidden'", "pauseBtn.addEventListener('click'"):
        assert k in js, k
    assert "ArrowLeft" in js and "touchend" in js


def test_home_css_and_script_only_on_home(site):
    out, _, _ = site
    for f in out.rglob("*.html"):
        tag = re.search(r'<script\b[^>]*src="/static/js/hero-slides\.js"[^>]*>', f.read_text(encoding="utf-8"))
        assert bool(tag) == (f.relative_to(out).as_posix() in ("it/index.html", "en/index.html")), f
        if tag:
            assert "defer" in tag.group(0) and 'type="module"' not in tag.group(0)
    assert (CSS / "main.css").stat().st_size < 30_000


def test_slide_photos_configured_for_every_slide():
    for key in HERO_SLIDES:
        photo, portrait, alt = HEROES[key]
        assert alt and photo and portrait
        for name in (photo, portrait) + NIGHT_HEROES.get(key, ()):
            assert list((ROOT / "site" / "static" / "img" / "home").glob(f"{name}-*.webp")), name
    for name in ("agricoltura-giorno", "agricoltura-notte"):
        with Image.open(ROOT / "content" / "photos" / f"{name}.jpg") as im:
            assert im.size == (4000, 2500)
