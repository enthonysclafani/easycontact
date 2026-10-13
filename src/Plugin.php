<?php
/**
 * Native WordPress storage and lifecycle.
 *
 * @package EasyContact
 */

namespace EasyContact;

use EasyContact\Phone\Phone;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Own native storage, site lifecycle and bounded maintenance.
 */
final class Plugin {
	public const OPTION = 'easycontact_settings';
	public const FORM   = 'easycontact_form';
	public const ENTRY  = 'easycontact_entry';

	/**
	 * Register the hooks of every part of the plugin, one line each.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'init' ) );
		add_action( 'easycontact_daily_cleanup', array( self::class, 'cleanup' ) );
		add_action( 'easycontact_retention_cleanup', array( self::class, 'cleanup' ) );
		add_action( 'easycontact_daily_cleanup', array( State::class, 'cleanup' ) );
		add_action( 'easycontact_state_cleanup', array( State::class, 'cleanup' ) );
		Submission::boot();
		Frontend::boot();
		Privacy::boot();
		Phone::boot();
		Review::boot();
		if ( is_admin() ) {
			Admin::boot();
		}
	}

	/**
	 * Return conservative defaults for site settings.
	 *
	 * @return array Result of this operation.
	 */
	public static function defaults(): array {
		return array(
			'store_entries'       => false,
			'retention_days'      => 30,
			'spam_retention_days' => 14,
			'antispam_level'      => 2,
			'antispam_observe'    => false,
			'quarantine_entries'  => false,
			'quarantine_limit'    => 500,
			'email_dns_check'     => false,
			'phone_country'       => '',
			'spam_phrases'        => '',
			'allow_erasure'       => true,
			'delete_on_uninstall' => false,
			'mailerlite_token'    => '',
			'turnstile_site_key'  => '',
			'turnstile_secret'    => '',
		);
	}

