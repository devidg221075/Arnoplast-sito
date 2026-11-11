"""Foto nuove della Galleria: dal file originale alle due versioni WebP del sito.

Il backoffice scrive in data/galleria.json le foto nuove con il campo `sorgente` (nome del file originale, in
content/photos/galleria/). `materialize` (richiamata da tools/galleria_apply.py prima della build) genera le due
versioni (NNN-t.webp, lato lungo max 560 px; NNN.webp, max 1400 px, mai ingrandite), completa w/h/tw/th e toglie
`sorgente`. Toglie anche i WebP delle foto eliminate. Stesse regole di tools/galleria_images.py.
"""
from __future__ import annotations

import json
import re
from pathlib import Path

from PIL import Image, ImageOps

CATEGORIES = ("impianti", "serre", "dettagli", "disegni")
ID = re.compile(r"^\d{3}$")
THUMB, FULL = (560, 60), (1400, 62)  # lato lungo massimo, qualità


def check(items: list[dict]) -> None:
    seen = set()
    for it in items:
        if not ID.match(str(it.get("id", ""))):
            raise ValueError(f"data/galleria.json: id non valido {it.get('id')!r} (tre cifre, es. 146)")
        if it["id"] in seen:
            raise ValueError(f"data/galleria.json: id ripetuto {it['id']!r}")
        seen.add(it["id"])
        if it.get("categoria", "impianti") not in CATEGORIES:
            raise ValueError(f"data/galleria.json: categoria non valida per la foto {it['id']}: {it.get('categoria')!r}")
        for k in ("alt", "alt_en"):
            if k in it and (not isinstance(it[k], str) or not it[k].strip() or len(it[k]) > 200):
                raise ValueError(f"data/galleria.json: {k} non valido per la foto {it['id']} (testo di 1-200 caratteri)")


def _save(im: Image.Image, dest: Path, longest: int, quality: int) -> tuple[int, int]:
    im = im.copy()
    if max(im.size) > longest:
        im.thumbnail((longest, longest), Image.LANCZOS)
    im.save(dest, "WEBP", quality=quality, method=6)
    return im.size


def materialize(data_path: Path, src_dir: Path, out_dir: Path, remove_sources: bool = False) -> dict:
    """Completa le foto nuove e toglie i file delle eliminate. Restituisce {'created': [...], 'removed': [...]}."""
    data_path, src_dir, out_dir = Path(data_path), Path(src_dir), Path(out_dir)
    data = json.loads(data_path.read_text(encoding="utf-8"))
    items = data["foto"]
    check(items)
    out_dir.mkdir(parents=True, exist_ok=True)
    created = []
    for it in items:
        src_name = it.get("sorgente")
        if not src_name:
            continue
        src = src_dir / Path(src_name).name  # solo il nome del file: mai percorsi
        if not src.is_file():
            raise ValueError(f"data/galleria.json: foto {it['id']}: file originale {src.name!r} non trovato in {src_dir}")
        im = ImageOps.exif_transpose(Image.open(src)).convert("RGB")
        it["tw"], it["th"] = _save(im, out_dir / f"{it['id']}-t.webp", *THUMB)
        it["w"], it["h"] = _save(im, out_dir / f"{it['id']}.webp", *FULL)
        it["origine"] = src.name
        del it["sorgente"]
        created.append(it["id"])
        if remove_sources:
            src.unlink()
    ids = {it["id"] for it in items}
    removed = []
    for f in out_dir.glob("*.webp"):
        if f.name.split(".")[0].removesuffix("-t") not in ids:
            f.unlink()
            removed.append(f.name)
    if created:
        data_path.write_text(json.dumps(data, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    return {"created": created, "removed": sorted(removed)}
