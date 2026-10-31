"""Foto di anteprima delle fiere (pagina Fiere e home) dalle foto del cliente.

Uso: python tools/fiere_images.py [id ...]      (senza id: tutte le fiere di data/fiere.json che hanno una foto)

Legge content/photos/fiere/<immagine> (campo `immagine` della fiera, oppure <id>.jpg|.jpeg|.png|.webp) e scrive in
site/static/img/fiere/ i WebP <id>-480, <id>-800, <id>-1200 (mai oltre la risoluzione nativa, proporzioni conservate,
qualita' 82, ogni file <= 150 KB) per le schede, e le versioni per la slide dell'hero della home <id>-hero-800,
-1280, -1600, -2000, -2560 (stesse regole, ogni file <= 250 KB: la qualita' scende da sola se serve). La build genera da sola le foto che mancano; questo script serve a rigenerarle,
per esempio dopo aver sostituito una foto. Richiede Pillow con WebP.
"""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
from arnoplast.site import fair_images as FI  # noqa: E402
from arnoplast.site.build import load_fairs  # noqa: E402


def main(ids: list[str]) -> int:
    fairs = {f["id"]: f for f in load_fairs()}
    unknown = [i for i in ids if i not in fairs]
    if unknown:
        print(f"id non presenti in data/fiere.json: {', '.join(unknown)}", file=sys.stderr)
        return 1
    done = 0
    for fid in ids or list(fairs):
        src = FI.source_for(fairs[fid])
        if src is None:
            if ids:
                print(f"{fid}: nessuna foto in {FI.SRC_DIR}", file=sys.stderr)
            continue
        for f in FI.make(src, fid) + FI.make_hero(src, fid):
            print(f"{f.relative_to(ROOT)}  {f.stat().st_size // 1024} KB")
        done += 1
    print(f"{done} fiere con foto")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
