<?php
/**
 * Form definitions: tag template, parsed schema, conditional rules and per-form configuration.
 *
 * @package EasyContact
 */

namespace EasyContact\Forms;

use EasyContact\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Read a form definition with the defaults of its configuration.
 */
final class Forms {
	/**
	 * Register the form meta: private to the plugin, out of REST and of the generic custom-field screens.
	 *
	 * Runs on `init` after the post types. Without `default`, a missing meta still reads as an empty string.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		foreach ( array(
			'_easycontact_definition'    => 'Template, parsed schema, conditional rules and configuration of the form.',
			'_easycontact_last_delivery' => 'Outcome of the latest delivery, shown in the form list.',
		) as $key => $description ) {
			register_post_meta(
				Plugin::FORM,
				$key,
				array(
					'type'          => 'object',
					'description'   => $description,
					'single'        => true,
					'show_in_rest'  => false,
					// Written only by the form editor and by deliveries, never through the generic meta APIs.
					'auth_callback' => '__return_false',
				)
			);
		}
	}

	/**
	 * Return the initial minimal form template.
	 *
	 * @return string Result of this operation.
	 */
	public static function default_template(): string {
		return '[text* nome "Nome"]' . "\n" . '[email* email "Email"]' . "\n" . '[select* motivo "Motivo" "Informazioni" "Preventivo"]' . "\n" . '[number budget "Budget indicativo" min:0 step:1]' . "\n" . '[textarea* messaggio "Messaggio"]' . "\n" . '[checkbox newsletter "Desidero ricevere la newsletter"]' . "\n" . '[acceptance* privacy "Ho letto l’informativa sulla privacy"]' . "\n" . '[submit "Invia messaggio"]';
	}

	/**
	 * Return defaults for a new form.
	 *
	 * @return array Result of this operation.
	 */
	public static function config_defaults(): array {
		return array(
			'mail_enabled'       => true,
			'mail_to'            => get_option( 'admin_email' ),
			'mail_subject'       => __( 'Nuovo messaggio: [form]', 'easycontact' ),
			'mail_reply_to'      => 'email',
			'mail_body'          => '[fields]',
			'store_entries'      => 'inherit',
			'conditions_match'   => 'all',
			'captcha'            => false,
			'antispam_level'     => 'inherit',
			'antispam_observe'   => 'inherit',
			'quarantine_entries' => 'inherit',
			'email_dns_check'    => 'inherit',
			'phone_country'      => '',
			'spam_phrases'       => '',
			'mailerlite_enabled' => false,
			'mailerlite_email'   => 'email',
			'mailerlite_name'    => 'nome',
			'mailerlite_consent' => 'newsletter',
			'mailerlite_groups'  => '',
			'slack_enabled'      => false,
			'slack_webhook'      => '',
			'slack_fields'       => 'nome,email,messaggio',
			'success_message'    => __( 'Grazie, il messaggio è stato acquisito.', 'easycontact' ),
		);
	}

	/**
	 * Read a saved form definition or provide editor defaults.
	 *
	 * @param int  $form_id Published form ID.
	 * @param bool $with_default Validated plugin-specific value.
	 * @return array|null Result of this operation.
	 */
	public static function definition( int $form_id, bool $with_default = false ): ?array {
		$definition = get_post_meta( $form_id, '_easycontact_definition', true );
		if ( is_array( $definition ) && isset( $definition['schema']['fields'], $definition['conditions']['order'], $definition['config'] ) ) {
			$definition['config'] = array_merge( self::config_defaults(), $definition['config'] );
			return $definition;
		}
		if ( ! $with_default ) {
			return null;
		}
		$template   = self::default_template();
		$schema     = Schema::parse( $template );
		$conditions = Schema::rules(
			$schema['fields'],
			array(
				array(
					'target'   => 'budget',
					'source'   => 'motivo',
					'operator' => 'equals',
					'value'    => 'Preventivo',
				),
			)
		);
		return array(
			'template'   => $template,
			'schema'     => $schema,
			'conditions' => $conditions,
			'config'     => self::config_defaults(),
		);
	}
}
