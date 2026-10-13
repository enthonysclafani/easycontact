<?php
/**
 * Settings page: Settings API registration, fields, validation and the disposable-domain synchronization.
 *
 * @package EasyContact
 */

namespace EasyContact\Settings\Admin;

use EasyContact\AntiSpam\AntiSpam;
use EasyContact\AntiSpam\DisposableDomains;
use EasyContact\Phone\Admin\CountrySelect;
use EasyContact\Phone\Phone;
use EasyContact\Plugin;
use EasyContact\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Native settings page under the forms menu, for administrators only.
 */
final class Page {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'settings' ) );
		add_action( 'admin_post_easycontact_sync_disposable_domains', array( self::class, 'sync_disposable_domains' ) );
	}

	/**
	 * Add the settings submenu.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . Plugin::FORM, __( 'Impostazioni', 'easycontact' ), __( 'Impostazioni', 'easycontact' ), 'manage_options', 'easycontact-settings', array( self::class, 'settings_page' ) );
	}

	/**
	 * Register the option, its sections and its fields with the Settings API.
	 *
	 * @return void
	 */
	public static function settings(): void {
		register_setting(
			'easycontact_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'object',
				'description'       => 'EasyContact site settings (schema: Settings::schema()).',
				'default'           => Settings::defaults(),
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				// Holds the MailerLite token and the Turnstile secret.
				'show_in_rest'      => false,
			)
		);
		foreach ( array(
			'privacy'      => __( 'Privacy dei moduli', 'easycontact' ),
			'antispam'     => __( 'Anti-spam e telefono internazionale', 'easycontact' ),
			'integrations' => __( 'MailerLite', 'easycontact' ),
			'captcha'      => 'CAPTCHA · Cloudflare Turnstile',
			'uninstall'    => __( 'Disinstallazione', 'easycontact' ),
		) as $section => $title ) {
			add_settings_section( 'easycontact-' . $section, $title, '__return_false', 'easycontact-settings' );
		}
		$items = array(
			'antispam_level'      => array( 'antispam', __( 'Livello predefinito', 'easycontact' ), 'level', __( 'Consigliato: 2 — Equilibrato. I moduli esistenti mantengono il livello 1 finché non viene modificato nelle loro opzioni.', 'easycontact' ) ),
			'antispam_observe'    => array( 'antispam', __( 'Modalità osservazione', 'easycontact' ), 'checkbox', __( 'Registra i segnali euristici senza fermare gli invii. Per esaminare i risultati, attiva l’archivio. L’honeypot resta attivo.', 'easycontact' ) ),
			'quarantine_entries'  => array( 'antispam', __( 'Quarantena con archivio disattivato', 'easycontact' ), 'checkbox', __( 'Conserva spam e sospetti per la revisione anche senza archivio normale; notifiche e integrazioni restano sospese.', 'easycontact' ) ),
			'spam_retention_days' => array( 'antispam', __( 'Conservazione di spam e sospetti', 'easycontact' ), 'number', __( 'Giorni prima della cancellazione automatica di spam e probabile spam. Minimo 1; gli invii accettati seguono la conservazione normale.', 'easycontact' ) ),
			'quarantine_limit'    => array( 'antispam', __( 'Limite giornaliero della quarantena', 'easycontact' ), 'number', __( 'Numero massimo di nuovi invii sospetti archiviabili in una finestra di 24 ore. Oltre il limite vengono rifiutati.', 'easycontact' ) ),
			'email_dns_check'     => array( 'antispam', __( 'Controllo DNS delle email', 'easycontact' ), 'checkbox', __( 'Controlla il dominio tramite il resolver del server, con cache e tolleranza agli errori temporanei. Non verifica la casella.', 'easycontact' ) ),
			'phone_country'       => array( 'antispam', __( 'Paese iniziale del telefono', 'easycontact' ), 'country', __( 'La selezione automatica segue la lingua del sito. Il visitatore può sempre cambiare paese.', 'easycontact' ) ),
			'disposable_domains'  => array( 'antispam', __( 'Lista domini temporanei', 'easycontact' ), 'disposable', __( 'Usata nei livelli 2 e 3. Sincronizza su richiesta le fonti pubbliche e conserva la copia locale se una fonte non è disponibile. Nessun aggiornamento automatico.', 'easycontact' ) ),
			'spam_phrases'        => array( 'antispam', __( 'Frasi sospette', 'easycontact' ), 'textarea', __( 'Una frase per riga, da 4 a 150 caratteri. Aumenta il sospetto; usa frasi specifiche, evitando parole generiche.', 'easycontact' ) ),
			'store_entries'       => array( 'privacy', __( 'Archivio degli invii', 'easycontact' ), 'checkbox', __( 'Salva gli invii locali per impostazione predefinita. Ogni modulo può cambiare questa scelta.', 'easycontact' ) ),
			'retention_days'      => array( 'privacy', __( 'Conservazione', 'easycontact' ), 'number', __( 'Giorni di conservazione. 0 mantiene gli invii fino alla cancellazione manuale. La pulizia avviene a lotti quando un amministratore apre la bacheca o dopo un invio, una volta al giorno.', 'easycontact' ) ),
			'allow_erasure'       => array( 'privacy', __( 'Richieste di cancellazione', 'easycontact' ), 'checkbox', __( 'Consenti agli strumenti privacy di WordPress di eliminare gli invii associati all’indirizzo email confermato.', 'easycontact' ) ),
			'mailerlite_token'    => array( 'integrations', __( 'Token API', 'easycontact' ), 'password', __( 'Token della versione attuale di MailerLite. Lascia vuoto per mantenere il token salvato.', 'easycontact' ) ),
			'turnstile_site_key'  => array( 'captcha', __( 'Site key', 'easycontact' ), 'text', __( 'Il CAPTCHA si attiva nelle opzioni di ciascun modulo.', 'easycontact' ) ),
			'turnstile_secret'    => array( 'captcha', __( 'Secret key', 'easycontact' ), 'password', __( 'Lascia vuoto per mantenere la chiave salvata.', 'easycontact' ) ),
			'delete_on_uninstall' => array( 'uninstall', __( 'Rimuovi i dati', 'easycontact' ), 'checkbox', __( 'Alla disinstallazione elimina definitivamente moduli, invii, impostazioni e credenziali del plugin. La sola disattivazione conserva i dati.', 'easycontact' ) ),
		);
		foreach ( $items as $key => $item ) {
			add_settings_field(
				'easycontact-' . $key,
				$item[1],
				array( self::class, 'setting_field' ),
				'easycontact-settings',
				'easycontact-' . $item[0],
				array(
					'key'       => $key,
					'type'      => $item[2],
					'help'      => $item[3],
					'label_for' => 'disposable' === $item[2] ? '' : 'easycontact-setting-' . $key,
				)
			);
		}
	}

	/**
	 * Render a registered Settings API field.
	 *
	 * @param array<string, string> $args Registered field arguments.
	 * @return void
	 */
	public static function setting_field( array $args ): void {
		$settings = Settings::get();
		$key      = $args['key'];
		$type     = $args['type'];
		$name     = Settings::OPTION . '[' . $key . ']';
		if ( 'disposable' === $type ) {
			$status = DisposableDomains::status();
			/* translators: %s: number of domains. */
			echo '<div id="easycontact-disposable-domains"><p><strong>' . esc_html( sprintf( __( '%s domini nella lista locale', 'easycontact' ), number_format_i18n( $status['count'] ) ) ) . '</strong></p>';
			/* translators: %s: date and time of the synchronization, or date of the bundled snapshot. */
			$label = $status['synced'] ? __( 'Ultima sincronizzazione: %s', 'easycontact' ) : __( 'Copia inclusa nel plugin, aggiornata al %s', 'easycontact' );
			echo '<p>' . esc_html( sprintf( $label, wp_date( get_option( 'date_format' ) . ( $status['synced'] ? ' ' . get_option( 'time_format' ) : '' ), $status['updated_at'] ) ) ) . '</p><ul>';
			foreach ( DisposableDomains::sources() as $source ) {
				echo '<li><a href="' . esc_url( $source['project'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $source['name'] ) . '</a></li>';
			}
			echo '</ul><p><button type="submit" class="button" id="easycontact-setting-disposable_domains" form="easycontact-disposable-sync">' . esc_html__( 'Sincronizza lista', 'easycontact' ) . '</button></p><p class="description">' . esc_html( $args['help'] ) . '</p></div>';
			return;
		}
		if ( 'level' === $type ) {
			echo '<input type="hidden" name="' . esc_attr( Settings::OPTION . '[antispam_present]' ) . '" value="1"><select id="easycontact-setting-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( array(
				1 => __( '1 — Base', 'easycontact' ),
				2 => __( '2 — Equilibrato', 'easycontact' ),
				3 => __( '3 — Rigoroso', 'easycontact' ),
			) as $level => $label ) {
				echo '<option value="' . (int) $level . '" ' . selected( $settings[ $key ], $level, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select><p class="description">' . esc_html( $args['help'] ) . '</p>';
			return;
		}
		if ( 'country' === $type ) {
			CountrySelect::render( $name, $settings[ $key ], 'easycontact-setting-' . $key, __( 'Automatico dalla lingua del sito', 'easycontact' ) );
			echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
			return;
		}
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text" rows="3" id="easycontact-setting-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $settings[ $key ] ) . '</textarea><p class="description">' . esc_html( $args['help'] ) . '</p>';
			return;
		}
		echo '<input id="easycontact-setting-' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '"';
		if ( 'checkbox' === $type ) {
			echo ' value="1" ' . checked( $settings[ $key ], true, false );
		} else {
			echo ' class="regular-text" value="' . esc_attr( 'password' === $type ? '' : (string) $settings[ $key ] ) . '"';
			if ( 'password' === $type ) {
				echo ' autocomplete="new-password" placeholder="' . esc_attr( $settings[ $key ] ? __( 'Configurato', 'easycontact' ) : '' ) . '"';
			} elseif ( 'number' === $type ) {
				echo ' min="' . ( in_array( $key, array( 'spam_retention_days', 'quarantine_limit' ), true ) ? '1' : '0' ) . '" max="' . ( 'quarantine_limit' === $key ? '5000' : '3650' ) . '" step="1"';
			}
		}
		echo '><p class="description">' . esc_html( $args['help'] ) . '</p>';
		if ( 'password' === $type ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( Settings::OPTION . '[clear_' . $key . ']' ) . '" value="1"> ' . esc_html__( 'Rimuovi la credenziale salvata', 'easycontact' ) . '</label></p>';
		}
	}

	/**
	 * Validate global preferences and preserve unchanged credentials.
	 *
	 * @param mixed $input Untrusted input to validate.
	 * @return array<string, mixed> Result of this operation.
	 */
	public static function sanitize_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = Settings::get();
		$settings = Settings::defaults();
		foreach ( array( 'store_entries', 'allow_erasure', 'delete_on_uninstall' ) as $key ) {
			$settings[ $key ] = isset( $input[ $key ] ) && '1' === (string) ( is_scalar( $input[ $key ] ) ? $input[ $key ] : '' );
		}
		$settings['retention_days'] = isset( $input['retention_days'] ) && is_scalar( $input['retention_days'] ) ? min( 3650, max( 0, (int) $input['retention_days'] ) ) : 30;
		foreach ( array( 'antispam_observe', 'quarantine_entries', 'email_dns_check' ) as $key ) {
			$settings[ $key ] = empty( $input['antispam_present'] ) ? $previous[ $key ] : ( isset( $input[ $key ] ) && '1' === (string) ( is_scalar( $input[ $key ] ) ? $input[ $key ] : '' ) );
		}
		foreach ( array(
			'antispam_level'      => array( 1, 3 ),
			'spam_retention_days' => array( 1, 3650 ),
			'quarantine_limit'    => array( 1, 5000 ),
		) as $key => $bounds ) {
			$value = $input[ $key ] ?? $previous[ $key ];
			if ( is_scalar( $value ) && preg_match( '/^\d+$/D', (string) $value ) && (int) $value >= $bounds[0] && (int) $value <= $bounds[1] ) {
				$settings[ $key ] = (int) $value;
			} else {
				$settings[ $key ] = $previous[ $key ];
				add_settings_error( Settings::OPTION, $key, __( 'Un valore anti-spam non è valido; è stato mantenuto quello precedente.', 'easycontact' ) );
			}
		}
		$country                   = $input['phone_country'] ?? $previous['phone_country'];
		$settings['phone_country'] = is_string( $country ) && ( '' === $country || Phone::valid_country( $country ) ) ? $country : $previous['phone_country'];
		$value                     = $input['spam_phrases'] ?? $previous['spam_phrases'];
		$result                    = is_string( $value ) ? AntiSpam::phrase_list( $value ) : new WP_Error( 'type' );
		$settings['spam_phrases']  = is_wp_error( $result ) ? $previous['spam_phrases'] : $result;
		if ( is_wp_error( $result ) ) {
			add_settings_error( Settings::OPTION, 'spam_phrases', __( 'Una lista anti-spam non è valida; è stata mantenuta quella precedente.', 'easycontact' ) );
		}
		$settings['turnstile_site_key'] = isset( $input['turnstile_site_key'] ) && is_string( $input['turnstile_site_key'] ) ? sanitize_text_field( $input['turnstile_site_key'] ) : '';
		if ( strlen( $settings['turnstile_site_key'] ) > 256 || ! preg_match( '/^[A-Za-z0-9_-]*$/D', $settings['turnstile_site_key'] ) ) {
			$settings['turnstile_site_key'] = $previous['turnstile_site_key'];
			add_settings_error( Settings::OPTION, 'turnstile_site_key', __( 'La site key non è valida; è stata mantenuta quella precedente.', 'easycontact' ) );
		}
		foreach ( array( 'mailerlite_token', 'turnstile_secret' ) as $key ) {
			$value            = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? trim( $input[ $key ] ) : '';
			$settings[ $key ] = ! empty( $input[ 'clear_' . $key ] ) ? '' : ( '' !== $value ? $value : $previous[ $key ] );
			if ( preg_match( '/[\x00-\x20\x7f]/', $settings[ $key ] ) || strlen( $settings[ $key ] ) > 2000 ) {
				$settings[ $key ] = $previous[ $key ];
				add_settings_error( Settings::OPTION, $key, __( 'La credenziale contiene caratteri non validi; è stata mantenuta quella precedente.', 'easycontact' ) );
			}
		}
		// Every branch above keeps the schema; this guard catches a previous value or a future bound that drifts from
		// it, one key at a time, so an old stored value never blocks saving the others.
		foreach ( Settings::properties() as $key => $property ) {
			if ( true !== rest_validate_value_from_schema( $settings[ $key ], $property, $key ) ) {
				$settings[ $key ] = $property['default'];
				add_settings_error( Settings::OPTION, $key, __( 'Un valore non era valido ed è stato riportato a quello predefinito.', 'easycontact' ) );
			}
		}
		return $settings;
	}

	/**
	 * Render the native authorized settings page.
	 *
	 * @return void
	 */
	public static function settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>EasyContact · ' . esc_html__( 'Impostazioni', 'easycontact' ) . '</h1>';
		settings_errors();
		$notice_key = 'easycontact_disposable_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		if ( is_array( $notice ) ) {
			delete_transient( $notice_key );
			echo '<div class="notice ' . ( $notice['error'] ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		echo '<form method="post" action="options.php">';
		settings_fields( 'easycontact_settings_group' );
		do_settings_sections( 'easycontact-settings' );
		submit_button();
		echo '</form><form id="easycontact-disposable-sync" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="easycontact_sync_disposable_domains">';
		wp_nonce_field( 'easycontact_sync_disposable_domains' );
		echo '</form></div>';
	}

	/**
	 * Manual synchronization of the disposable-domain list: administrators only, POST with nonce.
	 */
	public static function sync_disposable_domains(): void {
		if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Operazione non consentita.', 'easycontact' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'easycontact_sync_disposable_domains' );
		$result = DisposableDomains::sync();
		$error  = is_wp_error( $result );
		/* translators: %s: number of domains. */
		$message = $error ? $result->get_error_message() : sprintf( __( 'Sincronizzazione completata: %s domini nella lista locale.', 'easycontact' ), number_format_i18n( $result['count'] ) );
		set_transient(
			'easycontact_disposable_notice_' . get_current_user_id(),
			array(
				'error'   => $error,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Plugin::FORM . '&page=easycontact-settings#easycontact-disposable-domains' ) );
		exit;
	}
}
