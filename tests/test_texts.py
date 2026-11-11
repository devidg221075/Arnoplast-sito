"""Correzioni ai testi fissi (data/testi.json): controlli, applicazione alla build, file per il backoffice aggiornato."""
import json
import sys
from pathlib import Path

import pytest

from arnoplast.site import i18n, texts
from arnoplast.site.build import build_site
from tests.test_site_build import cat, prod

ROOT = Path(__file__).resolve().parent.parent


@pytest.fixture(autouse=True)
def _reset_overrides():
    yield
    i18n.OVERRIDES = {}


def write(tmp_path, data):
    p = tmp_path / "testi.json"
    p.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    return p


def test_missing_file_means_no_overrides(tmp_path):
    assert texts.load_overrides(tmp_path / "non-c-e.json") == {}


def test_repository_file_is_valid():
    assert texts.load_overrides(ROOT / "data" / "testi.json") is not None


def test_override_wins_over_original(tmp_path):
    p = write(tmp_path, {"it": {"od.text": "Aperto a ottobre"}, "en": {"od.text": "Open in October"}})
    assert texts.apply_overrides(p) == 2
    assert i18n.t("od.text", "it") == "Aperto a ottobre"
    assert i18n.t("od.text", "en") == "Open in October"
    assert i18n.t("home.title", "it") == i18n.STRINGS["it"]["home.title"]  # le altre voci non cambiano


def test_overrides_are_used_in_the_built_pages(tmp_path):
    texts.apply_overrides(write(tmp_path, {"it": {"od.text": "Visite su appuntamento da marzo"}}))
    out = tmp_path / "site"
    build_site(cat(prod()), out, sources=tmp_path / "vuota")
    assert "Visite su appuntamento da marzo" in (out / "it" / "open-day" / "index.html").read_text(encoding="utf-8")


@pytest.mark.parametrize("data, message", [
    ({"it": {"chiave.inesistente": "x"}}, "chiave sconosciuta"),
    ({"it": {"od.text": "   "}}, "vuoto"),
    ({"it": {"od.text": 5}}, "vuoto o non valido"),
    ({"it": {"od.text": "a\nb"}}, "una riga"),
    ({"it": {"od.text": "x" * 401}}, "una riga"),
    ({"it": {"ck.lead": "nuovo"}}, "testo legale"),
    ({"it": ["x"]}, "elenco"),
])
def test_invalid_overrides_stop_the_build(tmp_path, data, message):
    with pytest.raises(ValueError, match=message):
        texts.load_overrides(write(tmp_path, data))


def test_backoffice_seed_is_up_to_date():
    sys.path.insert(0, str(ROOT / "tools"))
    import export_testi
    assert (ROOT / "backoffice" / "seed" / "testi.json").read_text(encoding="utf-8") == export_testi.render(), \
        "rilanciare: python tools/export_testi.py"


def test_legal_texts_are_never_editable():
    seed = json.loads((ROOT / "backoffice" / "seed" / "testi.json").read_text(encoding="utf-8"))
    assert not any(texts.is_legal(k) for k in seed["texts"])
    assert any(k.startswith("ck.") for k in seed["legal_excluded"])
