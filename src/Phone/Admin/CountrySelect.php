<?php
/**
 * Country select of the telephone field, shared by the settings page and the form editor.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Phone\Admin;

use EasyFormly\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * Bundled regions sorted by name, with an automatic choice first.
 */
final class CountrySelect {
	/**
	 * Country select with an automatic choice.
	 *
	 * @param string $name      Field name.
	 * @param string $value     Selected country.
	 * @param string $id        Element ID.
	 * @param string $automatic Label of the automatic choice.
	 */
	public static function render( string $name, string $value, string $id, string $automatic ): void {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $automatic ) . '</option>';
		$countries = Phone::countries();
		uasort( $countries, static fn( $first, $second ) => strcasecmp( $first['name'], $second['name'] ) );
		foreach ( $countries as $region => $data ) {
			echo '<option value="' . esc_attr( $region ) . '" ' . selected( $value, $region, false ) . '>' . esc_html( Phone::country_label( $region ) ) . '</option>';
		}
		echo '</select>';
	}
}
