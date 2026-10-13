<?php
/**
 * Delivery outcomes shown in the admin: form list, form options and entry screen.
 *
 * @package EasyContact
 */

namespace EasyContact\Delivery\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Localized outcome per destination, without provider payloads.
 */
final class Outcomes {
	/**
	 * Render localized delivery outcomes without provider payloads.
	 *
	 * @param array<string, string> $outcomes Status codes keyed by destination.
	 * @return void
	 */
	public static function render( array $outcomes ): void {
		$names = array(
			'mail'       => 'Email',
			'mailerlite' => 'MailerLite',
			'slack'      => 'Slack',
			'archive'    => __( 'Archive', 'easycontact' ),
		);
		echo '<ul>';
		foreach ( $outcomes as $adapter => $outcome ) {
			if ( 'sent' === $outcome ) {
				$label = 'mail' === $adapter ? __( 'Accepted by the mail service', 'easycontact' ) : __( 'Sent', 'easycontact' );
			} elseif ( 'saved' === $outcome ) {
				$label = __( 'Saved', 'easycontact' );
			} elseif ( 'held' === $outcome ) {
				$label = __( 'Delivery held pending approval', 'easycontact' );
			} elseif ( 'disabled' === $outcome ) {
				$label = __( 'Destination disabled', 'easycontact' );
			} elseif ( 'skipped' === $outcome ) {
				$label = __( 'Not sent: consent not selected', 'easycontact' );
			} elseif ( str_ends_with( $outcome, '_uncertain' ) ) {
				$label = __( 'Outcome not confirmed; check the destination before sending again', 'easycontact' );
			} elseif ( preg_match( '/_http_(\d+)$/D', $outcome, $match ) ) {
				/* translators: %s: HTTP response code from an external integration. */
				$label = sprintf( __( 'Service error (HTTP %s)', 'easycontact' ), $match[1] );
			} else {
				$label = __( 'Delivery failed; check the settings', 'easycontact' );
			}
			echo '<li><strong>' . esc_html( $names[ $adapter ] ?? $adapter ) . '</strong>: ' . esc_html( $label ) . '</li>';
		}
		echo '</ul>';
	}
}
