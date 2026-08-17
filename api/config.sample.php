<?php
/**
 * Modello di configurazione — QUESTO file sta in git, `config.php` NO.
 *
 * Uso: copiare in `config.php` (fuori webroot se `open_basedir` lo consente,
 * altrimenti qui in `api/`) e riempire con i valori reali. Il percorso va
 * deciso una volta sola in Fase 0: nessun fallback a runtime.
 */

declare(strict_types=1);

return [
    // Token del bot dedicato (BotFather /newbot).
    'bot_token' => 'INSERIRE-TOKEN-BOT',

    // Segreto del webhook: openssl rand -hex 32.
    // Solo [A-Za-z0-9_-]: base64 è INVALIDO per secret_token.
    'secret_token' => 'INSERIRE-SEGRETO-HEX-32-BYTE',

    // Id numerico del canale, da getChat. Intero negativo -100…
    'chat_id' => 0,

    // Escludi messaggi che contengono un qualsiasi hashtag
    'escludi_tutti_hashtag' => true,
    // Hashtag specifici che escludono il messaggio dal sito (confronto case-insensitive).
    'hashtag_esclusi' => [],

    // Cap difensivi.
    'max_corpo_byte' => 65536,   // 64 KB sul corpo della richiesta
    'max_testo_char' => 4000,    // troncamento del testo pubblicato
    'max_log_byte'   => 524288,  // 512 KB, poi il log viene troncato

    // Soglia oltre la quale api/stato.php segnala guasto (503) invece di
    // fresco (200). Va tarata sulla cadenza reale dei post. 
    // Deve restare MINORE dei 30 giorni del cancello lato browser (js/orari.js): 
    // l'allarme deve scattare in tempo per intervenire prima che un visitatore veda la tabella di fallback.
    'soglia_stato_ore' => 240, // 10 giorni
];
