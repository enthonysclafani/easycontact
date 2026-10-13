# Piano: gli standard di EasyRankly in EasyContact

Obiettivo: portare in EasyContact gli standard di qualità di EasyRankly (branch `Refactory` di
`enthonysclafani/easyrankly`): stessi controlli automatici, stessa struttura, stessa disciplina sui dati,
**zero tabelle custom e zero accessi diretti al database non motivati**.

Questo documento è il punto di partenza delle sessioni di lavoro (anche nel cloud). Va letto per intero prima di
toccare il codice. Le decisioni aperte (sezione 3) si chiedono a Enthony: non si scelgono da soli.

Riferimento per ogni dubbio su "come lo fa EasyRankly":

```bash
git clone -b Refactory https://github.com/enthonysclafani/easyrankly.git /tmp/easyrankly
# CLAUDE.md, docs/, tools/check-architecture.php, tools/cloud-setup.sh, .github/workflows/ci.yml
```

Si copia il **metodo** e gli **strumenti**, non il codice delle funzioni SEO.

## 1. Stato di partenza (EasyContact 1.0.0, branch `main`)

- ~4.600 righe PHP in `includes/class-*.php` (namespace `EasyContact`, `require_once` a mano), più
  `includes/vendor/` (libphonenumber e polyfill mbstring) e `includes/data/` (paesi, domini usa e getta).
- Dati: post type `easycontact_form` ed `easycontact_entry`, tassonomia `easycontact_safety`, option
  `easycontact_settings`, `easycontact_version`, `easycontact_disposable_domains`,
  `easycontact_review_terms_version`, option dinamiche `easycontact_lock_*` ed `easycontact_state_*`, transient
  `easycontact_*`, meta `_easycontact_*`. **Nessuna tabella custom.**
- Accessi diretti a `$wpdb`:
  - `includes/class-state.php`: `INSERT IGNORE` in `wp_options` per lock atomici e contatori di rate limit;
  - `includes/class-submission.php:309`: query diretta su `postmeta` per gli invii da ritentare;
  - `uninstall.php`: `LIKE` su `wp_options` per trovare lock, stati e transient.
- Cron: `easycontact_daily_cleanup` (giornaliero), `easycontact_retention_cleanup`, `easycontact_state_cleanup`,
  `easycontact_retry_delivery`, `easycontact_resume_retries` (singoli).
- Frontend: `assets/frontend.css`, `assets/frontend.js`, script di Turnstile; invio via REST
  (`easycontact/v1/forms/{id}/submit`, `.../token`) e senza JS via `admin-post.php`.
- Chiamate remote all'invio: Turnstile, MailerLite, Slack, `wp_mail()`, `dns_get_record()` (controlli DNS).
- Admin: meta box classiche sul post type dei moduli, Settings API per le impostazioni, `assets/admin.css` e
  `assets/admin.js`, azioni via `admin_post_*`.
- Disinstallazione: rimuove i dati solo con l'opzione `delete_on_uninstall` attiva (default: conserva).
- Qualità: nessun `composer.json`, nessuna CI, nessun PHPCS/PHPStan; test in due script da lanciare con
  `studio wp eval-file`.

## 2. Standard di arrivo

Ripresi da `CLAUDE.md` di EasyRankly; quelli segnati con (D) dipendono da una decisione della sezione 3.

1. **Zero tabelle custom**: mai `CREATE TABLE`, `dbDelta()`, `ALTER TABLE`. Dati solo in Options API, meta,
   post type non pubblici, tassonomie nascoste.
2. **`$wpdb` solo in allowlist**: ogni file ammesso è elencato in `tools/check-architecture.php` con un commento
   che spiega perché le API non bastano; sempre `$wpdb->prepare()` con `%i` per gli identificatori.
3. **Cron** (D1).
4. **Asset nel frontend** (D2).
5. **Chiamate HTTP** (D3).
6. **Dipendenze PHP a runtime** (D4).
7. **Nessun sistema a moduli**: un plugin unico; le impostazioni spengono comportamenti, non caricano "addon".
8. **Struttura**: `src/` con autoload PSR-4 senza Composer, una cartella per funzionalità con una classe che
   espone `register(): void`, `Plugin::register()` con una riga per funzionalità, hook agganciati solo dentro
   `register()` (mai a livello di file né nel costruttore), codice admin in sottocartelle `Admin/`.
