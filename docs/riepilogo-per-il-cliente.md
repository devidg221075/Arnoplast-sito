# ARNOplast, restyling del sito: cosa serve dal cliente e cosa resta

Stato: tutte le pagine del prototipo sono state riviste insieme, in italiano e in inglese (la versione inglese è la copia di quella italiana, tradotta). Il prototipo è statico: alcune funzioni arrivano solo con WordPress.

## 1. Cose che servono dal cliente

| Cosa | Perché | Dove si usa |
|---|---|---|
| Elenco delle 8 fiere passate (nome, luogo, date, stand) | Nelle pagine salvate dell'archivio (3-6) mancano e non si possono leggere dal sito vecchio | Pagina Fiere, IT e EN |
| Primo documento dell'area riservata, nome del gruppo e regola d'accesso | Oggi la pagina è solo un'anteprima | Area riservata |
| Listini | Vanno protetti lato server con WordPress | Area riservata |
| Tutorial Garden e Raccolta olive (elenco e titoli) | Il sito vecchio non è accessibile da qui | Video e Tutorial |
| Testo del banner cookie | Serve con Google Analytics (WordPress) | Cookie e privacy |
| Conferma di titolo e durata del video della home | In sospeso | Home |
| Conferma del campo "Indirizzo" nel modulo contatti | In sospeso | Contatti |
| Foto ad alta risoluzione, anche in zip (sostituiscono quelle ridotte) | Le foto incollate in chat sono ridotte a 2000 px | Tutto il sito |
| Loghi EIMA e Interpoma | Mancano | Fiere |
| Didascalie della galleria | Mancano | Galleria |

## 2. Cose da far verificare al cliente o al consulente

- **Privacy (IT e EN)**: testo copiato dal sito vecchio, con refusi da controllare, una ripetizione e il dubbio Arezzo/Firenze. La versione inglese è una traduzione nostra.
- **Cookie e privacy**: testo scritto da noi sullo stato attuale del sito (nessun cookie). Da far vedere al consulente legale e da riscrivere con WordPress.
- **Dati dell'Azienda**: Agricoltura 80% / Giardinaggio 20% e Italia 60% / Estero 40% vengono dal sito vecchio. Chiedere se sono ancora validi.
- **Traduzioni nostre (inglese)**: 46 nomi di prodotto, 24 descrizioni di articolo (più 14 corrette), Imballaggi, privacy, titoli dei video, categorie della galleria, "Bozen", "Ancoraggi" → "Clips", "Agevolatori e macchine" → "Harvest aids and machines", frasi della home senza testo del cliente.
- **Dati del catalogo**: codice `1902-02…` incompleto; caratteristiche di alcuni prodotti prese dai PDF e possibilmente incomplete; voci di `data/review.html` (Chiodo cuci-rete 1705-0202/0203, Elastico Premontato, codici duplicati 1802-0410, Viteria GZ, pallet 1800).
- **Raccolta olive**: in inglese esiste solo il depliant italiano (indicato come "Italian only").

## 3. Cosa resta per la fase WordPress

- Tema personalizzato dal prototipo (decisione da prendere col cliente).
- Modulo contatti e richiesta preventivo con invio reale e captcha.
- Feed automatico dei post Facebook (plugin dedicato).
- Banner cookie con consenso e Google Analytics, poi aggiornare la pagina Cookie e privacy.
- Protezione vera dell'area riservata e dei listini, lato server.
- Header di sicurezza, Lighthouse almeno 90, hreflang.
- Al lancio: togliere `noindex` dalle pagine inglesi e metterle nella sitemap.

## 4. Decisioni già prese

- Notizie: rimossa. Open Day: pagina "Coming soon". Download area: decisa. Imballaggi: approvata.
- Galleria con filtri per tipo (impianti, serre, dettagli, disegni).
- Modulo contatti senza captcha fino a WordPress.

## 5. Prossimo passo concordato

- Il cliente verifica lo zip di pubblicazione (`arnoplast-sito-pubblicazione.zip`, rigenerabile con la build).
- Poi: **rendere indicizzata anche la versione inglese**, come quella italiana: togliere `noindex` dalle pagine `/en/`, inserirle nella sitemap, aggiungere gli hreflang tra le due lingue e ricostruire lo zip.

## Da ricevere: condizioni generali del preventivo
Testo delle condizioni di vendita (italiano e inglese) da mettere in fondo al PDF del preventivo. Si inserisce da soli nella pagina «Condizioni PDF» del backoffice. Finché manca, il PDF parte senza condizioni e il backoffice lo segnala prima dell'invio. Non lo scrive il backoffice né Claude.
