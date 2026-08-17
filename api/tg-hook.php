<?php
/**
 * Webhook Telegram — guscio HTTP sottile su lib.php.
 *
 * Riceve gli update del canale @Messe_Verona e aggiorna data/orari.json.
 * NON è nel percorso di richiesta del visitatore: il browser legge il JSON
 * statico, questo file gira solo quando posta Telegram.
 */

declare(strict_types=1);

// UAT: config.php dentro api/. In produzione si sposterà sopra la webroot —
// quando succede, questa è l'UNICA riga da cambiare in tutto il progetto:
//   '/config.php'        -> se config.php resta in questa stessa cartella
//   '/../../config.php'  -> se config.php va due cartelle sopra questa
// (il numero di /../ dipende da quanti livelli lo si sposta in su: contare
// le cartelle da qui fino alla nuova posizione di config.php).
$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

// Calcolati qui, non dentro config.php: __DIR__ = api/, che non si sposta
// mai (a differenza di config.php). Vedi commento in config.sample.php.
$cfg['file_json'] = __DIR__ . '/../data/orari.json';
$cfg['file_log']  = __DIR__ . '/tg-hook.log';


/**
 * Cancelli in ordine, prima di toccare qualunque dato:
 *
 *   1. metodo != POST                      → 405, fine
 *   2. Content-Length > max_corpo_byte     → 413, fine
 *   3. header X-Telegram-Bot-Api-Secret-Token confrontato con hash_equals()
 *      (tempo costante; header assente = fallimento) → 403, fine
 *
 * DOPO L'AUTENTICAZIONE SI RISPONDE SEMPRE 200. Telegram ritenta e mette il
 * webhook in backoff sui non-2xx, mentre ogni rifiuto *logico* (hashtag
 * escluso, chat sbagliata, nessun testo) è un esito corretto, non un errore.
 * Anche una scrittura fallita risponde 200: su hosting condiviso un disco
 * pieno non si risolve nella finestra di retry, e la rete di sicurezza vera
 * è l'allarme di staleness (stato.php).
 */
function cfrv_cancelli(array $cfg): void
{
    /* 1. Solo POST. Un GET su questo URL è quasi sempre uno scanner, o un
       umano che incolla l'indirizzo nel browser: 405 e via, senza log. */
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        cfrv_rispondi(405);
    }

    /* 2. Cap sul corpo PRIMA di leggerlo: Content-Length è dichiarato dal
       chiamante, quindi è solo un pre-filtro a costo zero, non una garanzia.
       Il cap vero va riapplicato in cfrv_gestisci() sulla lunghezza reale
       letta da php://input, perché un corpo può mentire su quanto è lungo.
       Assente o non numerico → 411: Telegram lo manda sempre, e distinguerlo
       dal caso "troppo grande" rende il log leggibile. */
    $len = $_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? '';
    if ($len === '' || !ctype_digit((string) $len)) {
        cfrv_rispondi(411);
    }
    if ((int) $len > $cfg['max_corpo_byte']) {
        cfrv_rispondi(413);
    }

    /* 3. Segreto condiviso. $_SERVER è la via che sopravvive a FastCGI;
       getallheaders() è il ripiego se l'hosting non popola HTTP_*.
       Header assente → stringa vuota → hash_equals() fallisce, nessun caso
       speciale da scrivere. */
    $ricevuto = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($ricevuto === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $nome => $valore) {
            if (strcasecmp($nome, 'X-Telegram-Bot-Api-Secret-Token') === 0) {
                $ricevuto = (string) $valore;
                break;
            }
        }
    }

    /* hash_equals confronta in tempo costante: un confronto con === perderebbe
       al primo byte diverso e renderebbe il segreto indovinabile a tentativi.
       Il segreto vuoto in config.php è un errore di installazione, non una
       richiesta da autenticare: si rifiuta comunque. */
    $atteso = (string) ($cfg['secret_token'] ?? '');
    if ($atteso === '' || !hash_equals($atteso, $ricevuto)) {
        cfrv_log($cfg, 'SEGRETO-ERRATO');
        cfrv_rispondi(403);
    }

    /* Superati i tre cancelli, la richiesta è autenticata: da qui in poi
       ogni esito, compreso il rifiuto logico, risponde 200. */
}


