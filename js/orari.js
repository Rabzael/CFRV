/**
 * Orari da Telegram — progressive enhancement della sezione ORARI.
 *
 * La <table class="orari"> resta nell'HTML ed è lo stato corretto senza JS:
 * viene sostituita solo quando arrivano dati freschi e validi. Nessun
 * <noscript>, nessuno spinner.
 *
 * ┌─ REGOLA NON NEGOZIABILE ─────────────────────────────────────────────┐
 * │ Il testo viene da una chat: NON è fidato. Solo textContent.          │
 * │ Vietati in questo file, su valori non fidati: innerHTML, outerHTML,  │
 * │ insertAdjacentHTML, document.write, eval, new Function, qualunque    │
 * │ linkificatore a regex su stringhe HTML.                              │
 * │ textContent non ha parser, quindi non ha bypass: la mitigazione è    │
 * │ strutturale, non un filtro.                                          │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 */
(function () {
  'use strict';

  var SORGENTE = 'data/orari.json';

  /* Cancello dei 30 giorni: se il webhook muore, il sito torna da solo alla
     tabella statica invece di mostrare avvisi vecchi per sempre. È l'unica
     mitigazione che protegge il visitatore invece dell'amministratore. */
  var MAX_ETA_MS = 30 * 24 * 60 * 60 * 1000;

  /* Orologio del server sballato o data futura: non ci fidiamo. */
  var MAX_FUTURO_MS = 24 * 60 * 60 * 1000;

  /**
   * Vero solo se i dati sono pubblicabili.
   * Scarta (→ resta la tabella) se: JSON non è un oggetto, `text` assente,
   * non stringa o vuoto (è il caso RITIRA), `posted_at` non numerico, più
   * vecchio di MAX_ETA_MS, o datato oltre MAX_FUTURO_MS nel futuro.
   *
   * Nessun controllo su `retracted`: un ritiro scrive text vuoto, quindi il
   * controllo sul testo lo copre già. Meglio fidarsi del contenuto che di un
   * flag, così un JSON scritto a mano (Fase 1, via FTP) si comporta uguale.
   */
  function valido(dati) {
    if (!dati || typeof dati !== 'object') {
      return false;
    }
    if (typeof dati.text !== 'string' || dati.text.trim() === '') {
      return false;
    }
    if (typeof dati.posted_at !== 'number' || !isFinite(dati.posted_at)) {
      return false;
    }
    /* eta > 0 = passato. Negativa = il messaggio è datato nel futuro:
       tollerata entro MAX_FUTURO_MS per lo sfasamento tra l'orologio del
       server e quello del visitatore, che è quello a cui Date.now() obbedisce. */
    const eta = Date.now() - dati.posted_at * 1000;
    if (eta > MAX_ETA_MS || eta < -MAX_FUTURO_MS) {
      return false;
    }
    return true;
  }

  /**
   * Costruisce il blocco dinamico con createElement + textContent.
   *   <div class="orari-dinamici">
   *     <p>…testo del canale, a capo resi da white-space:pre-wrap…</p>
   *     <div class="eyebrow orari-aggiornato">Aggiornato il …</div>
   *   </div>
   */
  function costruisci(dati) {
    const orari_dinamici = document.createElement('div');
    orari_dinamici.className = 'orari-dinamici';
    
    const p = document.createElement('p');
    p.textContent = dati['text'];
    orari_dinamici.appendChild(p);
    
    const ndata = formattaData(dati['posted_at']);
    if (ndata !== '') {
      const bdata = document.createElement('div');
      bdata.className = 'eyebrow orari-aggiornato';
      bdata.textContent = `Aggiornato il ${ndata}`;
      orari_dinamici.appendChild(bdata);
    }
    
    return orari_dinamici;
  }

  /**
   * posted_at (secondi epoch, UTC) → "16 agosto 2026". Solo il giorno:
   * l'ora dell'aggiornamento non aggiunge nulla per il visitatore.
   * Il prefisso "Aggiornato il " lo mette costruisci(), non questa funzione.
   *
   * timeZone fissato a 'Europe/Rome' anche senza ora, perché decide su quale
   * GIORNO cade il messaggio: un post delle 00:30 di Verona è delle 22:30 del
   * giorno prima in UTC, e senza fuso fissato mostrerebbe la data sbagliata a
   * chi apre il sito da Londra o da un browser regolato su UTC.
   *
   * Ritorna '' su data non valida invece di lanciare: Intl.format() su un
   * Invalid Date solleva RangeError, e un'eccezione qui interromperebbe la
   * sostituzione del DOM a metà. valido() dovrebbe già aver escluso il caso,
   * questa è la seconda rete.
   */
  function formattaData(posted_at) {
    const d = new Date(posted_at * 1000);
    if (isNaN(d.getTime())) {
      return '';
    }
    return new Intl.DateTimeFormat('it-IT', {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
      timeZone: 'Europe/Rome'
    }).format(d);
  }

  /**
   * fetch(SORGENTE) → valido() → costruisci() → sostituzione di #orari-blocco.
   *
   * R4, deciso: il blocco dinamico SOSTITUISCE la tabella, che resta visibile
   * solo come fallback. Quindi, in quest'ordine e non altro:
   *   1. costruisci() completo e senza eccezioni;
   *   2. solo allora blocco.replaceChildren(nuovo) — un colpo solo, niente
   *      stato intermedio in cui la sezione è vuota.
   * La tabella non va MAI nascosta prima (né in CSS, né a inizio script):
   * senza JS, o con fetch fallito, resterebbe una sezione vuota. Il breve
   * lampo della tabella prima della sostituzione è il prezzo accettato.
   *
   * Qualunque errore (rete, 404, JSON malformato, eccezione) non fa nulla:
   * il fallback è non toccare il DOM.
   */
  function avvia() {
    window
      .fetch(SORGENTE)
      .then((response) => {
        if (!response.ok) {
          throw new Error(`Errore recupero orari: ${response.status}`);
        }
        return response.json();
      })
      .then((jdata) => {
        if (!valido(jdata)) {
          throw new Error('Orari non validi')
        }
        let nuovo_testo = costruisci(jdata);
        document.getElementById('orari-blocco').replaceChildren(nuovo_testo);
      })
      .catch((error) => console.debug(error));
  }

  avvia();
})();
