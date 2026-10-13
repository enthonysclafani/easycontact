<?php
/**
 * Rate counters, with and without a persistent object cache.
 *
 * @package EasyContact
 */

namespace EasyContact\Tests;

use EasyContact\State;

/**
 * Counters are bounded, keyed and never store the raw identity.
 */
class RateLimitTest extends TestCase {

	/**
	 * Restore the object cache mode the suite started with.
	 */
	public function tear_down(): void {
		wp_using_ext_object_cache( false );
		parent::tear_down();
	}

	public function test_counter_without_a_persistent_cache_uses_a_transient(): void {
		$this->assertSame( 1, State::count( 'submit_1', '2001:db8::1', 300, 3 ) );
		$this->assertSame( 2, State::count( 'submit_1', '2001:db8::1', 300, 3 ) );
		$this->assertSame( 1, State::count( 'submit_1', '2001:db8::2', 300, 3 ), 'Each identity has its own counter' );
		$this->assertSame( 3, State::count( 'submit_1', '2001:db8::1', 300, 3 ) );
		$this->assertSame( 3, State::count( 'submit_1', '2001:db8::1', 300, 3 ), 'The count stops at the cap' );
		$this->assertStringNotContainsString( '2001:db8', (string) wp_json_encode( wp_load_alloptions() ), 'The address is never stored' );
	}

	public function test_counter_with_a_persistent_cache_writes_nothing_to_the_database(): void {
		global $wpdb;
		wp_using_ext_object_cache( true );
		$queries = $wpdb->num_queries;
		$this->assertSame( 1, State::count( 'submit_1', '2001:db8::1', 300, 3 ) );
		$this->assertSame( 2, State::count( 'submit_1', '2001:db8::1', 300, 3 ) );
		$this->assertSame( 1, State::count( 'submit_1', '2001:db8::2', 300, 3 ), 'Each identity has its own counter' );
		$this->assertSame( 3, State::count( 'submit_1', '2001:db8::1', 300, 3 ) );
		$this->assertSame( 3, State::count( 'submit_1', '2001:db8::1', 300, 3 ), 'The count stops at the cap' );
		$this->assertSame( $queries, $wpdb->num_queries, 'No lease and no transient: zero queries' );
	}

	public function test_rate_limit_refuses_after_the_limit(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8::9';
		for ( $i = 0; $i < 2; $i++ ) {
			$this->assertTrue( State::rate_allowed( 7, 2 ) );
		}
		$this->assertFalse( State::rate_allowed( 7, 2 ), 'The third request in the window is refused' );
		$this->assertTrue( State::rate_allowed( 8, 2 ), 'Other forms have their own limit' );
	}
}