9. **API native**: `register_setting()` con schema, `register_post_meta()` con schema e `auth_callback`,
   `register_rest_route()` con `permission_callback` e `args` tipizzati, `WP_Query` invece di SQL,
   `wp_cache_*()` con invalidazione esplicita.
10. **Stile admin**: un solo foglio di stile, piccolo, con selettori sotto il wrapper del plugin; markup e classi
    del core; niente CSS inline.
11. **Sicurezza**: guardia `ABSPATH` su ogni file, capability sull'oggetto specifico, nonce nei form classici,
    sanitizzazione all'ingresso ed escape all'uscita, segreti mai in JS/REST/log, `phpcs:ignore` solo con
    motivazione nella stessa riga.
12. **Dati e ciclo di vita**: ogni dato registrato in un punto e documentato in `docs/data-model.md`;
    `uninstall.php` coperto da test, anche in multisite (D5).
13. **Prestazioni**: nessuna query in più nelle pagine senza modulo; autoload solo per dati piccoli e letti a ogni
    richiesta; niente lavoro su `init` oltre le registrazioni.
14. **Controlli**: `composer check` = architettura + PHPCS (WPCS `WordPress-Extra` e `WordPress-Docs`,
    PHPCompatibilityWP) + PHPStan livello 8 con `szepeviktor/phpstan-wordpress` + PHPUnit sulla suite di
    WordPress (singolo sito e multisite) + Plugin Check in CI.
15. **Definizione di "fatto"** come in EasyRankly: `composer check` verde, invarianti rispettate, comportamento
    visibile coperto da test di integrazione, `docs/data-model.md` e `uninstall.php` aggiornati, casella spuntata
    in questo piano, resoconto di cosa è stato verificato e cosa no.

## 3. Decisioni aperte (da chiedere prima della Fase 2)

Per ognuna c'è una proposta; la decisione presa va scritta qui con data e motivo.

**D1 — Cron.** EasyRankly vieta ogni cron. EasyContact lo usa per pulizie e per ritentare le consegne fallite.
- Proposta: le pulizie (retention, stati scaduti) diventano pigre e a lotti su `admin_init` e dopo un invio, con
  il cursore in un'option. Per i nuovi tentativi verso MailerLite/Slack/email: tenere **un solo** evento singolo
  `easycontact_retry_delivery` come eccezione dichiarata, oppure toglierlo e lasciare solo il pulsante "Riprova"
  in admin (più semplice, ma un invio fallito resta fermo finché qualcuno non entra).
- Decisione: _aperta_

**D2 — Asset nel frontend.** Un plugin di moduli non può avere zero CSS/JS.
- Proposta: regola riformulata come "asset solo sulle pagine che stampano un modulo (enqueue al rendering),
  nessun JS inline, il modulo funziona anche senza JS (invio via `admin-post.php`)". Turnstile solo sui moduli
  che lo attivano. `composer architecture` verifica che l'enqueue stia solo nel file di rendering del modulo.
- Decisione: _aperta_

**D3 — Chiamate HTTP.** EasyRankly: mai nel frontend, solo admin/REST/WP-CLI.
- Proposta: "mai durante il rendering di una pagina; solo nella richiesta di invio (REST o `admin-post.php`) e in
  admin", con timeout espliciti (≤ 15 s), gestione di `WP_Error` e dei codici HTTP, servizi elencati in
  "External services" di `readme.txt`. I controlli DNS restano opzionali e con cache in transient.
- Decisione: _aperta_

**D4 — Dipendenze PHP a runtime.** EasyContact distribuisce libphonenumber (centinaia di file di metadati) e il
polyfill di mbstring.
- Proposta: polyfill mbstring rimosso se i requisiti lo permettono (verificare l'uso effettivo); libphonenumber
  tenuto come **unica eccezione dichiarata**, isolato in `includes/vendor/phone/` (o `lib/`), escluso da PHPCS e
  PHPStan, aggiornato solo con `tools/bundle-phone.php`, licenza in `THIRD-PARTY.md`. Alternativa: validazione
  telefonica semplificata senza libreria (meno precisa).
- Decisione: _aperta_

**D5 — Disinstallazione.** EasyRankly rimuove sempre tutto; EasyContact conserva moduli e invii salvo opt-in.
- Proposta: tenere l'opt-in per moduli e invii (sono dati dell'utente, perderli per errore è grave), ma rimuovere
  **sempre** dati tecnici (lock, stati, transient, versione, cache dei domini usa e getta, cron). Il test di
  disinstallazione copre entrambi i casi, anche in multisite.
