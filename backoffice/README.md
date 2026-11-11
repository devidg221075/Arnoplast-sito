# Backoffice

Backoffice riusabile per siti statici. Tappa 1: ricezione dei moduli (contatti e preventivo), e-mail al cliente e
all'azienda con modelli grafici, registro delle richieste con stato e note interne, utenti con due ruoli, registro delle attività.
Tappa 3: preventivo con prezzi, sconti, trasporto, IVA e PDF allegato all'e-mail.
Tappa 2: risposte al cliente dalla scheda della richiesta e lettura delle risposte dalla casella (conversazione completa in un solo posto).

Requisiti: PHP 8.3 o successivo (provato con 8.3; pensato anche per 8.5), MySQL, estensioni `pdo_mysql`, `mbstring`, `openssl`.
Non servono SSH né Composer sull'hosting: la cartella `vendor/` è già nel repository.

## Struttura

| Cartella | Contenuto |
|---|---|
| `public/` | L'unica cartella esposta al web: `index.php` (backoffice), `api/submit.php` (riceve i moduli), `assets/` |
| `src/` | Codice: `App` (configurazione, database, limiti), `Auth` (accesso), `Submit` (controlli e salvataggio), `Mailer` (SMTP), `Conversation` (risposte e collegamento ai messaggi), `Imap` e `Mime` (lettura della posta), `Inbox` (controllo della casella) |
| `src/Quote*.php` | Preventivo: calcolo (`Quote`), PDF (`QuotePdf`, libreria FPDF), schermata nella scheda richiesta (`QuoteView`) |
| `bin/` | `fetch-mail.php`: controllo della casella, da lanciare con una pianificazione (cron) |
| `tests/` | `php tests/inbox_test.php`: prova della conversazione con un server di posta finto (serve `python3`) |
| `emails/` | Modelli e-mail e anteprima (`php emails/preview.php <cartella>`) |
| `config.sample.php` | Modello della configurazione. `config.php` non va mai nel repository |

## Installazione su cPanel

1. **Database**: cPanel → Database MySQL → crea un database e un utente con tutti i privilegi su quel database.
2. **Sottodominio**: cPanel → Domini → crea `gestione.<dominio>` con cartella principale (document root) puntata a `backoffice/public`.
   Attiva il certificato (SSL/TLS Status) e imposta PHP 8.3 o successivo (Gestione MultiPHP).
3. **File**: carica la cartella `backoffice/` (Gestione file o Git Version Control), `vendor/` compresa.
4. **Configurazione**: copia `config.sample.php` in `config.php` e compila database, casella di posta, indirizzi e due stringhe casuali
   (`secret`, `setup_token`). Le tabelle si creano da sole al primo accesso.
5. **Primo utente**: apri `https://gestione.<dominio>/`, inserisci il `setup_token` e crea l'amministratore. Poi svuota `setup_token`.
6. **Sito**: ricostruisci il sito con `ARNO_FORMS_ENDPOINT=https://gestione.<dominio>/api/submit.php`. I moduli spediscono al backoffice e
   la politica di sicurezza del sito (CSP) lo consente. Senza la variabile i moduli aprono il programma di posta, come prima.
7. **Origini**: in `config.php`, `allowed_origins` deve elencare gli indirizzi del sito da cui partono i moduli.

## Preventivi con prezzi (tappa 3)

Dalla scheda di una richiesta: «Prepara il preventivo» (parte dagli articoli chiesti dal cliente) → prezzi, sconti, trasporto/imballo, aliquota IVA, validità,
pagamento, consegna, note → «Salva e vai all'invio» → controllo del PDF → invio con il PDF allegato a un'e-mail con la grafica del sito.
Il numero è quello della richiesta (P-AAAA-NNNN); un preventivo inviato non si modifica: si crea una nuova revisione («rev. 2»). Gli importi si
salvano in centesimi. La copia esatta del PDF inviato resta in `var/quotes/` (fuori dal web). Le condizioni generali (italiano e inglese) si inseriscono
nella pagina **Condizioni PDF** (amministratore): il backoffice non le scrive.

## Risposte dei clienti: lettura della casella (tappa 2)

Il backoffice legge la casella `mail.user` (la stessa da cui spedisce) via IMAP, senza estensioni: prende solo i messaggi con un riferimento
(`C-AAAA-NNNN` o `P-AAAA-NNNN`) nell'oggetto, oppure che rispondono a una conversazione già avviata. Gli altri non si toccano:
si leggono senza segnarli come letti e non si spostano né si cancellano. Risposte automatiche, bollettini e posta di liste si ignorano.
Gli allegati non si copiano: il messaggio li segnala e restano nella casella.

Per il controllo automatico, in cPanel → **Processi Cron** aggiungi, ogni 5 minuti:

```
/usr/local/bin/ea-php83 /home/<utente-cpanel>/backoffice/bin/fetch-mail.php >> /home/<utente-cpanel>/backoffice/var/fetch.log 2>&1
```

(se `ea-php83` non esiste, il percorso del PHP 8.3 si legge in cPanel → Gestione MultiPHP). In alternativa, o per provare, la pagina **Posta**
(amministratore) ha il pulsante «Controlla la posta ora». Impostazioni facoltative in `config.php`: `imap` con `host`, `port`, `user`, `password`,
`encryption` (`ssl`), `verify_peer`, `folder`; se manca si usano i dati di `mail`.

## Sicurezza

- Controlli lato server su ogni campo; trappola antispam; limite di 5 invii ogni 10 minuti e 30 al giorno per indirizzo.
- Moduli accettati solo dai siti in `allowed_origins`.
- La richiesta si salva prima di spedire: se la posta non parte resta nel registro, segnata come «non inviata».
- Password con `password_hash`, almeno 12 caratteri; 5 tentativi falliti → blocco di 15 minuti; sessione con cookie `HttpOnly`, `SameSite=Lax`, scadenza dopo 2 ore di inattività.
- Protezione CSRF su tutti i moduli del backoffice; nessuno script nelle pagine; intestazioni di sicurezza restrittive.
- Gli indirizzi IP non si salvano in chiaro: solo un'impronta.

## Riuso su un altro sito

Stesso codice, installazione separata: cambiano `config.php` (nome, indirizzi, posta, tipologie) e, in `emails/templates.php`, colori,
dati aziendali e testi. I moduli specifici (preventivo con articoli) si attivano solo se il sito li usa.
