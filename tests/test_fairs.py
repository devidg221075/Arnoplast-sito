"""Fiere: schema e validazione di data/fiere.json, descrizione breve, foto (tools/fiere_images.py) o locandina
tipografica, passaggio automatico alle passate con la data di build, data-fair-end per lo script di scadenza."""
import json
import re
import subprocess
import sys
from pathlib import Path

import pytest
from PIL import Image, ImageFilter

from arnoplast.catalog import Article, Catalog, Family, Line, Product
from arnoplast.site import build as B
from arnoplast.site import fair_images as FI

ROOT = Path(__file__).resolve().parent.parent


def tiny_catalog():
    a = Article("A-1", {"it": "Descrizione"}, 10, 100, 1000, "article")
    p = Product("copripalo", {"it": "Copripalo"}, ["PP"], [], [], None, None, [a])
    return Catalog([Line("agricoltura", {"it": "Agricoltura"}, "red", [Family("fam", {"it": "Famiglia"}, [p])])])


def fair(**kw):
    f = {"id": "fiera-2026", "nome": "Fiera", "luogo": "Bologna", "paese": "Italia", "data_inizio": "2026-11-10",
         "data_fine": "2026-11-14", "anno": 2026, "periodo": "", "stand": "", "testo": "Testo.",
         "fonte_url_sito_vecchio": "https://www.arnoplast.it/"}
    f.update(kw)
    return f


def write(tmp_path, fairs):
    p = tmp_path / "fiere.json"
    p.write_text(json.dumps({"fiere": fairs}), encoding="utf-8")
    return p


