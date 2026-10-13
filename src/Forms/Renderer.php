<?php
/**
 * Public form rendering: shortcode, accessible fields and the frontend assets, loaded only with a form.
 *
 * @package EasyContact
 */

namespace EasyContact\Forms;

use EasyContact\Frontend;
use EasyContact\Phone\Phone;
use EasyContact\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Escaped native HTML for one form, with its assets enqueued at render time.
 */
final class Renderer {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_shortcode( 'easycontact', array( self::class, 'shortcode' ) );
	}

	/**
	 * Manage plugin-specific native WordPress data.
	 *
	 * @param array|string $attributes Supported attributes.
	 * @return string Result of this operation.
	 */
	public static function shortcode( $attributes ): string {
		$attributes = shortcode_atts( array( 'id' => 0 ), $attributes, 'easycontact' );
		$id         = absint( $attributes['id'] );
		if ( ! Frontend::available( $id ) ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'Pubblica un modulo valido prima di usare lo shortcode.', 'easycontact' ) . '</p>' : '';
		}
		return self::render( $id, Forms::definition( $id ) );
	}

	/**
	 * Render escaped native HTML and enqueue assets only for pages containing a form.
	 *
	 * @param int   $id Native WordPress post ID.
	 * @param array $definition Validated form definition.
	 * @param bool  $preview Whether to disable submission and integrations.
	 * @return string Result of this operation.
	 */
	public static function render( int $id, array $definition, bool $preview = false ): string {
		$config   = $definition['config'];
		$settings = Settings::get();
		wp_enqueue_style( 'easycontact-frontend', EASYCONTACT_URL . 'assets/frontend.css', array(), EASYCONTACT_VERSION );
		wp_enqueue_script( 'easycontact-frontend', EASYCONTACT_URL . 'assets/frontend.js', array(), EASYCONTACT_VERSION, true );
		if ( $config['captcha'] && ! $preview ) {
			// phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent,WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Turnstile requires its documented vendor-hosted, unversioned widget, explicitly enabled per form.
			wp_enqueue_script(
				'easycontact-turnstile',
				// phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- Opt-in documented Turnstile SaaS widget, which the provider requires loading from its own host.
				'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=easycontactTurnstileReady',
				array( 'easycontact-frontend' ),
				// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The vendor requires its unversioned hosted widget.
				null,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
		$instance = wp_unique_id( 'easycontact-' . $id . '-' );
		$public   = array(
			'locale'     => str_replace( '_', '-', get_locale() ),
			'conditions' => $definition['conditions'],
			'mode'       => $config['conditions_match'],
			'preview'    => $preview,
			'messages'   => array(
				'sending'            => __( 'Invio in corso…', 'easycontact' ),
				'error'              => __( 'Invio non riuscito. Riprova.', 'easycontact' ),
				'network'            => __( 'Connessione interrotta. Riprova senza ricaricare la pagina per verificare questo invio.', 'easycontact' ),
				'expired'            => __( 'La sessione è scaduta. I campi compilati sono stati mantenuti: riprova.', 'easycontact' ),
				'captcha'            => __( 'Completa la verifica CAPTCHA.', 'easycontact' ),
				'captchaUnavailable' => __( 'La verifica CAPTCHA non è disponibile. Riprova più tardi.', 'easycontact' ),
				'uncertain'          => __( 'Non è stato possibile confermare l’invio. Contatta il sito prima di inviare di nuovo il messaggio.', 'easycontact' ),
			),
		);
		$tokens   = Frontend::issue( $id );
		ob_start();
		if ( did_action( 'wp_head' ) && ! wp_style_is( 'easycontact-frontend', 'done' ) ) {
			wp_print_styles( 'easycontact-frontend' );
		}
		$html   = (string) ob_get_clean();
		$html  .= '<form ' . self::attributes(
			array(
				'class'            => 'easycontact-form',
				'id'               => $instance,
				'method'           => 'post',
				'novalidate'       => true,
				'action'           => admin_url( 'admin-post.php' ),
				'data-easycontact' => wp_json_encode( $public, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ),
				'data-token-url'   => rest_url( 'easycontact/v1/forms/' . $id . '/token' ),
				'data-submit-url'  => rest_url( 'easycontact/v1/forms/' . $id . '/submit' ),
			)
		) . '>';
		$hidden = array(
			'action'     => 'easycontact_submit',
			'form_id'    => $id,
			'nonce'      => $tokens['nonce'],
			'ticket'     => $tokens['ticket'],
			'clock'      => $tokens['clock'],
			'return_url' => get_permalink() ? get_permalink() : home_url( '/' ),
		);
		foreach ( $hidden as $name => $value ) {
			$html .= '<input ' . self::attributes(
				array(
					'type'  => 'hidden',
					'name'  => $name,
					'value' => $value,
				)
			) . '>';
		}
		$html .= '<div class="easycontact-honeypot" aria-hidden="true"><label>' . esc_html__( 'Lascia vuoto questo campo', 'easycontact' ) . '<input type="text" name="honeypot[' . esc_attr( $tokens['honeypot'] ) . ']" data-easycontact-trap="' . esc_attr( $tokens['honeypot'] ) . '" tabindex="-1" autocomplete="off" maxlength="512"></label></div>';
		foreach ( $definition['schema']['items'] as $item ) {
			if ( 'row' === $item['type'] ) {
				$html .= '<div class="easycontact-row">';
				foreach ( $item['names'] as $name ) {
					$html .= self::field( $definition['schema']['fields'][ $name ], $instance, $config );
				}
				$html .= '</div>';
			} else {
				$html .= 'note' === $item['type'] ? '<p class="easycontact-note">' . esc_html( $item['label'] ) . '</p>' : self::field( $definition['schema']['fields'][ $item['name'] ], $instance, $config );
			}
		}
		if ( $config['captcha'] && ! $preview ) {
			$html .= '<div ' . self::attributes(
				array(
					'data-easycontact-captcha' => true,
					'data-sitekey'             => $settings['turnstile_site_key'],
					'data-action'              => 'easycontact_form_' . $id,
				)
			) . '></div><input type="hidden" name="captcha" value=""><noscript><p>' . esc_html__( 'Attiva JavaScript per completare il CAPTCHA e inviare il modulo.', 'easycontact' ) . '</p></noscript>';
		}
		$html   .= '<button ' . self::attributes(
			array(
				'type'     => 'submit',
				'disabled' => $preview,
			)
		) . '>' . esc_html( $definition['schema']['submit'] ) . '</button>';
		$message = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only redirect flag; no data, processing or state change.
		if ( ! $preview && isset( $_GET['easycontact_sent'] ) && is_scalar( $_GET['easycontact_sent'] ) && absint( $_GET['easycontact_sent'] ) === $id ) {
			$message = $config['success_message'];
		}
		if ( ! $preview && isset( $_GET['easycontact_held'] ) && is_scalar( $_GET['easycontact_held'] ) && absint( $_GET['easycontact_held'] ) === $id ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only confirmation.
			$message = __( 'Il messaggio è stato ricevuto ed è in attesa di verifica.', 'easycontact' );
		}
		return $html . '<p class="easycontact-result" role="status" aria-live="polite" tabindex="-1">' . esc_html( $message ) . '</p></form>';
	}

	/**
	 * Render one field with a visible label and a dedicated accessible error description.
	 *
	 * @param array  $field Validated field definition.
	 * @param string $instance Unique rendered form instance.
	 * @param array  $config Form configuration.
	 * @return string Result of this operation.
	 */
	private static function field( array $field, string $instance, array $config ): string {
		$id         = $instance . '-' . $field['name'];
		$check      = in_array( $field['type'], array( 'checkbox', 'acceptance' ), true );
		$attributes = array(
			'id'                       => $id,
			'name'                     => 'fields[' . $field['name'] . ']',
			'aria-describedby'         => $id . '-error',
			'required'                 => $field['required'],
			'data-easycontact-control' => true,
		);
		$label      = '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . ( $field['required'] ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>';
		$html       = '<div class="easycontact-field' . ( $check ? ' easycontact-check' : '' ) . '" data-field="' . esc_attr( $field['name'] ) . '">';
		if ( ! $check ) {
			$html .= $label;
		}
		if ( 'textarea' === $field['type'] ) {
			$html .= '<textarea ' . self::attributes(
				array_merge(
					$attributes,
					array(
						'rows'      => 5,
						'maxlength' => 10000,
					)
				)
			) . '></textarea>';
		} elseif ( 'select' === $field['type'] ) {
			$html .= '<select ' . self::attributes( $attributes ) . '><option value="">' . esc_html__( 'Seleziona…', 'easycontact' ) . '</option>';
			foreach ( $field['options'] as $option ) {
				$html .= '<option ' . self::attributes( array( 'value' => $option ) ) . '>' . esc_html( $option ) . '</option>';
			}
			$html .= '</select>';
		} elseif ( 'tel' === $field['type'] ) {
			$country = Phone::default_country( $config, $field );
			/* translators: %s: telephone field label. */
			$country_label = sprintf( __( 'Paese per %s', 'easycontact' ), $field['label'] );
			$html         .= '<div class="easycontact-phone"><div class="easycontact-phone-country"><input type="search" class="easycontact-country-search" data-easycontact-country-search placeholder="' . esc_attr__( 'Cerca paese…', 'easycontact' ) . '" aria-label="' . esc_attr__( 'Cerca un paese', 'easycontact' ) . '" autocomplete="off" hidden><select id="' . esc_attr( $id . '-country' ) . '" name="countries[' . esc_attr( $field['name'] ) . ']" data-easycontact-country aria-label="' . esc_attr( $country_label ) . '" aria-describedby="' . esc_attr( $id . '-error' ) . '"><option value="">' . esc_html__( 'Seleziona paese', 'easycontact' ) . '</option>';
			$countries     = Phone::countries();
			uasort( $countries, static fn( $first, $second ) => strcasecmp( $first['name'], $second['name'] ) );
			foreach ( $countries as $region => $data ) {
				$html .= '<option value="' . esc_attr( $region ) . '" data-dial="' . (int) $data['dial'] . '" ' . selected( $country, $region, false ) . '>' . esc_html( Phone::country_label( $region ) ) . '</option>';
			}
			$html .= '</select></div><input ' . self::attributes(
				array_merge(
					$attributes,
					array(
						'type'         => 'tel',
						'inputmode'    => 'tel',
						'autocomplete' => 'tel',
						'maxlength'    => 80,
					)
				)
			) . '></div>';
		} else {
			$attributes['type'] = $check ? 'checkbox' : $field['type'];
			if ( $check ) {
				$attributes['value'] = '1';
			} elseif ( 'number' === $field['type'] ) {
				$attributes = array_merge( $attributes, $field['limits'], array( 'step' => $field['limits']['step'] ?? 'any' ) );
			} else {
				$attributes['maxlength'] = 512;
			}
			if ( in_array( $field['type'], array( 'email', 'tel', 'url' ), true ) ) {
				$attributes['autocomplete'] = $field['type'];
			}
			$html .= '<input ' . self::attributes( $attributes ) . '>';
		}
		if ( $check ) {
			$html .= $label;
			if ( 'acceptance' === $field['type'] && get_privacy_policy_url() ) {
				$html .= ' <a href="' . esc_url( get_privacy_policy_url() ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr__( 'Informativa privacy (si apre in una nuova finestra)', 'easycontact' ) . '">' . esc_html__( 'Informativa privacy', 'easycontact' ) . '</a>';
			}
		}
		return $html . '<span class="easycontact-error" id="' . esc_attr( $id . '-error' ) . '"></span></div>';
	}

	/**
	 * Escape attributes assembled only from supported, hardcoded attribute names.
	 *
	 * @param array $attributes Supported attributes.
	 * @return string Result of this operation.
	 */
	private static function attributes( array $attributes ): string {
		$html = array();
		foreach ( $attributes as $name => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}
			$html[] = true === $value ? esc_attr( $name ) : esc_attr( $name ) . '="' . esc_attr( str_replace( '&', '&amp;', (string) $value ) ) . '"';
		}
		return implode( ' ', $html );
	}

	/**
	 * Print the standalone preview document of a saved form, with the frontend assets and without submission.
	 *
	 * @param int $id Form ID, already authorized by the caller.
	 * @return void
	 */
	public static function preview( int $id ): void {
		$html = self::render( $id, Forms::definition( $id, true ), true );
		echo '<!doctype html><html ';
		language_attributes();
		echo '><head><meta charset="' . esc_attr( get_option( 'blog_charset' ) ) . '"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__( 'Anteprima modulo', 'easycontact' ) . '</title>';
		wp_print_styles( array( 'easycontact-frontend' ) );
		echo '</head><body class="easycontact-preview-body"><h1>' . esc_html( get_the_title( $id ) ) . '</h1><p>' . esc_html__( 'Anteprima della versione salvata. L’invio e le integrazioni sono disattivati.', 'easycontact' ) . '</p>';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by self::render().
		wp_print_scripts( array( 'easycontact-frontend' ) );
		echo '</body></html>';
	}
}
