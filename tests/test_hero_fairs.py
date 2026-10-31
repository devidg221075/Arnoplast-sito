"""Slide delle fiere future nell'hero della home: contesto (solo future, tutte, dalla piu' vicina), markup (una sola
slide iniziale, data-fair-end, link alla scheda, niente testo inventato, escape), fondo grafico senza foto, foto della
fiera (versioni hero, sizes, dimensioni, peso), build con data futura = 4 slide, tools/fiere_images.py."""
import json
import re
from pathlib import Path

import pytest
from PIL import Image, ImageFilter

from arnoplast.catalog import load_catalog
from arnoplast.site import build as B
from arnoplast.site import fair_images as FI
from arnoplast.site.i18n import STRINGS, t

ROOT = Path(__file__).resolve().parent.parent
JS = ROOT / "site" / "static" / "js" / "hero-slides.js"
CATALOG = load_catalog(ROOT / "data" / "catalog.json")
LIMIT = 250 * 1024


def fair(**kw):
    f = {"id": "fiera-2026", "nome": "Fiera", "luogo": "Bologna", "paese": "Italia", "data_inizio": "2026-11-10",
         "data_fine": "2026-11-14", "anno": 2026, "periodo": "", "stand": "", "testo": "Testo.",
         "fonte_url_sito_vecchio": "https://www.arnoplast.it/"}
    f.update(kw)
    return f


@pytest.fixture
def photo_dirs(tmp_path, monkeypatch):
    """Foto delle fiere in cartelle temporanee: niente file nel repo."""
    src, out = tmp_path / "foto", tmp_path / "webp"
    src.mkdir()
    monkeypatch.setattr(FI, "SRC_DIR", src)
    monkeypatch.setattr(FI, "OUT_DIR", out)
    # I dati reali ora hanno le foto (content/photos/fiere): i test sul "stato senza foto" leggono una copia senza `immagine`.
    real = json.loads((B.DATA_DIR / "fiere.json").read_text(encoding="utf-8"))
    for f in real["fiere"]:
        f.pop("immagine", None)
    nophoto = tmp_path / "fiere-senza-foto.json"
    nophoto.write_text(json.dumps(real, ensure_ascii=False), encoding="utf-8")
    orig = B.load_fairs
    monkeypatch.setattr(B, "load_fairs", lambda path=None: orig(nophoto if path is None else path))
    return src, out


