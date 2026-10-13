<?php
/**
 * Public rendering and submission endpoint.
 *
 * @package EasyContact
 */

namespace EasyContact;

use EasyContact\Phone\Phone;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Render accessible controls and validate anonymous submissions.
 */
final class Frontend {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_shortcode( 'easycontact', array( self::class, 'shortcode' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_post_easycontact_submit', array( self::class, 'native_submit' ) );
		add_action( 'admin_post_nopriv_easycontact_submit', array( self::class, 'native_submit' ) );
	}

	/**
	 * Register anonymous form REST routes with validation in their callbacks.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			'easycontact/v1',
			'/forms/(?P<id>\d+)/token',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'rest_token' ),
			)
		);
		register_rest_route(
			'easycontact/v1',
			'/forms/(?P<id>\d+)/submit',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'rest_submit' ),
			)
		);
	}

	/**
	 * Check that a published form has a saved definition.
	 *
	 * @param int $id Native WordPress post ID.
	 * @return bool Result of this operation.
	 */
	private static function available( int $id ): bool {
		$post = get_post( $id );
		return $post && Plugin::FORM === $post->post_type && 'publish' === $post->post_status && null !== Plugin::definition( $id );
	}

	/**
	 * Issue a fresh signed ticket, nonce and UTC expiry.
	 *
	 * @param int    $id Native WordPress post ID.
	 * @param string $clock Signed start time to keep; a new one is issued when it is invalid.
	 * @return array Result of this operation.
	 */
	public static function issue( int $id, string $clock = '' ): array {
		$issued    = time();
		$uuid      = wp_generate_uuid4();
		$signature = hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . $issued . '|' . $uuid . '|2', wp_salt( 'auth' ) );
		$ticket    = $issued . '.' . $uuid . '.2.' . $signature;
		if ( false === self::elapsed( $id, $clock ) ) {
			$clock = $issued . '.' . $uuid . '.' . hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . $issued . '|' . $uuid . '|clock', wp_salt( 'auth' ) );
		}
		return array(
			'nonce'    => wp_create_nonce( 'easycontact_submit_' . get_current_blog_id() . '_' . $id ),
			'ticket'   => $ticket,
			'expires'  => $issued + HOUR_IN_SECONDS,
			'honeypot' => self::honeypot_name( $id, $ticket ),
			'clock'    => $clock,
		);
	}

	/**
	 * Field name of the honeypot, unpredictable per ticket.
	 *
	 * @param int    $id     Form ID.
	 * @param string $ticket Signed ticket.
	 */
	private static function honeypot_name( int $id, string $ticket ): string {
		return 'contact_' . substr( hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . ( explode( '.', $ticket )[1] ?? '' ), wp_salt( 'auth' ) ), 0, 12 );
	}

	/**
	 * A separate signed start time survives ticket refreshes and validation corrections.
	 *
	 * @param int    $id    Form ID.
	 * @param string $clock Signed start time.
	 * @return int|false Seconds since the form was first shown, or false when the clock is invalid.
	 */
	private static function elapsed( int $id, string $clock ) {
		if ( ! preg_match( '/^(\d{1,11})\.([a-f0-9-]{36})\.([a-f0-9]{64})$/D', $clock, $parts ) || (int) $parts[1] > time() + 60 || time() - (int) $parts[1] > DAY_IN_SECONDS ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . $parts[1] . '|' . $parts[2] . '|clock', wp_salt( 'auth' ) );
		return hash_equals( $expected, $parts[3] ) ? max( 0, time() - (int) $parts[1] ) : false;
	}

	/**
	 * Verify a form-bound ticket and its one-hour lifetime.
	 *
	 * @param int    $id Native WordPress post ID.
	 * @param string $ticket Signed submission ticket.
	 * @return bool Result of this operation.
	 */
	private static function valid_ticket( int $id, string $ticket ): bool {
		if ( ! preg_match( '/^(\d{1,11})\.([a-f0-9-]{36})\.2\.([a-f0-9]{64})$/D', $ticket, $parts ) || (int) $parts[1] > time() + 60 || time() - (int) $parts[1] > HOUR_IN_SECONDS ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . $parts[1] . '|' . $parts[2] . '|2', wp_salt( 'auth' ) );
		return hash_equals( $expected, $parts[3] );
	}

	/**
	 * Issue uncached tokens for a published form.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response|WP_Error Result of this operation.
	 */
	public static function rest_token( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! self::available( $id ) ) {
			return new WP_Error( 'unavailable', __( 'Modulo non disponibile.', 'easycontact' ), array( 'status' => 404 ) );
		}
		if ( ! State::rate_allowed( $id, 120, 'token' ) ) {
			return new WP_Error( 'rate', __( 'Troppe richieste ravvicinate. Attendi qualche minuto.', 'easycontact' ), array( 'status' => 429 ) );
		}
		$clock    = $request->get_param( 'clock' );
		$response = new WP_REST_Response( self::issue( $id, is_string( $clock ) ? $clock : '' ) );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $response;
	}

	/**
	 * Bound the REST body and delegate to common submission validation.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return array|WP_Error Result of this operation.
	 */
	public static function rest_submit( WP_REST_Request $request ) {
		if ( strlen( $request->get_body() ) > 50000 ) {
			return new WP_Error( 'size', __( 'Il messaggio è troppo lungo.', 'easycontact' ), array( 'status' => 413 ) );
		}
		return self::submit( (int) $request['id'], (array) $request->get_json_params() );
	}

	/**
	 * Validate availability, nonce, ticket and fields before any delivery.
	 *
	 * @param int   $id Native WordPress post ID.
	 * @param array $input Untrusted input to validate.
	 * @return array|WP_Error Result of this operation.
	 */
	public static function submit( int $id, array $input ) {
		if ( ! self::available( $id ) ) {
			return new WP_Error( 'unavailable', __( 'Modulo non disponibile.', 'easycontact' ), array( 'status' => 404 ) );
		}
		if ( ! State::rate_allowed( $id, 40, 'attempt' ) ) {
			return new WP_Error( 'rate', __( 'Troppi tentativi ravvicinati. Attendi qualche minuto.', 'easycontact' ), array( 'status' => 429 ) );
		}
		$nonce  = is_string( $input['nonce'] ?? null ) ? $input['nonce'] : '';
		$ticket = is_string( $input['ticket'] ?? null ) ? $input['ticket'] : '';
		if ( ! wp_verify_nonce( $nonce, 'easycontact_submit_' . get_current_blog_id() . '_' . $id ) || ! self::valid_ticket( $id, $ticket ) ) {
			return new WP_Error( 'expired', __( 'La sessione del modulo è scaduta. Ricarica la pagina.', 'easycontact' ), array( 'status' => 403 ) );
		}
		if ( ! is_array( $input['fields'] ?? null ) || count( $input['fields'] ) > 80 || strlen( (string) wp_json_encode( $input['fields'] ) ) > 40000 || ( isset( $input['countries'] ) && ( ! is_array( $input['countries'] ) || count( $input['countries'] ) > 40 || strlen( (string) wp_json_encode( $input['countries'] ) ) > 5000 ) ) ) {
			return new WP_Error( 'invalid', __( 'Il modulo non è valido.', 'easycontact' ), array( 'status' => 422 ) );
		}
		$countries = $input['countries'] ?? array();
		foreach ( $countries as $country ) {
			if ( ! is_string( $country ) || strlen( $country ) > 2 ) {
				return new WP_Error( 'invalid', __( 'Il paese selezionato non è valido.', 'easycontact' ), array( 'status' => 422 ) );
			}
		}
		$name    = self::honeypot_name( $id, $ticket );
		$traps   = $input['honeypot'] ?? null;
		$elapsed = self::elapsed( $id, is_string( $input['clock'] ?? null ) ? $input['clock'] : '' );
		if ( ! is_array( $traps ) || 1 !== count( $traps ) || ! isset( $traps[ $name ] ) || ! is_string( $traps[ $name ] ) || strlen( $traps[ $name ] ) > 512 || false === $elapsed ) {
			return new WP_Error( 'invalid', __( 'Il modulo non è valido. Ricarica la pagina.', 'easycontact' ), array( 'status' => 422 ) );
		}
		$context = array(
			'honeypot' => '' !== $traps[ $name ],
			'elapsed'  => $elapsed,
		);
		$fields  = $input['fields'];
		ksort( $fields, SORT_STRING );
		ksort( $countries, SORT_STRING );
		$encoded = wp_json_encode(
			$countries ? array(
				'fields'    => $fields,
				'countries' => $countries,
			) : $fields
		);
		if ( false === $encoded ) {
			return new WP_Error( 'invalid', __( 'Il modulo non è valido.', 'easycontact' ), array( 'status' => 422 ) );
		}
		$input_fingerprint = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
		$receipt           = State::read( substr( hash( 'sha256', $ticket ), 0, 40 ) );
		if ( isset( $receipt['response'], $receipt['input_fingerprint'] ) ) {
			return hash_equals( $receipt['input_fingerprint'], $input_fingerprint ) ? $receipt['response'] : new WP_Error( 'used', __( 'Questo invio è già stato elaborato. Contatta il sito prima di inviare un altro messaggio.', 'easycontact' ), array( 'status' => 409 ) );
		}
		$definition = Plugin::definition( $id );
		$validated  = Schema::validate( $definition, $input['fields'], $countries );
		if ( $validated['errors'] ) {
			return new WP_Error(
				'validation',
				__( 'Controlla i campi evidenziati.', 'easycontact' ),
				array(
					'status' => 422,
					'fields' => $validated['errors'],
				)
			);
		}
		return Submission::process( $id, $definition, $validated['values'], $ticket, is_string( $input['captcha'] ?? null ) ? $input['captcha'] : '', $input_fingerprint, $context );
	}

	/**
	 * Handle the classic POST fallback with the same server validation.
	 *
	 * @return void
	 */
	public static function native_submit(): void {
		// WordPress adds slashes only to classic POSTs, never to REST JSON bodies.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- submit() verifies the nonce and signed ticket before processing any fields or sending data.
		$input  = wp_unslash( $_POST );
		$id     = is_scalar( $input['form_id'] ?? null ) ? absint( $input['form_id'] ) : 0;
		$result = self::submit( $id, is_array( $input ) ? $input : array() );
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Invio del modulo', 'easycontact' ),
				array(
					'response'  => (int) ( $result->get_error_data()['status'] ?? 400 ),
					'back_link' => true,
				)
			);
		}
		$return = is_string( $input['return_url'] ?? null ) ? wp_validate_redirect( $input['return_url'], home_url( '/' ) ) : home_url( '/' );
		wp_safe_redirect( add_query_arg( ! empty( $result['held'] ) ? 'easycontact_held' : 'easycontact_sent', $id, $return ) );
		exit;
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
		if ( ! self::available( $id ) ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'Pubblica un modulo valido prima di usare lo shortcode.', 'easycontact' ) . '</p>' : '';
		}
		return self::render( $id, Plugin::definition( $id ) );
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
		$settings = Plugin::settings();
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
		$tokens   = self::issue( $id );
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
}
