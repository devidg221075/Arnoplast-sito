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


def test_dati_societari_collegamenti_tipologie_alt_e_descrizioni_arrivano_nelle_pagine(tmp_path):
    texts.apply_overrides(write(tmp_path, {
        "it": {"corp.phone1": "+39 055 999 888", "corp.email": "vendite@arnoplast.it", "corp.vat": "12345678901", "corp.street": "Via Nuova 1",
               "link.facebook": "https://www.facebook.com/arnoplast-nuova", "ut.1": "Privato", "hero.agricoltura.alt": "Frutteto con la rete",
               "meta.fairs": "Le nostre fiere, aggiornate."},
        "en": {"ut.1": "Private person", "hero.agricoltura.alt": "Orchard with netting", "meta.fairs": "Our fairs, updated."}}))
    out = tmp_path / "dist"
    build_site(cat(prod()), out, sources=tmp_path / "vuota")
    ct = (out / "it" / "contatti" / "index.html").read_text(encoding="utf-8")
    assert "+39 055 999 888" in ct and 'href="tel:+39055999888"' in ct and "vendite@arnoplast.it" in ct and "12345678901" in ct and "Via Nuova 1" in ct
    assert '<option value="Privato">Privato</option>' in ct
    home = (out / "it" / "index.html").read_text(encoding="utf-8")
    assert "https://www.facebook.com/arnoplast-nuova" in home and "Frutteto con la rete" in home
    assert "Le nostre fiere, aggiornate." in (out / "it" / "fiere" / "index.html").read_text(encoding="utf-8")
    ct_en = (out / "en" / "contatti" / "index.html")
    if ct_en.exists():
        assert '<option value="Privato">Private person</option>' in ct_en.read_text(encoding="utf-8")
    assert build.CONTACTS["phone_tel"] == "+39055999888" and build.CONTACTS["address"].startswith("Via Nuova 1, ")


def test_i_dati_tornano_originali_senza_correzioni(tmp_path):
    texts.apply_overrides(write(tmp_path, {"it": {"corp.vat": "99999999999"}, "en": {}}))
    build.refresh_contacts()
    assert build.CONTACTS["vat"] == "99999999999"
    i18n.OVERRIDES = {}
    build.refresh_contacts()
    assert build.CONTACTS["vat"] == "01987190483" and build.CONTACTS["phone_tel"] == "+390558347031"


def test_collegamenti_ed_email_non_validi_sono_rifiutati(tmp_path):
    for bad in ({"it": {"link.facebook": "http://insicuro.example"}}, {"it": {"link.play": "javascript:alert(1)"}}, {"it": {"corp.email": "senza chiocciola"}}):
        with pytest.raises(ValueError):
            texts.load_overrides(write(tmp_path, bad))


def test_i_valori_di_partenza_sono_quelli_del_sito():
    """Senza correzioni, ogni chiave nuova vale esattamente il testo che il sito usa oggi (italiano e inglese)."""
    for key, (_foto, _ritaglio, alt) in build.HEROES.items():
        assert i18n.STRINGS["it"][f"hero.{key}.alt"] == alt
        assert i18n.STRINGS["en"][f"hero.{key}.alt"] == build.HERO_ALT_EN.get(alt, alt)
    assert i18n.STRINGS["it"]["corp.vat"] == build.CONTACTS["vat"] == i18n.STRINGS["en"]["corp.vat"]
    assert i18n.STRINGS["it"]["corp.phone2"] == "+39 055 83 47 033"
    assert i18n.STRINGS["it"]["link.facebook"] == "https://www.facebook.com/arnoplast"
    assert i18n.STRINGS["en"]["ut.2"] == "Farm" and i18n.STRINGS["it"]["ut.2"] == "Azienda Agricola"
    assert i18n.STRINGS["it"]["meta.fairs"].startswith("Le fiere a cui partecipa")