@pytest.fixture
def photo_dirs(tmp_path, monkeypatch):
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
    """Foto finta con dettaglio (rumore e sfumature), per pesi realistici."""
    im = Image.effect_noise((w, h), 40).filter(ImageFilter.GaussianBlur(1.2)).convert("RGB")
    im.paste((40, 120, 30), (0, h // 2, w, h))
    im.save(path, quality=92)
    return path


def build(tmp_path, monkeypatch, fairs, day):
    monkeypatch.setenv("ARNO_BUILD_DATE", day)
    monkeypatch.setattr(B, "load_fairs", lambda path=None: [dict(f) for f in fairs])
    out = tmp_path / "site"
    B.build_site(tiny_catalog(), out)
    return (out / "it" / "fiere" / "index.html").read_text(encoding="utf-8"), out


def section(h, sid):
    return re.search(rf'id="{sid}".*?</section>', h, re.S).group(0)


# --- schema e validazione ---------------------------------------------------------------------------------------

def test_real_data_is_valid_and_documented():
    data = json.loads((ROOT / "data/fiere.json").read_text(encoding="utf-8"))
    for k in ("immagine", "descrizione_breve", "link", "content/photos/fiere"):
        assert k in data["_nota"]
    fairs = B.load_fairs()
    for f in fairs:
        assert B.FAIR_ID.match(f["id"]) and set(f) <= set(B.FAIR_FIELDS) | set(B.FAIR_OPTIONAL) | {"_nota"}
    readme = (ROOT / "README.md").read_text(encoding="utf-8")
    for k in ("descrizione_breve", "tools/fiere_images.py", "data-fair-end", "ricostruzione"):
        assert k in readme


@pytest.mark.parametrize("bad, why", [
    ({"id": "Fiera 2026"}, "id non valido"),
    ({"data_inizio": "2026-11-31"}, "data_inizio non valida"),
    ({"data_inizio": "2026-11-14", "data_fine": "2026-11-10"}, "precedente a data_inizio"),
    ({"link": "http://www.eima.it/"}, "solo indirizzi https"),
    ({"link": "javascript:alert(1)"}, "solo indirizzi https"),
    ({"immagine": "manca.jpg"}, "non trovata"),
    ({"immagine": "../fiere.json"}, "non trovata"),
])
def test_bad_fair_is_a_clear_error(tmp_path, photo_dirs, bad, why):
    with pytest.raises(ValueError, match=why):
        B.load_fairs(write(tmp_path, [fair(**bad)]))


def test_duplicate_id_and_valid_optional_fields(tmp_path, photo_dirs):
    with pytest.raises(ValueError, match="id ripetuto"):
        B.load_fairs(write(tmp_path, [fair(), fair()]))
    synthetic(photo_dirs[0] / "fiera.jpg", 400, 300)
    ok = fair(immagine="fiera.jpg", link="https://www.eima.it/it/", descrizione_breve="Breve.")
    assert B.load_fairs(write(tmp_path, [ok]))[0]["link"] == "https://www.eima.it/it/"


# --- descrizione breve ------------------------------------------------------------------------------------------

def test_short_description_never_invents():
    by = {f["id"]: f for f in B.load_fairs()}
    assert B.fair_short(fair(descrizione_breve="  Scritta  a mano. ", testo="Altro.")) == "Scritta a mano."
    # Prima frase (la domanda che segue resta fuori).
    s = B.fair_short(by["interpoma-2026"])
    assert s.endswith("esclusivamente alle mele.") and "Cos'è" not in s
    # Nessuna fine frase entro 170 caratteri: ultima parola intera e "…".
    for fid in ("eima-2026", "fruitinnovation-2015", "interpoma-2018"):
        s = B.fair_short(by[fid])
        assert len(s) <= B.SHORT_MAX
        body = s[:-1] if s.endswith("…") else s
        assert " ".join(by[fid]["testo"].split()).startswith(body), fid
    assert B.fair_short(by["eima-2026"]).endswith("…")
    assert B.fair_short(by["interpoma-2018"]).endswith("mela.")
    # "..." non e' una fine frase; "Pad. 9" nemmeno.
    assert B.fair_short(fair(testo="Agrilevante 2023... l'agricoltura in un evento!")) == \
        "Agrilevante 2023... l'agricoltura in un evento!"
    assert B.fair_short(fair(testo="Ci trovate al Pad. 9 della fiera. Vi aspettiamo.")) == "Ci trovate al Pad. 9 della fiera."


# --- pagina Fiere -------------------------------------------------------------------------------------------------

def test_typographic_fallback_and_expiry_attributes(tmp_path, monkeypatch, photo_dirs):
    fairs = [fair(id="eima-2026", nome="EIMA <International> & Co"), fair(id="altra-2026", data_inizio="2026-12-01",
                                                                        data_fine="2026-12-03", luogo="", paese="")]
    h, out = build(tmp_path, monkeypatch, fairs, "2026-10-31")
    nxt = section(h, "prossime")
    assert re.findall(r'<li class="fr-card" id="([^"]+)" data-fair-end="([^"]+)"', nxt) == \
        [("eima-2026", "2026-11-14"), ("altra-2026", "2026-12-03")]
    assert 'data-fairs data-fairs-none="fr-none"' in nxt and '<p class="notice" id="fr-none" hidden>' in nxt
    assert nxt.count('class="fr-media fr-poster" aria-hidden="true"') == 2 and "<img" not in nxt
    assert "EIMA &lt;International&gt; &amp; Co" in nxt and "<International>" not in nxt
    assert '<span class="fr-p-place">' not in nxt.split('id="altra-2026"')[1]  # luogo vuoto: niente riga
    assert "fr-ext" not in nxt  # nessun link al sito senza `link`
    js = (out / "static/js/anim.js").read_text(encoding="utf-8")
    assert (ROOT / "site/static/js/fairs.js").read_text(encoding="utf-8").rstrip() in js
    assert "import " not in (ROOT / "site/static/js/fairs.js").read_text(encoding="utf-8")


def test_photo_card(tmp_path, monkeypatch, photo_dirs):
    src, webp = photo_dirs
    synthetic(src / "eima-2026.jpg", 2400, 1600)
    synthetic(src / "foto-interpoma.png", 900, 700)
    fairs = [fair(id="eima-2026", link="https://www.eima.it/"),
             fair(id="interpoma-2026", immagine="foto-interpoma.png", data_inizio="2026-11-25", data_fine="2026-11-27")]
    h, out = build(tmp_path, monkeypatch, fairs, "2026-10-31")
    nxt = section(h, "prossime")
    imgs = re.findall(r"<img [^>]+>", nxt)
    assert len(imgs) == 2 and "fr-poster" not in nxt
    assert 'srcset="/static/img/fiere/eima-2026-480.webp 480w, /static/img/fiere/eima-2026-800.webp 800w, ' \
           '/static/img/fiere/eima-2026-1200.webp 1200w"' in imgs[0]
    assert 'width="1200" height="800"' in imgs[0] and 'alt=""' in imgs[0] and "loading" not in imgs[0]
    assert 'loading="lazy"' in imgs[1] and 'width="900" height="700"' in imgs[1]  # mai oltre i pixel nativi
    assert '<a class="fr-ext" href="https://www.eima.it/" target="_blank" rel="noopener noreferrer">' in nxt
    for f in FI.outputs("eima-2026", webp) + FI.outputs("interpoma-2026", webp):  # schede (hero: test_hero_fairs)
        assert f.stat().st_size <= 150 * 1024, f
    # La build genera solo le foto mancanti: una seconda build non le riscrive.
    before = {f: f.stat().st_mtime_ns for f in webp.glob("*.webp")}
    build(tmp_path, monkeypatch, fairs, "2026-10-31")
    assert before == {f: f.stat().st_mtime_ns for f in webp.glob("*.webp")}


@pytest.mark.parametrize("day, eima_upcoming", [("2026-11-13", True), ("2026-11-14", True), ("2026-11-15", False)])
def test_fair_moves_to_past_with_build_date(tmp_path, monkeypatch, photo_dirs, day, eima_upcoming):
    real = B.load_fairs()
    h, _ = build(tmp_path, monkeypatch, real, day)
    assert ('id="eima-2026"' in section(h, "prossime")) is eima_upcoming
    assert ('id="eima-2026"' in section(h, "passate")) is not eima_upcoming


def test_no_upcoming_fairs(tmp_path, monkeypatch, photo_dirs):
    h, _ = build(tmp_path, monkeypatch, B.load_fairs(), "2026-12-01")
    nxt = section(h, "prossime")
    assert '<p class="notice" id="fr-none">Nessuna fiera in programma al momento.</p>' in nxt
    assert "data-fair-end" not in nxt and "fr-cards" not in nxt
    assert 'id="interpoma-2026"' in section(h, "passate") and "Open Day" in h


def test_upcoming_fairs_data_for_home(monkeypatch, photo_dirs):
    monkeypatch.setenv("ARNO_BUILD_DATE", "2026-09-01")
    up = B.upcoming_fairs(3)
    assert [f["id"] for f in up] == ["agri-lazio-expo-2026", "fruit-attraction-2026", "luv-2026"]
    assert all(f["short"] and f["end_iso"] and "img" in f for f in up)
    assert up[0]["place"] == "Cisterna di Latina, Italia"


# --- tools/fiere_images.py ------------------------------------------------------------------------------------------

@pytest.mark.parametrize("w, h, widths", [(3000, 2000, [480, 800, 1200]), (1000, 800, [480, 800, 1000]),
                                          (300, 200, [300])])
def test_fair_images_sizes(tmp_path, w, h, widths):
    src = synthetic(tmp_path / "x.jpg", w, h)
    made = FI.make(src, "fiera-2026", tmp_path / "out")
    assert [int(f.stem.rsplit("-", 1)[1]) for f in made] == widths
    for f in made:
        with Image.open(f) as im:
            assert im.format == "WEBP" and im.width == int(f.stem.rsplit("-", 1)[1]) <= w
            assert abs(im.height / im.width - h / w) < 0.01
        assert f.stat().st_size <= FI.MAX_BYTES


def test_fair_images_tool_rejects_unknown_id():
    r = subprocess.run([sys.executable, str(ROOT / "tools/fiere_images.py"), "non-esiste"], capture_output=True,
                       text=True)
    assert r.returncode == 1 and "non-esiste" in r.stderr