/**
 * Dopo i cancelli:
 *   - corpo letto da php://input (MAI $_POST: Telegram manda application/json)
 *   - json_decode → se non valido: log CORPO-NON-VALIDO, 200
 *   - tipo update in {channel_post, edited_channel_post} → altrimenti 200
 *   - chat.id === $cfg['chat_id'] (confronto int, allowlist) → altrimenti
 *     log CHAT-NON-AUTORIZZATA, 200
 *   - cfrv_leggi_stato() → cfrv_decidi() → se PUBBLICA/RITIRA: cfrv_scrivi()
 *   - log dell'esito, 200
 */
function cfrv_gestisci(array $cfg): void
{
    // Le quattro varianti sotto sono tutte "il corpo non è utilizzabile":
    // un'unica etichetta di log (CORPO-NON-VALIDO, l'unica che cfrv_log
    // promette per questa famiglia), con $dettaglio a distinguerle per chi
    // legge il file — non servono quattro etichette diverse per lo stesso
    // concetto.
    $max = $cfg['max_corpo_byte'];
    $corpo = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($corpo === false) {
        cfrv_log($cfg, 'CORPO-NON-VALIDO', 'lettura fallita');
        cfrv_rispondi(200);
    }
    if (strlen($corpo) > $max) {
        cfrv_log($cfg, 'CORPO-NON-VALIDO', 'oltre il cap');
        cfrv_rispondi(200);
    }

    $json_body = json_decode($corpo, true, 16);
    if (!is_array($json_body)) {
        cfrv_log($cfg, 'CORPO-NON-VALIDO', 'json non decodificabile');
        cfrv_rispondi(200);
    }

    if (!isset($json_body['channel_post']) && !isset($json_body['edited_channel_post'])) {
        cfrv_log($cfg, 'CORPO-NON-VALIDO', 'tipo update non gestito');
        cfrv_rispondi(200);
    }

    $tipo_msg = isset($json_body['channel_post']) ? 'channel_post' : 'edited_channel_post';
    $message = $tipo_msg === 'channel_post' ? $json_body['channel_post'] : $json_body['edited_channel_post'];
    if (!isset($message['chat']) || !isset($message['chat']['id']) || $message['chat']['id'] !== $cfg['chat_id']) {
        cfrv_log($cfg, 'CHAT-NON-AUTORIZZATA', 'id non corrispondente');
        cfrv_rispondi(200);
    }

    $stato = cfrv_leggi_stato($cfg['file_json']);
    $esito = cfrv_decidi($stato, $message, $tipo_msg, $cfg);

    if ($esito['azione'] === CFRV_PUBBLICA || $esito['azione'] === CFRV_RITIRA) {
        if (cfrv_scrivi($cfg['file_json'], $esito['payload'])) {
            cfrv_log($cfg, 'OK-' . $esito['azione']);
        } else {
            cfrv_log($cfg, 'SCRITTURA-FALLITA');
        }
    } else {
        cfrv_log($cfg, 'IGNORA-' . strtoupper($esito['motivo']));
    }

    cfrv_rispondi(200);
}


/**
 * Risposta minima: nessun corpo utile, Telegram guarda solo lo status.
 *
 * ⚠️ CONTRATTO: questa funzione NON DEVE MAI RITORNARE. cfrv_cancelli() la
 * chiama e prosegue solo se la richiesta è legittima: se dopo http_response_code()
 * mancasse l'exit, l'esecuzione continuerebbe fino a cfrv_gestisci() e una
 * richiesta appena respinta con 403 verrebbe comunque processata.
 */
function cfrv_rispondi(int $status): never
{
    http_response_code($status);
    exit;
}

/* HOOK */
cfrv_cancelli($cfg);
cfrv_gestisci($cfg);