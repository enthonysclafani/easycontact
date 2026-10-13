<?php
/**
 * Rebuild the isolated phone library from an installed, pinned upstream package.
 *
 * Usage and sources: THIRD-PARTY.md. Development-only file, never shipped.
 *
 * @package EasyContact
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
$prefix   = 'EasyContact\\Vendor\\Phone';
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( $file->getExtension() !== 'php' ) {
		continue;
	}
	$relative = substr( $file->getPathname(), strlen( $source . '/src/' ) );
	$output   = '';
	foreach ( token_get_all( file_get_contents( $file->getPathname() ) ) as $token ) {
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
	foreach ( token_get_all( file_get_contents( $file->getPathname() ) ) as $token ) {
		if ( ! is_array( $token ) ) {
			$output .= $token;
			continue;
		}
		[ $kind, $value ] = $token;
		if ( in_array( $kind, array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
			$value = str_replace( 'Symfony\\Polyfill\\Mbstring', 'EasyContact\\Vendor\\Mbstring', $value );
		}
		$output .= $value;
	}
	$destination = $root . '/includes/vendor/mbstring/' . $relative;
	if ( ! is_dir( dirname( $destination ) ) ) {
		mkdir( dirname( $destination ), 0755, true );
	}
	file_put_contents( $destination, $output );
}
require $source . '/../../autoload.php';
$phone     = \libphonenumber\PhoneNumberUtil::getInstance();
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
