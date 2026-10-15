<?php
/**
 * The rename from EasyContact to EasyFormly: existing pages and sites keep working (D10).
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\Plugin;

/**
 * Pages keep the 1.0.0 shortcode, and EasyContact 1.0.0 never runs next to EasyFormly.
 */
class RenameTest extends TestCase {

	/**
	 * The new shortcode and the 1.0.0 one print the same form.
	 */
	public function test_both_shortcodes_print_the_form(): void {
		$id = $this->form( $this->definition() );

		$new    = do_shortcode( '[easyformly id="' . $id . '"]' );
		$legacy = do_shortcode( '[easycontact id="' . $id . '"]' );

		$this->assertStringContainsString( '<form', $new );
		$this->assertStringContainsString( '<form', $legacy );
	}

	/**
	 * Activating EasyFormly deactivates EasyContact 1.0.0 on the same site.
	 */
	public function test_activation_deactivates_easycontact(): void {
		update_option( 'active_plugins', array( Plugin::LEGACY_PLUGIN, 'hello.php' ) );

		Plugin::activate();

		$this->assertSame( array( 'hello.php' ), array_values( get_option( 'active_plugins' ) ) );
	}
}
