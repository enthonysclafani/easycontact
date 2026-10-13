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
		add_submenu_page( 'edit.php?post_type=' . Plugin::FORM, __( 'Supporto', 'easycontact' ), __( 'Supporto', 'easycontact' ), 'manage_options', 'easycontact-support', array( self::class, 'support_page' ) );
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
				'optionOne' => __( 'Opzione 1', 'easycontact' ),
				'optionTwo' => __( 'Opzione 2', 'easycontact' ),
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
			<h1>EasyContact · <?php esc_html_e( 'Supporto', 'easycontact' ); ?></h1>
			<h2><?php esc_html_e( 'Sintassi ed esempi', 'easycontact' ); ?></h2>
			<p><?php esc_html_e( 'Separa i campi sulla stessa riga con | per affiancarli in colonne di uguale larghezza. Vai a capo per iniziare una nuova riga. L’asterisco rende il campo obbligatorio. Salva il modulo per aggiornare i campi disponibili nelle regole.', 'easycontact' ); ?></p>
			<p><?php esc_html_e( 'Due colonne (50% ciascuna):', 'easycontact' ); ?></p>
			<pre><?php echo esc_html( '[text* nome "Nome"] | [email* email "Email"]' ); ?></pre>
			<p><?php esc_html_e( 'Tre colonne (33,33% ciascuna):', 'easycontact' ); ?></p>
			<pre><?php echo esc_html( '[text* nome "Nome"] | [text* cognome "Cognome"] | [email* email "Email"]' ); ?></pre>
			<p><?php esc_html_e( 'Le larghezze tengono conto dello spazio tra le colonne. Su schermi piccoli i campi si dispongono uno sotto l’altro. Note e pulsante devono restare separati.', 'easycontact' ); ?></p>
			<pre><?php echo esc_html( '[select motivo "Motivo" "Informazioni" "Preventivo"]' . "\n" . '[number budget "Budget" min:0 max:100000 step:1]' . "\n" . '[checkbox newsletter "Desidero ricevere la newsletter"]' . "\n" . '[acceptance* privacy "Ho letto l’informativa privacy"]' . "\n" . '[note "Una breve indicazione"]' . "\n" . '[submit "Invia"]' ); ?></pre>
			<pre><?php echo esc_html( '[tel telefono "Telefono"]' . "\n" . '[tel* cellulare "Cellulare" mobile country:IT]' . "\n" . '[tel recapito "Recapito" allow-repeated]' ); ?></pre>
			<p><?php esc_html_e( 'Il telefono include il selettore del paese. country:IT imposta il paese iniziale del campo, mobile richiede un cellulare e allow-repeated consente numeri interamente ripetitivi. Sono disponibili anche email, textarea e url. Usa nomi unici in minuscolo.', 'easycontact' ); ?></p>
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
