# Modello dati

Ogni dato che il plugin salva è elencato qui. Se non è in questa pagina, il plugin non deve crearlo.
`uninstall.php` rimuove tutto ciò che è elencato, in multisite su ogni sito della rete, e `tests/UninstallTest.php` lo
verifica. Cosa si rimuove sempre e cosa solo con `delete_on_uninstall` dipende dalla decisione D5
(`docs/decisioni.md`); la colonna "Disinstallazione" descrive il comportamento attuale.

Regola: zero tabelle custom. Solo Options API, post meta, post type non pubblici, tassonomie nascoste e transient.
I nomi sono quelli della 1.0.0 e non si rinominano (D8).

## Post type

| Nome | Contenuto | Visibilità | Disinstallazione |
|---|---|---|---|
| `easycontact_form` | Un modulo. La definizione sta nel meta `_easycontact_definition`. | Non pubblico, `show_ui` per `manage_options`, nessun REST del core. | Solo con opt-in |
| `easycontact_entry` | Un invio archiviato, stato `private`. | Non pubblico, sottomenu dei moduli, nessun REST del core. | Solo con opt-in |

## Tassonomia

| Nome | Oggetto | Contenuto | Disinstallazione |
|---|---|---|---|
| `easycontact_safety` | `easycontact_entry` | Categoria anti-spam, una per invio: termini `accepted`, `suspect`, `spam`. Nascosta (niente UI, REST, query var). Creati da `Review::create_terms()` al setup; `Review::write()` li ricrea se spariscono. | Solo con opt-in |

## Option

| Nome | Autoload | Contenuto | Disinstallazione |
|---|---|---|---|
| `easycontact_settings` | no | Impostazioni del sito, creata con i default all'attivazione e al setup (`Settings::defaults()`): `store_entries`, `retention_days` (30), `spam_retention_days` (14), `antispam_level` (2), `antispam_observe`, `quarantine_entries`, `quarantine_limit` (500), `email_dns_check`, `phone_country`, `spam_phrases`, `allow_erasure`, `delete_on_uninstall`, `mailerlite_token`, `turnstile_site_key`, `turnstile_secret`. | Solo con opt-in |
| `easycontact_version` | sì | Versione per cui è girato il setup. Se diversa da `EASYCONTACT_VERSION`, `Plugin::init()` rifà il setup (impostazioni mancanti, categorie, ripresa dei tentativi). La disattivazione la cancella. Sui siti aggiornati dalla 1.0.0 resta non autoload fino al primo cambio di versione. | Sempre |
| `easycontact_review_terms_version` | no | `1` quando le tre categorie esistono. Letta solo durante il setup. | Sempre |
| `easycontact_disposable_domains` | no | Ultima sincronizzazione completa della lista domini usa e getta: `version`, `domains` (testo, un dominio per riga, < 8 MB), `count` (≤ 250.000), `sources`, `updated_at`. Assente = lista inclusa nel plugin (`includes/data/`). Scritta solo dalla sincronizzazione manuale in admin. | Sempre |
| `easycontact_lock_{chiave}` | no | Lease atomico (`State::claim()`, `INSERT IGNORE`): `scadenza.uuid`. Chiavi: `submit_{ticket}`, `retry_{entry}` e `rate_{hash}` (lease di 5 secondi attorno a un contatore). Si libera alla fine dell'operazione; quelli scaduti li toglie `State::cleanup()`. | Sempre |
| `easycontact_state_{ticket}` | no | Stato di un invio per renderlo idempotente (JSON): `fingerprint`, `input_fingerprint`, `hash` dei dati, `entry`, `attempts` (max 3), `outcomes`, `assessment`. Chiave = primi 40 caratteri dello SHA-256 del ticket. Scade e lo toglie `State::cleanup()`. | Sempre |

## Post meta

Sui moduli (`easycontact_form`):

| Chiave | Contenuto |
|---|---|
| `_easycontact_definition` | `template` (testo con i tag), `schema` (campi analizzati da `Schema::parse()`), `conditions` (regole dei campi condizionali), `config` (impostazioni del modulo, `Forms::config_defaults()`: destinatari, archivio, anti-spam, Turnstile, MailerLite, Slack con `slack_webhook`, messaggio di conferma). |
| `_easycontact_last_delivery` | Esito dell'ultima consegna, mostrato nell'elenco dei moduli. |

