"""Foto della Galleria (impianti realizzati): ottimizza le foto del cliente e scrive data/galleria.json.

Uso: python tools/galleria_images.py <cartella-con-le-foto> [--keep-order]

Per ogni foto (JPG/PNG/WebP, ordinate per nome: i nomi NNN-... mantengono l'ordine del sito vecchio) scrive in
site/static/img/galleria/ due WebP: NNN-t.webp (anteprima, lato lungo max 560 px) e NNN.webp (ingrandimento, lato
lungo max 1400 px, mai oltre la risoluzione nativa, qualita' 62). Doppioni (stessa immagine) scartati. Il testo
alternativo e' generico finche' il cliente non manda le didascalie: si scrive in data/galleria.json (campo `alt`).
Le foto originali NON vanno nel repository (solo le versioni ottimizzate). Richiede Pillow con WebP.
Rieseguendo lo script i campi `alt` gia' scritti a mano in data/galleria.json si conservano.
"""
import hashlib
import json
import sys
from pathlib import Path

from PIL import Image, ImageOps

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "site" / "static" / "img" / "galleria"
DATA = ROOT / "data" / "galleria.json"
EXT = {".jpg", ".jpeg", ".png", ".webp"}
ALT = "Accessori ARNOplast su un impianto realizzato"


def save(im: Image.Image, dest: Path, longest: int, quality: int) -> tuple[int, int]:
    im = im.copy()
    if max(im.size) > longest:
        im.thumbnail((longest, longest), Image.LANCZOS)
    im.save(dest, "WEBP", quality=quality, method=6)
    return im.size


def main(src: Path) -> int:
    old = {e["id"]: e for e in json.loads(DATA.read_text(encoding="utf-8"))["foto"]} if DATA.is_file() else {}
    OUT.mkdir(parents=True, exist_ok=True)
    seen: dict[str, str] = {}
    items = []
    for f in sorted(p for p in src.iterdir() if p.suffix.lower() in EXT):
        im = ImageOps.exif_transpose(Image.open(f)).convert("RGB")
        digest = hashlib.sha1(im.resize((32, 32)).tobytes()).hexdigest()
        if digest in seen:
            print(f"doppione: {f.name} = {seen[digest]}")
            continue
        seen[digest] = f.name
        fid = f"{len(items) + 1:03d}"
        tw, th = save(im, OUT / f"{fid}-t.webp", 560, 60)
        w, h = save(im, OUT / f"{fid}.webp", 1400, 62)
        items.append({"id": fid, "w": w, "h": h, "tw": tw, "th": th,
                      "alt": old.get(fid, {}).get("alt") or ALT, "origine": f.name})
    for stale in OUT.glob("*.webp"):  # niente file orfani se le foto sono diminuite
        if stale.name.split(".")[0].removesuffix("-t") not in {i["id"] for i in items}:
            stale.unlink()
    DATA.write_text(json.dumps({"foto": items}, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    kb = sum(p.stat().st_size for p in OUT.glob("*.webp")) // 1024
    print(f"{len(items)} foto, {kb} KB in {OUT.relative_to(ROOT)}")
    return 0


if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    sys.exit(main(Path(sys.argv[1])))
