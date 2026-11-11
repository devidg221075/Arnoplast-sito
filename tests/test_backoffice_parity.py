"""Il backoffice (PHP) e la build (Python) devono dare lo stesso risultato sui dati delle fiere."""
import json
import shutil
import subprocess

import pytest

from arnoplast.site.build import ROOT, fair_dates

PHP = shutil.which("php")


@pytest.mark.skipif(PHP is None, reason="php non installato")
def test_fair_dates_match_between_backoffice_and_build():
    fairs = json.loads((ROOT / "data" / "fiere.json").read_text(encoding="utf-8"))["fiere"]
    code = ('require "backoffice/vendor/autoload.php"; $f = json_decode(file_get_contents("data/fiere.json"), true)["fiere"];'
            ' $o = []; foreach ($f as $x) { $o[] = [Backoffice\\Fairs::dates($x, "it"), Backoffice\\Fairs::dates($x, "en")]; }'
            ' echo json_encode($o, JSON_UNESCAPED_UNICODE);')
    out = json.loads(subprocess.check_output([PHP, "-r", code], cwd=ROOT))
    assert len(out) == len(fairs)
    for f, (it, en) in zip(fairs, out):
        assert it == fair_dates(f, "it"), f["id"]
        assert en == fair_dates(f, "en"), f["id"]


def test_gallery_seed_is_the_site_data():
    assert (ROOT / "backoffice" / "seed" / "galleria.json").read_text(encoding="utf-8") == (ROOT / "data" / "galleria.json").read_text(encoding="utf-8"), \
        "copiare data/galleria.json in backoffice/seed/galleria.json"


@pytest.mark.skipif(PHP is None, reason="php non installato")
def test_backoffice_seed_is_the_site_data():
    """seed/fiere.json è la copia di data/fiere.json che si importa nel backoffice: devono coincidere."""
    seed = (ROOT / "backoffice" / "seed" / "fiere.json").read_text(encoding="utf-8")
    data = (ROOT / "data" / "fiere.json").read_text(encoding="utf-8")
    assert seed == data, "copiare data/fiere.json in backoffice/seed/fiere.json"


@pytest.mark.skipif(PHP is None, reason="php non installato")
def test_text_overrides_from_backoffice_are_accepted_by_the_build(tmp_path):
    """Le correzioni salvate dal backoffice, nel formato di data/testi.json, passano i controlli della build."""
    from arnoplast.site import i18n, texts
    code = ('require "backoffice/vendor/autoload.php"; use Backoffice\\{App, Texts};'
            ' App::boot(["site_url" => "https://x.it", "secret" => "s", "db" => ["dsn" => "sqlite::memory:"]]);'
            ' Texts::saveGroup("openday", ["od.text" => "Visite da marzo"], ["od.text" => "Visits from March"], 1);'
            ' Texts::saveGroup("home", ["home.title" => "Nuovo titolo"], [], 1);'
            ' echo json_encode(Texts::overrides(), JSON_UNESCAPED_UNICODE);')
    data = subprocess.check_output([PHP, "-r", code], cwd=ROOT).decode()
    p = tmp_path / "testi.json"
    p.write_text(data, encoding="utf-8")
    try:
        assert texts.apply_overrides(p) == 3
        assert i18n.t("od.text", "it") == "Visite da marzo" and i18n.t("od.text", "en") == "Visits from March"
        assert i18n.t("home.title", "en") == i18n.STRINGS["en"]["home.title"]
    finally:
        i18n.OVERRIDES = {}


@pytest.mark.skipif(PHP is None, reason="php non installato")
def test_gallery_from_backoffice_is_accepted_by_the_build_tools(tmp_path):
    """La galleria modificata nel backoffice (foto nuova, categoria, ordine, eliminazione) si converte e si carica nella build."""
    import os

    from PIL import Image

    from arnoplast.site import gallery_tools as GT
    src = tmp_path / "src"
    src.mkdir()
    img = tmp_path / "nuova.png"
    Image.new("RGB", (1600, 900), "green").save(img)
    code = ('define("BACKOFFICE_TESTING", true); require "backoffice/vendor/autoload.php"; use Backoffice\\{App, Gallery};'
            ' App::boot(["site_url" => "https://x.it", "secret" => "s", "db" => ["dsn" => "sqlite::memory:"]]); Gallery::importSeed();'
            ' Gallery::save("003", "serre", "Tunnel", "Tunnel EN", 1, 1); Gallery::delete("010", 1);'
            ' $f = ["name" => ["n.png"], "tmp_name" => [getenv("IMG")], "size" => [filesize(getenv("IMG"))], "error" => [0]];'
            ' Gallery::addUploads($f, "dettagli", 1); echo json_encode(Gallery::export(), JSON_UNESCAPED_UNICODE);')
    leftover = ROOT / "backoffice" / "var" / "content" / "galleria" / "146.png"
    try:
        data = subprocess.check_output([PHP, "-r", code], cwd=ROOT, env=dict(os.environ, IMG=str(img))).decode()
    finally:
        leftover.unlink(missing_ok=True)  # l'originale salvato dal backoffice nella cartella di prova
    exported = json.loads(data)["foto"]
    assert exported[0]["id"] == "003" and exported[0]["alt_en"] == "Tunnel EN" and exported[0]["categoria"] == "serre"
    assert "010" not in [p["id"] for p in exported]
    assert exported[-1]["id"] == "146" and exported[-1]["sorgente"] == "146.png"
    out = tmp_path / "out"
    out.mkdir()
    for p in exported:
        if "sorgente" not in p:
            (out / f"{p['id']}.webp").write_bytes(b"x")
            (out / f"{p['id']}-t.webp").write_bytes(b"x")
    (out / "010.webp").write_bytes(b"x")
    (out / "010-t.webp").write_bytes(b"x")
    (src / "146.png").write_bytes(img.read_bytes())
    dp = tmp_path / "galleria.json"
    dp.write_text(json.dumps({"foto": exported}), encoding="utf-8")
    r = GT.materialize(dp, src, out)
    assert r["created"] == ["146"] and r["removed"] == ["010-t.webp", "010.webp"]
    final = json.loads(dp.read_text(encoding="utf-8"))["foto"]
    assert (final[-1]["w"], final[-1]["h"]) == (1400, 788) and "sorgente" not in final[-1]
    GT.check(final)
