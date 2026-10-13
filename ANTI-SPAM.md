# Anti-spam: configurazione e uso

Apri **EasyContact → Impostazioni → Anti-spam e telefono internazionale**.
Il livello predefinito del sito è **2 — Equilibrato** e ogni modulo lo eredita.
Puoi scegliere un livello diverso nella sezione Anti-spam del singolo modulo.

| Controllo | Livello 1 | Livello 2 | Livello 3 |
| --- | --- | --- | --- |
| Honeypot variabile, firma dell’invio, campi obbligatori | Attivo | Attivo | Attivo |
| Sintassi email e validità internazionale del telefono | Attivo | Attivo | Attivo |
| Limite invii per IP e modulo, ogni 5 minuti | 10 | 10 | 6 |
| Domini email temporanei, telefoni ripetitivi | — | Attivo | Attivo |
| Link, schemi promozionali, frasi personalizzate | — | Attivo | Più sensibile |
| Recapiti frequenti e messaggi simili da invii diversi | — | Attivo | Più sensibile |
| Verifica DNS del dominio email | Facoltativa | Facoltativa | Facoltativa |

Le regole producono un punteggio spiegabile da 0 a 100. Segnali correlati non
sommano indiscriminatamente i punti. Un invio veloce o un alias insolito, da soli,
non impediscono un contatto. Il livello 3 può aumentare le revisioni necessarie.

## Archivio, quarantena e categorie

L’archivio normale conserva gli invii e consente di gestirli nella schermata
**Invii**. Se è disattivato, puoi attivare **Quarantena con archivio disattivato**
per conservare soltanto gli invii sospetti. Entrambe le preferenze sono
disattivate inizialmente e vanno attivate in modo esplicito.

Ogni invio archiviato ha una sola categoria:

- **Accettato**: ha superato i controlli configurati, senza garanzia sull’identità.
- **Probabile spam**: segnali sospetti da esaminare.
- **Spam**: honeypot o combinazione di segnali più forti.

Puoi filtrare la lista, riclassificare più invii insieme o aprirne il dettaglio
per vedere punteggio, motivi e categoria. La decisione manuale ha precedenza.

Spam e sospetti non attivano email, MailerLite o Slack. **Aggiorna categoria**
modifica soltanto la classificazione. **Approva e invia** libera l’inoltro dopo
la verifica, usando la configurazione attuale del modulo e saltando le
destinazioni già raggiunte. Gli esiti non confermati richiedono la normale
verifica manuale prima di un eventuale reinvio.

Spam e sospetti vengono eliminati dopo **14 giorni** per impostazione
predefinita. Gli accettati seguono la conservazione normale, inizialmente 30
giorni. La quarantena accetta al massimo **500 nuovi invii per finestra di 24
ore** per sito. Questi valori sono modificabili. La pulizia dipende da WP-Cron.

## Telefono internazionale

```text
[tel* telefono "Telefono"]
[tel* cellulare "Cellulare" country:IT mobile]
```

Il selettore parte dal paese della lingua del sito: `it_IT` → Italia, `en_GB` →
Regno Unito, `en_US` → Stati Uniti. È una proposta iniziale che il visitatore
può cambiare. Un override nel campo precede quello del modulo e del sito.

Sono ammessi fissi e cellulari, spazi e separatori comuni, e i prefissi `+` o
`00`. Il server verifica il numero rispetto ai piani di numerazione locali e
conserva la forma internazionale, ad esempio `+390236618300`. Non verifica
che il numero sia attivo o appartenga al visitatore.

Nei livelli 2 e 3 un numero interamente ripetitivo come `3333333333` richiede
correzione. Aggiungi `allow-repeated` al singolo campo se il tuo caso d’uso
deve consentirlo; la validità internazionale viene comunque verificata.

## Email e contenuto

I domini temporanei sono segnalati nei livelli 2 e 3 tramite una lista locale
condivisa da tutti i moduli. Il plugin include 96.316 domini unici provenienti da
[disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains)
e dalla lista ordinaria di
[disposable/disposable](https://github.com/disposable/disposable). Non usa la
lista separata dei servizi con registrazione anonima del secondo progetto.
Sono riconosciuti anche i sottodomini, senza confondere domini con nomi simili.

In **EasyContact → Impostazioni → Lista domini temporanei** sono visibili
le fonti, il numero di domini e la data della copia locale. **Sincronizza lista**
scarica e verifica entrambe le fonti soltanto su richiesta dell’amministratore.
La nuova lista sostituisce la precedente, recependo aggiunte e rimozioni dei
provider. Se una fonte non risponde o restituisce dati vuoti, troppo grandi o
non validi, resta attiva l’ultima copia completa. Non ci sono cron, download
automatici o richieste esterne durante la verifica degli invii.

La verifica DNS, disattivata inizialmente, controlla soltanto il dominio. Un
errore temporaneo del resolver non blocca il contatto. Non identifica la
singola casella. Alias come `Nome+casa@example.org` restano validi e vengono
conservati senza modifiche alla parte prima di `@`.

Un indirizzo come `djaisfauaus@gmail.com` può essere una casella vera: senza una
conferma del destinatario non è possibile distinguerlo con certezza. Il plugin
lo valuta insieme agli altri segnali, senza rifiutarlo per il solo aspetto.
Le frasi personalizzate aiutano a intercettare campagne fuori tema: usa frasi
specifiche, evitando parole comuni o filtri sulla lingua del visitatore.

La **Modalità osservazione** permette di raccogliere punteggi e motivi senza
sospendere gli invii per le euristiche. Attiva l’archivio per consultarli.
Honeypot e validazione strutturale rimangono attivi.

Non sono presenti AI, servizi di reputazione o analisi semantica esterna.
Messaggi irrilevanti scritti in modo naturale possono superare queste regole.
Tutti i controlli di telefono e contenuto sono locali; il DNS opzionale usa
il resolver del server. I dati locali sono gestibili anche con gli strumenti
privacy di WordPress.

## Verifiche di sviluppo

I comportamenti descritti qui sono coperti dai test PHPUnit in `tests/` (in particolare `SubmissionTest.php` e
`DisposableDomainsTest.php`), che girano sulla suite di test di WordPress con email e richieste esterne
intercettate:

```sh
composer test
```

Preparazione dell'ambiente: `README.md`. Le fonti e la ricostruzione delle dipendenze incluse sono documentate in
`THIRD-PARTY.md`.
