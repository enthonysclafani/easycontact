<?php
/**
 * The tag language of the form editor.
 *
 * @package EasyContact
 */

namespace EasyContact\Tests;

use EasyContact\Forms\Schema;

/**
 * Fields joined with | share a row; notes and the button stay on their own line.
 */
class SchemaTest extends TestCase {

	/**
	 * Joined fields become one row, in order, and a row can hold more than two fields.
	 */
	public function test_joined_fields_form_rows(): void {
		$schema = Schema::parse( '[text a "A"] | [text b "B"] | [email c "C"]' . "\n" . '[note "Note"]' . "\n" . '[text d "D"]' . "\n" . '[text e "E"] | [text f "F"]' );

		$this->assertNotWPError( $schema );
		$this->assertSame(
			array(
				array(
					'type'  => 'row',
					'names' => array( 'a', 'b', 'c' ),
				),
				array(
					'type'  => 'note',
					'label' => 'Note',
				),
				array(
					'type' => 'field',
					'name' => 'd',
				),
				array(
					'type'  => 'row',
					'names' => array( 'e', 'f' ),
				),
			),
			$schema['items']
		);
		$this->assertWPError( Schema::parse( '[note "Note"] | [text a "A"]' ), 'A note cannot share a row' );
		$this->assertWPError( Schema::parse( '| [text a "A"]' ), 'A row needs a field before the bar' );
	}
}
