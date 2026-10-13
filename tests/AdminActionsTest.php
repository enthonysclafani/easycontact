<?php
/**
 * Classic admin forms posted to admin-post.php.
 *
 * @package EasyContact
 */

namespace EasyContact\Tests;

use EasyContact\Forms\Admin\Editor;
use EasyContact\Review\Admin\Screen;
use EasyContact\Review\Review;
use EasyContact\Settings\Admin\Page;
use EasyContact\Submissions\Admin\EntryScreen;
use RuntimeException;

/**
 * Every admin action needs manage_options and a nonce for the same object (CLAUDE.md, "Sicurezza").
 */
class AdminActionsTest extends TestCase {

	/**
	 * Turn wp_die() and the final redirect into exceptions, so a handler stops before exit.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter(
			'wp_die_handler',
			static fn() => static function () {
				throw new RuntimeException( 'denied' );
			}
		);
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . esc_url_raw( $location ) );
			}
		);
	}

	/**
	 * Clear the simulated request.
	 */
	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	/**
	 * Every action registered on admin_post_* is one this test covers, and none is open to visitors.
	 */
	public function test_every_admin_action_is_covered(): void {
		global $wp_filter;

		// Admin screens register their hooks only in wp-admin.
		Editor::boot();
		Screen::boot();
		EntryScreen::boot();
		Page::boot();
		$actions = array();
		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( str_starts_with( $hook, 'admin_post_' ) && str_contains( $hook, 'easycontact' ) && has_action( $hook ) ) {
				$actions[] = $hook;
			}
		}
		sort( $actions );

