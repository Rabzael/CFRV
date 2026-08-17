# Piano — Orari da Telegram via webhook

> Documento di progettazione. Nessun codice è ancora stato scritto.

## Contesto

La sezione ORARI di `index.html` contiene oggi una tabella statica con una sola riga, modificata a mano
a ogni variazione. L'obiettivo è che al suo posto compaia **il testo dell'ultimo messaggio pubblicato sul
canale `@Messe_Verona`**, mostrato *così com'è*, senza tentare di distinguere orari da avvisi.

Tre vincoli emersi in fase di analisi determinano l'architettura:

1. **Il post sul canale è la fonte autorevole, non [TabulaBot](https://github.com/Rabzael/TabulaBot).**
   Sul canale, i messaggi possono venire sia da TabulaBot (lo scheduler che compone e invia i messaggi)
   sia da autori umani direttamente: quello che conta è cosa risulta scritto sul canale in un dato
   momento, non da dove arriva → serve un webhook Telegram che legga il canale stesso, non un push
   dai dati interni di TabulaBot.
2. **Regola Telegram verificata** ([Bots FAQ](https://core.telegram.org/bots/faq)): *"bots will not be able
   to see messages from other bots regardless of mode"*. Quando a pubblicare è un umano, il webhook
   riceve regolarmente; quando pubblica TabulaBot, **il webhook non riceve nulla, in silenzio** — è un
   rischio già presente oggi, non solo ipotetico → va progettato l'allarme fin da subito.
3. **Aruba Linux shared hosting**: PHP 8.3/8.2/8.1/8.0, cURL, MySQL. Niente Node/Python, niente cron.
   → il codice server è PHP, sullo stesso dominio (nessun CORS, nessun servizio esterno).

Il sito ha **zero JavaScript** oggi: questo sarà il primo `<script>` del progetto. Nessun build step,
nessuna dipendenza — va mantenuto l'approccio "HTML a mano + FTP".

## Architettura

**Nessun PHP nel percorso di richiesta del visitatore.** Il webhook scrive un file JSON statico fuori
banda; il browser legge un file statico dalla stessa origine.

```
[umano posta su @Messe_Verona]
      │
      ▼
[Telegram] ──HTTPS POST──► /api/tg-hook.php
      │      header: X-Telegram-Bot-Api-Secret-Token
      ▼
  valida → filtra → decide → scrittura atomica
      │
      ▼
  /data/orari.json  (statico, pubblico, ~1 KB)
      │
      ▼
[browser] fetch → textContent in #orari-blocco
      └─ su qualunque errore: resta la <table class="orari"> statica
```

Conseguenze: la pagina resta veloce come oggi e non tocca PHP (nessun cookie, nessuna sessione → la
dichiarazione privacy nel footer resta vera); se PHP si rompe resta l'ultimo JSON valido; se il JSON si
rompe resta la tabella statica.

## File

| Path | Stato | In git | Note |
|---|---|---|---|
| `index.html` | mod | sì | wrapper `<div id="orari-blocco">` + `<script defer>` |
| `styles.css` | mod | sì | 3 regole in coda |
| `js/orari.js` | nuovo | sì | ~50 righe, nessuna dipendenza |
| `api/lib.php` | nuovo | sì | funzioni pure: normalizza, filtra, decidi, scrivi |
| `api/tg-hook.php` | nuovo | sì | guscio HTTP sottile su `lib.php` |
| `api/config.sample.php` | nuovo | sì | solo segnaposto |
| `api/config.php` | nuovo | **NO** | token + segreti reali |
| `api/stato.php` | nuovo (F4) | sì | heartbeat staleness |
| `data/.htaccess` | nuovo | sì | Content-Type, nosniff, no indexes |
| `data/orari.json` | runtime | **NO** | scritto da PHP |
| `.gitignore` | nuovo | sì | non esiste ancora nel repo |
| `tests/decidi.php` | nuovo | sì | asserzioni CLI sulla tabella decisionale |

Su Aruba, `config.php` può stare **sopra la webroot** (raggiungibile via FTP, non via HTTP):
`open_basedir` risulta **non ristretto** (verificato in Fase 0), quindi nessun impedimento tecnico
a farlo. Il ripiego dentro `api/` resta comunque sicuro anche come scelta permanente — è un `.php`,
quindi non viene mai servito come sorgente — più `.htaccess`.

**Deciso (17 agosto 2026): non una scelta unica fissata subito, ma due passi.**
- **UAT**: `config.php` dentro `api/` — più semplice da gestire durante i test, nessun rischio reale
  dato che `open_basedir` non c'entra.
- **Produzione**: spostato sopra la webroot, quando il sito lascia la cartella `/nuovo/`.

`tg-hook.php` e `stato.php` sono scritti apposta perché questo passaggio richieda **una sola riga
da cambiare per file** — il `require __DIR__ . '/config.php'`, con l'offset `/../` giusto per la
nuova posizione. `file_json` e `file_log` sono calcolati lì, ancorati alla posizione di quei due
file (che non si sposta mai), non dentro `config.php`: un `__DIR__` scritto dentro `config.php`
si romperebbe a ogni spostamento del file stesso. Vedi i commenti in `config.sample.php`,
`tg-hook.php`, `stato.php`.

## Fasi

Ogni fase è verificabile e rilasciabile da sola.

**Fase 0 — Ricognizione Aruba + bot.** *È un gate: qui si scoprono i blocchi, non in Fase 3.*
- Pannello → PHP 8.3. Sonda usa-e-getta che stampa `PHP_VERSION`, `open_basedir`, `is_writable(data/)`,
  leggibilità della cartella sopra la webroot, `getallheaders()`. **Cancellarla subito** (mai lasciare `phpinfo()`).
- Verificare che `.htaccess` sia rispettato e che ci sia HTTPS valido sull'hostname esatto (Telegram
  esige certificato valido su 443/80/88/8443).
- BotFather `/newbot` → bot dedicato (es. `CFRVSitoBot`). **Non riusare il token di TabulaBot**: un token
  può avere un solo webhook, e i raggi d'azione vanno separati.
- Aggiungerlo amministratore di @Messe_Verona con **tutti i permessi disattivati**: deve leggere, non
  scrivere. Un bot admin in sola lettura non può deturpare il canale nemmeno se il token trapela.
- Id numerico del canale: `curl "https://api.telegram.org/bot<TOKEN>/getChat?chat_id=@Messe_Verona"` →
  `-100…`, da salvare come `int`.
- *Blocco possibile:* se PHP non può scrivere da nessuna parte, il ripiego è MySQL + `orari.php`, ma
  rimette PHP nel percorso del visitatore. Avere la risposta, non costruirlo in anticipo.

**Fase 1 — Front-end con JSON scritto a mano. Nessun Telegram.**
Massimo valore per rischio minimo: disaccoppia il JS dal PHP e resta utile da solo (si aggiornano gli
orari caricando un JSON via FTP). Se la Fase 0 rivela un blocco, questa fase si rilascia comunque.

**Fase 2 — Endpoint PHP.** `config.php`, `lib.php`, `tg-hook.php`, `data/.htaccess`, log. Ancora nessun
Telegram: si collauda con la matrice curl.

**Fase 3 — `setWebhook` e collaudo dal vivo.**

**Fase 4 — `stato.php`, `.gitignore`, `LEGGIMI-telegram.md`** con i quattro interruttori d'emergenza.

**Fase 5 — *Rimandata*.** Percorso push di TabulaBot + allarme. Da attivare solo quando TabulaBot starà
per pubblicare direttamente sul canale ufficiale.

## Dettagli implementativi chiave

### Endpoint (`api/tg-hook.php`)

Cancelli in ordine: `POST` obbligatorio (405) → `Content-Length` max 64 KB (413) → segreto confrontato
con **`hash_equals`** (403, tempo costante) → corpo letto da `php://input` (non `$_POST`) → JSON valido →
tipo update tra `channel_post` / `edited_channel_post` → **`chat.id` uguale a quello in allowlist**.

**Dopo l'autenticazione si risponde sempre 200.** Telegram ritenta e mette in backoff il webhook sui non-2xx;
ogni rifiuto *logico* (hashtag escluso, chat sbagliata, nessun testo) è un esito corretto, non un errore.
Anche una scrittura fallita risponde 200: su hosting condiviso un disco pieno non si risolve nella finestra
di retry, e l'allarme staleness è la vera rete di sicurezza.

### Tabella decisionale — `cfrv_decidi()`, funzione pura

Post in arrivo **P**, stato salvato **S** (`S.message_id = 0` se il file manca):

| tipo update | `P.message_id` vs `S` | hashtag escluso | azione |
|---|---|---|---|
| `channel_post` | `>` | no | **PUBBLICA** |
| `channel_post` | `>` | sì | ignora (S sopravvive) |
| `channel_post` | `<=` | — | ignora (replay / fuori ordine) |
| `edited_channel_post` | `==` | no | **PUBBLICA** (la correzione si propaga) |
| `edited_channel_post` | `==` | sì | **RITIRA** |
| `edited_channel_post` | `>` | no | **PUBBLICA** (tag rimosso a posteriori) |
| `edited_channel_post` | `>` | sì | ignora (S sopravvive — confermato 17 agosto 2026) |
| `edited_channel_post` | `<` | — | ignora (un post vecchio modificato non deve risorgere) |
| nessun `text` né `caption` | — | — | ignora (una foto non deve svuotare il sito) |

**Riga `edited/>/sì` — non ovvia, spiegata**: capita solo dopo un `channel_post` già scartato per hashtag
(riga 2, S non è mai avanzato a quell'id) seguito da una modifica dello stesso post che non toglie il tag.
RITIRA presuppone che qualcosa sia *live* per quell'id — qui non lo è mai stato, quindi RITIRA
avanzerebbe S e cancellerebbe dal sito il messaggio precedente ancora valido come effetto collaterale.
Trattata come la riga 2 da cui deriva.

**RITIRA scrive un payload con testo vuoto, non cancella il file**: `message_id` va conservato perché una
modifica successiva che toglie l'hashtag possa ripubblicare (`==` + no tag → PUBBLICA).

Tenerla **pura** — `(array $stato, array $post, string $tipo, array $cfg) -> array` — è la decisione più
importante per la testabilità: tutte le righe diventano asserzioni CLI senza HTTP, Telegram o filesystem.

### Normalizzazione ed esclusione

`text ?? caption`; validare UTF-8 con `mb_check_encoding`; CRLF→LF; comprimere righe vuote multiple;
togliere i caratteri di controllo; `trim`; troncare a 4000 caratteri con `…`.

Esclusione, **due modalità** (config `escludi_tutti_hashtag`, non nel disegno originale):
- **Lista**: `preg_match` case-insensitive con confine di parola sui soli hashtag configurati in
  `hashtag_esclusi` (es. `#nosito`, `#privato`). `#nositox` non fa match.
- **Generica**: esclude qualunque messaggio che contenga un hashtag *qualsiasi*
  (`/(?<!\S)#\w+/u`), a prescindere da quale sia — `hashtag_esclusi` viene ignorato in questa
  modalità. È il valore di default in `config.sample.php`.

Il confine di parola non è un `\b` su entrambi i lati: `#` non è un carattere di parola, quindi un
`\b` subito prima di `#nosito` non scatta mai nei casi reali (preceduto da spazio o inizio stringa,
entrambi non-parola → nessun confine). Serve un lookbehind negativo `(?<!\S)` sul lato iniziale —
vedi `cfrv_escluso()` in `lib.php`.

**Il filtro gira prima della scrittura, mai al rendering**: un messaggio escluso non deve mai finire in un
file leggibile pubblicamente.

### Scrittura atomica

`file_put_contents` su un temporaneo con nome da `random_bytes` nella **stessa cartella**, poi `rename()`
— atomico su Linux, quindi il lettore non vede mai un file a metà.
`json_encode` con `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`: garantisce che il file
non possa contenere `<` o `>`, quindi mai `</script>`. **Senza `JSON_UNESCAPED_UNICODE`**: l'output resta
ASCII puro e accenti ed emoji sopravvivono a qualunque errore di charset su Aruba.

### Forma del JSON (`/data/orari.json`)

```json
{ "schema": 1, "text": "…", "message_id": 482,
  "posted_at": 1755350400, "updated_at": 1755350412,
  "edited": false, "retracted": false }
```

`posted_at` = `edit_date ?? date`. **Zero dati personali**: nessun autore, nessun titolo canale, nessun
update grezzo — il footer resta letteralmente vero e non c'è nulla da esfiltrare.

### Front-end (`js/orari.js`)

Progressive enhancement: la `<table class="orari">` **resta nell'HTML** come fallback e viene sostituita
solo quando arrivano dati freschi e validi. Nessun `<noscript>`: la tabella statica *è* lo stato senza JS.

Scarta (e quindi lascia la tabella) se: risposta non ok, JSON non valido, `text` vuoto,
`posted_at` non numerico, **più vecchio di 30 giorni**, o datato oltre 1 giorno nel futuro.

> Il cancello dei 30 giorni è l'unica mitigazione che protegge il *visitatore* invece dell'amministratore,
> e non richiede infrastruttura: se il webhook muore, il sito torna da solo alla tabella statica invece di
> mostrare avvisi vecchi per sempre.

**XSS — il rischio critico.** Il contenuto è testo non fidato da una chat.
**Regola: solo `textContent`.** `p.textContent = testo` non può creare un elemento: non c'è parser, quindi
non c'è bypass. Vietati in questo file: `innerHTML`/`outerHTML`/`insertAdjacentHTML`/`document.write` su
valori non fidati, `eval`/`new Function`, qualunque linkificatore a regex su stringhe HTML.

**Non implementare le `entities` Telegram (grassetto/link) in v1** — e mai in PHP: gli offset Telegram sono
in **unità UTF-16**, mentre `substr` è a byte e `mb_substr` a codepoint. Un 🙏 vale 1 codepoint, 2 unità
UTF-16, 4 byte: su un canale parrocchiale con emoji ogni offset successivo sarebbe sbagliato. L'unico
posto sicuro sarebbe JavaScript, dove gli indici *sono* unità UTF-16 — molta macchineria per del grassetto
che nessuno ha chiesto.

Timestamp con `Intl.DateTimeFormat('it-IT', … timeZone:'Europe/Rome')` → `Aggiornato il 16 agosto 2026, 14:32`.

### CSS

Tre regole in coda a `styles.css`, riusando i valori già presenti: bordo/raggio/sfondo copiati da
`table.orari` (`styles.css:35`) così il blocco dinamico è indistinguibile da quello statico;
`white-space:pre-wrap` per gli a capo del canale senza HTML; `overflow-wrap:anywhere` perché un URL lungo
non sfondi il contenitore su mobile; la riga "Aggiornato il…" riusa la classe `.eyebrow` esistente
(`styles.css:14`) sovrascrivendo solo il margine. Nessuno spinner: la tabella statica è già contenuto corretto.

## Comportamento in caso di guasto

| Condizione | Il visitatore vede |
|---|---|
| JS disattivato, fetch assente, errore di rete, 404, 500, JSON malformato | tabella statica |
| `text` vuoto (RITIRA) o messaggio più vecchio di 30 giorni | tabella statica |
| Messaggio fresco e valido | blocco dinamico + "Aggiornato il …" |

**Post cancellato su Telegram**: Telegram non notifica le cancellazioni ai bot, quindi il JSON conserva il
testo. Quattro interruttori, dal più economico:

1. **Modificare il post aggiungendo `#nosito`** → arriva `edited_channel_post`, RITIRA, il sito torna alla
   tabella in pochi secondi. *Si fa dal telefono, senza FTP: è l'interfaccia di emergenza, ed è Telegram stesso.*
2. Pubblicare un nuovo messaggio: diventa l'ultimo e sostituisce il precedente.
3. Cancellare `/data/orari.json` via FTP (~30 secondi).
4. Togliere il tag `<script>` da `index.html`: ritorno permanente al sito statico.

## Sicurezza

| Superficie | Minaccia | Mitigazione |
|---|---|---|
| `POST /api/tg-hook.php` | update falsificato → defacement | `secret_token` 32 byte hex, `hash_equals`; solo POST; cap 64 KB; allowlist `chat.id`; solo HTTPS |
| Testo del messaggio | **XSS (critico)** | `textContent`; niente `innerHTML`; niente entities/linkify; `JSON_HEX_TAG`; `nosniff` |
| `/data/orari.json` servito come HTML | XSS stored sulla propria origine | `Content-Type: application/json` + `X-Content-Type-Options: nosniff` |
| Token e segreti a riposo | compromissione | fuori webroot; mai `.env`/`.json`/`.txt`; **mai lasciare `config.php.bak` o `config.php~`** (gli avanzi degli editor vengono serviti in chiaro) |
| Repo git | token in cronologia, di fatto irreversibile | `.gitignore` + `config.sample.php` |
| Scrittura arbitraria | path traversal | percorso di output costante, **nessun input utente tocca un filename** |
| Riempimento disco | DoS | cap corpo/testo, log troncato a 512 KB, JSON sovrascritto mai appeso |
| Token trafugato | bot che posta o cancella nel canale | bot admin **senza alcun permesso** → non può postare nemmeno col token rubato |

**Perché il token non va in git anche senza remote:** un repo senza remote prima o poi ne acquista uno, e
una volta che il token è in un commit toglierlo richiede riscrivere la storia. In caso di fuga: `/revoke`
su BotFather e nuovo `setWebhook`.

**Rischio residuo se trapela il segreto del webhook:** un attaccante può scrivere *testo* in un blocco di
una pagina. Niente XSS (la mitigazione è strutturale, non a filtro), niente furto dati, niente persistenza
oltre il prossimo post legittimo. Recupero: nuovo `secret_token` + cancellazione del JSON.

**Nota preesistente, fuori ambito:** `index.html:7,9` caricano Google Fonts, richieste a terzi che
espongono l'IP dei visitatori — già in tensione con la dichiarazione privacy del footer, indipendentemente
da questa feature. Questo piano non peggiora la situazione; auto-ospitare i due font la risolverebbe.

## `setWebhook` (Fase 3)

```bash
SEGRETO=$(openssl rand -hex 32)   # solo hex: secret_token ammette A-Za-z0-9_- — base64 è INVALIDO
curl -X POST "https://api.telegram.org/bot<TOKEN>/setWebhook" \
  -d "url=https://sanremigioverona.org/api/tg-hook.php" \
  -d "secret_token=$SEGRETO" \
  -d 'allowed_updates=["channel_post","edited_channel_post"]' \
  -d "max_connections=4" -d "drop_pending_updates=true"
```

Mettere `$SEGRETO` in `config.php` **prima**, o il primo post reale viene rifiutato.
Verifica: `getWebhookInfo` → `url` corretto, `pending_update_count: 0`, e soprattutto
**`last_error_message` assente** — è il segnale diagnostico più utile di tutto il sistema (se il WAF di
Aruba rifiuta i corpi JSON, compare lì testualmente). Ricontrollarlo dopo ogni prova.

## Verifica

**Livello 1 — logica pura, in locale:** `php tests/decidi.php` copre tutte le righe della tabella
decisionale, il normalizzatore (CRLF, righe vuote, troncamento, caratteri di controllo) e il matcher degli
hashtag (`#nosito` vs `#nositox` vs `Il #nosito.`). Nessun framework, nessuna rete.

**Livello 2 — front-end in locale:** `php -S localhost:8080` dalla radice del repo (**`file://` non
funziona**: `fetch` è bloccato su quell'origine). Scrivere `data/orari.json` a mano e provare: file
assente → tabella; `text:""` → tabella; `posted_at` di 60 giorni fa → tabella; JSON malformato → tabella;
testo `<script>alert(1)</script>` → **caratteri letterali, e in DevTools nessun elemento `<script>` nel DOM**.

**Livello 3 — endpoint in produzione, senza Telegram.** Matrice curl: GET → 405; segreto sbagliato → 403;
**nessun header segreto → 403** (prova anche che gli header custom sopravvivono al FastCGI di Aruba);
segreto giusto → 200 e JSON aggiornato; chat id sbagliato / corpo malformato / hashtag escluso → 200 e
**JSON invariato**. Infine: `config.php` mai servito come sorgente, log 403/404, `/data/` senza listing,
header `Content-Type` e `nosniff` presenti.

> Se l'header venisse stripped, il ripiego è mettere il segreto nella query string dell'URL del webhook
> (Telegram accetta URL arbitrari e l'URL non è mai visibile a un browser). Costo: finisce nei log di
> accesso di Aruba, cosa che un header evita. Solo se costretti.

**Livello 4 — end-to-end da Telegram.** Postare su @Messe_Verona e controllare sito e log:
testo semplice; accenti ed emoji (`perché 🙏 ore 9:00`) senza mojibake; multi-riga con riga vuota;
**`<script>alert(1)</script>` e `"><img src=x onerror=alert(1)>` resi come testo letterale — la prova
decisiva**; modifica del post → il testo si aggiorna; modifica con `#nosito` → torna la tabella; modifica
che toglie `#nosito` → ripubblica; nuovo post con `#nosito` → sito invariato; foto senza didascalia →
invariato; foto con didascalia → didascalia mostrata; >4000 caratteri → troncato, layout integro su mobile;
post cancellato → invariato (documenta il buco), poi provare l'interruttore 1.

## Rischi

- **R1 — Obsolescenza silenziosa (rischio principale).** Un sito congelato che mostra orari vecchi ma
  plausibili è peggio di uno che mostra la tabella statica. Mitigato dal cancello dei 30 giorni (non
  negoziabile) + allarme di Fase 5.
- **R2 — Incognite Aruba** (`open_basedir`, permessi di scrittura, ModSecurity, header stripped): tutte
  gate di Fase 0/2, nessuna va scoperta in Fase 3.
- **R3 — Il bot perde i diritti di admin**, o il canale migra a supergruppo (l'id cambia e l'allowlist
  inizia a rifiutare tutto, correttamente ma invisibilmente). Stessa classe di R1, stesso allarme; la
  spia è la riga di log `CHAT-NON-AUTORIZZATA`.
- **R4 — Rischio di prodotto, non tecnico.** L'ultimo messaggio potrebbe essere un avviso, un annuncio
  funebre o la didascalia di una foto, e sostituirebbe comunque il contenuto della sezione intitolata
  "Orario delle celebrazioni". Scelta già presa consapevolmente ("così com'è", filtro hashtag come
  contromisura).
  **Deciso (17 agosto 2026): il messaggio SOSTITUISCE la tabella.** La tabella statica è esclusivamente
  un fallback: si vede solo quando non ci sono dati freschi e validi, mai insieme al blocco dinamico.
  Conseguenza per la Fase 1: la sostituzione avviene in un colpo solo (`replaceChildren`) e *solo dopo*
  che il blocco dinamico è stato costruito senza errori — la tabella non va mai nascosta preventivamente,
  altrimenti il caso "JS attivo ma fetch fallito" resterebbe con la sezione vuota. Il breve lampo della
  tabella prima della sostituzione è accettato: nasconderla in CSS romperebbe il caso senza JS.
  Contromisura in caso di messaggio inadatto: `#nosito` dal telefono (interruttore 1).

**Trappole da rifiutare** su un sito di queste dimensioni: parsing delle `entities`; rendering di Markdown
o HTML da Telegram (reintroduce XSS per design); un database (un file JSON è il datastore corretto; MySQL
solo come ripiego se il filesystem non è scrivibile); cache/CDN/service worker per 1 KB; una UI web di
amministrazione (l'interruttore è il post Telegram, già sul telefono); archivio storico dei messaggi;
coda di retry; rate limiting; minificazione o build step (romperebbe l'impostazione "a mano + FTP");
una coda di approvazione per il percorso TabulaBot (la revisione è già il passaggio umano + `#nosito`).
