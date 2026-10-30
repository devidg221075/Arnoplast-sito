"""Accesso ai PDF tramite poppler (pdftotext, pdftoppm)."""
from __future__ import annotations

import html
import re
import subprocess
from dataclasses import dataclass
from pathlib import Path

# A4 in punti PDF (tutti e tre i cataloghi)
PAGE_W = 596
PAGE_H = 842
RENDER_DPI = 110

_WORD_RE = re.compile(
    r'<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*?)</word>'
)


@dataclass(frozen=True)
class Word:
    text: str
    x0: float
    y0: float
    x1: float
    y1: float

    @property
    def height(self) -> float:
        return self.y1 - self.y0


def _crop_args(box: tuple[float, float] | None) -> list[str]:
    """Ritaglio verticale (y0, y1) in punti; pdftotext usa 72 dpi = punti."""
    if box is None:
        return []
    y0, y1 = box
    return ["-x", "0", "-y", str(int(y0)), "-W", str(PAGE_W), "-H", str(int(y1 - y0))]


def page_text(pdf: Path, page: int, box: tuple[float, float] | None = None,
              raw: bool = False) -> str:
    """Testo di una pagina (`pdftotext -layout`, o `-raw`), eventualmente ritagliato in y."""
    mode = "-raw" if raw else "-layout"
    cmd = ["pdftotext", mode, "-f", str(page), "-l", str(page), *_crop_args(box), str(pdf), "-"]
    return subprocess.run(cmd, check=True, capture_output=True, text=True).stdout


def page_words(pdf: Path, page: int) -> list[Word]:
    """Parole con bounding box (`pdftotext -bbox`), in punti."""
    cmd = ["pdftotext", "-bbox", "-f", str(page), "-l", str(page), str(pdf), "-"]
    out = subprocess.run(cmd, check=True, capture_output=True, text=True).stdout
    return [Word(html.unescape(m.group(5)), *map(float, m.groups()[:4]))
            for m in _WORD_RE.finditer(out)]


def page_count(pdf: Path) -> int:
    out = subprocess.run(["pdfinfo", str(pdf)], check=True, capture_output=True, text=True).stdout
    return int(re.search(r"^Pages:\s+(\d+)", out, re.M).group(1))


def page_image(pdf: Path, page: int, out: Path,
               box: tuple[float, float] | None = None) -> Path:
    """Render JPEG della pagina (`pdftoppm -r 110 -jpeg`), opzionalmente solo la fascia y0..y1."""
    out = Path(out)
    out.parent.mkdir(parents=True, exist_ok=True)
    cmd = ["pdftoppm", "-r", str(RENDER_DPI), "-jpeg", "-f", str(page), "-l", str(page),
           "-singlefile"]
    if box is not None:
        scale = RENDER_DPI / 72
        y0, y1 = box
        cmd += ["-x", "0", "-y", str(int(y0 * scale)),
                "-W", str(int(PAGE_W * scale)), "-H", str(int((y1 - y0) * scale))]
    cmd += [str(pdf), str(out.with_suffix(""))]
    subprocess.run(cmd, check=True, capture_output=True)
    return out.with_suffix(".jpg")