		$this->assertSame(
			array(
				'admin_post_easycontact_approve',
				'admin_post_easycontact_classify',
				'admin_post_easycontact_preview',
				'admin_post_easycontact_retry',
				// The public form submission without JavaScript, covered by SubmissionTest and RestTest.
				'admin_post_easycontact_submit',
				'admin_post_easycontact_sync_disposable_domains',
				'admin_post_nopriv_easycontact_submit',
			),
			$actions
		);
	}

	/**
	 * Classification changes only with an administrator and the nonce of that entry.
	 */
	public function test_classification_needs_capability_and_nonce(): void {
		$entry = $this->entry_for_review();
		$other = $this->entry_for_review();
		$post  = array(
			'action'                    => 'easycontact_classify',
			'easycontact_review_entry'  => (string) $entry,
			'easycontact_review_status' => 'spam',
		);

		$this->assert_denied( array( Screen::class, 'action' ), $post, 'easycontact_review_' . $entry, 'easycontact_review_nonce', 'editor' );
		$this->assert_denied( array( Screen::class, 'action' ), $post, '', 'easycontact_review_nonce' );
		$this->assert_denied( array( Screen::class, 'action' ), $post, 'easycontact_review_' . $other, 'easycontact_review_nonce' );
		$this->assertSame( 'accepted', Review::category( $entry ), 'Refused requests change nothing' );

		$this->assert_redirect( array( Screen::class, 'action' ), $post, 'easycontact_review_' . $entry, 'easycontact_review_nonce', 'post.php?post=' . $entry . '&action=edit' );
		$this->assertSame( 'spam', Review::category( $entry ) );
	}

	/**
	 * Approval of a held entry needs an administrator and the nonce of that entry.
	 */
	public function test_approval_needs_capability_and_nonce(): void {
		$entry  = $this->entry_for_review( 'filled by bot' );
		$before = $this->calls['mail'];
		$this->assertTrue( Review::held( $entry ), 'Fixture entry is held as spam' );
		$post = array(
			'action'                   => 'easycontact_approve',
			'easycontact_review_entry' => (string) $entry,
		);

		$this->assert_denied( array( Screen::class, 'action' ), $post, 'easycontact_review_' . $entry, 'easycontact_review_nonce', 'editor' );
		$this->assert_denied( array( Screen::class, 'action' ), $post, '', 'easycontact_review_nonce' );
		$this->assertTrue( Review::held( $entry ), 'Refused approvals release nothing' );
		$this->assertSame( $before, $this->calls['mail'], 'Refused approvals deliver nothing' );

		$this->assert_redirect( array( Screen::class, 'action' ), $post, 'easycontact_review_' . $entry, 'easycontact_review_nonce', 'post.php?post=' . $entry . '&action=edit' );
		$this->assertFalse( Review::held( $entry ), 'Approval releases the entry' );
		$this->assertSame( $before + 1, $this->calls['mail'], 'Approval delivers the email' );
	}

	/**
	 * A manual retry needs an administrator and the nonce of that entry.
	 */
	public function test_retry_needs_capability_and_nonce(): void {
		$entry = $this->entry_for_review();
		$post  = array( 'easycontact_retry_entry' => (string) $entry );

		$this->assert_denied( array( EntryScreen::class, 'retry' ), $post, 'easycontact_retry_' . $entry, 'easycontact_retry_nonce', 'editor' );
		$this->assert_denied( array( EntryScreen::class, 'retry' ), $post, '', 'easycontact_retry_nonce' );
		$this->assert_denied( array( EntryScreen::class, 'retry' ), $post, 'easycontact_review_' . $entry, 'easycontact_retry_nonce' );

		$this->assert_redirect( array( EntryScreen::class, 'retry' ), $post, 'easycontact_retry_' . $entry, 'easycontact_retry_nonce', 'post.php?post=' . $entry . '&action=edit' );
	}

	/**
	 * The preview opens only for an administrator with the nonce of that form.
	 */
	public function test_preview_needs_capability_and_nonce(): void {
		$form  = $this->form( $this->definition() );
		$other = $this->form( $this->definition() );
		$get   = array( 'id' => (string) $form );

		$this->assert_denied( array( Editor::class, 'preview' ), $get, 'easycontact_preview_' . $form, '_wpnonce', 'editor', 'GET' );
		$this->assert_denied( array( Editor::class, 'preview' ), $get, '', '_wpnonce', 'administrator', 'GET' );
		$this->assert_denied( array( Editor::class, 'preview' ), $get, 'easycontact_preview_' . $other, '_wpnonce', 'administrator', 'GET' );
		$this->assert_denied( array( Editor::class, 'preview' ), array( 'id' => (string) $this->entry_for_review() ), 'easycontact_preview_' . $form, '_wpnonce', 'administrator', 'GET' );
	}

	/**
	 * The list synchronization needs an administrator, POST and its nonce; DisposableDomainsTest covers the rest.
	 */
	public function test_synchronization_refuses_editors(): void {
		$this->assert_denied( array( Page::class, 'sync_disposable_domains' ), array(), 'easycontact_sync_disposable_domains', '_wpnonce', 'editor' );
	}

	/**
	 * An archived entry from a real submission: accepted, or held as spam when the honeypot is filled.
	 *
	 * @param string $trap Honeypot value.
	 * @return int Entry ID.
	 */
	private function entry_for_review( string $trap = '' ): int {
		$id     = $this->form( $this->definition() );
		$result = $this->submit( $id, $this->input( $id, $this->values(), $trap ) );
		$this->assertNotWPError( $result, 'Fixture submission is accepted' );
		return $this->entry();
	}

	/**
	 * Run a handler as a request and require that it stops with wp_die().
	 *
	 * @param callable $handler Admin action handler.
	 * @param array    $fields  Request fields.
	 * @param string   $nonce   Nonce action to sign; empty for no nonce.
	 * @param string   $field   Nonce field name.
	 * @param string   $role    Role of the current user.
	 * @param string   $method  HTTP method.
	 */
	private function assert_denied( callable $handler, array $fields, string $nonce, string $field, string $role = 'administrator', string $method = 'POST' ): void {
		$this->assertSame( 'denied', $this->run_handler( $handler, $fields, $nonce, $field, $role, $method ), $role . ' with nonce "' . $nonce . '"' );
	}

	/**
	 * Run a handler as an administrator and require the redirect back to the given admin path.
	 *
	 * @param callable $handler Admin action handler.
	 * @param array    $fields  Request fields.
	 * @param string   $nonce   Nonce action to sign.
	 * @param string   $field   Nonce field name.
	 * @param string   $path    Expected admin path.
	 */
	private function assert_redirect( callable $handler, array $fields, string $nonce, string $field, string $path ): void {
		$this->assertSame( 'redirect:' . admin_url( $path ), $this->run_handler( $handler, $fields, $nonce, $field, 'administrator', 'POST' ) );
	}

	/**
	 * Simulate the request and return how the handler stopped.
	 *
	 * @param callable $handler Admin action handler.
	 * @param array    $fields  Request fields.
	 * @param string   $nonce   Nonce action to sign; empty for no nonce.
	 * @param string   $field   Nonce field name.
	 * @param string   $role    Role of the current user.
	 * @param string   $method  HTTP method.
	 * @return string "denied" or "redirect:" and the location.
	 */
	private function run_handler( callable $handler, array $fields, string $nonce, string $field, string $role, string $method ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		if ( '' !== $nonce ) {
			$fields[ $field ] = wp_create_nonce( $nonce );
		}
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = 'GET' === $method ? $fields : array();
		$_POST                     = 'POST' === $method ? $fields : array();
		$_REQUEST                  = $fields;
		try {
			call_user_func( $handler );
		} catch ( RuntimeException $stop ) {
			return $stop->getMessage();
		}
		$this->fail( 'The handler returned without stopping' );
	}
}