- Decisione: _aperta_

**D6 — Lock atomici in `wp_options`.** `State::claim()` usa `INSERT IGNORE` per evitare consegne doppie in
richieste concorrenti.
- Proposta: tenerlo come unico file in allowlist `$wpdb` (con il commento già presente), ma solo per i lock; i
  contatori di rate limit passano a transient/`wp_cache_add()` dove l'atomicità non è critica. Valutare se
  `wp_cache_add()` basta quando c'è una cache persistente.
- Decisione: _aperta_

**D7 — Requisiti minimi.** Oggi PHP 8.1 / WordPress 6.4; EasyRankly PHP 8.0 / WordPress 7.0.
- Proposta: tenere PHP 8.1, allineare WordPress al minimo realmente testato in CI.
- Decisione: _aperta_

**D8 — Nomi dei dati.** Post type, option e meta sono già prefissati `easycontact_`. Rinominarli richiede una
migrazione.
- Proposta: **nessuna rinomina**; si documentano così come sono.
- Decisione: _aperta_

## 4. Fasi

Ogni punto = una pull request verso `Refactory`, con CI verde. Nessun cambio di comportamento visibile nelle
Fasi 0 e 1.

### Fase 0 — Strumenti (nessun cambio al codice del plugin)

- [ ] 0.1 `composer.json` con soli strumenti di sviluppo (`require-dev`), `composer.lock` committato,
      script `architecture`, `lint`, `lint:fix`, `analyse`, `test`, `check`
- [ ] 0.2 `phpcs.xml.dist` (WPCS Extra + Docs, PHPCompatibilityWP, text domain `easycontact`, esclusi
      `includes/vendor/`, `includes/data/`, `tools/phone-build/`)
- [ ] 0.3 PHPStan livello 8 in `tools/phpstan/` (dipendenze separate, come in EasyRankly), `tools/analyse.sh`
- [ ] 0.4 PHPUnit sulla suite di WordPress: `phpunit.xml.dist`, `tests/bootstrap.php`,
      `tools/install-wp-tests.sh`; i casi di `tests/integration.php` e `tests/disposable-domains.php`
      convertiti in test PHPUnit
- [ ] 0.5 `tools/check-architecture.php` adattato (con test sui casi vietati e consentiti in
      `tests/fixtures/architecture/`), inizialmente con l'allowlist dello stato attuale e un `TODO` per ogni
      eccezione da eliminare nelle fasi successive
- [ ] 0.6 CI GitHub (`.github/workflows/ci.yml`): statici su PHP minimo e 8.4, PHPUnit su WordPress minimo e
      ultimo, anche multisite, Plugin Check; azioni fissate per SHA; `.github/dependabot.yml`
- [ ] 0.7 Sessioni cloud: `tools/cloud-setup.sh` e hook `SessionStart` in `.claude/settings.json`
      (Composer, MariaDB, suite di test di WordPress, sorgenti del core in `/tmp/wordpress-develop`)
- [ ] 0.8 `.editorconfig`, `.gitattributes` (strumenti di sviluppo esclusi dal pacchetto), `tools/build-dist.sh`
- [ ] 0.9 Primo run della CI: si registra quanti errori PHPCS/PHPStan ci sono (baseline), senza correggerli qui

### Fase 1 — Regole e documentazione

- [ ] 1.1 `CLAUDE.md` (e `AGENTS.md` che lo richiama) sul modello di EasyRankly, con le invarianti decise in D1–D8
- [ ] 1.2 `docs/data-model.md`: ogni option, meta, post type, tassonomia, transient, cron e chiave dinamica,
      con chi li scrive, autoload e rimozione
