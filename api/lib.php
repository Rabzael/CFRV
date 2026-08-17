<?php
/**
 * Logica pura del webhook orari — nessun I/O HTTP, nessuna variabile superglobale.
 *
 * Tutto ciò che è testabile da riga di comando vive qui; `tg-hook.php` è solo
 * il guscio HTTP. `cfrv_decidi()` in particolare deve restare una funzione pura:
 * è la scelta di progetto che rende la tabella decisionale verificabile senza
 * rete, senza Telegram e senza filesystem (vedi tests/decidi.php).
 */

declare(strict_types=1);

/** Versione dello schema scritto in data/orari.json. */
const CFRV_SCHEMA = 1;

/* Esiti possibili di cfrv_decidi(). */
const CFRV_PUBBLICA = 'PUBBLICA';
const CFRV_RITIRA   = 'RITIRA';
const CFRV_IGNORA   = 'IGNORA';


/**
 * Normalizza il testo grezzo di un post di canale.
 *
 * Ordine previsto: `text ?? caption` → mb_check_encoding UTF-8 (se fallisce,
 * stringa vuota) → CRLF/CR in LF → rimozione caratteri di controllo (tranne \n)
 * → compressione di 3+ righe vuote consecutive → trim → troncamento a
 * $max_char con '…' finale.
 *
 * @param array $post `channel_post` o `edited_channel_post` grezzo.
 * @return string Testo pronto alla pubblicazione, '' se non pubblicabile.
 */
function cfrv_normalizza(array $post, int $max_char): string
{
    $grezzo = $post['text'] ?? $post['caption'] ?? '';
    $grezzo = is_string($grezzo) ? $grezzo : '';

    if (!mb_check_encoding($grezzo, 'UTF-8')) {
        return '';
    }

    $testo = str_replace(["\r\n", "\r"], "\n", $grezzo);

    // C0 (tranne \n = 0x0A), DEL, C1 — via codepoint, non byte: /u perché il
    // testo può contenere sequenze UTF-8 multi-byte (accenti, emoji).
    $testo = preg_replace('/[\x00-\x09\x0B-\x1F\x7F\x{0080}-\x{009F}]/u', '', $testo);

    // 3+ \n consecutivi (2+ righe vuote) → un'unica riga vuota (2 \n).
    $testo = preg_replace('/\n{3,}/', "\n\n", $testo);

    $testo = trim($testo);

    // mb_strlen/mb_substr contano CODEPOINT, non byte: un troncamento a byte
    // spezzerebbe un'emoji o un accento a metà lasciando un carattere rotto.
    if (mb_strlen($testo, 'UTF-8') > $max_char) {
        $testo = mb_substr($testo, 0, $max_char, 'UTF-8') . '…';
    }

    return $testo;
}


/**
 * Vero se il messaggio va escluso dalla pubblicazione.
 *
 * $escludi_tutti (config: escludi_tutti_hashtag) allarga il filtro da lista
 * chiusa a rilevamento generico: se true, esclude qualunque messaggio che
 * contenga UN HASHTAG QUALSIASI, non solo quelli in $hashtag — utile se si
 * preferisce "ogni tag nasconde il post" invece di mantenere un elenco.
 * In questa modalità $hashtag viene ignorato (config.sample.php lo lascia
 * vuoto quando escludi_tutti_hashtag è true, coerentemente).
 *
 * Altrimenti, confronto case-insensitive con confine di parola sui soli tag
 * di $hashtag: `#nosito` fa match anche in «Il #nosito.» ma NON in `#nositox`.
 *
 * In entrambi i casi: `#` non è un carattere di parola, quindi un \b subito
 * prima di `#nosito` non scatta nei casi reali (preceduto da spazio o inizio
 * stringa, entrambi non-parola → nessun confine). Serve un lookbehind
 * negativo su "non preceduto da carattere non-spazio" sul lato iniziale.
 * Flag /u: il testo viene da Telegram, può contenere accenti ed emoji.
 *
 * @param string[] $hashtag Es. ['#nosito', '#privato']. Ignorato se $escludi_tutti.
 */
function cfrv_escluso(string $testo, bool $escludi_tutti, array $hashtag): bool
{
    if ($escludi_tutti) {
        // \w in modalità /u copre anche lettere accentate: #perché conta come hashtag.
        return preg_match('/(?<!\S)#\w+/u', $testo) === 1;
    }

    foreach ($hashtag as $tag) {
        $pattern = '/(?<!\S)' . preg_quote($tag, '/') . '\b/iu';
        if (preg_match($pattern, $testo) === 1) {
            return true;
        }
    }

    return false;
}


