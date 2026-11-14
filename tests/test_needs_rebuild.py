"""La ricostruzione notturna serve solo se ieri è finita una fiera o se è il primo gennaio."""
import importlib.util
from datetime import date
from pathlib import Path

spec = importlib.util.spec_from_file_location("needs_rebuild", Path(__file__).resolve().parent.parent / "tools" / "needs_rebuild.py")
nr = importlib.util.module_from_spec(spec)
spec.loader.exec_module(nr)

FAIRS = [{"id": "a", "data_fine": "2026-11-13"}, {"id": "b", "data_fine": "2026-12-05"}, {"id": "c", "data_fine": ""}]


def test_serve_il_giorno_dopo_la_fine_di_una_fiera():
    assert nr.needed(date(2026, 11, 14), FAIRS)


def test_non_serve_negli_altri_giorni():
    assert not nr.needed(date(2026, 11, 15), FAIRS)
    assert not nr.needed(date(2026, 11, 13), FAIRS)
    assert not nr.needed(date(2026, 7, 1), [])


def test_serve_il_primo_gennaio_per_l_anno_del_piede_di_pagina():
    assert nr.needed(date(2027, 1, 1), [])


def test_il_file_vero_si_legge():
    import json
    d = json.loads((Path(__file__).resolve().parent.parent / "data" / "fiere.json").read_text(encoding="utf-8"))
    assert isinstance(nr.needed(date(2026, 11, 14), d["fiere"]), bool)
