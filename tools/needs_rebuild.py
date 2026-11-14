"""Dice se la costruzione notturna del sito serve davvero (solo libreria standard, gira prima di installare altro).

Il sito è statico: una fiera passa da «Prossime» a «Passate» solo quando si ricostruisce, e il piè di pagina porta
l'anno. Quindi la notte serve ricostruire soltanto se ieri è finita una fiera o se oggi è il primo gennaio.
Uso: python3 tools/needs_rebuild.py   → scrive «build=yes» o «build=no» (da accodare a $GITHUB_OUTPUT).
"""
import json
import sys
from datetime import date, datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parent.parent


def needed(today: date, fairs: list[dict]) -> bool:
    if (today.month, today.day) == (1, 1):
        return True
    yesterday = (today - timedelta(days=1)).isoformat()
    return any(str(f.get("data_fine") or "") == yesterday for f in fairs)


def main() -> None:
    data = json.loads((ROOT / "data" / "fiere.json").read_text(encoding="utf-8"))
    today = datetime.now(ZoneInfo("Europe/Rome")).date()
    print("build=" + ("yes" if needed(today, data.get("fiere", [])) else "no"))


if __name__ == "__main__":
    sys.exit(main())
