<?php
/**
 * Heartbeat di staleness — mitigazione di R1 (obsolescenza silenziosa) e R3
 * (il bot perde i diritti di admin / il canale cambia id).
 *
 * Risponde con lo stato di freschezza di data/orari.json in forma leggibile
 * sia da un umano sia da un monitor esterno (UptimeRobot o simili), così il
 * guasto si vede da fuori invece di restare invisibile.
 *
 * Pubblico e senza segreto: non c'è nulla da proteggere qui (nessun dato
 * personale, nessun token — vedi cfrv_stato()). Per lo stesso motivo non
 * richiede lib.php: nessuna delle funzioni pure serve a questo endpoint.
 *
 * Vedi PIANO-orari-telegram.md § Fasi (Fase 4), § Rischi.
 */

declare(strict_types=1);

// UAT: config.php dentro api/. In produzione si sposterà sopra la webroot —
// quando succede, questa è l'UNICA riga da cambiare in questo file:
//   '/config.php'        -> se config.php resta in questa stessa cartella
//   '/../../config.php'  -> se config.php va due cartelle sopra questa
// (il numero di /../ dipende da quanti livelli lo si sposta in su: contare
// le cartelle da qui fino alla nuova posizione di config.php).
$cfg = require __DIR__ . '/config.php';

// Calcolato qui, non dentro config.php: __DIR__ = api/, che non si sposta
// mai (a differenza di config.php). Vedi commento in config.sample.php.
$cfg['file_json'] = __DIR__ . '/../data/orari.json';

cfrv_stato($cfg);


/**
 * Legge data/orari.json e ne calcola l'età.
 *
 * Esito (JSON, nessun dato personale, nessun segreto):
 *   { "ok": bool, "eta_ore": ?int, "message_id": ?int, "retracted": ?bool }
 * I tre campi sono null solo se il file manca o è illeggibile/malformato.
 *
 * Status HTTP: 200 se fresco, 503 se il file manca o supera la soglia — così
 * un monitor esterno può allarmare guardando solo lo status, senza dover
 * interpretare il corpo.
 *
 * "Fresco" significa che il file è stato TOCCATO di recente (updated_at),
 * non che il contenuto sia "giusto": subito dopo un RITIRA legittimo il
 * sistema funziona benissimo, quindi ok resta true anche con retracted=true.
 *
 * NOTA: soglia_stato_ore è per l'AMMINISTRATORE (dell'ordine di giorni) ed è
 * cosa diversa dal cancello dei 30 giorni in js/orari.js, che protegge il
 * VISITATORE. Le due soglie sono diverse di proposito: questa deve scattare
 * PRIMA di quella, per lasciare tempo di reagire prima che un visitatore
 * veda la tabella di fallback.
 */
function cfrv_stato(array $cfg): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $dati = cfrv_leggi_orari($cfg['file_json']);

    if ($dati === null) {
        http_response_code(503);
        echo json_encode([
            'ok'         => false,
            'eta_ore'    => null,
            'message_id' => null,
            'retracted'  => null,
        ]);
        return;
    }

    $updated_at  = (int) ($dati['updated_at'] ?? 0);
    // max(0, ...): un updated_at nel futuro (piccolo sfasamento d'orologio)
    // non deve produrre un'età negativa — viene trattato come "appena scritto".
    $eta_secondi = max(0, time() - $updated_at);
    $eta_ore     = intdiv($eta_secondi, 3600);

    $fresco = $updated_at > 0 && $eta_ore <= $cfg['soglia_stato_ore'];

    http_response_code($fresco ? 200 : 503);
    echo json_encode([
        'ok'         => $fresco,
        'eta_ore'    => $eta_ore,
        'message_id' => (int) ($dati['message_id'] ?? 0),
        'retracted'  => (bool) ($dati['retracted'] ?? false),
    ]);
}


/**
 * Lettura difensiva di data/orari.json: null su qualunque anomalia (file
 * assente, illeggibile, JSON non valido) — mai un errore fatale su un
 * endpoint pensato per essere interrogato ogni pochi minuti da un monitor
 * esterno.
 */
function cfrv_leggi_orari(string $percorso): ?array
{
    if (!is_readable($percorso)) {
        return null;
    }

    $contenuto = file_get_contents($percorso);
    if ($contenuto === false) {
        return null;
    }

    $dati = json_decode($contenuto, true, 16);
    return is_array($dati) ? $dati : null;
}
