<?php
/**
 * Shared administration: menu, assets, support page and row actions.
 *
 * @package EasyContact
 */

namespace EasyContact\Admin;

use EasyContact\Plugin;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Share the plugin menu, assets and list-table actions between forms and entries.
 */
final class Admin {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
	}

	/**
	 * Remove the form creation shortcut and add the support submenu.
	 *
	 * @return void
	 */
	public static function menu(): void {
		remove_submenu_page( 'edit.php?post_type=' . Plugin::FORM, 'post-new.php?post_type=' . Plugin::FORM );
		add_submenu_page( 'edit.php?post_type=' . Plugin::FORM, __( 'Support', 'easycontact' ), __( 'Support', 'easycontact' ), 'manage_options', 'easycontact-support', array( self::class, 'support_page' ) );
	}

	/**
	 * Enqueue administrative assets only on this plugin’s screens.
	 *
	 * @return void
	 */
	public static function assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || ( ! in_array( $screen->post_type, array( Plugin::FORM, Plugin::ENTRY ), true ) && ! str_contains( $screen->id, 'easycontact-settings' ) && ! str_contains( $screen->id, 'easycontact-support' ) ) ) {
			return;
		}
		wp_enqueue_style( 'easycontact-admin', EASYCONTACT_URL . 'assets/admin.css', array(), EASYCONTACT_VERSION );
		if ( str_contains( $screen->id, 'easycontact-support' ) ) {
			return;
		}
		wp_enqueue_script( 'easycontact-admin', EASYCONTACT_URL . 'assets/admin.js', array(), EASYCONTACT_VERSION, true );
		wp_localize_script(
			'easycontact-admin',
			'easycontactAdmin',
			array(
				'optionOne' => __( 'Option 1', 'easycontact' ),
				'optionTwo' => __( 'Option 2', 'easycontact' ),
			)
		);
	}

	/**
	 * Render form syntax and examples on the authorized support page.
	 *
	 * @return void
	 */
	public static function support_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap easycontact-support">
			<h1>EasyContact · <?php esc_html_e( 'Support', 'easycontact' ); ?></h1>
			<h2><?php esc_html_e( 'Syntax and examples', 'easycontact' ); ?></h2>
			<p><?php esc_html_e( 'Separate fields on the same line with | to place them in equal-width columns. Start a new line for a new row. An asterisk makes the field required. Save the form to update the fields available in the rules.', 'easycontact' ); ?></p>
			<p><?php esc_html_e( 'Two columns (50% each):', 'easycontact' ); ?></p>
			<pre><?php /* translators: Example form syntax: translate only the labels in quotes. */ echo esc_html__( '[text* name "Name"] | [email* email "Email"]', 'easycontact' ); ?></pre>
			<p><?php esc_html_e( 'Three columns (33.33% each):', 'easycontact' ); ?></p>
			<pre><?php /* translators: Example form syntax: translate only the labels in quotes. */ echo esc_html__( '[text* name "Name"] | [text* surname "Surname"] | [email* email "Email"]', 'easycontact' ); ?></pre>
			<p><?php esc_html_e( 'Widths account for the space between columns. On small screens fields stack vertically. Notes and the button must stay on their own line.', 'easycontact' ); ?></p>
			<pre><?php /* translators: Example form syntax, one tag per line: translate only the labels in quotes. */ echo esc_html__( "[select reason \"Reason\" \"Information\" \"Quote\"]\n[number budget \"Budget\" min:0 max:100000 step:1]\n[checkbox newsletter \"I would like to receive the newsletter\"]\n[acceptance* privacy \"I have read the privacy policy\"]\n[note \"A short hint\"]\n[submit \"Send\"]", 'easycontact' ); ?></pre>
			<pre><?php /* translators: Example form syntax, one tag per line: translate only the labels in quotes. */ echo esc_html__( "[tel phone \"Phone\"]\n[tel* cell \"Mobile\" mobile country:IT]\n[tel contact \"Contact number\" allow-repeated]", 'easycontact' ); ?></pre>
			<p><?php esc_html_e( 'The phone field includes a country selector. country:IT sets the initial country, mobile requires a mobile number and allow-repeated accepts fully repetitive numbers. email, textarea and url are also available. Use unique lowercase names.', 'easycontact' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Remove unsupported inline editing and public view actions.
	 *
	 * @param array   $actions Native row actions.
	 * @param WP_Post $post Native WordPress post.
	 * @return array Result of this operation.
	 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( in_array( $post->post_type, array( Plugin::FORM, Plugin::ENTRY ), true ) ) {
			unset( $actions['inline hide-if-no-js'], $actions['view'] );
		}
		return $actions;
	}
}
