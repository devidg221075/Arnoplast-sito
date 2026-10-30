# Restyling sito ARNOplast: design

Data: 2026-10-30 · Stato: in attesa di revisione

## 1. Obiettivo

Rifare www.arnoplast.it come sito di **informazione commerciale e richiesta preventivo**, in italiano e inglese. Il cliente sfoglia i prodotti, sceglie singoli codici articolo con quantità e invia una richiesta di preventivo. Non è un e-commerce: niente carrello, pagamenti né prezzi pubblici.

Al termine il sito viene integrato in WordPress, mantenendo integrità dei contenuti, velocità, dinamismo e funzionalità.

Criteri di successo:
- Un cliente trova un codice e lo aggiunge alla richiesta in pochi passaggi, anche da telefono.
- La richiesta arriva a `info@arnoplast.it` con la tabella dei codici e delle quantità.
- Lighthouse mobile ≥ 90 sul tema WordPress, verificato prima della messa online.
- Nessun contenuto del sito attuale perso e vecchi URL reindirizzati (301).

## 2. Contesto e fonti

Fonti:
- Sito attuale (https://www.arnoplast.it): già WordPress; dal codice HTML risultano Divi, WPML, WooCommerce e WP Download Manager (da verificare sul backend).
- Catalogo Agricoltura 2026 (72 pp.), Garden 2026 (56 pp.), Raccolta olive 2025 (12 pp.), tutti in italiano.
- I cataloghi inglesi esistono ma non sono ancora stati forniti. L'inglese viene integrato quando arrivano.

Dati dei cataloghi:
- **Agricoltura (7 famiglie):** Copripalo, Placchette, Ferma-teli e reti, Moschettoni e tiranti, Ancoraggi/ganci/chiodi, Sistemi di legatura, Accessori/PET/ricambi.
- **Garden (8 famiglie):** Testine, Filo nylon, Lame, Visiere, Abbigliamento, Accessori, Ancoraggi, Espositori.
- **Raccolta olive:** agevolatori e macchine, con specifiche tecniche (potenza, tensione, peso) e imballo.
- Scheda prodotto: nome, materiale (FE, FV, PA, PET, PE, PP, PVC, AL), caratteristiche, dimensioni, disegno tecnico, foto, video YouTube.
- Tabella articoli: codice (es. `1807-1351`), descrizione, confezione, imballo, pallet. Raccolta olive ha solo l'imballo.
- Ricambi e viteria hanno codici propri e compaiono dentro la scheda.

Il testo dei PDF è estraibile; le immagini si estraggono con `pdfimages`.

## 3. Struttura del sito

Lingue: italiano e inglese, URL paralleli (`/it/…`, `/en/…`).

Menu principale:
- **Prodotti:** Agricoltura, Garden, Raccolta olive. Un solo ingresso per linea, con il PDF scaricabile nella pagina della linea. Il flipbook "sfoglia catalogo" viene eliminato.
- **Applicazioni:** la galleria attuale (circa 145 foto di impianti realizzati), filtrabile per linea e famiglia e collegata alle schede prodotto.
- **Video e tutorial:** una sola sezione con filtro per linea. Ogni video compare anche nella scheda del suo prodotto.
- **Azienda:** storia, siti produttivi, qualità e imballaggi, Fiere ed eventi (prossime in alto, archivio sotto, open day incluso).
- **Area riservata:** login, documenti per rivenditori e clienti. Fuori dal catalogo pubblico, stessa grafica.
- **Contatti** e **Richiesta preventivo** sempre visibili, con contatore dei codici scelti.

Gerarchia catalogo: Linea → Famiglia → Prodotto → Articoli (righe con codice). URL brevi, per esempio `/it/agricoltura/copripalo/prisma`. Redirect 301 dai vecchi URL.

## 4. Richiesta di preventivo

- Ogni riga articolo ha il pulsante "aggiungi alla richiesta" e la quantità (in confezioni).
- La lista si salva nel browser (localStorage) e il contatore è sempre visibile.
- Ricerca istantanea per codice o parola chiave.
- Modulo: nome, azienda, email, telefono, paese, note, consenso privacy (GDPR).
- All'invio: email a `info@arnoplast.it` con la tabella dei codici e copia al cliente. Anti-spam senza captcha invasivi.
- Fuori ambito: carrello, pagamenti, prezzi pubblici, account clienti per ordini.

## 5. Stile visivo

Direzione scelta: **moderna e audace**, sui colori del brand.
- Rosso ARNOplast, nero e bianco; accenti per linea (rosso Agricoltura, verde Garden, terzo accento per Raccolta olive).
- Grandi foto di campo, titoli forti in maiuscolo, animazioni misurate che non penalizzano le prestazioni.
- Prodotti su sfondi chiari; disegni tecnici e foto grandi nelle schede; sigle dei materiali come etichette gialle, come nel catalogo.
- Mobile first.

## 6. Dati

Un archivio strutturato unico (JSON) estratto dai tre PDF: linee, famiglie, prodotti, articoli, specifiche, immagini, video.
- Modello unico per due casi: molte righe con poche caratteristiche (Agricoltura, Garden) e poche righe con molte caratteristiche (Raccolta olive).
- Campi per le traduzioni IT/EN.
- Estrazione automatica con controllo manuale da parte dell'utente prima dell'uso, perché le tabelle impaginate possono contenere errori.
- Il prototipo e il plugin WordPress leggono lo stesso archivio.

## 7. Integrazione WordPress

Fattibile e consigliata: il sito è già WordPress, quindi il cliente non cambia strumento.
- **Tema su misura, senza Divi e senza WooCommerce.**
- **Plugin Catalogo:** tipi di contenuto Linea, Famiglia, Prodotto, Articolo (codice, confezione, imballo, pallet), campi per le specifiche.
- Lista richiesta: JavaScript leggero e invio email con WordPress.
- Bilingue: plugin di traduzione con pagine collegate IT/EN (il plugin si sceglie in fase di piano).
- Area riservata: login WordPress con ruolo "rivenditore" e documenti protetti.
- Migrazione: importo i prodotti dall'archivio, redirect 301, test in ambiente di prova prima della produzione.

## 8. Prestazioni

- Pagine servite dalla cache come file statici.
- Immagini WebP/AVIF responsive, caricamento ritardato.
- JavaScript solo dove serve (lista richiesta, ricerca).
- Obiettivo: Lighthouse mobile ≥ 90, verificato sul WordPress di prova prima della messa online.
- Rischio non controllabile da qui: il piano di hosting. Se la prima risposta del server è lenta, serve verificarlo.
- Piano B: se l'obiettivo non viene raggiunto, valutare l'approccio headless (frontend statico generato da WordPress).
- Prima di partire: misura dei tempi reali del sito attuale come riferimento.

## 9. Fasi

1. Estrazione dati dai PDF e archivio controllato.
2. Prototipo navigabile in italiano, da approvare.
3. Inglese (da cataloghi EN) e rifinitura.
4. Tema + plugin WordPress, test prestazioni, migrazione.

## 10. Questioni aperte

- Cataloghi inglesi: da fornire.
- Area riservata: cosa contiene oggi (documenti per rivenditori/clienti) e come sono gestiti gli accessi; non ho potuto vedere la pagina dal sandbox.
- Backend WordPress attuale: versione, plugin effettivi, hosting. Da verificare.
- Tempi reali del sito attuale: da misurare.
