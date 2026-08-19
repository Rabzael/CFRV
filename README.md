# Sito CFRV

Sito internet in produzione per un'associazione reale ([sanremigioverona.org](https://sanremigioverona.org)), sviluppato e mantenuto da me dalla progettazione al rilascio. Lo includo tra i progetti di portfolio perché mostra come lavoro anche fuori dal mio ambito abituale con lo stesso metodo che porterei in un ruolo da architect: partire dai vincoli reali (hosting condiviso, nessun processo persistente), esplicitare i rischi prima di scrivere codice, e documentare le decisioni così che restino leggibili a chi le riprende in mano dopo.

Il documento di progettazione — [`PIANO-orari-telegram.md`](PIANO-orari-telegram.md) — è la parte che meglio rappresenta questo approccio: architettura, fasi di rilascio verificabili singolarmente, matrice dei rischi (R1-R4) con relative mitigazioni, quattro livelli di verifica dal più isolato (logica pura, riga di comando) al più end-to-end (canale Telegram reale).

Gran parte del codice è stata scritta con il supporto di strumenti di IA generativa, sotto la mia direzione: architettura, decisioni di design e revisione di ogni funzione restano mie, verificate a ogni passaggio prima di essere accettate. Lo dichiaro apertamente perché saperlo dirigere e
controllare con disciplina — non delegare a scatola chiusa — è una competenza che considero parte del lavoro da architect, non qualcosa da nascondere.

**Stack**: HTML/CSS/JS scritti a mano, senza framework né build step; PHP puro sul backend.
Nessuna dipendenza da installare, deploy via FTP su hosting condiviso.

**Un paio di scelte tecniche di cui vado più soddisfatto:**

- **Il sito funziona anche senza JavaScript.** La sezione orari mostra una tabella statica per difetto; uno script la sostituisce con l'ultimo messaggio di un canale Telegram solo dopo aver ricevuto ed elaborato dati validi — mai una sezione vuota nel frattempo, mai un `<noscript>` come ripiego.
- **Difesa dall'XSS strutturale, non un filtro.** Il testo del canale Telegram è contenuto non fidato: sul frontend viene inserito nel DOM solo con `textContent`, mai `innerHTML` — per costruzione non può essere interpretato come markup, non serve sanificarlo.
- **La logica "quando pubblicare / ritirare / ignorare" un aggiornamento** è isolata in un'unica funzione PHP pura, testabile da riga di comando senza rete, senza Telegram, senza filesystem.
- **Scrittura su disco atomica** (file temporaneo + `rename()`) e verifica del segreto del webhook a tempo costante (`hash_equals`), per evitare sia corruzione sia timing attack banali.
- Architettura vincolata ai limiti reali dell'hosting (PHP puro, niente Node/Python in produzione, niente cron) invece che a un ambiente ideale immaginato a tavolino.

Il resto di questo file è la documentazione operativa reale del progetto — chi pubblica sul canale Telegram e chi gestisce il sito la usano per lavorarci davvero, non è scritta per questa presentazione.

---

Due parti, due pubblici diversi:

1. **Chi pubblica sul canale Telegram** → regole per gli orari sul sito
2. **Chi gestisce il sito** → come aggiungere pagine e contenuti

Non serve sapere programmare per nessuna delle due cose.

---

## 1. Per chi gestisce il canale Telegram (@Messe_Verona)

### La regola base

**L'ultimo messaggio pubblicato sul canale appare sul sito, esattamente come scritto.** Va nella sezione "Orario delle celebrazioni", al posto della tabella.

Quindi: se l'ultimo messaggio è un avviso, o la didascalia di una foto, è quello che i visitatori vedranno — non necessariamente un orario. Scrivere l'ultimo messaggio pensando che finirà anche sul sito.

Il sito controlla il canale da solo, non serve avvisare nessuno dopo aver postato: l'aggiornamento è nel giro di pochi secondi.

### Come NON far comparire un messaggio sul sito

**Qualsiasi hashtag nel testo nasconde il messaggio dal sito** — non è una parola magica specifica, basta un `#` seguito da una parola, ovunque nel messaggio. `#nosito` è solo la convenzione consigliata perché è facile da ricordare, ma funziona allo stesso modo anche `#privato`, `#interno` o qualsiasi altro.

- Se lo scrivi già nel messaggio originale, non compare da subito.
- Se il messaggio è già pubblicato e comparso sul sito, basta **modificarlo** aggiungendo un hashtag qualsiasi (es. `#nosito`): il sito torna alla tabella normale nel giro di pochi secondi. Si può fare anche dal telefono.

Per far ricomparire un messaggio già nascosto, basta modificarlo togliendo l'hashtag.

**Attenzione**: siccome basta un hashtag qualsiasi, evitare di usarli per altri scopi (categorie, riferimenti...) nell'ultimo messaggio pubblicato — altrimenti il messaggio sparisce dal sito senza che fosse quella l'intenzione.

### Altre cose da sapere

