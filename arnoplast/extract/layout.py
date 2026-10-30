"""Mappa linee -> famiglie -> pagine di inizio, dagli indici dei cataloghi."""
from __future__ import annotations

from pathlib import Path

SOURCE_DIR = Path(__file__).resolve().parents[2] / "content" / "source"

# slug linea -> (nome, accento, file sorgente)
LINES: dict[str, tuple[str, str, str]] = {
    "agricoltura": ("Agricoltura", "red", "agricoltura-it.pdf"),
    "garden": ("Garden", "green", "garden-it.pdf"),
    "olive": ("Raccolta olive", "olive", "olive-it.pdf"),
}

# slug linea -> [(pagina di inizio sezione, nome famiglia)], dagli indici (pag. 7)
FAMILIES: dict[str, list[tuple[int, str]]] = {
    "agricoltura": [
        (8, "Copripalo"),
        (26, "Placchette"),
        (40, "Ferma-teli e reti"),
        (48, "Moschettoni e tiranti"),
        (54, "Ancoraggi, ganci e chiodi"),
        (62, "Sistemi di legatura"),
        (66, "Accessori, PET e ricambi"),
    ],
    "garden": [
        (8, "Testine"),
        (22, "Filo nylon"),
        (30, "Lame"),
        (36, "Visiere"),
        (40, "Abbigliamento"),
        (44, "Accessori"),
        (50, "Ancoraggi"),
        # l'indice del catalogo dice pag. 56, ma nel PDF la pagina "ESPOSITORI"
        # (con la sua tabella 5703-…) è la 55: vale il PDF
        (55, "Espositori"),
    ],
    # volantino di 12 pagine: una sola famiglia
    "olive": [
        (3, "Agevolatori e macchine"),
    ],
}


def family_for(line: str, page: int) -> str | None:
    """Nome della famiglia a cui appartiene `page` (None prima della prima sezione)."""
    current = None
    for start, name in FAMILIES[line]:
        if page >= start:
            current = name
    return current
