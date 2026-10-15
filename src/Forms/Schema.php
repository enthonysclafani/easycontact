<?php
/**
 * Parser, conditional rules and server-side validation.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Forms;

use EasyFormly\AntiSpam\AntiSpam;
use EasyFormly\Phone\Phone;
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
	 * @return array{fields: array<string, array<string, mixed>>, items: list<array<string, mixed>>, submit: string}|WP_Error Result of this operation.
	 */
	public static function parse( string $template ) {
		if ( strlen( $template ) > 20000 ) {
			return new WP_Error( 'template', __( 'The form is too long.', 'easyformly' ) );
		}
		preg_match_all( '/\[([^\[\]]*)\]/u', $template, $matches, PREG_OFFSET_CAPTURE );
		$offset        = 0;
		$fields        = array();
		$items         = array();
		$submit        = __( 'Send', 'easyformly' );
		$has_submit    = false;
		$previous_type = '';
		foreach ( $matches[0] as $index => $match ) {
			$spacing = substr( $template, $offset, $match[1] - $offset );
			$joined  = 1 === preg_match( '/^[ \t]*\|[ \t]*$/D', $spacing );
			if ( '' !== trim( $spacing ) && ! $joined ) {
				return new WP_Error( 'template', __( 'Use the documented tags and | to place fields on the same line; free text and HTML are not supported.', 'easyformly' ) );
			}
			$offset = $match[1] + strlen( $match[0] );
			$tokens = self::tokens( $matches[1][ $index ][0] );
			if ( is_wp_error( $tokens ) || empty( $tokens ) || $tokens[0]['quoted'] ) {
				return new WP_Error( 'template', __( 'A tag is not valid. Check the quotes.', 'easyformly' ) );
			}
			if ( ! preg_match( '/^([a-z]+)(\*)?$/D', $tokens[0]['value'], $tag ) ) {
				return new WP_Error( 'template', __( 'The field type is not valid.', 'easyformly' ) );
			}
			$type     = $tag[1];
			$required = isset( $tag[2] );
			if ( $joined && ( ! in_array( $previous_type, self::TYPES, true ) || ! in_array( $type, self::TYPES, true ) ) ) {
				return new WP_Error( 'template', __( 'Use | only between two fields on the same line. Notes and the button must stay on their own line.', 'easyformly' ) );
			}
			$previous_type = $type;
			if ( in_array( $type, array( 'note', 'submit' ), true ) ) {
				if ( 2 !== count( $tokens ) || ! $tokens[1]['quoted'] || $required || ( 'submit' === $type && $has_submit ) ) {
					return new WP_Error( 'template', __( 'Notes and the button need a single label in quotes.', 'easyformly' ) );
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
				return new WP_Error( 'template', __( 'Syntax: [type name "Label"].', 'easyformly' ) );
			}
			$name = $tokens[1]['value'];
			if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,49}$/D', $name ) || isset( $fields[ $name ] ) || in_array( $name, array( 'form', 'site', 'fields' ), true ) ) {
				return new WP_Error( 'template', __( 'Each field needs a unique lowercase name. The names form, site and fields are reserved.', 'easyformly' ) );
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
				return new WP_Error( 'template', __( 'Enter a short label for each field.', 'easyformly' ) );
			}
			foreach ( array_slice( $tokens, 3 ) as $token ) {
				if ( 'select' === $type && $token['quoted'] ) {
					$option = sanitize_text_field( $token['value'] );
					if ( '' !== $option && strlen( $option ) <= 500 ) {
						$field['options'][] = $option;
					} else {
						return new WP_Error( 'template', __( 'Select options must have a short text.', 'easyformly' ) );
					}
				} elseif ( 'number' === $type && ! $token['quoted'] && preg_match( '/^(min|max|step):(-?[0-9]+(?:\.[0-9]+)?)$/D', $token['value'], $limit ) ) {
					$number = (float) $limit[2];
					if ( ! is_finite( $number ) || ( 'step' === $limit[1] && $number <= 0 ) ) {
						return new WP_Error( 'template', __( 'The number limits are not valid.', 'easyformly' ) );
					}
					$field['limits'][ $limit[1] ] = $number;
				} elseif ( 'tel' === $type && ! $token['quoted'] && preg_match( '/^country:([A-Za-z]{2})$/D', $token['value'], $country ) && Phone::valid_country( strtoupper( $country[1] ) ) ) {
					$field['phone_country'] = strtoupper( $country[1] );
				} elseif ( 'tel' === $type && ! $token['quoted'] && in_array( $token['value'], array( 'mobile', 'allow-repeated' ), true ) ) {
					$field[ 'mobile' === $token['value'] ? 'phone_mobile_only' : 'phone_allow_repeated' ] = true;
				} else {
					return new WP_Error( 'template', __( 'This tag contains an unsupported option.', 'easyformly' ) );
				}
			}
			$field['options'] = array_values( array_unique( $field['options'] ) );
			if ( ( 'select' === $type && ( empty( $field['options'] ) || count( $field['options'] ) > 40 ) ) || ( isset( $field['limits']['min'], $field['limits']['max'] ) && $field['limits']['min'] > $field['limits']['max'] ) ) {
				return new WP_Error( 'template', __( 'Check the select options or the number limits.', 'easyformly' ) );
			}
			$fields[ $name ] = $field;
			// A joined field always follows a field or a row, checked above with the previous type.
			$previous = $joined ? array_pop( $items ) : null;
			if ( isset( $previous['names'] ) ) {
				$previous['names'][] = $name;
				$items[]             = $previous;
			} elseif ( isset( $previous['name'] ) ) {
				$items[] = array(
					'type'  => 'row',
					'names' => array( $previous['name'], $name ),
				);
			} else {
				$items[] = array(
					'type' => 'field',
					'name' => $name,
				);
			}
		}
		if ( '' !== trim( substr( $template, $offset ) ) || empty( $fields ) || count( $fields ) > 40 ) {
			return new WP_Error( 'template', __( 'Enter 1 to 40 valid fields, using only the documented tags.', 'easyformly' ) );
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
	 * @return list<array{value: string, quoted: bool}>|WP_Error Result of this operation.
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
	 * @param array<string, array<string, mixed>> $fields Field definitions keyed by name.
	 * @param array<mixed>                        $input Untrusted input to validate.
	 * @return array{rules: list<array{target: string, source: string, operator: string, value: string}>, order: list<string>}|WP_Error Result of this operation.
	 */
	public static function rules( array $fields, array $input ) {
		if ( count( $input ) > 80 ) {
			return new WP_Error( 'rules', __( 'At most 80 rules are allowed.', 'easyformly' ) );
		}
		$rules = array();
		foreach ( $input as $rule ) {
			if ( ! is_array( $rule ) ) {
				return new WP_Error( 'rules', __( 'A rule is not valid.', 'easyformly' ) );
			}
			$target = isset( $rule['target'] ) && is_string( $rule['target'] ) ? $rule['target'] : '';
			$source = isset( $rule['source'] ) && is_string( $rule['source'] ) ? $rule['source'] : '';
			if ( '' === $target && '' === $source ) {
				continue;
			}
			$operator = isset( $rule['operator'] ) && is_string( $rule['operator'] ) ? $rule['operator'] : '';
			$value    = isset( $rule['value'] ) && is_string( $rule['value'] ) ? sanitize_text_field( $rule['value'] ) : '';
			if ( ! isset( $fields[ $target ], $fields[ $source ] ) || $target === $source || ! in_array( $operator, self::OPERATORS, true ) || strlen( $value ) > 500 || ( in_array( $operator, array( 'greater', 'less' ), true ) && ( ! self::numeric( $value ) ) ) ) {
				return new WP_Error( 'rules', __( 'Rules need existing fields and consistent values. A field cannot depend on itself.', 'easyformly' ) );
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
				return new WP_Error( 'rules', __( 'The rules form a loop. Remove one of the dependencies.', 'easyformly' ) );
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
	 * @param array<string, mixed>  $schema Parsed field schema.
	 * @param array<string, mixed>  $conditions Validated conditional dependency graph.
	 * @param string                $mode Conditional matching mode: all or any.
	 * @param array<string, string> $values Sanitized visible field values.
	 * @return array<string, bool> Result of this operation.
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
	 * @param array<string, mixed>  $definition Validated form definition.
	 * @param array<mixed>          $input Untrusted input to validate.
	 * @param array<string, string> $countries Country selected for each telephone field.
	 * @param bool                  $archived Whether the values are re-validated from an archived entry.
	 * @return array{values: array<string, string>, visible: array<string, bool>, errors: array<string, string>} Result of this operation.
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
				$errors[ $name ] = __( 'Check this field.', 'easyformly' );
				continue;
			}
			if ( '' === $value ) {
				continue;
			}
			if ( 'email' === $field['type'] ) {
				$email = AntiSpam::email( $comparison[ $name ] );
				if ( false === $email ) {
					$errors[ $name ] = __( 'Enter a valid email address.', 'easyformly' );
				} elseif ( AntiSpam::enabled( $definition['config'], 'email_dns_check' ) && 'invalid' === AntiSpam::mail_domain( explode( '@', $email, 2 )[1] ) ) {
					$errors[ $name ] = __( 'The email domain does not seem able to receive mail. Check the address.', 'easyformly' );
				} else {
					$values[ $name ] = $email;
				}
				continue;
			}
			if ( 'tel' === $field['type'] ) {
				$country = $countries[ $name ] ?? Phone::default_country( $definition['config'], $field );
				$phone   = is_string( $country ) ? Phone::normalize( $comparison[ $name ], $country, ! empty( $field['phone_mobile_only'] ) ) : new \WP_Error( 'phone' );
				if ( is_wp_error( $phone ) ) {
					$errors[ $name ] = __( 'Check the number and the selected country.', 'easyformly' );
				} elseif ( ! $archived && AntiSpam::level( $definition['config'] ) >= 2 && ! AntiSpam::enabled( $definition['config'], 'antispam_observe' ) && empty( $field['phone_allow_repeated'] ) && Phone::repetitive( $phone ) ) {
					$errors[ $name ] = __( 'The number is a fully repetitive sequence. Check the contact details.', 'easyformly' );
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
				$errors[ $name ] = __( 'Enter a valid value.', 'easyformly' );
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
	 * @param string               $value Validated plugin-specific value.
	 * @param array<string, float> $limits Finite number constraints.
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
