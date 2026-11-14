"""Testi lunghi di Azienda (storia) e Imballaggi: chiavi registrate, correzioni applicate alle pagine IT/EN, limiti."""
import json
from pathlib import Path

import pytest

from arnoplast.site import build, i18n, pagetexts, texts
from arnoplast.site.build import build_site
from tests.test_site_build import cat, prod


@pytest.fixture(autouse=True)
def _reset_overrides():
    yield
    i18n.OVERRIDES = {}


def write(tmp_path, data):
    p = tmp_path / "testi.json"
    p.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    return p


def test_chiavi_registrate_per_italiano_e_inglese():
    for key in ("story.kicker", "story.s1.label", "story.s4.text", "story.today.text", "pack.title", "pack.p3", "pack.r14.codice", "pack.note"):
        assert key in i18n.STRINGS["it"] and key in i18n.STRINGS["en"], key
    assert i18n.STRINGS["it"]["story.s1.label"] == "1975"
    assert i18n.STRINGS["en"]["story.s3.label"] == "Mid 1980s"
    assert i18n.STRINGS["it"]["story.s1.text"] == build.COMPANY_STORY["steps"][0][1]


def test_senza_correzioni_la_pagina_non_cambia():
    assert pagetexts.apply_story(build.COMPANY_STORY, "it") == {**build.COMPANY_STORY, "steps": [tuple(s) for s in build.COMPANY_STORY["steps"]], "today": tuple(build.COMPANY_STORY["today"])}
    pack = build.load_packaging()
    assert pagetexts.apply_pack(pack, "it", build.PACK_KINDS) == pack


def test_i_paragrafi_lunghi_sono_ammessi_ma_non_oltre_il_limite(tmp_path):
    assert texts.max_len("story.s1.text") == 3000 and texts.max_len("od.text") == 400
    ok = "x" * 2500
    texts.apply_overrides(write(tmp_path, {"it": {"story.s1.text": ok}}))
    assert i18n.t("story.s1.text", "it") == ok
    with pytest.raises(ValueError):
        texts.load_overrides(write(tmp_path, {"it": {"story.s1.text": "x" * 3001}}))
    with pytest.raises(ValueError):  # i testi brevi restano a 400
        texts.load_overrides(write(tmp_path, {"it": {"od.text": "x" * 401}}))


def test_storia_e_imballaggi_corretti_arrivano_nelle_pagine(tmp_path):
    texts.apply_overrides(write(tmp_path, {
        "it": {"story.s1.text": "Storia corretta italiana.", "story.today_title": "ARNOplast oggi e domani", "pack.p1": "Primo paragrafo corretto con **grassetto**.",
               "pack.r1.codice": "ZZ 99", "pack.note": "Nota corretta."},
        "en": {"story.s1.text": "Corrected English history.", "pack.r2.imballaggio": "Corrected English item"}}))
    out = tmp_path / "dist"
    build_site(cat(prod()), out, sources=tmp_path / "vuota")
    it = (out / "it" / "azienda" / "index.html").read_text(encoding="utf-8")
    assert "Storia corretta italiana." in it and "ARNOplast oggi e domani" in it
    pk = (out / "it" / "azienda" / "imballaggi" / "index.html").read_text(encoding="utf-8")
    assert "ZZ 99" in pk and "Nota corretta." in pk and "<strong>grassetto</strong>" in pk
    en = (out / "en" / "azienda" / "index.html")
    if en.exists():
        assert "Corrected English history." in en.read_text(encoding="utf-8")


def test_il_colore_della_riga_segue_la_raccolta_corretta():
    kinds = build.PACK_KINDS
    pack = build.load_packaging()
    first = pack["tabella"][0]
    other = next(k for k in kinds if kinds[k] != first["tipo"])
    i18n.OVERRIDES = {"it": {"pack.r1.raccolta": other}, "en": {}}
    changed = pagetexts.apply_pack(pack, "it", kinds)
    assert changed["tabella"][0]["tipo"] == kinds[other.strip().lower()]