- [ ] 1.3 `docs/decisioni.md`: decisioni D1–D8 con data e motivo (spostate da questo documento)
- [ ] 1.4 Subagenti in `.claude/agents/` (ricercatore, esecutore, verificatore) adattati

### Fase 2 — Database: zero SQL non motivato

- [ ] 2.1 `class-submission.php:309`: query su `postmeta` sostituita da `WP_Query` con `meta_query`
      (`fields => ids`, `no_found_rows`), test di regressione sul riavvio dei nuovi tentativi
- [ ] 2.2 Lock e rate limit secondo D6; allowlist `$wpdb` ridotta al minimo deciso
- [ ] 2.3 `uninstall.php` secondo D5; ricerca delle chiavi dinamiche ridotta (se possibile, chiavi tracciate in
      un'option indice invece del `LIKE`); `tests/UninstallTest.php` singolo sito e multisite
- [ ] 2.4 `composer architecture` blocca `CREATE TABLE`, `dbDelta`, `ALTER TABLE` e `$wpdb` fuori allowlist

### Fase 3 — Struttura

- [ ] 3.1 `src/autoload.php` PSR-4, `src/Plugin.php` con `register()`; bootstrap in `easycontact.php` ridotto
- [ ] 3.2 Una cartella per funzionalità (ipotesi: `Forms/`, `Submissions/`, `Delivery/`, `AntiSpam/`, `Phone/`,
      `Review/`, `Privacy/`, `Settings/`), classi admin in `*/Admin/`; un PR per funzionalità, comportamento
      invariato e coperto dai test della Fase 0
- [ ] 3.3 Hook solo dentro `register()`; niente metodi statici usati come registro globale dove non serve
- [ ] 3.4 `register_setting()` con schema completo per `easycontact_settings`; `register_post_meta()` con schema
      e `auth_callback` per i meta dei moduli
- [ ] 3.5 REST: `args` con `type`, `sanitize_callback`, `validate_callback` per le route pubbliche; motivazione
      scritta del `__return_true` (moduli pubblici) e test con utente anonimo

### Fase 4 — Cron, asset, HTTP (secondo D1–D3)

- [ ] 4.1 Pulizie senza cron (o con l'eccezione decisa), idempotenti e a lotti; test
- [ ] 4.2 Asset del frontend caricati solo con un modulo nella pagina; test che una pagina senza modulo non
      carichi né CSS né JS del plugin e non faccia query in più (`tests/QueriesTest.php`)
- [ ] 4.3 Chiamate remote solo nella richiesta di invio e in admin, timeout ≤ 15 s, "External services" in
      `readme.txt`; test con risposte HTTP simulate (`pre_http_request`)

### Fase 5 — Admin

- [ ] 5.1 Un solo foglio di stile admin (regola `one-stylesheet` come in EasyRankly), niente CSS inline
- [ ] 5.2 Azioni `admin_post_*` con nonce e capability verificate da test; valutare il passaggio a REST dove
      serve interazione
- [ ] 5.3 Testi delle schermate brevi e coerenti

### Fase 6 — Controllo finale

- [ ] 6.1 `composer check` verde senza baseline né `phpcs:ignore` non motivati
- [ ] 6.2 Controllo di sicurezza (lista sul modello di `docs/sicurezza.md` di EasyRankly): input dell'invio,
      upload (se presenti), segreti delle integrazioni, SSRF sui webhook, rate limit
- [ ] 6.3 Verifica manuale sul sito Studio locale (`~/Studio/form-a-caso`) con Query Monitor
- [ ] 6.4 Merge di `Refactory` in `main` e nuova versione

## 5. Regole di lavoro

- Branch base: `Refactory`. Ogni sessione lavora su un branch proprio e apre una pull request verso `Refactory`,
  mai verso `main`.
- Cambia il minimo: niente refactor non richiesti dal punto in corso.
- Prima di aggiungere una dipendenza, un hook pubblico, un'option o un meta: chiedi.
- Se un download o un comando viene bloccato dal proxy o dai permessi, non aggirarlo: riferisci e proponi opzioni.
- I problemi di sicurezza non corretti non vanno in issue, PR o commit (il repository è pubblico).
- Messaggi di commit in italiano, all'indicativo presente, che spiegano il perché.
