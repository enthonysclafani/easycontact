<?php
/**
 * WordPress personal-data tools and disclosure.
 *
 * @package EasyContact
 */

namespace EasyContact\Privacy;

use EasyContact\AntiSpam\AntiSpam;
use EasyContact\Plugin;
use EasyContact\Review\Review;
use EasyContact\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Integrate local archives with WordPress personal data tools.
 */
final class Privacy {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'admin_init', array( self::class, 'policy' ) );
	}

	/**
	 * Register the native personal data exporter.
	 *
	 * @param array $items Registered WordPress privacy callbacks.
	 * @return array Result of this operation.
	 */
	public static function exporters( array $items ): array {
		$items['easycontact'] = array(
			'exporter_friendly_name' => 'EasyContact',
			'callback'               => array( self::class, 'export' ),
		);
		return $items;
	}

	/**
	 * Register the native personal data eraser.
	 *
	 * @param array $items Registered WordPress privacy callbacks.
	 * @return array Result of this operation.
	 */
	public static function erasers( array $items ): array {
		$items['easycontact'] = array(
			'eraser_friendly_name' => 'EasyContact',
			'callback'             => array( self::class, 'erase' ),
		);
		return $items;
	}

	/**
	 * Find a bounded batch of private entries matching an email identity.
	 *
	 * @param string $email Email identity supplied by WordPress privacy tools.
	 * @param int    $page One-based privacy batch page.
	 * @return array Result of this operation.
	 */
	private static function entries( string $email, int $page ): array {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Core privacy tools require email matching; native metadata is queried in batches of 50.
		return get_posts(
			array(
				'post_type'      => Plugin::ENTRY,
				'post_status'    => array( 'private', 'trash', 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 50,
				'paged'          => max( 1, $page ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded native metadata matching for the WordPress personal data tools; no custom tables.
				'meta_key'       => '_easycontact_email',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded native metadata matching for the WordPress personal data tools; no custom tables.
				'meta_value'     => strtolower( $email ),
			)
		);
	}

	/**
	 * Export a page of archived data through WordPress privacy tools.
	 *
	 * @param string $email Email identity supplied by WordPress privacy tools.
	 * @param int    $page One-based privacy batch page.
	 * @return array Result of this operation.
	 */
	public static function export( string $email, int $page = 1 ): array {
		$posts = is_email( $email ) ? self::entries( $email, $page ) : array();
		$data  = array();
		foreach ( $posts as $post ) {
			$values = (array) get_post_meta( $post->ID, '_easycontact_values', true );
			$labels = (array) get_post_meta( $post->ID, '_easycontact_labels', true );
			$fields = array(
				array(
					'name'  => __( 'Data invio', 'easycontact' ),
					'value' => $post->post_date_gmt . ' UTC',
				),
			);
			foreach ( $values as $name => $value ) {
				$fields[] = array(
					'name'  => $labels[ $name ] ?? $name,
					'value' => (string) $value,
				);
			}
			$category = Review::category( $post->ID );
			if ( $category ) {
				$fields[] = array(
					'name'  => __( 'Classificazione anti-spam', 'easycontact' ),
					'value' => Review::labels()[ $category ],
				);
			}
			$assessment = get_post_meta( $post->ID, '_easycontact_antispam', true );
			if ( is_array( $assessment ) ) {
				$fields[] = array(
					'name'  => __( 'Punteggio anti-spam', 'easycontact' ),
					'value' => (string) $assessment['score'] . '/100',
				);
				$reasons  = array_intersect_key( AntiSpam::reason_labels(), array_flip( $assessment['reasons'] ) );
				$fields[] = array(
					'name'  => __( 'Motivi del controllo', 'easycontact' ),
					'value' => implode( '; ', $reasons ),
				);
			}
			$data[] = array(
				'group_id'    => 'easycontact',
				'group_label' => __( 'Invii dei moduli', 'easycontact' ),
				'item_id'     => 'easycontact-entry-' . $post->ID,
				'data'        => $fields,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $posts ) < 50,
		);
	}

	/**
	 * Erase a page without skipping records as the result set shrinks.
	 *
	 * @param string $email Email identity supplied by WordPress privacy tools.
	 * @param int    $page One-based privacy batch page.
	 * @return array Result of this operation.
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$allowed = (bool) Settings::get()['allow_erasure'];
		// Deletions shrink the result set: always consume the first batch, never skip with an offset.
		$posts    = is_email( $email ) ? self::entries( $email, $allowed ? 1 : $page ) : array();
		$removed  = false;
		$retained = false;
		foreach ( $posts as $post ) {
			if ( $allowed && wp_delete_post( $post->ID, true ) ) {
				$removed = true;
			} else {
				$retained = true;
			}
		}
		$messages = array();
		if ( $retained ) {
			$messages[] = __( 'Alcuni invii sono stati conservati secondo le impostazioni del sito o non è stato possibile eliminarli.', 'easycontact' );
		}
		if ( $removed && 1 === $page ) {
			$messages[] = __( 'Le copie locali sono state eliminate. Le eventuali copie in email, MailerLite e Slack vanno gestite nei rispettivi servizi.', 'easycontact' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $posts ) < 50 || ( $allowed && $retained ),
		);
	}

	/**
	 * Provide editable privacy policy guidance in WordPress.
	 *
	 * @return void
	 */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = __( 'I moduli inviano i dati compilati ai destinatari email configurati. Se attivato, l’archivio locale conserva i campi e la data dell’invio per il periodo impostato dall’amministratore. Gli strumenti di esportazione e cancellazione di WordPress possono gestire queste copie tramite gli indirizzi email presenti nell’invio. Il plugin non archivia indirizzi IP o user agent negli invii; usa temporaneamente un’impronta dell’IP, non reversibile senza la chiave del sito, per limitare gli invii ripetuti. Se abilitati per il modulo, MailerLite riceve email, nome e gruppo dell’iscritto dopo la selezione del consenso; Slack riceve solo i campi scelti dall’amministratore; Cloudflare Turnstile verifica il CAPTCHA e può trattare dati del browser. La cancellazione locale non elimina le copie già trasmesse ai servizi esterni. Adattare questo testo alla configurazione effettiva del sito e alle informative dei servizi utilizzati.', 'easycontact' );
		$text .= "\n\n" . __( 'I controlli anti-spam usano regole locali, senza intelligenza artificiale. Contatori temporanei con impronte protette dalla chiave del sito misurano la frequenza di email, telefoni e messaggi simili per un massimo di 30 minuti. La quarantena, se consentita dall’archivio o attivata separatamente, conserva gli invii sospetti, il punteggio, i motivi e la revisione manuale per il periodo configurato. Gli invii sospesi non vengono inoltrati ai destinatari prima dell’approvazione. La verifica DNS delle email è facoltativa: interroga il resolver del server per il solo dominio e conserva il risultato per un massimo di 15 minuti; non verifica l’esistenza della singola casella. I metadati internazionali dei telefoni e una copia dell’elenco dei domini temporanei sono inclusi nel plugin. L’amministratore può sincronizzare manualmente l’elenco scaricando le liste pubbliche da GitHub, senza trasmettere indirizzi email o contenuti degli invii. I controlli dei domini temporanei usano sempre la copia locale.', 'easycontact' );
		wp_add_privacy_policy_content( 'EasyContact', wpautop( esc_html( $text ) ) );
	}
}