Sugli invii (`easycontact_entry`):

| Chiave | Contenuto |
|---|---|
| `_easycontact_form_id` | ID del modulo. |
| `_easycontact_values` | Valori dei campi visibili, già validati. |
| `_easycontact_labels` | Etichette dei campi al momento dell'invio. |
| `_easycontact_email` | Indirizzi email dell'invio (uno per meta), per esportazione e cancellazione dei dati personali. |
| `_easycontact_initializing` | Presente mentre l'invio si sta archiviando: blocca consegne e revisione. |
| `_easycontact_antispam` | Valutazione automatica (`AntiSpam::assess()`): `status`, `detected`, `score`, `level`, `observe`, `reasons`. Scritta una volta. |
| `_easycontact_review` | Ultima classificazione: `status`, `source` (`automatic` o `manual`), `time`, `reviewer`. |
| `_easycontact_review_history` | Ultime 10 classificazioni manuali. |
| `_easycontact_held` | `1` se le consegne sono sospese (sospetto, spam o revisione a metà), `0` altrimenti. |
| `_easycontact_delivery` | Esito per destinazione (`outcomes`: email, MailerLite, Slack). |
| `_easycontact_retry` | Nuovo tentativo in corso: `hash`, `attempts` (max 3), `expires`. |

Tutti i meta si cancellano con il loro post (quindi solo con opt-in). Nessuno è registrato con
`register_post_meta()` (Fase 3.4 del piano).

## Transient

| Nome | Durata | Contenuto |
|---|---|---|
| `easycontact_rate_{hash}` | finestra del limite (5 minuti di default) | Contatore di invii, token e tentativi per indirizzo e modulo. L'indirizzo non si salva: la chiave è un HMAC. Con una cache a oggetti persistente il contatore sta invece nel gruppo di cache `easycontact_rate` (chiave `rate_{hash}`, stessa scadenza) e non scrive nel database. |
| `easycontact_dns_{hash}` | 15 minuti (1 se l'esito è incerto) | Esito del controllo DNS sul dominio di un'email, se `email_dns_check` è attivo. |
| `easycontact_review_notice_{utente}` | 90 secondi | Messaggio da mostrare dopo una revisione. |
| `easycontact_disposable_notice_{utente}` | 1 minuto | Esito della sincronizzazione dei domini. |
| `easycontact_admin_error_{utente}_{modulo}` | 1 minuto | Errore di salvataggio di un modulo. |

Tutti rimossi alla disinstallazione (ricerca per prefisso in `uninstall.php`, poi `delete_transient()`).

## Eventi cron

In attesa della decisione D1.

| Hook | Tipo | Cosa fa |
|---|---|---|
| `easycontact_daily_cleanup` | giornaliero | Retention degli invii (`Plugin::cleanup()`) e pulizia di lock e stati scaduti (`State::cleanup()`). |
| `easycontact_retention_cleanup` | singolo | Lotto successivo della retention, se ne restano. |
| `easycontact_state_cleanup` | singolo | Lotto successivo della pulizia di lock e stati, con il cursore come argomento. |
| `easycontact_retry_delivery` | singolo | Nuovo tentativo di consegna di un invio (argomento: ID). |
| `easycontact_resume_retries` | singolo | Dopo un aggiornamento, ripianifica i tentativi rimasti a metà, a lotti. |

La disattivazione e la disinstallazione tolgono tutti e cinque gli hook (`wp_unschedule_hook()`).

## Nonce, azioni e chiavi che non sono dati

Non salvano niente e non vanno rimossi: azioni dei nonce (`easycontact_editor_{id}`, `easycontact_preview_{id}`,
`easycontact_retry_{id}`, `easycontact_review_{id}`, `easycontact_submit_{sito}_{id}`,
`easycontact_sync_disposable_domains`), azioni di `admin-post.php` (`easycontact_submit`, `easycontact_preview`,
`easycontact_retry`, `easycontact_classify`, `easycontact_approve`, `easycontact_sync_disposable_domains`), lo
shortcode `[easycontact]` e le route REST `easycontact/v1/forms/{id}/token` e `easycontact/v1/forms/{id}/submit`.
