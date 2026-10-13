<?php
/**
 * Form editor: native metaboxes, validated saving, saved preview and the form list columns.
 *
 * @package EasyContact
 */

namespace EasyContact\Forms\Admin;

use EasyContact\AntiSpam\AntiSpam;
use EasyContact\Delivery\Admin\Outcomes;
use EasyContact\Delivery\Delivery;
use EasyContact\Forms\Forms;
use EasyContact\Forms\Renderer;
use EasyContact\Forms\Schema;
use EasyContact\Phone\Admin\CountrySelect;
use EasyContact\Phone\Phone;
use EasyContact\Plugin;
use EasyContact\Settings\Settings;
use EasyContact\Submissions\Entries;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The form edit screen, for administrators only.
 */
final class Editor {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'add_meta_boxes', array( self::class, 'boxes' ) );
		add_action( 'save_post_' . Plugin::FORM, array( self::class, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_action( 'admin_post_easycontact_preview', array( self::class, 'preview' ) );
		add_filter( 'manage_' . Plugin::FORM . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . Plugin::FORM . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
	}

	/**
	 * Register the form metaboxes.
	 *
	 * @return void
	 */
	public static function boxes(): void {
		add_meta_box( 'easycontact-module', __( 'Modulo', 'easycontact' ), array( self::class, 'module_box' ), Plugin::FORM, 'normal', 'high' );
		add_meta_box( 'easycontact-conditions', __( 'Campi condizionali', 'easycontact' ), array( self::class, 'conditions_box' ), Plugin::FORM, 'normal' );
		add_meta_box( 'easycontact-email', __( 'Email', 'easycontact' ), array( self::class, 'email_box' ), Plugin::FORM, 'normal' );
		add_meta_box( 'easycontact-integrations', __( 'Integrazioni', 'easycontact' ), array( self::class, 'integrations_box' ), Plugin::FORM, 'normal' );
		add_meta_box( 'easycontact-antispam', __( 'Anti-spam e telefono internazionale', 'easycontact' ), array( self::class, 'antispam_box' ), Plugin::FORM, 'normal' );
		add_meta_box( 'easycontact-options', __( 'Opzioni', 'easycontact' ), array( self::class, 'options_box' ), Plugin::FORM, 'side' );
	}

	/**
	 * Add the shortcode and last delivery columns to the form list.
	 *
	 * @param array<string, string> $columns Native list-table columns.
	 * @return array<string, string> Result of this operation.
	 */
	public static function columns( array $columns ): array {
		$columns['easycontact-shortcode'] = 'Shortcode';
		$columns['easycontact-delivery']  = __( 'Esito invii', 'easycontact' );
		return $columns;
	}

	/**
	 * Render a form list column.
	 *
	 * @param string $column Current list-table column name.
	 * @param int    $id Native WordPress post ID.
	 * @return void
	 */
	public static function column( string $column, int $id ): void {
		if ( 'easycontact-shortcode' === $column ) {
			echo '<code>' . esc_html( '[easycontact id="' . $id . '"]' ) . '</code>';
		}
		if ( 'easycontact-delivery' === $column ) {
			$status = get_post_meta( $id, '_easycontact_last_delivery', true );
			if ( is_array( $status ) ) {
				Outcomes::render( $status['outcomes'] );
			}
		}
	}

	/**
	 * Render the restricted tag editor and saved preview link.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function module_box( WP_Post $post ): void {
		$definition = Forms::definition( $post->ID, true );
		wp_nonce_field( 'easycontact_editor_' . $post->ID, 'easycontact_editor_nonce' );
		?>
		<p class="easycontact-toolbar">
		<?php
		foreach ( array(
			'text'       => __( 'Testo', 'easycontact' ),
			'email'      => 'Email',
			'textarea'   => __( 'Messaggio', 'easycontact' ),
			'select'     => __( 'Selezione', 'easycontact' ),
			'number'     => __( 'Numero', 'easycontact' ),
			'tel'        => __( 'Telefono', 'easycontact' ),
			'checkbox'   => __( 'Checkbox', 'easycontact' ),
			'acceptance' => __( 'Consenso', 'easycontact' ),
		) as $type => $label ) :
			?>
	<button type="button" class="button" data-easycontact-insert="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></button> <?php endforeach; ?></p>
		<label class="screen-reader-text" for="easycontact-template"><?php esc_html_e( 'Definizione del modulo', 'easycontact' ); ?></label>
		<textarea class="large-text code" id="easycontact-template" name="easycontact_template" rows="13" maxlength="20000" spellcheck="false"><?php echo esc_textarea( $definition['template'] ); ?></textarea>
		<?php
		if ( 'auto-draft' !== $post->post_status ) :
			?>
			<p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=easycontact_preview&id=' . $post->ID ), 'easycontact_preview_' . $post->ID ) ); ?>"><?php esc_html_e( 'Anteprima salvata', 'easycontact' ); ?></a></p><?php endif; ?>
		<?php
	}

	/**
	 * Render a mapping selector using the saved schema.
	 *
	 * @param string $key Internal identifier.
	 * @param string $label Localized visible label.
	 * @param array  $definition Validated form definition.
	 * @param array  $types Allowed field types; empty accepts all types.
	 * @param bool   $allow_empty Whether the empty mapping is available.
	 * @return void
	 */
	private static function field_select( string $key, string $label, array $definition, array $types = array(), bool $allow_empty = true ): void {
		$value = $definition['config'][ $key ];
		echo '<p><label for="easycontact-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><select id="easycontact-' . esc_attr( $key ) . '" name="easycontact_config[' . esc_attr( $key ) . ']">';
		if ( $allow_empty ) {
			echo '<option value="">' . esc_html__( 'Nessuno', 'easycontact' ) . '</option>';
		}
		foreach ( $definition['schema']['fields'] as $name => $field ) {
			if ( ! $types || in_array( $field['type'], $types, true ) ) {
				echo '<option value="' . esc_attr( $name ) . '" ' . selected( $value, $name, false ) . '>' . esc_html( $field['label'] . ' (' . $name . ')' ) . '</option>';
			}
		}
		echo '</select></p>';
	}

	/**
	 * Render a configuration field while keeping secrets out of the response.
	 *
	 * @param string $key Internal identifier.
	 * @param string $label Localized visible label.
	 * @param array  $config Validated delivery and privacy configuration.
	 * @param string $type Supported input type.
	 * @param string $help Localized explanatory text.
	 * @return void
	 */
	private static function input( string $key, string $label, array $config, string $type = 'text', string $help = '' ): void {
		$is_secret = 'password' === $type;
		echo '<p><label for="easycontact-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><input class="large-text" type="' . esc_attr( $type ) . '" id="easycontact-' . esc_attr( $key ) . '" name="easycontact_config[' . esc_attr( $key ) . ']" value="' . esc_attr( $is_secret ? '' : $config[ $key ] ) . '"' . ( $is_secret ? ' autocomplete="new-password" placeholder="' . esc_attr( $config[ $key ] ? __( 'Configurato; lascia vuoto per mantenerlo', 'easycontact' ) : '' ) . '"' : '' ) . '></p>';
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Render a boolean form preference.
	 *
	 * @param string $key Internal identifier.
	 * @param string $label Localized visible label.
	 * @param array  $config Validated delivery and privacy configuration.
	 * @return void
	 */
	private static function checkbox( string $key, string $label, array $config ): void {
		echo '<p><label><input type="checkbox" name="easycontact_config[' . esc_attr( $key ) . ']" value="1" ' . checked( $config[ $key ], true, false ) . '> ' . esc_html( $label ) . '</label></p>';
	}

	/**
	 * Render plain-text email configuration.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function email_box( WP_Post $post ): void {
		$definition = Forms::definition( $post->ID, true );
		$config     = $definition['config'];
		self::checkbox( 'mail_enabled', __( 'Invia una notifica email', 'easycontact' ), $config );
		self::input( 'mail_to', __( 'Destinatari', 'easycontact' ), $config, 'text', __( 'Indirizzi email separati da virgola.', 'easycontact' ) );
		self::input( 'mail_subject', __( 'Oggetto', 'easycontact' ), $config );
		self::field_select( 'mail_reply_to', __( 'Rispondi all’indirizzo del campo', 'easycontact' ), $definition, array( 'email' ) );
		echo '<p><label for="easycontact-mail-body">' . esc_html__( 'Messaggio', 'easycontact' ) . '</label><textarea class="large-text code" id="easycontact-mail-body" name="easycontact_config[mail_body]" rows="6">' . esc_textarea( $config['mail_body'] ) . '</textarea></p><p class="description">' . esc_html__( '[fields] include i campi visibili; [form] il titolo del modulo; [site] il nome del sito. Puoi usare anche [nome_del_campo].', 'easycontact' ) . '</p>';
	}

	/**
	 * Render per-form consent and destination mappings.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function integrations_box( WP_Post $post ): void {
		$definition = Forms::definition( $post->ID, true );
		$config     = $definition['config'];
		echo '<h3>MailerLite</h3>';
		self::checkbox( 'mailerlite_enabled', __( 'Attiva MailerLite per questo modulo', 'easycontact' ), $config );
		self::field_select( 'mailerlite_email', 'Email', $definition, array( 'email' ) );
		self::field_select( 'mailerlite_name', __( 'Nome', 'easycontact' ), $definition );
		self::field_select( 'mailerlite_consent', __( 'Campo del consenso newsletter', 'easycontact' ), $definition, array( 'checkbox', 'acceptance' ) );
		self::input( 'mailerlite_groups', __( 'ID dei gruppi MailerLite', 'easycontact' ), $config, 'text', __( 'Uno o più ID separati da virgola. L’iscrizione avviene solo con il consenso selezionato. Il double opt-in si configura in MailerLite, nelle impostazioni API e integrazioni.', 'easycontact' ) );
		echo '<h3>Slack</h3>';
		self::checkbox( 'slack_enabled', __( 'Invia una notifica Slack', 'easycontact' ), $config );
		self::input( 'slack_webhook', __( 'Webhook del canale', 'easycontact' ), $config, 'password' );
		echo '<p><label><input type="checkbox" name="easycontact_config[clear_slack_webhook]" value="1"> ' . esc_html__( 'Rimuovi il webhook salvato', 'easycontact' ) . '</label></p>';
		self::input( 'slack_fields', __( 'Campi da includere nella notifica', 'easycontact' ), $config, 'text', __( 'Nomi dei campi separati da virgola. Solo i campi visibili scelti qui saranno inviati a Slack.', 'easycontact' ) );
		echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Plugin::FORM . '&page=easycontact-settings' ) ) . '">' . esc_html__( 'Configura token MailerLite e CAPTCHA nelle impostazioni', 'easycontact' ) . '</a></p>';
	}

	/**
	 * Render privacy, CAPTCHA and latest delivery outcomes.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function options_box( WP_Post $post ): void {
		$config = Forms::definition( $post->ID, true )['config'];
		self::checkbox( 'captcha', __( 'Verifica con Cloudflare Turnstile', 'easycontact' ), $config );
		echo '<p><label for="easycontact-archive">' . esc_html__( 'Archivio degli invii', 'easycontact' ) . '</label><br><select id="easycontact-archive" name="easycontact_config[store_entries]">';
		foreach ( array(
			'inherit' => __( 'Usa impostazioni generali', 'easycontact' ),
			'no'      => __( 'Disattivato', 'easycontact' ),
			'yes'     => __( 'Attivato', 'easycontact' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $config['store_entries'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		self::input( 'success_message', __( 'Messaggio di conferma', 'easycontact' ), $config );
		$status = get_post_meta( $post->ID, '_easycontact_last_delivery', true );
		if ( is_array( $status ) ) {
			echo '<hr><p><strong>' . esc_html__( 'Ultimo esito', 'easycontact' ) . '</strong><br>' . esc_html( $status['time'] ) . '</p>';
			Outcomes::render( $status['outcomes'] );
		}
	}

	/**
	 * A form setting that can inherit the general settings.
	 *
	 * @param string $key    Configuration key.
	 * @param string $label  Visible label.
	 * @param array  $config Form configuration.
	 * @param string $help   Description below the field.
	 */
	private static function inherited_select( string $key, string $label, array $config, string $help ): void {
		echo '<p><label for="easycontact-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><select id="easycontact-' . esc_attr( $key ) . '" name="easycontact_config[' . esc_attr( $key ) . ']">';
		foreach ( array(
			'inherit' => __( 'Usa impostazioni generali', 'easycontact' ),
			'yes'     => __( 'Attivato', 'easycontact' ),
			'no'      => __( 'Disattivato', 'easycontact' ),
		) as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $config[ $key ], $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></p><p class="description">' . esc_html( $help ) . '</p>';
	}

	/**
	 * Anti-spam settings of a form.
	 *
	 * @param WP_Post $post Form.
	 */
	public static function antispam_box( WP_Post $post ): void {
		$config = Forms::definition( $post->ID, true )['config'];
		echo '<p><label for="easycontact-antispam-level">' . esc_html__( 'Livello anti-spam', 'easycontact' ) . '</label><br><select id="easycontact-antispam-level" name="easycontact_config[antispam_level]">';
		foreach ( array(
			'inherit' => __( 'Usa impostazioni generali', 'easycontact' ),
			'1'       => __( '1 — Base', 'easycontact' ),
			'2'       => __( '2 — Equilibrato', 'easycontact' ),
			'3'       => __( '3 — Rigoroso', 'easycontact' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $config['antispam_level'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p class="description">' . esc_html__( 'I controlli di validità e l’honeypot sono sempre attivi. I livelli 2 e 3 aggiungono controlli sul contenuto, domini temporanei, ripetizioni e frequenza. Il livello 3 è più sensibile e può richiedere più revisioni.', 'easycontact' ) . '</p>';
		self::inherited_select( 'quarantine_entries', __( 'Quarantena con archivio disattivato', 'easycontact' ), $config, __( 'Conserva spam e sospetti anche quando l’archivio normale è disattivato. Con archivio attivo, i sospetti sono sempre salvati con inoltro sospeso. Senza conservazione, vengono rifiutati.', 'easycontact' ) );
		self::inherited_select( 'antispam_observe', __( 'Modalità osservazione', 'easycontact' ), $config, __( 'Calcola il punteggio senza fermare gli invii per i segnali euristici. L’honeypot resta attivo. Per esaminare i risultati, attiva l’archivio.', 'easycontact' ) );
		self::inherited_select( 'email_dns_check', __( 'Controllo DNS del dominio email', 'easycontact' ), $config, __( 'Verifica la capacità del dominio di ricevere posta. Non verifica l’esistenza della casella. Gli errori temporanei del resolver non bloccano l’invio.', 'easycontact' ) );
		echo '<p><label for="easycontact-phone-country">' . esc_html__( 'Paese iniziale del telefono', 'easycontact' ) . '</label><br>';
		CountrySelect::render( 'easycontact_config[phone_country]', $config['phone_country'], 'easycontact-phone-country', __( 'Usa impostazioni generali o lingua del sito', 'easycontact' ) );
		echo '</p><p class="description">' . esc_html__( 'Il visitatore può scegliere qualsiasi paese. Per lingue senza un paese riconoscibile, configura un valore iniziale oppure lascia la scelta al visitatore.', 'easycontact' ) . '</p>';
		echo '<p>' . esc_html__( 'I domini temporanei vengono riconosciuti tramite le liste pubbliche condivise da tutti i moduli nei livelli 2 e 3.', 'easycontact' ) . ' <a href="' . esc_url( admin_url( 'edit.php?post_type=' . Plugin::FORM . '&page=easycontact-settings#easycontact-disposable-domains' ) ) . '">' . esc_html__( 'Gestisci la lista nelle impostazioni', 'easycontact' ) . '</a></p>';
		echo '<p><label for="easycontact-spam_phrases">' . esc_html__( 'Frasi sospette per questo modulo', 'easycontact' ) . '</label><textarea rows="3" class="large-text" id="easycontact-spam_phrases" name="easycontact_config[spam_phrases]">' . esc_textarea( $config['spam_phrases'] ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'Una frase per riga. Le frasi del modulo si aggiungono a quelle generali e contribuiscono al punteggio nei livelli 2 e 3; non provano da sole che il messaggio sia spam.', 'easycontact' ) . '</p>';
	}

	/**
	 * Return localized supported comparison labels.
	 *
	 * @return array Result of this operation.
	 */
	private static function operators(): array {
		return array(
			'equals'     => __( 'È uguale a', 'easycontact' ),
			'not_equals' => __( 'È diverso da', 'easycontact' ),
			'contains'   => __( 'Contiene', 'easycontact' ),
			'empty'      => __( 'È vuoto', 'easycontact' ),
			'not_empty'  => __( 'Non è vuoto', 'easycontact' ),
			'greater'    => __( 'È maggiore di', 'easycontact' ),
			'less'       => __( 'È minore di', 'easycontact' ),
		);
	}

	/**
	 * Render a conditional editor row.
	 *
	 * @param mixed $index Unique editor row index.
	 * @param array $rule Validated conditional rule.
	 * @param array $fields Field definitions keyed by name.
	 * @return void
	 */
	private static function rule_row( $index, array $rule, array $fields ): void {
		echo '<tr>';
		foreach ( array(
			'target' => __( 'Campo da mostrare', 'easycontact' ),
			'source' => __( 'Campo da confrontare', 'easycontact' ),
		) as $key => $label ) {
			echo '<td><select aria-label="' . esc_attr( $label ) . '" name="easycontact_rules[' . esc_attr( (string) $index ) . '][' . esc_attr( $key ) . ']"><option value="">—</option>';
			foreach ( $fields as $name => $field ) {
				echo '<option value="' . esc_attr( $name ) . '" ' . selected( $rule[ $key ] ?? '', $name, false ) . '>' . esc_html( $field['label'] . ' (' . $name . ')' ) . '</option>';
			}
			echo '</select></td>';
		}
		echo '<td><select aria-label="' . esc_attr__( 'Confronto', 'easycontact' ) . '" name="easycontact_rules[' . esc_attr( (string) $index ) . '][operator]">';
		foreach ( self::operators() as $operator => $label ) {
			echo '<option value="' . esc_attr( $operator ) . '" ' . selected( $rule['operator'] ?? 'equals', $operator, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td><td><input aria-label="' . esc_attr__( 'Valore', 'easycontact' ) . '" type="text" name="easycontact_rules[' . esc_attr( (string) $index ) . '][value]" value="' . esc_attr( $rule['value'] ?? '' ) . '" maxlength="500"></td><td><button type="button" class="button-link-delete" data-easycontact-remove>' . esc_html__( 'Rimuovi', 'easycontact' ) . '</button></td></tr>';
	}

	/**
	 * Render conditional rules and matching preferences.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function conditions_box( WP_Post $post ): void {
		$definition = Forms::definition( $post->ID, true );
		echo '<p>' . esc_html__( 'Mostra un campo quando il confronto è soddisfatto. I campi nascosti non vengono validati né trasmessi. Per una checkbox usa il valore 1 quando selezionata.', 'easycontact' ) . '</p><p><label>' . esc_html__( 'Per le regole dello stesso campo richiedi', 'easycontact' ) . ' <select name="easycontact_config[conditions_match]"><option value="all" ' . selected( $definition['config']['conditions_match'], 'all', false ) . '>' . esc_html__( 'tutte le condizioni (AND)', 'easycontact' ) . '</option><option value="any" ' . selected( $definition['config']['conditions_match'], 'any', false ) . '>' . esc_html__( 'almeno una condizione (OR)', 'easycontact' ) . '</option></select></label></p>';
		echo '<div class="easycontact-rule-scroll"><table class="widefat easycontact-rules"><thead><tr><th>' . esc_html__( 'Mostra', 'easycontact' ) . '</th><th>' . esc_html__( 'Quando', 'easycontact' ) . '</th><th>' . esc_html__( 'Confronto', 'easycontact' ) . '</th><th>' . esc_html__( 'Valore', 'easycontact' ) . '</th><th></th></tr></thead><tbody id="easycontact-rules">';
		foreach ( $definition['conditions']['rules'] as $index => $rule ) {
			self::rule_row( $index, $rule, $definition['schema']['fields'] );
		}
		echo '</tbody></table></div><p><button class="button" type="button" id="easycontact-add-rule">' . esc_html__( 'Aggiungi regola', 'easycontact' ) . '</button></p><template id="easycontact-rule-template">';
		self::rule_row( '__INDEX__', array(), $definition['schema']['fields'] );
		echo '</template>';
	}

	/**
	 * Validate an authorized editor submission before persisting it.
	 *
	 * @param int     $id Native WordPress post ID.
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function save( int $id, WP_Post $post ): void {
		$nonce = isset( $_POST['easycontact_editor_nonce'] ) && is_string( $_POST['easycontact_editor_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['easycontact_editor_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'easycontact_editor_' . $id ) || ! current_user_can( 'manage_options' ) || Plugin::FORM !== $post->post_type || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Schema::parse validates the complete tag language before any persistence.
		$template = isset( $_POST['easycontact_template'] ) && is_string( $_POST['easycontact_template'] ) ? wp_unslash( $_POST['easycontact_template'] ) : '';
		$schema   = Schema::parse( $template );
		if ( is_wp_error( $schema ) ) {
			self::error( $id, $schema );
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Schema::rules sanitizes values and validates every name, operator and dependency.
		$rules_input = isset( $_POST['easycontact_rules'] ) && is_array( $_POST['easycontact_rules'] ) ? wp_unslash( $_POST['easycontact_rules'] ) : array();
		$conditions  = Schema::rules( $schema['fields'], $rules_input );
		if ( is_wp_error( $conditions ) ) {
			self::error( $id, $conditions );
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- self::config sanitizes and validates each supported setting; unknown keys are discarded.
		$config_input = isset( $_POST['easycontact_config'] ) && is_array( $_POST['easycontact_config'] ) ? wp_unslash( $_POST['easycontact_config'] ) : array();
		$config       = self::config( $config_input, Forms::definition( $id, true )['config'], $schema['fields'] );
		if ( is_wp_error( $config ) ) {
			self::error( $id, $config );
			return;
		}
		$definition = compact( 'template', 'schema', 'conditions', 'config' );
		update_post_meta( $id, '_easycontact_definition', wp_slash( $definition ) );
		if ( get_post_meta( $id, '_easycontact_definition', true ) !== $definition ) {
			self::error( $id, new WP_Error( 'save', __( 'Non è stato possibile salvare il modulo. Riprova.', 'easycontact' ) ) );
		}
	}

	/**
	 * Validate and normalize only supported per-form configuration.
	 *
	 * @param array $input Untrusted input to validate.
	 * @param array $previous Previously validated configuration.
	 * @param array $fields Field definitions keyed by name.
	 * @return mixed Result of this operation.
	 */
	public static function config( array $input, array $previous, array $fields ) {
		$config = Forms::config_defaults();
		foreach ( array( 'mail_enabled', 'mailerlite_enabled', 'slack_enabled', 'captcha' ) as $key ) {
			$config[ $key ] = isset( $input[ $key ] ) && '1' === (string) ( is_scalar( $input[ $key ] ) ? $input[ $key ] : '' );
		}
		foreach ( array( 'mail_to', 'mail_subject', 'mail_reply_to', 'mailerlite_email', 'mailerlite_name', 'mailerlite_consent', 'mailerlite_groups', 'slack_fields', 'success_message' ) as $key ) {
			$config[ $key ] = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : '';
			if ( strlen( $config[ $key ] ) > 2000 ) {
				return new WP_Error( 'config', __( 'Un’opzione è troppo lunga.', 'easycontact' ) );
			}
		}
		$config['mail_body']        = isset( $input['mail_body'] ) && is_string( $input['mail_body'] ) ? sanitize_textarea_field( $input['mail_body'] ) : '[fields]';
		$config['conditions_match'] = 'any' === ( $input['conditions_match'] ?? '' ) ? 'any' : 'all';
		$config['store_entries']    = in_array( $input['store_entries'] ?? '', array( 'inherit', 'yes', 'no' ), true ) ? $input['store_entries'] : 'inherit';
		$level                      = $input['antispam_level'] ?? ( $previous['antispam_level'] ?? 'inherit' );
		if ( ! is_scalar( $level ) || ! in_array( (string) $level, array( 'inherit', '1', '2', '3' ), true ) ) {
			return new WP_Error( 'config', __( 'Seleziona un livello anti-spam da 1 a 3.', 'easycontact' ) );
		}
		$config['antispam_level'] = (string) $level;
		foreach ( array( 'antispam_observe', 'quarantine_entries', 'email_dns_check' ) as $key ) {
			$value = $input[ $key ] ?? ( $previous[ $key ] ?? 'inherit' );
			if ( ! in_array( $value, array( 'inherit', 'yes', 'no' ), true ) ) {
				return new WP_Error( 'config', __( 'Una preferenza anti-spam non è valida.', 'easycontact' ) );
			}
			$config[ $key ] = $value;
		}
		$country = $input['phone_country'] ?? ( $previous['phone_country'] ?? '' );
		if ( ! is_string( $country ) || ( '' !== $country && ! Phone::valid_country( $country ) ) ) {
			return new WP_Error( 'config', __( 'Seleziona un paese iniziale valido.', 'easycontact' ) );
		}
		$config['phone_country'] = $country;
		$phrases                 = $input['spam_phrases'] ?? ( $previous['spam_phrases'] ?? '' );
		if ( ! is_string( $phrases ) ) {
			return new WP_Error( 'config', __( 'Le regole anti-spam devono essere testo.', 'easycontact' ) );
		}
		$config['spam_phrases'] = AntiSpam::phrase_list( $phrases );
		if ( is_wp_error( $config['spam_phrases'] ) ) {
			return $config['spam_phrases'];
		}
		$config['slack_webhook'] = ! empty( $input['clear_slack_webhook'] ) ? '' : ( isset( $input['slack_webhook'] ) && is_string( $input['slack_webhook'] ) && '' !== trim( $input['slack_webhook'] ) ? trim( $input['slack_webhook'] ) : $previous['slack_webhook'] );
		if ( strlen( $config['slack_webhook'] ) > 512 ) {
			return new WP_Error( 'config', __( 'Il webhook Slack è troppo lungo.', 'easycontact' ) );
		}
		if ( strlen( $config['mail_body'] ) > 20000 || strlen( $config['mail_subject'] ) > 300 || '' === $config['success_message'] || strlen( $config['success_message'] ) > 500 ) {
			return new WP_Error( 'config', __( 'Controlla la lunghezza dei messaggi e inserisci una conferma per il visitatore.', 'easycontact' ) );
		}
		if ( $config['mail_enabled'] ) {
			$addresses = array_map( 'trim', explode( ',', $config['mail_to'] ) );
			if ( ! $addresses || count( $addresses ) > 10 || count( array_filter( $addresses, 'is_email' ) ) !== count( $addresses ) || '' === $config['mail_subject'] || '' === $config['mail_body'] || ( '' !== $config['mail_reply_to'] && 'email' !== ( $fields[ $config['mail_reply_to'] ]['type'] ?? '' ) ) ) {
				return new WP_Error( 'config', __( 'Controlla destinatari, oggetto, messaggio e campo Reply-To della notifica email.', 'easycontact' ) );
			}
			$config['mail_to'] = implode( ',', $addresses );
		}
		if ( $config['mailerlite_enabled'] && ( '' === Settings::get()['mailerlite_token'] || 'email' !== ( $fields[ $config['mailerlite_email'] ]['type'] ?? '' ) || ( '' !== $config['mailerlite_name'] && ! isset( $fields[ $config['mailerlite_name'] ] ) ) || ! in_array( $fields[ $config['mailerlite_consent'] ]['type'] ?? '', array( 'checkbox', 'acceptance' ), true ) || ! preg_match( '/^\d{1,30}(?:\s*,\s*\d{1,30}){0,9}$/D', $config['mailerlite_groups'] ) ) ) {
			return new WP_Error( 'config', __( 'MailerLite richiede token, campo email, consenso newsletter e ID di almeno un gruppo.', 'easycontact' ) );
		}
		if ( ( $config['slack_webhook'] && ! Delivery::valid_slack_url( $config['slack_webhook'] ) ) || ( $config['slack_enabled'] && ! $config['slack_webhook'] ) ) {
			return new WP_Error( 'config', __( 'Inserisci un webhook HTTPS valido di hooks.slack.com.', 'easycontact' ) );
		}
		$slack_fields = array_filter( array_map( 'trim', explode( ',', $config['slack_fields'] ) ) );
		if ( $config['slack_enabled'] && ( ! $slack_fields || array_diff( $slack_fields, array_keys( $fields ) ) ) ) {
			return new WP_Error( 'config', __( 'Slack richiede almeno un campo esistente da includere.', 'easycontact' ) );
		}
		$config['slack_fields'] = implode( ',', array_unique( $slack_fields ) );
		$settings               = Settings::get();
		if ( $config['captcha'] && ( ! $settings['turnstile_site_key'] || ! $settings['turnstile_secret'] ) ) {
			return new WP_Error( 'config', __( 'Configura entrambe le chiavi Turnstile prima di attivare il CAPTCHA.', 'easycontact' ) );
		}
		if ( ! $config['mail_enabled'] && ! $config['mailerlite_enabled'] && ! $config['slack_enabled'] && ! Entries::stores_entries( $config ) ) {
			return new WP_Error( 'config', __( 'Attiva almeno una destinazione o l’archivio degli invii.', 'easycontact' ) );
		}
		return $config;
	}

	/**
	 * Store a short-lived notice for the current administrator.
	 *
	 * @param int      $id Native WordPress post ID.
	 * @param WP_Error $error Validated error without credentials or response bodies.
	 * @return void
	 */
	private static function error( int $id, WP_Error $error ): void {
		set_transient( 'easycontact_admin_error_' . get_current_user_id() . '_' . $id, $error->get_error_message(), MINUTE_IN_SECONDS );
	}

	/**
	 * Display the current administrator’s pending editor error.
	 *
	 * @return void
	 */
	public static function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads only the current administrator's own expiring notice; does not change form data.
		$id    = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$key   = 'easycontact_admin_error_' . get_current_user_id() . '_' . $id;
		$error = get_transient( $key );
		if ( $error && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'La definizione del modulo non è stata aggiornata: ', 'easycontact' ) . esc_html( $error ) . '</p></div>';
			delete_transient( $key );
		}
	}

	/**
	 * Render an authenticated saved preview without sending data.
	 *
	 * @return void
	 */
	public static function preview(): void {
		$id = isset( $_GET['id'] ) && is_scalar( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! $id || Plugin::FORM !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Anteprima non disponibile.', 'easycontact' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'easycontact_preview_' . $id );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		Renderer::preview( $id );
		exit;
	}
}
