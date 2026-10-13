<?php
/**
 * Private post types, site lifecycle and hook registration.
 *
 * @package EasyContact
 */

namespace EasyContact;

use EasyContact\Admin\Admin;
use EasyContact\Forms\Admin\Editor;
use EasyContact\Forms\Forms;
use EasyContact\Forms\Renderer;
use EasyContact\Phone\Phone;
use EasyContact\Privacy\Privacy;
use EasyContact\Review\Admin\Screen;
use EasyContact\Review\Review;
use EasyContact\Settings\Admin\Page;
use EasyContact\Settings\Settings;
use EasyContact\Submissions\Admin\EntryScreen;
use EasyContact\Submissions\Endpoint;
use EasyContact\Submissions\State;
use EasyContact\Submissions\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Register post types and every part of the plugin, and own activation and deactivation.
 */
final class Plugin {
	public const FORM  = 'easycontact_form';
	public const ENTRY = 'easycontact_entry';

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
		Renderer::boot();
		Endpoint::boot();
		Privacy::boot();
		Phone::boot();
		if ( is_admin() ) {
			Page::boot();
			Editor::boot();
			Admin::boot();
			EntryScreen::boot();
			Screen::boot();
		}
	}

	/**
	 * Initialize non-autoloaded settings and scheduled maintenance.
	 *
	 * @return void
	 */
	public static function activate(): void {
		add_option( Settings::OPTION, Settings::defaults(), '', false );
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
		Forms::register_meta();
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
		add_option( Settings::OPTION, Settings::defaults(), '', false );
		if ( ! Review::create_terms() ) {
			// Retried on the next request.
			return;
		}
		update_option( 'easycontact_version', EASYCONTACT_VERSION, true );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'easycontact_resume_retries' );
	}

	/**
	 * Delete expired data in bounded batches.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		$settings = Settings::get();
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