/**
 * Tabella decisionale — FUNZIONE PURA, nessun effetto collaterale.
 *
 * | tipo update           | P.message_id vs S | hashtag | azione                        |
 * |-----------------------|-------------------|---------|-------------------------------|
 * | channel_post          | >                 | no      | PUBBLICA                      |
 * | channel_post          | >                 | sì      | IGNORA (S sopravvive)         |
 * | channel_post          | <=                | —       | IGNORA (replay / fuori ordine)|
 * | edited_channel_post   | ==                | no      | PUBBLICA (correzione)         |
 * | edited_channel_post   | ==                | sì      | RITIRA                        |
 * | edited_channel_post   | >                 | no      | PUBBLICA (tag tolto dopo)     |
 * | edited_channel_post   | <                 | —       | IGNORA (non far risorgere)    |
 * | nessun text/caption   | —                 | —       | IGNORA (foto non svuota)      |
 *
 * RITIRA scrive un payload con testo vuoto e CONSERVA message_id: serve perché
 * una modifica successiva che toglie l'hashtag possa ripubblicare (== + no tag).
 *
 * @param array  $stato Stato salvato; message_id = 0 se il file manca.
 * @param array  $post  Post in arrivo, grezzo.
 * @param string $tipo  'channel_post' | 'edited_channel_post'.
 * @param array  $cfg   Configurazione (hashtag_esclusi, escludi_tutti_hashtag,
 *                       max_testo_char).
 * @return array{azione:string, payload:?array, motivo:?string}
 *   payload null se IGNORA. motivo valorizzato SOLO se azione === CFRV_IGNORA,
 *   uno tra 'hashtag' | 'vecchio' | 'no-testo' — sono gli unici tre motivi
 *   d'ignora della tabella sopra (replay e fuori-ordine confluiscono in
 *   'vecchio': per chi legge il log sono lo stesso caso, "questo aggiornamento
 *   non fa avanzare lo stato"). Il chiamante lo usa SOLO per comporre
 *   l'etichetta di log — cfrv_decidi() resta pura, non chiama cfrv_log().
 *
 * NOTA — caso non nella tabella del piano: edited_channel_post, id > S,
 * CON hashtag escluso. Può accadere solo se il channel_post originale era
 * già stato ignorato per hashtag (riga 2, "S sopravvive"): non c'è quindi
 * nulla di pubblicato per quell'id da ritirare. Trattato come riga 2:
 * IGNORA, motivo 'hashtag', S non avanza. Non RITIRA, perché RITIRA
 * presuppone che qualcosa sia live sul sito per quell'id — qui non lo è.
 */
function cfrv_decidi(array $stato, array $post, string $tipo, array $cfg): array
{
    $ignora = static fn (string $motivo): array => [
        'azione'  => CFRV_IGNORA,
        'payload' => null,
        'motivo'  => $motivo,
    ];

    // Riga 8: nessun text/caption pubblicabile, a prescindere da tipo/id/hashtag.
    $testo = cfrv_normalizza($post, $cfg['max_testo_char']);
    if ($testo === '') {
        return $ignora('no-testo');
    }

    $s_id = (int) ($stato['message_id'] ?? 0);
    $p_id = (int) ($post['message_id'] ?? 0);

    $escluso = cfrv_escluso(
        $testo,
        (bool) $cfg['escludi_tutti_hashtag'],
        $cfg['hashtag_esclusi']
    );

    if ($tipo === 'channel_post') {
        if ($p_id <= $s_id) {
            return $ignora('vecchio');              // riga 3
        }
        if ($escluso) {
            return $ignora('hashtag');               // riga 2 — S sopravvive
        }
        return [                                     // riga 1
            'azione'  => CFRV_PUBBLICA,
            'payload' => cfrv_payload($post, $testo, false, false),
            'motivo'  => null,
        ];
    }

    // edited_channel_post
    if ($p_id < $s_id) {
        return $ignora('vecchio');                   // riga 7
    }
    if ($escluso) {
        if ($p_id === $s_id) {
            return [                                  // riga 5
                'azione'  => CFRV_RITIRA,
                'payload' => cfrv_payload($post, '', true, true),
                'motivo'  => null,
            ];
        }
        return $ignora('hashtag');                    // vedi NOTA sopra
    }

    return [                                          // righe 4 e 6
        'azione'  => CFRV_PUBBLICA,
        'payload' => cfrv_payload($post, $testo, true, false),
        'motivo'  => null,
    ];
}


/**
 * Costruisce il payload da serializzare. Zero dati personali: nessun autore,
 * nessun titolo canale, nessun update grezzo.
 *
 * { schema, text, message_id, posted_at, updated_at, edited, retracted }
 * posted_at = edit_date ?? date.
 */
