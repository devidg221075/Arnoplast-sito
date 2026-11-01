"""Anteprime dei video (pagina Video e Tutorial): converte le immagini del cliente in site/static/img/video/.

Uso: python tools/video_thumbs.py <cartella>

La cartella contiene file chiamati vimeo-<id>.jpg e youtube-<id>.jpg (come nel salvataggio della pagina "Video e Tutorial"
del cliente). Ogni immagine diventa <servizio>-<id>.webp 640x360 (ritaglio centrale 16:9, qualita' 72). Poi la build usa
da sola le anteprime presenti; per i video senza file resta lo sfondo grafico. Richiede Pillow con WebP.
"""
import re
import sys
from pathlib import Path

from PIL import Image, ImageOps

OUT = Path(__file__).resolve().parent.parent / "site" / "static" / "img" / "video"


def main(src: Path) -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    n = 0
    for f in sorted(src.iterdir()):
        m = re.fullmatch(r"(vimeo|youtube)-([\w-]+)\.(jpe?g|png|webp)", f.name, re.I)
        if not m:
            continue
        im = ImageOps.fit(Image.open(f).convert("RGB"), (640, 360), Image.LANCZOS)
        im.save(OUT / f"{m.group(1).lower()}-{m.group(2)}.webp", "WEBP", quality=72, method=6)
        n += 1
    print(f"{n} anteprime in {OUT}")
    return 0


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    sys.exit(main(Path(sys.argv[1])))
