# ARNOplast - catalogo e prototipo sito

Progetto per la ristrutturazione del sito ARNOplast: dati estratti dai
cataloghi PDF (agricoltura, garden, olive) e generatore di sito statico.

## Struttura

- `content/source/` - PDF sorgente dei cataloghi
- `arnoplast/` - pacchetto Python (dati e generatore)
- `tests/` - test automatici
- `docs/` - specifiche e piani

## Test

    pip install -r requirements.txt
    pytest -q

## Build

Il generatore del sito statico sara' aggiunto nei prossimi passi; l'output
verra' scritto in `dist/` (non versionata).
