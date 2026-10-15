<?php
/**
 * Private post types, site lifecycle and hook registration.
 *
 * @package EasyFormly
 */

namespace EasyFormly;

use EasyFormly\Admin\Admin;
use EasyFormly\Forms\Admin\Editor;
use EasyFormly\Forms\Forms;
use EasyFormly\Forms\Renderer;
use EasyFormly\Phone\Phone;
use EasyFormly\Privacy\Privacy;
use EasyFormly\Review\Admin\Screen;
use EasyFormly\Review\Review;
use EasyFormly\Settings\Admin\Page;
use EasyFormly\Settings\Settings;
use EasyFormly\Submissions\Admin\EntryScreen;
use EasyFormly\Submissions\Endpoint;
use EasyFormly\Submissions\Maintenance;
use EasyFormly\Submissions\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Register post types and every part of the plugin, and own activation and deactivation.
 */
final class Plugin {
	public const FORM  = 'easycontact_form';
	public const ENTRY = 'easycontact_entry';

	/**
	 * Events of version 1.0.0, replaced by Maintenance (D1): removed by the setup after an update.
	 *
	 * @var list<string>
	 */
	private const LEGACY_EVENTS = array( 'easycontact_daily_cleanup', 'easycontact_retention_cleanup', 'easycontact_state_cleanup', 'easycontact_resume_retries' );

	/**
	 * The plugin before the rename (EasyContact 1.0.0): it uses the same data, so the two never run together (D10).
	 */
	public const LEGACY_PLUGIN = 'easycontact/easycontact.php';

	/**
	 * Register the hooks of every part of the plugin, one line each.
	 *
	 * @return void
	 */
	public static function register(): void {
		// EasyContact 1.0.0 loads first and already registers the same names: it serves this request alone.
		if ( defined( 'EASYCONTACT_VERSION' ) ) {
			add_action( 'admin_init', array( self::class, 'replace_legacy' ) );
			return;
		}
		add_action( 'pre_uninstall_plugin', array( self::class, 'guard_legacy_uninstall' ) );
		add_action( 'init', array( self::class, 'init' ) );
		Submission::boot();
		Maintenance::boot();
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
	 * Initialize non-autoloaded settings and take over from EasyContact 1.0.0.
	 *
	 * @param bool $network_wide Whether activation applies to the whole network.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		add_option( Settings::OPTION, Settings::defaults(), '', false );
		self::deactivate_legacy( $network_wide );
	}

	/**
	 * Deactivate EasyContact 1.0.0 when an administrator opens the dashboard with both plugins active.
	 *
	 * @return void
	 */
	public static function replace_legacy(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			self::deactivate_legacy( is_multisite() && is_plugin_active_for_network( plugin_basename( EASYFORMLY_FILE ) ) );
		}
	}

	/**
	 * Deactivate EasyContact 1.0.0 where EasyFormly is active: on the current site, or on the network.
	 *
	 * Its own deactivation clears the version and the retries, so the next request runs the setup of EasyFormly,
	 * which resumes them. A network activation of EasyContact is left alone when EasyFormly runs on one site only.
	 *
	 * @param bool $network_wide Whether EasyFormly is active on the whole network.
	 * @return void
	 */
	private static function deactivate_legacy( bool $network_wide ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$on_network = is_multisite() && is_plugin_active_for_network( self::LEGACY_PLUGIN );
		if ( $on_network && $network_wide ) {
			deactivate_plugins( self::LEGACY_PLUGIN, false, true );
		} elseif ( ! $on_network && is_plugin_active( self::LEGACY_PLUGIN ) ) {
			deactivate_plugins( self::LEGACY_PLUGIN );
		}
	}

	/**
	 * Keep forms, entries and settings when EasyContact 1.0.0 is deleted: they now belong to EasyFormly.
	 *
	 * Its uninstall.php reads the same settings; during that request it sees data removal turned off. The technical
	 * data it removes (version, locks, receipts, retries) is rebuilt by the setup on the next request.
	 *
	 * @param string $plugin Basename of the plugin being uninstalled.
	 * @return void
	 */
	public static function guard_legacy_uninstall( $plugin ): void {
		if ( self::LEGACY_PLUGIN === $plugin ) {
			add_filter( 'option_' . Settings::OPTION, array( self::class, 'keep_data' ) );
		}
	}

	/**
	 * Settings as read by the uninstall of EasyContact 1.0.0: data removal off.
	 *
	 * @param mixed $settings Stored settings.
	 * @return mixed Settings without data removal.
	 */
	public static function keep_data( $settings ) {
		if ( is_array( $settings ) ) {
			$settings['delete_on_uninstall'] = false;
		}
		return $settings;
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
	 * Stop all plugin jobs while retaining preferences and archived data.
	 *
	 * @return void
	 */
	private static function unschedule(): void {
		foreach ( array_merge( self::LEGACY_EVENTS, array( 'easycontact_retry_delivery' ) ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		delete_option( 'easycontact_version' );
	}

	/**
	 * Load translations, register private post types and initialize each site lazily.
	 *
	 * @return void
	 */
	public static function init(): void {
		// The Italian translation ships with the plugin (D9); files in wp-content/languages still take precedence.
		// Registering the folder only when it has the current locale spares other sites a lookup on every string.
		if ( is_readable( plugin_dir_path( EASYFORMLY_FILE ) . 'languages/easyformly-' . determine_locale() . '.mo' ) ) {
			load_plugin_textdomain( 'easyformly', false, dirname( plugin_basename( EASYFORMLY_FILE ) ) . '/languages' );
		}
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
						'name'          => __( 'Forms', 'easyformly' ),
						'singular_name' => __( 'Form', 'easyformly' ),
						'menu_name'     => 'EasyFormly',
						'add_new_item'  => __( 'Add a form', 'easyformly' ),
						'edit_item'     => __( 'Edit form', 'easyformly' ),
						'all_items'     => __( 'Forms', 'easyformly' ),
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
						'name'          => __( 'Submissions', 'easyformly' ),
						'singular_name' => __( 'Submission', 'easyformly' ),
						'edit_item'     => __( 'Submission details', 'easyformly' ),
						'all_items'     => __( 'Submissions', 'easyformly' ),
					),
					'show_in_menu' => 'edit.php?post_type=' . self::FORM,
					'capabilities' => $capabilities,
					'supports'     => false,
				)
			)
		);
		Forms::register_meta();
		Review::register();
		// The version is autoloaded, so the check costs no query; setup runs only after an update.
		if ( EASYFORMLY_VERSION !== get_option( 'easycontact_version' ) ) {
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
		foreach ( self::LEGACY_EVENTS as $hook ) {
			wp_unschedule_hook( $hook );
		}
		update_option( 'easycontact_version', EASYFORMLY_VERSION, true );
		Maintenance::resume_retries();
	}
}
