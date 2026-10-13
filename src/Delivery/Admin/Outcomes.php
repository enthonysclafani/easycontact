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
	 * @param array $outcomes Status codes keyed by destination.
	 * @return void
	 */
	public static function render( array $outcomes ): void {
		$names = array(
			'mail'       => 'Email',
			'mailerlite' => 'MailerLite',
			'slack'      => 'Slack',
			'archive'    => __( 'Archivio', 'easycontact' ),
		);
		echo '<ul>';
		foreach ( $outcomes as $adapter => $outcome ) {
			if ( 'sent' === $outcome ) {
				$label = 'mail' === $adapter ? __( 'Accettata dal servizio email', 'easycontact' ) : __( 'Inviato', 'easycontact' );
			} elseif ( 'saved' === $outcome ) {
				$label = __( 'Salvato', 'easycontact' );
			} elseif ( 'held' === $outcome ) {
				$label = __( 'Inoltro sospeso in attesa di approvazione', 'easycontact' );
			} elseif ( 'disabled' === $outcome ) {
				$label = __( 'Destinazione disattivata', 'easycontact' );
			} elseif ( 'skipped' === $outcome ) {
				$label = __( 'Non inviato: consenso non selezionato', 'easycontact' );
			} elseif ( str_ends_with( $outcome, '_uncertain' ) ) {
				$label = __( 'Esito non confermato; verifica la destinazione prima di reinviare', 'easycontact' );
			} elseif ( preg_match( '/_http_(\d+)$/D', $outcome, $match ) ) {
				/* translators: %s: HTTP response code from an external integration. */
				$label = sprintf( __( 'Errore del servizio (HTTP %s)', 'easycontact' ), $match[1] );
			} else {
				$label = __( 'Invio non riuscito; controlla le impostazioni', 'easycontact' );
			}
			echo '<li><strong>' . esc_html( $names[ $adapter ] ?? $adapter ) . '</strong>: ' . esc_html( $label ) . '</li>';
		}
		echo '</ul>';
	}
}
