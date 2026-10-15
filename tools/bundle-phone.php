<?php
/**
 * Rebuild the isolated phone library from an installed, pinned upstream package.
 *
 * Usage and sources: THIRD-PARTY.md. Development-only file, never shipped.
 *
 * @package EasyFormly
 */

if ( 'cli' !== PHP_SAPI || empty( $argv[1] ) ) {
	exit( "Usage: php tools/bundle-phone.php /path/to/giggsey/libphonenumber-for-php-lite\n" );
}
$source = realpath( $argv[1] );
if ( ! $source || ! is_file( $source . '/src/PhoneNumberUtil.php' ) ) {
	exit( "Missing upstream source.\n" );
}
$root     = dirname( __DIR__ );
$target   = $root . '/includes/vendor/phone';
$prefix   = 'EasyFormly\\Vendor\\Phone';
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( $file->getExtension() !== 'php' ) {
		continue;
	}
	$relative = substr( $file->getPathname(), strlen( $source . '/src/' ) );
	$output   = '';
	$code     = file_get_contents( $file->getPathname() );
	if ( false === $code ) {
		exit( "Cannot read the upstream sources.\n" );
	}
	foreach ( token_get_all( $code ) as $token ) {
		if ( ! is_array( $token ) ) {
			$output .= $token;
			continue;
		}
		[ $kind, $value ] = $token;
		if ( in_array( $kind, array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
			$value = preg_replace( '/^(\\\\?)libphonenumber(?=\\\\|$)/', '$1' . str_replace( '\\', '\\\\', $prefix ), $value );
		} elseif ( T_CONSTANT_ENCAPSED_STRING === $kind && str_contains( $value, 'libphonenumber' ) ) {
			$value = str_replace( 'libphonenumber', str_replace( '\\', '\\\\', $prefix ), $value );
		}
		$output .= $value;
	}
	$destination = $target . '/' . $relative;
	if ( ! is_dir( dirname( $destination ) ) ) {
		mkdir( dirname( $destination ), 0755, true );
	}
	file_put_contents( $destination, $output );
}
copy( $source . '/LICENSE.txt', $target . '/LICENSE.txt' );
$polyfill = realpath( $source . '/../../symfony/polyfill-mbstring' );
if ( ! $polyfill ) {
	exit( "Missing mbstring polyfill. Install the locked Composer dependencies first.\n" );
}
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $polyfill, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( $file->getExtension() !== 'php' && $file->getFilename() !== 'LICENSE' ) {
		continue;
	}
	$relative = substr( $file->getPathname(), strlen( $polyfill . '/' ) );
	$output   = '';
	$code     = file_get_contents( $file->getPathname() );
	if ( false === $code ) {
		exit( "Cannot read the upstream sources.\n" );
	}
	foreach ( token_get_all( $code ) as $token ) {
		if ( ! is_array( $token ) ) {
			$output .= $token;
			continue;
		}
		[ $kind, $value ] = $token;
		if ( in_array( $kind, array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
			$value = str_replace( 'Symfony\\Polyfill\\Mbstring', 'EasyFormly\\Vendor\\Mbstring', $value );
		}
		$output .= $value;
	}
	$destination = $root . '/includes/vendor/mbstring/' . $relative;
	if ( ! is_dir( dirname( $destination ) ) ) {
		mkdir( dirname( $destination ), 0755, true );
	}
	file_put_contents( $destination, $output );
}
// Read the regions from the copy just written, so the bundled library is also loaded once.
spl_autoload_register(
	static function ( string $class_name ) use ( $target ): void {
		$prefix = 'EasyFormly\\Vendor\\Phone\\';
		if ( str_starts_with( $class_name, $prefix ) ) {
			require_once $target . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		}
	}
);
$phone     = \EasyFormly\Vendor\Phone\PhoneNumberUtil::getInstance();
$countries = array();
foreach ( $phone->getSupportedRegions() as $region ) {
	$countries[ $region ] = array(
		'name' => locale_get_display_region( 'und_' . $region, 'en' ),
		'dial' => $phone->getCountryCodeForRegion( $region ),
	);
}
ksort( $countries );
if ( ! is_dir( $root . '/includes/data' ) ) {
	mkdir( $root . '/includes/data', 0755, true );
}
file_put_contents( $root . '/includes/data/countries.json', json_encode( $countries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
echo count( $countries ) . " regions bundled.\n";