- **Le foto senza didascalia vengono ignorate**: il sito continua a mostrare il messaggio precedente, una foto muta non lo cancella.
- **Niente formattazione**: grassetto, corsivo e link cliccabili del canale non vengono mostrati sul sito, solo il testo semplice. Vanno bene invece accenti ed emoji.
- **Testi molto lunghi** (oltre 4000 caratteri) vengono tagliati.
- **Un messaggio cancellato dal canale NON viene tolto automaticamente dal sito** (Telegram non lo comunica). Se cancelli per errore l'ultimo messaggio, aggiungi `#nosito` a un messaggio successivo, o segui il punto 3 sotto.
- **Un messaggio con più di 30 giorni non viene mai mostrato**: se per qualche motivo il canale smette di essere letto, dopo un mese il sito torna da solo alla tabella normale invece di mostrare un orario vecchio.

### Se qualcosa va storto (dal più semplice al più drastico)

1. **Modifica l'ultimo post aggiungendo `#nosito`.** Dal telefono, senza bisogno del sito o di FTP. Nel giro di pochi secondi il sito torna alla tabella statica.
2. **Pubblica un nuovo messaggio.** Diventa l'ultimo e sostituisce quello precedente.
3. Se i primi due non bastano, contatta chi gestisce il sito: può cancellare il file che il canale scrive (`data/orari.json`) via FTP.
4. In casi estremi, chi gestisce il sito può disattivare del tutto il collegamento col canale: il sito torna a mostrare solo la tabella scritta a mano, come prima di questa funzione.

---

## 2. Per chi gestisce il sito

### Struttura, in breve

```
index.html          la homepage
styles.css           tutto lo stile grafico
img/                  immagini
celebrante/           pagina "Biografia completa"
siti-amici/           pagina "Siti amici"
evento-stub.html      modello da copiare per un nuovo evento/pagina
js/, api/, data/       collegamento col canale Telegram — non toccare (vedi in fondo)
```

Il sito è fatto a mano, senza programmi di generazione: si modifica il file HTML con un editor di testo qualsiasi e si carica via FTP.

### Modificare l'orario scritto a mano (tabella di riserva)

Anche col collegamento a Telegram attivo, `index.html` contiene sempre una tabella con un orario scritto a mano — è quella che compare se il canale non è raggiungibile o non ha ancora pubblicato nulla. Cercare in `index.html` questo blocco (sezione ORARI) e modificare la riga:

```html
<tr><td>Domenica e festivi</td><td style="text-align:right;">Messa ore 9:00</td></tr>
```

Si possono aggiungere altre righe `<tr>...</tr>` allo stesso modo per altri orari.

### Aggiungere una nuova sezione alla homepage

Ogni sezione della homepage segue lo stesso schema. Per aggiungerne una nuova, copiare un blocco esistente (es. "CHI SIAMO") e modificarlo:

```html
<section id="NOME-SEZIONE" class="section">
  <div class="container" style="text-align:center;">
    <div class="eyebrow">TITOLO PICCOLO SOPRA</div>
    <h2 style="font-size:30px;margin-bottom:16px;">Titolo grande</h2>
    <p>Testo della sezione.</p>
  </div>
</section>
```

- `id="NOME-SEZIONE"` è l'ancora usata dai link del menu (es. `#chisiamo`).
- `class="section"` oppure `class="section alt"` alternano lo sfondo chiaro/scuro tra una sezione e l'altra — guardare le sezioni vicine per capire quale usare.

**Dopo aver aggiunto una sezione, aggiungere il link in DUE punti** (c'è già un promemoria nel codice):
1. nel menu in alto, dentro `<div class="nav-links">`
2. nel footer in fondo alla pagina, dentro `<div class="footer-nav">`

```html
<a href="#nome-sezione">Nome nel menu</a>
```

### Aggiungere una pagina a sé stante (come "Biografia completa")

Per un contenuto troppo lungo per stare nella homepage (un evento importante, una biografia):

1. Copiare `evento-stub.html`, rinominarlo (es. `pellegrinaggio-2026.html`).
2. Modificarne il contenuto.
3. Linkarlo da dove serve — un pulsante, una card, o una voce di menu — con `<a href="pellegrinaggio-2026.html">...</a>`.

`celebrante/index.html` e `siti-amici/index.html` sono esempi già fatti di questo stesso schema, dentro una cartella propria.

### Immagini

Vanno caricate nella cartella `img/` e richiamate con `<img src="img/nomefile.jpg">`. Usare nomi file semplici, senza spazi o accenti.

### Cosa NON toccare senza sentire chi ha sviluppato il sito

Le cartelle `js/`, `api/` e `data/`, e il file `config.php` (se presente dentro `api/`) fanno funzionare il collegamento con Telegram descritto nella prima parte di questa guida. Non contengono contenuti da modificare a mano: `config.php` in particolare contiene password e chiavi segrete e non va mai condiviso, copiato altrove o messo online in altri punti del sito.

Tutto il resto (HTML, CSS, immagini, testi) si può modificare liberamente: nel peggiore dei casi, si ripristina la versione precedente via FTP.
