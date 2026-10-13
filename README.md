# EasyContact

Plugin di moduli di contatto per WordPress: anti-spam locale, telefono internazionale, campi condizionali,
MailerLite, Slack e Cloudflare Turnstile, costruito solo su dati nativi di WordPress (nessuna tabella custom).

- Regole del progetto: [CLAUDE.md](CLAUDE.md)
- Piano per portare qui gli standard di EasyRankly: [docs/piano-standard.md](docs/piano-standard.md)
- Analisi rispetto a EasyRankly e stato per la produzione: [docs/analisi-easyrankly.md](docs/analisi-easyrankly.md)
- Decisioni aperte e prese: [docs/decisioni.md](docs/decisioni.md)
- Modello dati: [docs/data-model.md](docs/data-model.md)
- Come funziona l'anti-spam: [ANTI-SPAM.md](ANTI-SPAM.md)
- Librerie incluse e licenze: [THIRD-PARTY.md](THIRD-PARTY.md)

## Sviluppo

Il branch `Refactory` contiene tutto il necessario per lavorare in locale o con Claude Code nel cloud.

- **Cloud (Claude Code)**: all'avvio della sessione `tools/cloud-setup.sh` (hook in `.claude/settings.json`) installa
  dipendenze, MariaDB e la suite di test di WordPress. Basta lanciare `composer check`. PHPStan nel cloud non si
  può installare: lo esegue la CI sulla pull request.
- **Locale**: servono PHP 8.1+, Composer e MySQL o MariaDB.

```bash
composer install
tools/install-wp-tests.sh wordpress_test root '' 127.0.0.1   # una volta sola (WordPress 7.1.0 di default)
composer check
```

| Comando | Cosa fa |
|---|---|
| `composer architecture` | Invarianti di `CLAUDE.md`: tabelle, `$wpdb`, cron, asset e HTTP fuori dai file ammessi, funzioni vietate |
| `composer lint` | PHPCS con WordPress Coding Standards e PHPCompatibilityWP (`composer lint:fix` corregge lo stile) |
| `composer analyse` | PHPStan livello 8, installato a parte in `tools/phpstan/` |
| `composer test` | PHPUnit sulla suite di WordPress; `WP_MULTISITE=1 composer test` per il multisito |
| `composer check` | Tutto quanto sopra: deve passare prima di dire "fatto" |

`tools/build-dist.sh` crea `.dist/easycontact.zip` con solo i file che vanno distribuiti (`.gitattributes` esclude
test, strumenti e documenti). La libreria del telefono in `includes/vendor/phone/` si aggiorna solo con
`tools/bundle-phone.php` (vedi `THIRD-PARTY.md`).

La CI su GitHub esegue gli stessi controlli a ogni push su `Refactory` e a ogni pull request, su WordPress 6.4 (il
minimo dichiarato) e 7.1, PHP 8.1 e 8.4, anche in multisito, più Plugin Check sullo zip distribuibile.
