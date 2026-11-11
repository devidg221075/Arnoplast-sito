"""Foto nuove della Galleria: dall'originale alle versioni WebP, foto eliminate, controlli."""
import json
from pathlib import Path

import pytest
from PIL import Image

from arnoplast.site import gallery_tools as GT


def setup(tmp_path, items):
    data = tmp_path / "galleria.json"
    data.write_text(json.dumps({"foto": items}), encoding="utf-8")
    (tmp_path / "src").mkdir()
    (tmp_path / "out").mkdir()
    return data, tmp_path / "src", tmp_path / "out"


def test_new_photo_is_converted(tmp_path):
    data, src, out = setup(tmp_path, [{"id": "001", "w": 10, "h": 10, "tw": 10, "th": 10, "alt": "a", "categoria": "serre"},
                                      {"id": "002", "alt": "Nuova", "categoria": "impianti", "sorgente": "nuova.jpg"}])
    (out / "001.webp").write_bytes(b"x")
    (out / "001-t.webp").write_bytes(b"x")
    Image.new("RGB", (2000, 1000), "red").save(src / "nuova.jpg")
    r = GT.materialize(data, src, out)
    assert r["created"] == ["002"] and r["removed"] == []
    assert (out / "002.webp").is_file() and (out / "002-t.webp").is_file()
    new = json.loads(data.read_text(encoding="utf-8"))["foto"][1]
    assert (new["w"], new["h"], new["tw"], new["th"]) == (1400, 700, 560, 280)
    assert "sorgente" not in new and new["origine"] == "nuova.jpg"
    with Image.open(out / "002-t.webp") as thumb:
        assert thumb.size == (560, 280)


def test_small_photos_are_not_enlarged(tmp_path):
    data, src, out = setup(tmp_path, [{"id": "001", "alt": "a", "sorgente": "p.png"}])
    Image.new("RGB", (800, 500), "blue").save(src / "p.png")
    GT.materialize(data, src, out)
    it = json.loads(data.read_text(encoding="utf-8"))["foto"][0]
    assert (it["w"], it["h"]) == (800, 500)


def test_deleted_photos_lose_their_files(tmp_path):
    data, src, out = setup(tmp_path, [{"id": "001", "w": 1, "h": 1, "tw": 1, "th": 1, "alt": "a"}])
    for n in ("001.webp", "001-t.webp", "002.webp", "002-t.webp"):
        (out / n).write_bytes(b"x")
    assert GT.materialize(data, src, out)["removed"] == ["002-t.webp", "002.webp"]
    assert sorted(p.name for p in out.glob("*.webp")) == ["001-t.webp", "001.webp"]


def test_missing_source_is_an_error(tmp_path):
    data, src, out = setup(tmp_path, [{"id": "001", "alt": "a", "sorgente": "manca.jpg"}])
    with pytest.raises(ValueError, match="non trovato"):
        GT.materialize(data, src, out)


def test_source_path_cannot_escape(tmp_path):
    data, src, out = setup(tmp_path, [{"id": "001", "alt": "a", "sorgente": "../../etc/passwd"}])
    with pytest.raises(ValueError, match="non trovato"):
        GT.materialize(data, src, out)


@pytest.mark.parametrize("items, message", [
    ([{"id": "1", "alt": "a"}], "id non valido"),
    ([{"id": "001", "alt": "a"}, {"id": "001", "alt": "b"}], "ripetuto"),
    ([{"id": "001", "alt": "a", "categoria": "altro"}], "categoria"),
    ([{"id": "001", "alt": ""}], "alt non valido"),
    ([{"id": "001", "alt": "a", "alt_en": "x" * 201}], "alt_en"),
])
def test_invalid_items(items, message):
    with pytest.raises(ValueError, match=message):
        GT.check(items)


def test_repository_gallery_is_valid():
    root = Path(__file__).resolve().parent.parent
    GT.check(json.loads((root / "data" / "galleria.json").read_text(encoding="utf-8"))["foto"])
