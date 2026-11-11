"""Applica le modifiche della Galleria fatte dal backoffice: converte le foto nuove e toglie quelle eliminate.

Uso: python tools/galleria_apply.py [--remove-sources]
Legge data/galleria.json e content/photos/galleria/, scrive site/static/img/galleria/. Da lanciare prima della build.
"""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from arnoplast.site.gallery_tools import materialize  # noqa: E402

if __name__ == "__main__":
    r = materialize(ROOT / "data" / "galleria.json", ROOT / "content" / "photos" / "galleria",
                    ROOT / "site" / "static" / "img" / "galleria", remove_sources="--remove-sources" in sys.argv)
    print(f"galleria: {len(r['created'])} foto nuove, {len(r['removed'])} file tolti")
