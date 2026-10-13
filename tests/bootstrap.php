<?php
/**
 * PHPUnit bootstrap for the WordPress core test suite.
 *
 * Development-only file: it runs in CLI before WordPress loads, so the
 * direct-access guard does not apply.
 *
 * @package EasyContact
 */

$easycontact_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $easycontact_tests_dir ) || '' === $easycontact_tests_dir ) {
	// Default location used by tools/install-wp-tests.sh (local, CI and cloud sessions).
	$easycontact_tests_dir = '/tmp/wordpress-develop/tests/phpunit';
}

$easycontact_polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', is_string( $easycontact_polyfills ) && '' !== $easycontact_polyfills ? $easycontact_polyfills : dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

if ( ! file_exists( $easycontact_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found. Run tools/install-wp-tests.sh or set WP_TESTS_DIR.\n" );
	exit( 1 );
}

// Resolver fixture, defined before the plugin runs: no DNS query leaves the test process.
require_once __DIR__ . '/support/dns.php';

require_once $easycontact_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/easycontact.php';
	}
);

require $easycontact_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/support/class-testcase.php';
