<?php
/**
 * International telephone validation using locally bundled numbering metadata.
 *
 * @package EasyContact
 */

namespace EasyContact;

use EasyContact\Vendor\Phone\PhoneNumberFormat;
use EasyContact\Vendor\Phone\PhoneNumberType;
use EasyContact\Vendor\Phone\PhoneNumberUtil;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Telephone fields: country list, default country and E.164 normalization.
 */
final class Phone {
	/** Load only our isolated upstream namespace, never another plugin's version. */
	public static function boot(): void {
		if ( ! function_exists( 'mb_strtoupper' ) || ! function_exists( 'mb_strtolower' ) ) {
			require_once EASYCONTACT_DIR . 'includes/vendor/mbstring/Mbstring.php';
			require_once EASYCONTACT_DIR . 'includes/vendor/mbstring/bootstrap.php';
		}
		spl_autoload_register(
			static function ( string $class_name ): void {
				$prefix = 'EasyContact\\Vendor\\Phone\\';
				if ( ! str_starts_with( $class_name, $prefix ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( $prefix ) );
				if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/D', $relative ) ) {
					return;
				}
				$path = EASYCONTACT_DIR . 'includes/vendor/phone/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_file( $path ) ) {
					require_once $path;
				}
			}
		);
	}

	/** ISO region identifiers, human names and calling codes; no remote lookups. */
	public static function countries(): array {
		static $countries;
		if ( null === $countries ) {
			$countries = wp_json_file_decode( EASYCONTACT_DIR . 'includes/data/countries.json', array( 'associative' => true ) );
			$countries = is_array( $countries ) ? $countries : array();
		}
		return $countries;
	}

	/**
	 * Whether the bundled metadata knows the region.
	 *
	 * @param string $country ISO 3166-1 alpha-2 code.
	 */
	public static function valid_country( string $country ): bool {
		return isset( self::countries()[ $country ] );
	}

	/**
	 * A locale's territory is a suggestion, and can be overridden at either scope.
	 *
	 * @param array $config Form configuration.
	 * @param array $field  Field definition.
	 */
	public static function default_country( array $config = array(), array $field = array() ): string {
		foreach ( array( $field['phone_country'] ?? '', $config['phone_country'] ?? '', Plugin::settings()['phone_country'] ) as $country ) {
			if ( self::valid_country( $country ) ) {
				return $country;
			}
		}
		$locale = str_replace( '-', '_', get_locale() );
		if ( preg_match( '/_([A-Z]{2})(?:_|$)/', $locale, $match ) && self::valid_country( $match[1] ) ) {
			return $match[1];
		}
		// WordPress language packs without a territory: these remain editable suggestions.
		$defaults = array(
			'it' => 'IT',
			'de' => 'DE',
			'fr' => 'FR',
			'es' => 'ES',
			'ja' => 'JP',
			'ko' => 'KR',
			'nl' => 'NL',
			'pl' => 'PL',
			'ru' => 'RU',
			'uk' => 'UA',
			'tr' => 'TR',
			'sv' => 'SE',
			'da' => 'DK',
			'fi' => 'FI',
			'cs' => 'CZ',
			'el' => 'GR',
			'he' => 'IL',
			'id' => 'ID',
			'vi' => 'VN',
			'th' => 'TH',
			'ro' => 'RO',
			'hu' => 'HU',
			'bg' => 'BG',
		);
		return $defaults[ strtolower( explode( '_', $locale )[0] ) ] ?? '';
	}

	/**
	 * Localized region name with its calling code.
	 *
	 * @param string $country ISO 3166-1 alpha-2 code.
	 */
	public static function country_label( string $country ): string {
		$data = self::countries()[ $country ] ?? null;
		if ( ! $data ) {
			return $country;
		}
		$name = function_exists( 'locale_get_display_region' ) ? locale_get_display_region( 'und_' . $country, get_locale() ) : $data['name'];
		return ( $name && $name !== $country ? $name : $data['name'] ) . ' (+' . $data['dial'] . ')';
	}

	/**
	 * Validate the whole value before parsing; upstream's lenient text extraction is unsuitable here.
	 *
	 * @param string $value   Number as typed by the visitor.
	 * @param string $country Region used for national numbers; empty to require the international prefix.
	 * @param bool   $mobile  Whether only mobile numbers are accepted.
	 * @return string|WP_Error E.164 number, or the error shown to the visitor.
	 */
	public static function normalize( string $value, string $country = '', bool $mobile = false ) {
		$value = strtr(
			trim( $value ),
			array(
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
			)
		);
		if ( str_starts_with( $value, '00' ) ) {
			$value = '+' . substr( $value, 2 );
		}
		if ( strlen( $value ) > 80 || ! preg_match( '/^\+?[0-9\s().-]+$/uD', $value ) || ( '' !== $country && ! self::valid_country( $country ) ) ) {
			return self::error();
		}
		try {
			$util   = PhoneNumberUtil::getInstance();
			$number = $util->parse( $value, '' !== $country ? $country : null );
			if ( ! $util->isValidNumber( $number ) || $number->hasExtension() || ( $mobile && ! in_array( $util->getNumberType( $number ), array( PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE ), true ) ) ) {
				return self::error();
			}
			return $util->format( $number, PhoneNumberFormat::E164 );
		} catch ( \Throwable $error ) {
			return self::error();
		}
	}

	/**
	 * Whether the national number is one digit repeated, a common filler.
	 *
	 * @param string $value E.164 number.
	 */
	public static function repetitive( string $value ): bool {
		try {
			$number = PhoneNumberUtil::getInstance()->parse( $value, null );
			return 1 === preg_match( '/^(\d)\1{6,}$/D', (string) $number->getNationalNumber() );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * The single message shown for any invalid number.
	 */
	private static function error(): WP_Error {
		return new WP_Error( 'phone', __( 'Controlla il numero e il paese selezionato.', 'easycontact' ) );
	}
}
