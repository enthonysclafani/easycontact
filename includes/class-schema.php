<?php
/**
 * Parser, conditional rules and server-side validation.
 *
 * @package EasyContact
 */

namespace EasyContact;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Parse and validate the restricted form language.
 */
final class Schema {
	public const TYPES     = array( 'text', 'email', 'textarea', 'select', 'number', 'url', 'tel', 'checkbox', 'acceptance' );
	public const OPERATORS = array( 'equals', 'not_equals', 'contains', 'empty', 'not_empty', 'greater', 'less' );

	/**
	 * Parse our small tag language. It intentionally does not execute shortcodes or HTML.
	 *
	 * @param string $template Restricted tag or email template.
	 * @return array|WP_Error Result of this operation.
	 */
	public static function parse( string $template ) {
		if ( strlen( $template ) > 20000 ) {
			return new WP_Error( 'template', __( 'Il modulo è troppo lungo.', 'easycontact' ) );
		}
		preg_match_all( '/\[([^\[\]]*)\]/u', $template, $matches, PREG_OFFSET_CAPTURE );
		$offset        = 0;
		$fields        = array();
		$items         = array();
		$submit        = __( 'Invia', 'easycontact' );
		$has_submit    = false;
		$previous_type = '';
		foreach ( $matches[0] as $index => $match ) {
			$spacing = substr( $template, $offset, $match[1] - $offset );
			$joined  = 1 === preg_match( '/^[ \t]*\|[ \t]*$/D', $spacing );
			if ( '' !== trim( $spacing ) && ! $joined ) {
				return new WP_Error( 'template', __( 'Usa i tag documentati e | per affiancare i campi sulla stessa riga; testo libero e HTML non sono supportati.', 'easycontact' ) );
			}
			$offset = $match[1] + strlen( $match[0] );
			$tokens = self::tokens( $matches[1][ $index ][0] );
			if ( is_wp_error( $tokens ) || empty( $tokens ) || $tokens[0]['quoted'] ) {
				return new WP_Error( 'template', __( 'Un tag non è valido. Controlla le virgolette.', 'easycontact' ) );
			}
			if ( ! preg_match( '/^([a-z]+)(\*)?$/D', $tokens[0]['value'], $tag ) ) {
				return new WP_Error( 'template', __( 'Il tipo di campo non è valido.', 'easycontact' ) );
			}
			$type     = $tag[1];
			$required = isset( $tag[2] );
			if ( $joined && ( ! in_array( $previous_type, self::TYPES, true ) || ! in_array( $type, self::TYPES, true ) ) ) {
				return new WP_Error( 'template', __( 'Usa | solo tra due campi sulla stessa riga. Note e pulsante devono restare separati.', 'easycontact' ) );
			}
			$previous_type = $type;
			if ( in_array( $type, array( 'note', 'submit' ), true ) ) {
				if ( 2 !== count( $tokens ) || ! $tokens[1]['quoted'] || $required || ( 'submit' === $type && $has_submit ) ) {
					return new WP_Error( 'template', __( 'Note e pulsante richiedono una sola etichetta tra virgolette.', 'easycontact' ) );
				}
				$label = sanitize_text_field( $tokens[1]['value'] );
				if ( 'submit' === $type ) {
					$submit     = $label ? $label : $submit;
					$has_submit = true;
				} else {
					$items[] = array(
						'type'  => 'note',
						'label' => $label,
					);
				}
				continue;
			}
			if ( ! in_array( $type, self::TYPES, true ) || count( $tokens ) < 3 || $tokens[1]['quoted'] || ! $tokens[2]['quoted'] ) {
				return new WP_Error( 'template', __( 'Sintassi: [tipo nome "Etichetta"].', 'easycontact' ) );
			}
			$name = $tokens[1]['value'];
			if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,49}$/D', $name ) || isset( $fields[ $name ] ) || in_array( $name, array( 'form', 'site', 'fields' ), true ) ) {
				return new WP_Error( 'template', __( 'Ogni campo richiede un nome unico in minuscolo. I nomi form, site e fields sono riservati.', 'easycontact' ) );
			}
			$field = array(
				'name'     => $name,
				'type'     => $type,
				'label'    => sanitize_text_field( $tokens[2]['value'] ),
				'required' => $required,
				'options'  => array(),
				'limits'   => array(),
			);
			if ( '' === $field['label'] || strlen( $field['label'] ) > 500 ) {
				return new WP_Error( 'template', __( 'Inserisci un’etichetta breve per ogni campo.', 'easycontact' ) );
			}
			foreach ( array_slice( $tokens, 3 ) as $token ) {
				if ( 'select' === $type && $token['quoted'] ) {
					$option = sanitize_text_field( $token['value'] );
					if ( '' !== $option && strlen( $option ) <= 500 ) {
						$field['options'][] = $option;
					} else {
						return new WP_Error( 'template', __( 'Le opzioni della selezione devono avere un testo breve.', 'easycontact' ) );
					}
				} elseif ( 'number' === $type && ! $token['quoted'] && preg_match( '/^(min|max|step):(-?[0-9]+(?:\.[0-9]+)?)$/D', $token['value'], $limit ) ) {
					$number = (float) $limit[2];
					if ( ! is_finite( $number ) || ( 'step' === $limit[1] && $number <= 0 ) ) {
						return new WP_Error( 'template', __( 'I limiti numerici non sono validi.', 'easycontact' ) );
					}
					$field['limits'][ $limit[1] ] = $number;
				} elseif ( 'tel' === $type && ! $token['quoted'] && preg_match( '/^country:([A-Za-z]{2})$/D', $token['value'], $country ) && Phone::valid_country( strtoupper( $country[1] ) ) ) {
					$field['phone_country'] = strtoupper( $country[1] );
				} elseif ( 'tel' === $type && ! $token['quoted'] && in_array( $token['value'], array( 'mobile', 'allow-repeated' ), true ) ) {
					$field[ 'mobile' === $token['value'] ? 'phone_mobile_only' : 'phone_allow_repeated' ] = true;
				} else {
					return new WP_Error( 'template', __( 'Questo tag contiene un’opzione non supportata.', 'easycontact' ) );
				}
			}
			$field['options'] = array_values( array_unique( $field['options'] ) );
			if ( ( 'select' === $type && ( empty( $field['options'] ) || count( $field['options'] ) > 40 ) ) || ( isset( $field['limits']['min'], $field['limits']['max'] ) && $field['limits']['min'] > $field['limits']['max'] ) ) {
				return new WP_Error( 'template', __( 'Controlla le opzioni della selezione o i limiti numerici.', 'easycontact' ) );
			}
			$fields[ $name ] = $field;
			if ( $joined ) {
				$row = array_pop( $items );
				if ( 'field' === $row['type'] ) {
					$row = array(
						'type'  => 'row',
						'names' => array( $row['name'] ),
					);
				}
				$row['names'][] = $name;
				$items[]        = $row;
			} else {
				$items[] = array(
					'type' => 'field',
					'name' => $name,
				);
			}
		}
		if ( '' !== trim( substr( $template, $offset ) ) || empty( $fields ) || count( $fields ) > 40 ) {
			return new WP_Error( 'template', __( 'Inserisci da 1 a 40 campi validi, usando solo i tag documentati.', 'easycontact' ) );
		}
		return array(
			'fields' => $fields,
			'items'  => $items,
			'submit' => $submit,
		);
	}

	/**
	 * Tokenize quoted tag arguments and reject malformed quoting.
	 *
	 * @param string $input Untrusted input to validate.
	 * @return array|WP_Error Result of this operation.
	 */
	private static function tokens( string $input ) {
		$tokens = array();
		$offset = 0;
		$length = strlen( $input );
		while ( $offset < $length && '' !== trim( substr( $input, $offset ) ) ) {
			if ( ! preg_match( '/\G\s*("(?:[^"\\\\]|\\\\.)*"|[^\s"]+)(?=\s|$)/u', $input, $match, 0, $offset ) ) {
				return new WP_Error( 'tokens', 'Invalid quotes.' );
			}
			$raw      = $match[1];
			$quoted   = str_starts_with( $raw, '"' );
			$tokens[] = array(
				'value'  => $quoted ? str_replace( array( '\\"', '\\\\' ), array( '"', '\\' ), substr( $raw, 1, -1 ) ) : $raw,
				'quoted' => $quoted,
			);
			$offset  += strlen( $match[0] );
		}
		return $tokens;
	}

	/**
	 * Validate a directed acyclic graph so both browser and server can evaluate it identically.
	 *
	 * @param array $fields Field definitions keyed by name.
	 * @param array $input Untrusted input to validate.
	 * @return array|WP_Error Result of this operation.
	 */
	public static function rules( array $fields, array $input ) {
		if ( count( $input ) > 80 ) {
			return new WP_Error( 'rules', __( 'Sono consentite al massimo 80 regole.', 'easycontact' ) );
		}
		$rules = array();
		foreach ( $input as $rule ) {
			if ( ! is_array( $rule ) ) {
				return new WP_Error( 'rules', __( 'Una regola non è valida.', 'easycontact' ) );
			}
			$target = isset( $rule['target'] ) && is_string( $rule['target'] ) ? $rule['target'] : '';
			$source = isset( $rule['source'] ) && is_string( $rule['source'] ) ? $rule['source'] : '';
			if ( '' === $target && '' === $source ) {
				continue;
			}
			$operator = isset( $rule['operator'] ) && is_string( $rule['operator'] ) ? $rule['operator'] : '';
			$value    = isset( $rule['value'] ) && is_string( $rule['value'] ) ? sanitize_text_field( $rule['value'] ) : '';
			if ( ! isset( $fields[ $target ], $fields[ $source ] ) || $target === $source || ! in_array( $operator, self::OPERATORS, true ) || strlen( $value ) > 500 || ( in_array( $operator, array( 'greater', 'less' ), true ) && ( ! self::numeric( $value ) ) ) ) {
				return new WP_Error( 'rules', __( 'Le regole richiedono campi esistenti e valori coerenti. Un campo non può dipendere da sé stesso.', 'easycontact' ) );
			}
			$rules[] = compact( 'target', 'source', 'operator', 'value' );
		}
		$order   = array();
		$pending = array_keys( $fields );
		while ( $pending ) {
			$progress = false;
			foreach ( $pending as $index => $name ) {
				$dependencies = array_column( array_filter( $rules, static fn( $rule ) => $rule['target'] === $name ), 'source' );
				if ( ! array_diff( $dependencies, $order ) ) {
					$order[] = $name;
					unset( $pending[ $index ] );
					$progress = true;
				}
			}
			if ( ! $progress ) {
				return new WP_Error( 'rules', __( 'Le regole formano un ciclo. Rimuovi una delle dipendenze.', 'easycontact' ) );
			}
		}
		return array(
			'rules' => $rules,
			'order' => $order,
		);
	}

	/**
	 * Calculate visibility in dependency order.
	 *
	 * @param array  $schema Parsed field schema.
	 * @param array  $conditions Validated conditional dependency graph.
	 * @param string $mode Conditional matching mode: all or any.
	 * @param array  $values Sanitized visible field values.
	 * @return array Result of this operation.
	 */
	public static function visible( array $schema, array $conditions, string $mode, array $values ): array {
		$visible = array();
		foreach ( $conditions['order'] as $name ) {
			$results = array();
			foreach ( $conditions['rules'] as $rule ) {
				if ( $rule['target'] !== $name ) {
					continue;
				}
				$source    = $rule['source'];
				$value     = ! empty( $visible[ $source ] ) ? (string) ( $values[ $source ] ?? '' ) : '';
				$results[] = ! empty( $visible[ $source ] ) && self::matches( $value, $rule['operator'], $rule['value'] );
			}
			$visible[ $name ] = ! $results || ( 'any' === $mode ? in_array( true, $results, true ) : ! in_array( false, $results, true ) );
		}
		return $visible;
	}

	/**
	 * Evaluate a supported comparison.
	 *
	 * @param string $actual Actual field value.
	 * @param string $operator Supported comparison operator.
	 * @param string $expected Validated comparison value.
	 * @return bool Result of this operation.
	 */
	public static function matches( string $actual, string $operator, string $expected ): bool {
		return match ( $operator ) {
			'equals' => $actual === $expected,
			'not_equals' => $actual !== $expected,
			'contains' => str_contains( $actual, $expected ),
			'empty' => '' === $actual,
			'not_empty' => '' !== $actual,
			'greater' => self::numeric( $actual ) && (float) $actual > (float) $expected,
			'less' => self::numeric( $actual ) && (float) $actual < (float) $expected,
			default => false,
		};
	}

	/**
	 * Validate visible values and discard hidden submitted values.
	 *
	 * @param array $definition Validated form definition.
	 * @param array $input Untrusted input to validate.
	 * @return array Result of this operation.
	 */
	public static function validate( array $definition, array $input, array $countries = array(), bool $archived = false ): array {
		$values     = array();
		$errors     = array();
		$invalid    = array();
		$comparison = array();
		foreach ( $definition['schema']['fields'] as $name => $field ) {
			$raw = $input[ $name ] ?? '';
			if ( ! is_string( $raw ) || mb_strlen( $raw, 'UTF-8' ) > ( 'textarea' === $field['type'] ? 10000 : 512 ) ) {
				$invalid[ $name ] = true;
				$raw              = '';
			}
			$comparison[ $name ] = trim( $raw );
			$values[ $name ]     = 'textarea' === $field['type'] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		}
		$visible = self::visible( $definition['schema'], $definition['conditions'], $definition['config']['conditions_match'], $comparison );
		foreach ( $definition['schema']['fields'] as $name => $field ) {
			if ( ! $visible[ $name ] ) {
				unset( $values[ $name ] ); // Hidden forged values never reach mail, storage or integrations.
				continue;
			}
			$value = $values[ $name ];
			if ( isset( $invalid[ $name ] ) || ( $field['required'] && '' === $value ) ) {
				$errors[ $name ] = __( 'Controlla questo campo.', 'easycontact' );
				continue;
			}
			if ( '' === $value ) {
				continue;
			}
			if ( 'email' === $field['type'] ) {
				$email = AntiSpam::email( $comparison[ $name ] );
				if ( false === $email ) {
					$errors[ $name ] = __( 'Inserisci un indirizzo email valido.', 'easycontact' );
				} elseif ( AntiSpam::enabled( $definition['config'], 'email_dns_check' ) && 'invalid' === AntiSpam::mail_domain( explode( '@', $email, 2 )[1] ) ) {
					$errors[ $name ] = __( 'Il dominio email non risulta in grado di ricevere posta. Controlla l’indirizzo.', 'easycontact' );
				} else {
					$values[ $name ] = $email;
				}
				continue;
			}
			if ( 'tel' === $field['type'] ) {
				$country = $countries[ $name ] ?? Phone::default_country( $definition['config'], $field );
				$phone = is_string( $country ) ? Phone::normalize( $comparison[ $name ], $country, ! empty( $field['phone_mobile_only'] ) ) : new \WP_Error( 'phone' );
				if ( is_wp_error( $phone ) ) {
					$errors[ $name ] = __( 'Controlla il numero e il paese selezionato.', 'easycontact' );
				} elseif ( ! $archived && AntiSpam::level( $definition['config'] ) >= 2 && ! AntiSpam::enabled( $definition['config'], 'antispam_observe' ) && empty( $field['phone_allow_repeated'] ) && Phone::repetitive( $phone ) ) {
					$errors[ $name ] = __( 'Il numero contiene una sequenza interamente ripetitiva. Controlla il recapito.', 'easycontact' );
				} else {
					$values[ $name ] = $phone;
				}
				continue;
			}
			$valid = match ( $field['type'] ) {
				'url' => false !== filter_var( $value, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ),
				'select' => in_array( $value, $field['options'], true ),
				'checkbox', 'acceptance' => '1' === $value,
				'number' => self::number_valid( $value, $field['limits'] ),
				default => true,
			};
			if ( ! $valid ) {
				$errors[ $name ] = __( 'Inserisci un valore valido.', 'easycontact' );
			}
		}
		return compact( 'values', 'visible', 'errors' );
	}

	/**
	 * Recognize the same finite decimal and exponent syntax used by the browser.
	 *
	 * @param string $value Validated plugin-specific value.
	 * @return bool Result of this operation.
	 */
	private static function numeric( string $value ): bool {
		return 1 === preg_match( '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', trim( $value ) ) && is_finite( (float) $value );
	}

	/**
	 * Validate a finite number against bounds and step.
	 *
	 * @param string $value Validated plugin-specific value.
	 * @param array  $limits Finite number constraints.
	 * @return bool Result of this operation.
	 */
	private static function number_valid( string $value, array $limits ): bool {
		if ( ! self::numeric( $value ) ) {
			return false;
		}
		$number = (float) $value;
		if ( ( isset( $limits['min'] ) && $number < $limits['min'] ) || ( isset( $limits['max'] ) && $number > $limits['max'] ) ) {
			return false;
		}
		if ( isset( $limits['step'] ) ) {
			$steps = ( $number - ( $limits['min'] ?? 0 ) ) / $limits['step'];
			return abs( $steps - round( $steps ) ) < 0.0000001;
		}
		return true;
	}
}