function cfrv_payload(array $post, string $testo, bool $edited, bool $retracted): array
{
    return [
        'schema'     => CFRV_SCHEMA,
        'text'       => $testo,
        'message_id' => (int) ($post['message_id'] ?? 0),
        'posted_at'  => (int) ($post['edit_date'] ?? $post['date'] ?? 0),
        // Non è nel piano: orologio del server al momento della scrittura,
        // distinto da posted_at (orologio di Telegram) — utile a stato.php
        // per calcolare l'età del FILE, non del messaggio.
        'updated_at' => time(),
        'edited'     => $edited,
        'retracted'  => $retracted,
    ];
}


/**
 * Legge lo stato corrente da disco.
 *
 * File assente, illeggibile o JSON non valido → stato neutro
 * ['message_id' => 0], così il primo post valido pubblica comunque.
 */
function cfrv_leggi_stato(string $percorso): array
{
    $neutro = ['message_id' => 0];

    if (!is_readable($percorso)) {
        return $neutro;
    }

    $contenuto = file_get_contents($percorso);
    if ($contenuto === false) {
        return $neutro;
    }

    $dati = json_decode($contenuto, true, 16);
    if (!is_array($dati) || !isset($dati['message_id']) || !is_int($dati['message_id'])) {
        return $neutro;
    }

    return ['message_id' => $dati['message_id']];
}


/**
 * Scrittura ATOMICA del JSON pubblico.
 *
 * Temporaneo con nome da random_bytes() nella STESSA cartella, poi rename():
 * atomico su Linux, quindi il browser non legge mai un file a metà.
 * json_encode con JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT e
 * SENZA JSON_UNESCAPED_UNICODE: il file resta ASCII puro e non può contenere
 * '<' o '>', quindi mai un '</script>'.
 *
 * Nessun input utente tocca il nome del file: il percorso è costante.
 *
 * @return bool false su fallimento — il chiamante risponde 200 lo stesso.
 */
function cfrv_scrivi(string $percorso, array $payload): bool
{
    $json = json_encode(
        $payload,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if ($json === false) {
        return false;
    }

    // Temporaneo nella STESSA cartella (rename() atomico solo se sorgente e
    // destinazione sono sullo stesso filesystem) e nome imprevedibile, così
    // due scritture concorrenti non collidono mai sullo stesso temporaneo.
    // Suffisso .tmp: data/.htaccess nega esplicitamente quei file.
    $temp = dirname($percorso) . '/.' . bin2hex(random_bytes(16)) . '.tmp';

    if (file_put_contents($temp, $json) === false) {
        @unlink($temp);
        return false;
    }

    if (!rename($temp, $percorso)) {
        @unlink($temp);
        return false;
    }

    return true;
}


/**
 * Riga di log a lunghezza limitata. Il file viene troncato oltre max_log_byte.
 * Non registrare mai il corpo grezzo dell'update: solo esito e motivo.
 * Etichette previste: OK-PUBBLICA, OK-RITIRA, IGNORA-HASHTAG, IGNORA-VECCHIO,
 * IGNORA-NO-TESTO, CHAT-NON-AUTORIZZATA, SEGRETO-ERRATO, CORPO-NON-VALIDO,
 * SCRITTURA-FALLITA.
 */
function cfrv_log(array $cfg, string $etichetta, string $dettaglio = ''): void
{
    $percorso = $cfg['file_log'];

    // Non nel piano: timestamp fissato a Europe/Rome invece dell'orologio di
    // sistema. Se Aruba gira in UTC, un log in UTC sfaserebbe la lettura di
    // chi confronta gli orari qui con "Aggiornato il…" mostrato sul sito
    // (quello sì già fissato a Europe/Rome in js/orari.js).
    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))
        ->format('Y-m-d H:i:s');

    $riga = $timestamp . "\t" . $etichetta;
    if ($dettaglio !== '') {
        $riga .= "\t" . $dettaglio;
    }
    $riga .= "\n";

    // Il log è best-effort: una scrittura fallita qui (disco pieno, permessi)
    // non deve mai interrompere la richiesta né impedire la risposta 200 —
    // stesso principio già applicato a cfrv_scrivi() dal chiamante.
    $dimensione = @filesize($percorso);
    if ($dimensione !== false && $dimensione + strlen($riga) > $cfg['max_log_byte']) {
        // Cap raggiunto: si riparte da un file vuoto invece di una rotazione vera
        @file_put_contents($percorso, '');
    }

    @file_put_contents($percorso, $riga, FILE_APPEND);
}
