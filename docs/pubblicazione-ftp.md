# Pubblicazione del sito: dal backoffice, via GitHub Actions e FTP

Come funziona:
1. In **Pubblica** (backoffice) l'amministratore vede le modifiche in bozza (testi, fiere, galleria, video) e conferma.
2. Il backoffice salva i file dati e le foto nuove nel repository GitHub, in **un solo commit** sul ramo `main`.
3. GitHub Actions (`.github/workflows/pubblica.yml`) costruisce il sito e lo carica via FTP sul server. Ci vogliono pochi minuti.
4. Lo stesso lavoro parte ogni notte (alle 01:10 UTC): le fiere finite passano da sole fra le «passate».

Nessuna password sta nel repository: i segreti stanno in GitHub (FTP) e in `config.php` sul server (chiave GitHub).

## Cosa deve creare il cliente (una volta sola)

### 1. Utente FTP dedicato (cPanel → Account FTP)
- Crea un account FTP **solo per il sito**, con la cartella di partenza uguale alla radice di `nuovo.arnoplast.it` (poi di `www.arnoplast.it`). Non usare la password di cPanel.
- Annota: indirizzo del server FTP, nome utente, password.

### 2. Segreti e variabili in GitHub (repository → Settings → Secrets and variables → Actions)
| Dove | Nome | Valore |
|---|---|---|
| Secrets | `FTP_SERVER` | indirizzo del server FTP (es. `ftp.arnoplast.it`) |
| Secrets | `FTP_USERNAME` | utente FTP dedicato |
| Secrets | `FTP_PASSWORD` | password dell'utente FTP |
| Variables | `FTP_DIR` | cartella sul server, di norma `/` (con l'utente dedicato) |
| Variables | `ARNO_FORMS_ENDPOINT` | `https://gestione.arnoplast.it/api/submit.php` (se manca, vale questo) |

### 3. Chiave GitHub per il backoffice (Settings dell'account → Developer settings → Fine-grained tokens)
- **Solo questo repository.** Scadenza: 1 anno (da rinnovare).
- Permessi: **Contents: Read and write**; **Actions: Read-only** (serve a mostrare lo stato dell'ultima costruzione); Metadata: Read-only (automatico).
- Nessun altro permesso. La chiave si scrive **soltanto** in `config.php` sul server, mai in chat o nel repository:

```php
'github' => ['repo' => 'devidg221075/arnoplast-sito', 'branch' => 'main', 'token' => 'github_pat_…'],
```

### 4. Il ramo `main`
- Il sito pubblicato è il ramo `main`. Oggi esiste solo il ramo di lavoro: `main` nasce unendo la richiesta di unione (pull request).
- Su `main` **non va attivata la protezione «richiedi pull request»**: il backoffice salva direttamente lì con la sua chiave.

### 5. Prima prova
- GitHub → Actions → «Costruisci e pubblica il sito» → **Run workflow**. Se è verde, il sito è sul server.
- Poi nel backoffice: modifica un testo → **Pubblica** → controlla che lo stato dell'ultima costruzione passi a «riuscita».

## Quando si passa a www.arnoplast.it
- Cambia `FTP_DIR` / l'utente FTP perché puntino alla radice del sito definitivo e, in `config.php`, `site_url` e `assets_url`.
- Il file `.ftp-deploy-sync-state.json` che l'azione scrive sul server serve a caricare solo i file cambiati: non va cancellato.

## Sicurezza
- La chiave GitHub può solo scrivere nel repository: non può toccare il server. L'utente FTP può solo scrivere nella cartella del sito.
- Per revocare l'accesso: elimina la chiave su GitHub (il backoffice smette di pubblicare) oppure cambia la password FTP.
- Ogni pubblicazione finisce nel registro attività («sito.pubblicato») con il collegamento al commit.
- Le foto nuove di galleria e fiere vengono salvate nel repository (`content/photos/galleria/`, `content/photos/fiere/`) e convertite dalla costruzione.