def synthetic(path, w, h):
    im = Image.effect_noise((w, h), 40).filter(ImageFilter.GaussianBlur(1.2)).convert("RGB")
    im.paste((40, 120, 30), (0, h // 2, w, h))
    im.save(path, quality=92)
    return path


def home(tmp_path, monkeypatch, day, fairs=None):
    monkeypatch.setenv("ARNO_BUILD_DATE", day)
    if fairs is not None:
        monkeypatch.setattr(B, "load_fairs", lambda path=None: [dict(f) for f in fairs])
    out = tmp_path / "site"
    B.build_site(CATALOG, out)
    return (out / "it" / "index.html").read_text(encoding="utf-8"), out


def hero(h):
    return h[h.index('<section class="a-hero hs"'):h.index('<ul class="wrap a-facts">')]


def pics(h):
    return re.findall(r'<div class="hs-pic[^"]*"[^>]*>.*?(?=<div class="hs-pic|\n  </div>\n  <div class="wrap)', hero(h), re.S)


def caps(h):
    return re.findall(r'<div class="hs-cap[^"]*" role="group"[^>]*>.*?</a>', hero(h), re.S)


# --- contesto ------------------------------------------------------------------------------------------------------

@pytest.mark.parametrize("day, ids", [
    ("2026-09-01", ["agri-lazio-expo-2026", "fruit-attraction-2026", "luv-2026", "eima-2026", "interpoma-2026"]),
    ("2026-10-31", ["eima-2026", "interpoma-2026"]),
    ("2026-11-14", ["eima-2026", "interpoma-2026"]),  # l'ultimo giorno la fiera c'e' ancora
    ("2026-11-20", ["interpoma-2026"]),
    ("2026-12-01", []),
])
def test_fair_slides_only_future_all_sorted(photo_dirs, day, ids):
    from datetime import date
    slides = B.fair_slides("it", date.fromisoformat(day))
    assert [s["id"] for s in slides] == ids  # tutte le future, nessun limite, dalla piu' vicina
    fairs = {f["id"]: f for f in B.load_fairs()}
    for s in slides:
        f = fairs[s["id"]]
        assert s["fair"] and s["href"] == f"/it/fiere/#{f['id']}" and s["label"] == f["nome"]
        assert s["aria"] == f"Fiera: {f['nome']}" and s["end"] == f["data_fine"]
        assert s["short"] == B.fair_short(f, B.FAIR_HERO_SHORT) and len(s["short"]) <= B.FAIR_HERO_SHORT + 1
        assert s["hero"] is None  # oggi nessuna foto delle fiere


def test_order_by_start_date_and_days_label():
    fairs = [fair(id="b-2026", data_inizio="2026-12-01", data_fine="2026-12-03"),
             fair(id="a-2026", data_inizio="2026-11-30", data_fine="2026-12-05"),
             fair(id="c-2026", data_inizio="2026-11-20", data_fine="2026-11-20")]
    from datetime import date
    slides = B.fair_slides("it", date(2026, 10, 31), fairs)
    assert [s["id"] for s in slides] == ["c-2026", "a-2026", "b-2026"]
    assert [s["days"] for s in slides] == ["20", "30.11–5.12", "1–3"]


def test_future_build_date_gives_the_four_sections(tmp_path, monkeypatch, photo_dirs):
    h, _ = home(tmp_path, monkeypatch, "2027-06-01")
    assert len(caps(h)) == 4 and "data-fair-end" not in hero(h) and "hs-poster" not in h
    assert 'aria-label="1 di 4: Agricoltura"' in caps(h)[0]


def test_focal_point_is_validated(tmp_path):
    import json
    for bad in ("30%", "50% 50% 10%", "url(x)", "30% ;color:red"):
        p = tmp_path / "f.json"
        p.write_text(json.dumps({"fiere": [fair(punto_focale=bad)]}), encoding="utf-8")
        if bad == "30%":
            assert B.load_fairs(p)[0]["punto_focale"] == "30%"  # una sola coordinata e' CSS valido
            continue
        with pytest.raises(ValueError, match="punto_focale non valido"):
            B.load_fairs(p)
    p.write_text(json.dumps({"fiere": [fair(punto_focale="30% 50%"), fair(id="b", punto_focale="left top")]}))
    assert len(B.load_fairs(p)) == 2


# --- markup: stato reale (fiere senza foto) --------------------------------------------------------------------------

def test_real_state_fair_slides_first_without_photo(tmp_path, monkeypatch, photo_dirs):
    h, _ = home(tmp_path, monkeypatch, "2026-10-31")
    hs, cs, ps = hero(h), caps(h), pics(h)
    assert len(cs) == 6 and len(ps) == 6
    labels = ["Fiera: EIMA International", "Fiera: Interpoma", "Agricoltura", "Garden", "Raccolta olive", "Azienda"]
    for i, (cap, lab) in enumerate(zip(cs, labels), 1):
        assert f'aria-label="{i} di 6: {lab}" data-label="{lab}"' in cap, cap[:200]
    dots = re.findall(r'<button type="button" class="hs-dot"[^>]*>', hs)
    assert [re.search(r'aria-label="([^"]+)"', d).group(1) for d in dots] == [f"{i} di 6: {l}" for i, l in enumerate(labels, 1)]
    assert '<b class="hs-cur">01</b> / <span class="hs-tot">06</span>' in hs
    eima, interpoma = cs[0], cs[1]
    assert 'data-fair-end="2026-11-14"' in eima and 'data-fair-end="2026-11-27"' in interpoma
    assert "data-fair-end" not in "".join(cs[2:])
    assert '<a class="hs-more" href="/it/fiere/#eima-2026">Scopri<span class="sr-only"> EIMA International</span>' in eima
    assert f'<p class="hs-label">{t("hs.fair_kicker", "it")}</p>' in eima and '<p class="hs-fname">EIMA International</p>' in eima
    assert '<time datetime="2026-11-10">10–14 novembre 2026</time> · Bologna, Italia' in eima
    fairs = {f["id"]: f for f in B.load_fairs()}
    for cap, fid in ((eima, "eima-2026"), (interpoma, "interpoma-2026")):
        desc = re.search(r'<p class="hs-fdesc">(.*?)</p>', cap).group(1)
        src = " ".join(fairs[fid]["testo"].split())
        assert src.startswith(desc.rstrip("…").replace("&#39;", "'"))  # solo testo della fonte, tagliato
        assert "http" not in cap.replace(f'href="/it/fiere/#{fid}"', "")  # niente sito ufficiale nel pannello
    # Una sola slide attiva; le fiere senza foto: fondo grafico, nessuna immagine e quindi nessuna priorita'.
    assert sum('is-active' in c.split(">")[0] for c in cs) == 1 and 'is-active' in cs[0].split(">")[0]
    assert sum('is-active' in p.split(">")[0] for p in ps) == 1 and 'is-active' in ps[0].split(">")[0]
    for p, days in zip(ps[:2], ("10–14", "25–27")):
        assert 'class="hs-pic hs-fpost' in p and "<img" not in p and "<picture" not in p
        assert f'<div class="hs-poster hx-img" aria-hidden="true"><span class="hs-p-days">{days}</span></div>' in p
    assert 'fetchpriority="high"' not in h  # la foto di Agricoltura arriva dopo il load, come le altre
    for p in ps[2:]:
        assert not re.search(r'\s(?:src|srcset)="', p) and 'data-srcset="' in p
    assert ps[0].split(">")[0].count("aria-hidden") == 0 and all('aria-hidden="true"' in p.split(">")[0] for p in ps[1:])
    nos = re.search(r"<noscript>(.*?)</noscript>", hs, re.S).group(1)
    assert re.findall(r'href="([^"]+)"', nos) == ["/it/fiere/#eima-2026", "/it/fiere/#interpoma-2026", "/it/agricoltura/",
                                                   "/it/garden/", "/it/olive/", "/it/azienda/"]


def test_fair_text_is_escaped(tmp_path, monkeypatch, photo_dirs):
    h, _ = home(tmp_path, monkeypatch, "2026-10-31", [fair(nome="EIMA <b>& Co", luogo="Bo<i>", testo="Uno <script>.")])
    cap = caps(h)[0]
    assert "EIMA &lt;b&gt;&amp; Co" in cap and "<b>&" not in cap and "&lt;script&gt;" in cap and "<script>" not in cap
    assert 'data-label="Fiera: EIMA &lt;b&gt;&amp; Co"' in cap and "Bo&lt;i&gt;" in cap


def test_fair_without_dates_or_place(tmp_path, monkeypatch, photo_dirs):
    h, _ = home(tmp_path, monkeypatch, "2026-10-31",
                [fair(data_inizio="", data_fine="", periodo="Novembre 2026", luogo="", paese="", testo="")])
    cap = caps(h)[0]
    assert '<p class="hs-fmeta"><b>Novembre 2026</b></p>' in cap and "hs-fdesc" not in cap
    assert 'data-fair-end="2026-12-31"' in cap and '<span class="hs-p-days">2026</span>' in pics(h)[0]


# --- markup: fiera con foto --------------------------------------------------------------------------------------

def test_fair_with_photo(tmp_path, monkeypatch, photo_dirs):
    src, webp = photo_dirs
    synthetic(src / "eima-2026.jpg", 1700, 1100)  # piu' stretta di 2000: mai ingrandita
    synthetic(src / "foto-int.png", 3000, 2000)
    fairs = [fair(id="eima-2026", nome="EIMA", punto_focale="30% 50%", link="https://www.eima.it/"),
             fair(id="interpoma-2026", nome="Interpoma", immagine="foto-int.png", data_inizio="2026-11-25",
                  data_fine="2026-11-27")]
    h, _ = home(tmp_path, monkeypatch, "2026-10-31", fairs)
    ps = pics(h)
    assert h.count('fetchpriority="high"') == 1 and "hs-poster" not in h
    first = ps[0]
    assert first.startswith('<div class="hs-pic hs-fp is-active" style="--fp: 30% 50%">')
    img = re.search(r"<img [^>]+>", first).group(0)
    assert 'fetchpriority="high"' in img and 'sizes="120vw"' in img and 'width="1700" height="1100"' in img
    assert ('srcset="/static/img/fiere/eima-2026-hero-800.webp 800w, /static/img/fiere/eima-2026-hero-1280.webp 1280w, '
            '/static/img/fiere/eima-2026-hero-1600.webp 1600w, /static/img/fiere/eima-2026-hero-1700.webp 1700w"') in img
    assert 'alt=""' in img and "<source" not in first  # nessun ritaglio verticale
    second = ps[1]
    assert 'style="--fp: center"' in second and 'fetchpriority' not in second
    assert re.search(r'data-srcset="[^"]*interpoma-2026-hero-2560\.webp 2560w"', second)
    assert not re.search(r'\s(?:src|srcset)="', second)
    assert "eima.it" not in hero(h)  # il sito ufficiale resta nella pagina Fiere
    for f in FI.hero_outputs("eima-2026", webp) + FI.hero_outputs("interpoma-2026", webp):
        assert f.stat().st_size <= LIMIT, f
        with Image.open(f) as im:
            assert im.format == "WEBP" and im.width == int(f.stem.rsplit("-", 1)[1])
    assert [int(f.stem.rsplit("-", 1)[1]) for f in FI.hero_outputs("interpoma-2026", webp)] == [800, 1280, 1600, 2000, 2560]


# --- immagini e strumento -----------------------------------------------------------------------------------------

@pytest.mark.parametrize("w, h, widths", [(4000, 2500, [800, 1280, 1600, 2000, 2560]), (1500, 1000, [800, 1280, 1500]),
                                          (600, 400, [600])])
def test_hero_images_sizes_never_upscaled(tmp_path, w, h, widths):
    src = synthetic(tmp_path / "x.jpg", w, h)
    made = FI.make_hero(src, "fiera-2026", tmp_path / "out")
    assert [int(f.stem.rsplit("-", 1)[1]) for f in made] == widths
    assert all(f.name.startswith("fiera-2026-hero-") for f in made)
    assert FI.outputs("fiera-2026", tmp_path / "out") == []  # le versioni delle schede restano separate
    for f in made:
        with Image.open(f) as im:
            assert im.width <= w and abs(im.height / im.width - h / w) < 0.01
        assert f.stat().st_size <= FI.HERO_MAX_BYTES
    # Rigenerare toglie le vecchie versioni hero della stessa fiera.
    assert FI.make_hero(synthetic(tmp_path / "y.jpg", 900, 600), "fiera-2026", tmp_path / "out") == \
        FI.hero_outputs("fiera-2026", tmp_path / "out")


def test_tool_makes_card_and_hero_versions():
    src = (ROOT / "tools" / "fiere_images.py").read_text(encoding="utf-8")
    assert "FI.make(src, fid) + FI.make_hero(src, fid)" in src and "-hero-" in src


# --- script e testi -----------------------------------------------------------------------------------------------

def test_script_prunes_expired_fairs_before_rotation():
    js = JS.read_text(encoding="utf-8")
    prune = js.index("data-fair-end")
    assert prune < js.index("function show(") and prune < js.index("root.classList.add('hs-on')")
    for k in ("getFullYear()", "end >= today", "removeChild", "hs-tot", "hs-cur", "data-label", "firstGone", "load(0)"):
        assert k in js, k
    assert not re.search(r"^\s*(import|export)\b", js, re.M)


def test_i18n_labels():
    it = STRINGS["it"]
    assert it["hs.fair_kicker"] == "Ci trovi a" and it["hs.fair_more"] == "Scopri" and it["hs.fair"] == "Fiera"
    assert t("hs.fair_kicker", "en") == "Ci trovi a"  # finche' non c'e' l'inglese ricade sull'italiano