	/**
	 * Read site settings with defaults.
	 *
	 * @return array Result of this operation.
	 */
	public static function settings(): array {
		$settings = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $settings ) ? $settings : array() );
	}

	/**
	 * Initialize non-autoloaded settings and scheduled maintenance.
	 *
	 * @return void
	 */
	public static function activate(): void {
		add_option( self::OPTION, self::defaults(), '', false );
		self::schedule();
	}

	/**
	 * Stop scheduled work on the selected sites.
	 *
	 * @param bool $network_wide Whether deactivation applies to the whole network.
	 * @return void
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			self::unschedule();
			return;
		}
		$offset = 0;
		do {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => $offset,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::unschedule();
				restore_current_blog();
			}
			$offset    += 100;
			$site_count = count( $sites );
		} while ( 100 === $site_count );
	}

	/**
	 * Schedule bounded background work.
	 *
	 * @return void
	 */
	private static function schedule(): void {
		if ( ! wp_next_scheduled( 'easycontact_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'easycontact_daily_cleanup' );
		}
	}

	/**
	 * Stop all plugin jobs while retaining preferences and archived data.
	 *
	 * @return void
	 */
	private static function unschedule(): void {
		foreach ( array( 'easycontact_daily_cleanup', 'easycontact_retention_cleanup', 'easycontact_state_cleanup', 'easycontact_retry_delivery', 'easycontact_resume_retries' ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		delete_option( 'easycontact_version' );
	}

	/**
	 * Register private post types and initialize each site lazily.
	 *
	 * @return void
	 */
	public static function init(): void {
		$capabilities = array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ), 'manage_options' );
		$shared       = array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_in_rest'        => false,
			'show_ui'             => true,
			'can_export'          => false,
			'rewrite'             => false,
			'query_var'           => false,
			'map_meta_cap'        => false,
			'capabilities'        => $capabilities,
		);
		register_post_type(
			self::FORM,
			array_merge(
				$shared,
				array(
					'labels'    => array(
						'name'          => __( 'Moduli', 'easycontact' ),
						'singular_name' => __( 'Modulo', 'easycontact' ),
						'menu_name'     => 'EasyContact',
						'add_new_item'  => __( 'Aggiungi un modulo', 'easycontact' ),
						'edit_item'     => __( 'Modifica modulo', 'easycontact' ),
						'all_items'     => __( 'Moduli', 'easycontact' ),
					),
					'menu_icon' => 'dashicons-email',
					'supports'  => array( 'title' ),
				)
			)
		);
		$capabilities['create_posts'] = 'do_not_allow';
		register_post_type(
			self::ENTRY,
			array_merge(
				$shared,
				array(
					'labels'       => array(
						'name'          => __( 'Invii', 'easycontact' ),
						'singular_name' => __( 'Invio', 'easycontact' ),
						'edit_item'     => __( 'Dettaglio invio', 'easycontact' ),
						'all_items'     => __( 'Invii', 'easycontact' ),
					),
					'show_in_menu' => 'edit.php?post_type=' . self::FORM,
					'capabilities' => $capabilities,
					'supports'     => false,
				)
			)
		);
		Review::register();
		// Reads the autoloaded cron option: no query.
		self::schedule();
		// The version is autoloaded, so the check costs no query; setup runs only after an update.
		if ( EASYCONTACT_VERSION !== get_option( 'easycontact_version' ) ) {
			self::setup();
		}
	}

	/**
	 * Create missing data and resume archive jobs after activation or an update.
	 *
	 * Lazy setup also covers sites reached after network activation.
	 */
	private static function setup(): void {
		add_option( self::OPTION, self::defaults(), '', false );
		if ( ! Review::create_terms() ) {
			// Retried on the next request.
			return;
		}
		update_option( 'easycontact_version', EASYCONTACT_VERSION, true );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'easycontact_resume_retries' );
	}

	/**
	 * Return the initial minimal form template.
	 *
	 * @return string Result of this operation.
	 */
	public static function default_template(): string {
		return '[text* nome "Nome"]' . "\n" . '[email* email "Email"]' . "\n" . '[select* motivo "Motivo" "Informazioni" "Preventivo"]' . "\n" . '[number budget "Budget indicativo" min:0 step:1]' . "\n" . '[textarea* messaggio "Messaggio"]' . "\n" . '[checkbox newsletter "Desidero ricevere la newsletter"]' . "\n" . '[acceptance* privacy "Ho letto l’informativa sulla privacy"]' . "\n" . '[submit "Invia messaggio"]';
	}

	/**
	 * Return defaults for a new form.
	 *
	 * @return array Result of this operation.
	 */
	public static function config_defaults(): array {
		return array(
			'mail_enabled'       => true,
			'mail_to'            => get_option( 'admin_email' ),
			'mail_subject'       => __( 'Nuovo messaggio: [form]', 'easycontact' ),
			'mail_reply_to'      => 'email',
			'mail_body'          => '[fields]',
			'store_entries'      => 'inherit',
			'conditions_match'   => 'all',
			'captcha'            => false,
			'antispam_level'     => 'inherit',
			'antispam_observe'   => 'inherit',
			'quarantine_entries' => 'inherit',
			'email_dns_check'    => 'inherit',
			'phone_country'      => '',
			'spam_phrases'       => '',
			'mailerlite_enabled' => false,
			'mailerlite_email'   => 'email',
			'mailerlite_name'    => 'nome',
			'mailerlite_consent' => 'newsletter',
			'mailerlite_groups'  => '',
			'slack_enabled'      => false,
			'slack_webhook'      => '',
			'slack_fields'       => 'nome,email,messaggio',
			'success_message'    => __( 'Grazie, il messaggio è stato acquisito.', 'easycontact' ),
		);
	}

	/**
	 * Read a saved form definition or provide editor defaults.
	 *
	 * @param int  $form_id Published form ID.
	 * @param bool $with_default Validated plugin-specific value.
	 * @return array|null Result of this operation.
	 */
	public static function definition( int $form_id, bool $with_default = false ): ?array {
		$definition = get_post_meta( $form_id, '_easycontact_definition', true );
		if ( is_array( $definition ) && isset( $definition['schema']['fields'], $definition['conditions']['order'], $definition['config'] ) ) {
			$definition['config'] = array_merge( self::config_defaults(), $definition['config'] );
			return $definition;
		}
		if ( ! $with_default ) {
			return null;
		}
		$template   = self::default_template();
		$schema     = Schema::parse( $template );
		$conditions = Schema::rules(
			$schema['fields'],
			array(
				array(
					'target'   => 'budget',
					'source'   => 'motivo',
					'operator' => 'equals',
					'value'    => 'Preventivo',
				),
			)
		);
		return array(
			'template'   => $template,
			'schema'     => $schema,
			'conditions' => $conditions,
			'config'     => self::config_defaults(),
		);
	}

	/**
	 * Resolve the explicit per-form archive preference.
	 *
	 * @param array $config Validated delivery and privacy configuration.
	 * @return bool Result of this operation.
	 */
	public static function stores_entries( array $config ): bool {
		return 'inherit' === $config['store_entries'] ? (bool) self::settings()['store_entries'] : 'yes' === $config['store_entries'];
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
				'post_type'    => self::ENTRY,
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

	/**
	 * Delete expired data in bounded batches.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		$settings = self::settings();
		$started  = microtime( true );
		$pending  = false;
		foreach ( array(
			'IN'     => 'spam_retention_days',
			'NOT IN' => 'retention_days',
		) as $operator => $setting ) {
			$days = (int) $settings[ $setting ];
			if ( $days < 1 ) {
				continue;
			}
			for ( $batch = 0; $batch < 5; ++$batch ) {
				if ( microtime( true ) - $started > 10 ) {
					$pending = true;
					break;
				}
				$ids     = get_posts(
					array(
						'post_type'      => self::ENTRY,
						'post_status'    => array( 'private', 'trash', 'publish', 'draft', 'pending', 'future', 'auto-draft' ),
						'posts_per_page' => 100,
						'fields'         => 'ids',
						'orderby'        => 'ID',
						'order'          => 'ASC',
						'date_query'     => array(
							array(
								'column'    => 'post_date_gmt',
								'before'    => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
								'inclusive' => false,
							),
						),
						'tax_query'      => array(
							array(
								'taxonomy' => Review::TAXONOMY,
								'field'    => 'slug',
								'terms'    => array( 'suspect', 'spam' ),
								'operator' => $operator,
							),
						),
					)
				); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Separate bounded retention for quarantine and ordinary entries.
				$removed = 0;
				foreach ( $ids as $id ) {
					$lease = 'retry_' . $id;
					$owner = State::claim( $lease );
					if ( null === $owner ) {
						$pending = true;
						continue;
					}
					try {
						$removed += wp_delete_post( $id, true ) ? 1 : 0;
					} finally {
						State::release( $lease, $owner );
					}
				}
				if ( count( $ids ) < 100 || 0 === $removed ) {
					break;
				}
				$pending = true;
			}
		}
		if ( $pending && ! wp_next_scheduled( 'easycontact_retention_cleanup' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'easycontact_retention_cleanup' );
		}
	}
}
