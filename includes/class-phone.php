<?php
/** International telephone validation using locally bundled numbering metadata. */
namespace EasyContact;

use EasyContact\Vendor\Phone\PhoneNumberFormat;
use EasyContact\Vendor\Phone\PhoneNumberType;
use EasyContact\Vendor\Phone\PhoneNumberUtil;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Phone {
	/** Load only our isolated upstream namespace, never another plugin's version. */
	public static function boot(): void {
		if ( ! function_exists( 'mb_strtoupper' ) || ! function_exists( 'mb_strtolower' ) ) {
			require_once __DIR__ . '/vendor/mbstring/Mbstring.php';
			require_once __DIR__ . '/vendor/mbstring/bootstrap.php';
		}
		spl_autoload_register( static function ( string $class ): void {
			$prefix = 'EasyContact\\Vendor\\Phone\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/D', $relative ) ) {
				return;
			}
			$path = __DIR__ . '/vendor/phone/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_file( $path ) ) {
				require_once $path;
			}
		} );
	}

	/** ISO region identifiers, human names and calling codes; no remote lookups. */
	public static function countries(): array {
		static $countries;
		if ( null === $countries ) {
			$countries = json_decode( file_get_contents( __DIR__ . '/data/countries.json' ), true );
			$countries = is_array( $countries ) ? $countries : array();
		}
		return $countries;
	}

	public static function valid_country( string $country ): bool {
		return isset( self::countries()[ $country ] );
	}

	/** A locale's territory is a suggestion, and can be overridden at either scope. */
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
		$defaults = array( 'it' => 'IT', 'de' => 'DE', 'fr' => 'FR', 'es' => 'ES', 'ja' => 'JP', 'ko' => 'KR', 'nl' => 'NL', 'pl' => 'PL', 'ru' => 'RU', 'uk' => 'UA', 'tr' => 'TR', 'sv' => 'SE', 'da' => 'DK', 'fi' => 'FI', 'cs' => 'CZ', 'el' => 'GR', 'he' => 'IL', 'id' => 'ID', 'vi' => 'VN', 'th' => 'TH', 'ro' => 'RO', 'hu' => 'HU', 'bg' => 'BG' );
		return $defaults[ strtolower( explode( '_', $locale )[0] ) ] ?? '';
	}

	public static function country_label( string $country ): string {
		$data = self::countries()[ $country ] ?? null;
		if ( ! $data ) {
			return $country;
		}
		$name = function_exists( 'locale_get_display_region' ) ? locale_get_display_region( 'und_' . $country, get_locale() ) : $data['name'];
		return ( $name && $name !== $country ? $name : $data['name'] ) . ' (+' . $data['dial'] . ')';
	}

	/** Validate the whole value before parsing; upstream's lenient text extraction is unsuitable here. */
	public static function normalize( string $value, string $country = '', bool $mobile = false ) {
		$value = strtr( trim( $value ), array( '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
		if ( str_starts_with( $value, '00' ) ) {
			$value = '+' . substr( $value, 2 );
		}
		if ( strlen( $value ) > 80 || ! preg_match( '/^\+?[0-9\s().-]+$/uD', $value ) || ( '' !== $country && ! self::valid_country( $country ) ) ) {
			return self::error();
		}
		try {
			$util = PhoneNumberUtil::getInstance();
			$number = $util->parse( $value, $country ?: null );
			if ( ! $util->isValidNumber( $number ) || $number->hasExtension() || ( $mobile && ! in_array( $util->getNumberType( $number ), array( PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE ), true ) ) ) {
				return self::error();
			}
			return $util->format( $number, PhoneNumberFormat::E164 );
		} catch ( \Throwable $error ) {
			return self::error();
		}
	}

	public static function repetitive( string $value ): bool {
		try {
			$number = PhoneNumberUtil::getInstance()->parse( $value, null );
			return 1 === preg_match( '/^(\d)\1{6,}$/D', (string) $number->getNationalNumber() );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	private static function error(): WP_Error {
		return new WP_Error( 'phone', __( 'Controlla il numero e il paese selezionato.', 'easycontact' ) );
	}
}
