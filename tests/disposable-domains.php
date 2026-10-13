<?php
/** Run through `studio wp eval-file tests/disposable-domains.php` on a local test site. */
use EasyContact\Admin;
use EasyContact\DisposableDomains;
use EasyContact\Plugin;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( DisposableDomains::class ) ) {
	exit( "Use the active plugin in a local WordPress CLI process.\n" );
}
require_once EASYCONTACT_DIR . 'includes/class-admin.php';

$original = get_option( DisposableDomains::OPTION );
$original_user = get_current_user_id();
$original_request = $_REQUEST;
$original_method = $_SERVER['REQUEST_METHOD'] ?? null;
$original_cron = get_option( 'cron' );
$checks = 0;
$calls = 0;
$mode = 'valid';
$broken = 'aggregate';
$check = static function ( bool $condition, string $label ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
};
$base = array_map( static fn( $i ) => 'throwaway-' . $i . '.example.test', range( 1, 1000 ) );
$feeds = array(
	'community' => implode( "\n", array_merge( $base, array( 'MAILINATOR.COM', 'retired.example.test', 'mailinator.com' ) ) ),
	'aggregate' => implode( "\n", array_merge( $base, array( 'mailinator.com', 'retired.example.test', 'only-second.example.test' ) ) ),
);
$http = static function ( $preempt, $args, $url ) use ( &$calls, &$mode, &$broken, &$feeds ) {
	++$calls;
	foreach ( DisposableDomains::sources() as $key => $source ) {
		if ( $url !== $source['url'] ) {
			continue;
		}
		if ( $key === $broken && 'network' === $mode ) {
			return new WP_Error( 'test_network', 'Simulated network failure.' );
		}
		$body = $feeds[ $key ];
		if ( $key === $broken ) {
			$body = match ( $mode ) {
				'html' => '<html><body>Provider unavailable</body></html>',
				'empty' => '',
				'short' => "mailinator.com\nretired.example.test",
				'malformed' => $body . "\nhttps://example.org/path",
				'oversized' => str_repeat( 'a', 8 * 1024 * 1024 ),
				default => $body,
			};
		}
		return array( 'response' => array( 'code' => $key === $broken && 'status' === $mode ? 503 : 200 ), 'headers' => array(), 'body' => $body, 'cookies' => array() );
	}
	return new WP_Error( 'test_external', 'External HTTP disabled in this process.' );
};
$die_handler = static fn() => static function () { throw new RuntimeException( 'denied' ); };
add_filter( 'pre_http_request', $http, 10, 3 );
add_filter( 'wp_die_handler', $die_handler );
$failure = null;
try {
	delete_option( DisposableDomains::OPTION );
	$check( DisposableDomains::status()['count'] > 90000 && ! DisposableDomains::status()['synced'], 'Bundled public snapshot works before first synchronization' );
	$check( DisposableDomains::contains( 'INBOX.MAILINATOR.COM' ) && ! DisposableDomains::contains( 'easycontact-unrelated-mailinator.com' ), 'Bundled domains include case-insensitive parent matches' );
	foreach ( array( 'gmail.com', 'outlook.com', 'proton.me', 'icloud.com', 'yahoo.com' ) as $provider ) {
		$check( ! DisposableDomains::contains( $provider ), 'Established mail providers remain outside the disposable list' );
	}
	$check( 0 === $calls, 'Reading the list and checking email domains perform no HTTP requests' );

	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_REQUEST = array();
	wp_set_current_user( 0 );
	try {
		Admin::sync_disposable_domains();
		throw new RuntimeException( 'Unauthenticated synchronization was allowed' );
	} catch ( RuntimeException $error ) {
		$check( 'denied' === $error->getMessage() && 0 === $calls, 'Unauthenticated synchronization is rejected before HTTP' );
	}
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$check( ! empty( $admins ), 'An administrator exists for authorization checks' );
	wp_set_current_user( $admins[0] );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	try {
		Admin::sync_disposable_domains();
		throw new RuntimeException( 'GET synchronization was allowed' );
	} catch ( RuntimeException $error ) {
		$check( 'denied' === $error->getMessage() && 0 === $calls, 'Synchronization requires POST' );
	}
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try {
		Admin::sync_disposable_domains();
		throw new RuntimeException( 'Synchronization without nonce was allowed' );
	} catch ( RuntimeException $error ) {
		$check( 'denied' === $error->getMessage() && 0 === $calls, 'Synchronization requires a valid administrator nonce' );
	}
	Admin::settings();
	ob_start();
	Admin::settings_page();
	$html = ob_get_clean();
	$check( str_contains( $html, 'Sincronizza lista' ) && str_contains( $html, 'form="easycontact-disposable-sync"' ) && str_contains( $html, 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' ), 'Settings render a separate manual POST form' );
	$check( ! str_contains( $html, 'blocked_email_domains' ) && ! str_contains( $html, 'allowed_email_domains' ) && 0 === $calls, 'Settings remove manual domain fields and perform no background download' );

	$result = DisposableDomains::sync();
	$check( ! is_wp_error( $result ) && 1003 === $result['count'] && 2 === $calls, 'Both public feeds are normalized, combined and deduplicated' );
	$saved = get_option( DisposableDomains::OPTION );
	$check( $result['synced'] && $result['updated_at'] > 0 && 1002 === $result['sources']['community'] && 1003 === $result['sources']['aggregate'], 'Synchronization records its time and source counts' );
	global $wpdb;
	$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", DisposableDomains::OPTION ) );
	$check( in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ), 'Large downloaded lists are not autoloaded' );
	$check( DisposableDomains::contains( 'retired.example.test' ) && DisposableDomains::contains( 'sub.only-second.example.test' ), 'Freshly synchronized domains are immediately available to local checks' );
	foreach ( array( 'network', 'status', 'html', 'empty', 'short', 'malformed', 'oversized' ) as $mode ) {
		$result = DisposableDomains::sync();
		$check( is_wp_error( $result ) && get_option( DisposableDomains::OPTION ) === $saved && DisposableDomains::contains( 'retired.example.test' ), 'A failed or invalid second feed preserves the entire last successful list: ' . $mode );
	}
	$mode = 'network';
	$broken = 'community';
	$before = $calls;
	$check( is_wp_error( DisposableDomains::sync() ) && $before + 1 === $calls && get_option( DisposableDomains::OPTION ) === $saved, 'A failed first feed preserves the previous list without fetching the next' );
	$mode = 'valid';
	$feeds['community'] = "\xEF\xBB\xBF# Public list\r\n" . implode( "\r\n", array_merge( $base, array( 'mailinator.com' ) ) ) . "\r\n";
	$feeds['aggregate'] = implode( "\n", array_merge( $base, array( 'mailinator.com', 'new.example.test' ) ) );
	$result = DisposableDomains::sync();
	$check( ! is_wp_error( $result ) && 1002 === $result['count'], 'A subsequent manual synchronization accepts updated feeds with comments and BOM' );
	$check( ! DisposableDomains::contains( 'retired.example.test' ) && ! DisposableDomains::contains( 'only-second.example.test' ) && DisposableDomains::contains( 'new.example.test' ), 'Provider removals and additions replace the previous snapshot and invalidate local caches' );
	$before = $calls;
	DisposableDomains::contains( 'new.example.test' );
	DisposableDomains::status();
	$check( $calls === $before && get_option( 'cron' ) === $original_cron, 'Using and synchronizing lists creates no cron job or visitor download' );
} catch ( Throwable $error ) {
	$failure = $error->getMessage();
} finally {
	remove_filter( 'pre_http_request', $http, 10 );
	remove_filter( 'wp_die_handler', $die_handler );
	if ( false === $original ) {
		delete_option( DisposableDomains::OPTION );
	} else {
		update_option( DisposableDomains::OPTION, $original, false );
	}
	wp_set_current_user( $original_user );
	$_REQUEST = $original_request;
	if ( null === $original_method ) {
		unset( $_SERVER['REQUEST_METHOD'] );
	} else {
		$_SERVER['REQUEST_METHOD'] = $original_method;
	}
}
if ( null !== $failure ) {
	WP_CLI::error( 'Check ' . $checks . ': ' . $failure );
}
WP_CLI::success( $checks . ' disposable-domain checks passed; HTTP was intercepted and saved data restored.' );
