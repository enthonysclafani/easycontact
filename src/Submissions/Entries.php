<?php
/**
 * Archived entries: storage preferences and storage.
 *
 * @package EasyContact
 */

namespace EasyContact\Submissions;

use EasyContact\AntiSpam\AntiSpam;
use EasyContact\Plugin;
use EasyContact\Review\Review;
use EasyContact\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Store accepted and quarantined entries with all their metadata, or none of it.
 */
final class Entries {
	/**
	 * Resolve the explicit per-form archive preference.
	 *
	 * @param array $config Validated delivery and privacy configuration.
	 * @return bool Result of this operation.
	 */
	public static function stores_entries( array $config ): bool {
		return 'inherit' === $config['store_entries'] ? (bool) Settings::get()['store_entries'] : 'yes' === $config['store_entries'];
	}

	/**
	 * Suspicious entries are saved only when an archive preference explicitly permits it.
	 *
	 * @param array $config Validated delivery and privacy configuration.
	 */
	public static function stores_quarantine( array $config ): bool {
		return self::stores_entries( $config ) || AntiSpam::enabled( $config, 'quarantine_entries' );
	}

	/**
	 * Persist all archived metadata or roll back an incomplete entry.
	 *
	 * @param int   $form_id Published form ID.
	 * @param array $definition Validated form definition.
	 * @param array $values Sanitized visible field values.
	 * @param array $assessment Result of AntiSpam::assess(); empty for entries saved without one.
	 * @return int|WP_Error Result of this operation.
	 */
	public static function store( int $form_id, array $definition, array $values, array $assessment = array() ) {
		$id = wp_insert_post(
			array(
				'post_type'    => Plugin::ENTRY,
				'post_status'  => 'private',
				'post_title'   => wp_slash( get_the_title( $form_id ) . ' · ' . current_time( 'mysql' ) ),
				'post_content' => '',
				'post_author'  => 0,
				'meta_input'   => array( '_easycontact_initializing' => '1' ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$labels = array();
		$emails = array();
		foreach ( $values as $name => $value ) {
			$field           = $definition['schema']['fields'][ $name ];
			$labels[ $name ] = $field['label'];
			if ( 'email' === $field['type'] && '' !== $value ) {
				$emails[] = strtolower( $value );
			}
		}
		foreach ( array(
			'_easycontact_form_id' => $form_id,
			'_easycontact_values'  => $values,
			'_easycontact_labels'  => $labels,
		) as $key => $value ) {
			if ( ! add_post_meta( $id, $key, wp_slash( $value ), true ) ) {
				wp_delete_post( $id, true );
				return new WP_Error( 'archive_failed', 'archive_failed' );
			}
		}
		foreach ( array_unique( $emails ) as $email ) {
			if ( ! add_post_meta( $id, '_easycontact_email', wp_slash( $email ) ) ) {
				wp_delete_post( $id, true );
				return new WP_Error( 'archive_failed', 'archive_failed' );
			}
		}
		if ( $assessment && ! Review::initialize( $id, $assessment ) ) {
			wp_delete_post( $id, true );
			return new WP_Error( 'archive_failed', 'archive_failed' );
		}
		delete_post_meta( $id, '_easycontact_initializing' );
		return $id;
	}
}
