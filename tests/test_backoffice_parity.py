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
