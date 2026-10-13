<?php
/**
 * Native post editor, metaboxes and Settings API.
 *
 * @package EasyContact
 */

namespace EasyContact;

use EasyContact\Delivery\Admin\Outcomes;
use EasyContact\Review\Review;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Use native administrative screens and authorized settings.
 */
final class Admin {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'add_meta_boxes', array( self::class, 'boxes' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_easycontact_retry', array( self::class, 'retry' ) );
		add_filter( 'manage_' . Plugin::ENTRY . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . Plugin::ENTRY . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter(
			'bulk_actions-edit-' . Plugin::ENTRY,
			static function ( $actions ) {
				unset( $actions['edit'] );
				return $actions;
			}
		);
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
	 * Register the entry metabox.
	 *
	 * @return void
	 */
	public static function boxes(): void {
		add_meta_box( 'easycontact-entry-data', __( 'Dati dell’invio', 'easycontact' ), array( self::class, 'entry_box' ), Plugin::ENTRY, 'normal', 'high' );
		remove_meta_box( 'submitdiv', Plugin::ENTRY, 'side' );
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
	 * Add the delivery status column to the entry list.
	 *
	 * @param array $columns Native list-table columns.
	 * @return array Result of this operation.
	 */
	public static function columns( array $columns ): array {
		$columns['easycontact-delivery'] = __( 'Esito invii', 'easycontact' );
		return $columns;
	}

	/**
	 * Render a native list-table column.
	 *
	 * @param string $column Current list-table column name.
	 * @param int    $id Native WordPress post ID.
	 * @return void
	 */
	public static function column( string $column, int $id ): void {
		if ( 'easycontact-delivery' === $column ) {
			$status = get_post_meta( $id, '_easycontact_delivery', true );
			if ( is_array( $status ) ) {
				Outcomes::render( $status['outcomes'] );
			}
		}
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

	/**
	 * Render escaped private archive values and recovery actions.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function entry_box( WP_Post $post ): void {
		$values = (array) get_post_meta( $post->ID, '_easycontact_values', true );
		$labels = (array) get_post_meta( $post->ID, '_easycontact_labels', true );
		echo '<p>' . esc_html( $post->post_title ) . '</p><table class="widefat striped"><tbody>';
		foreach ( $values as $name => $value ) {
			echo '<tr><th>' . esc_html( $labels[ $name ] ?? $name ) . '</th><td class="easycontact-value">' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$status = get_post_meta( $post->ID, '_easycontact_delivery', true );
		if ( is_array( $status ) ) {
			Outcomes::render( $status['outcomes'] );
			$retry = false;
			foreach ( $status['outcomes'] as $adapter => $outcome ) {
				$retry = $retry || ( 'archive' !== $adapter && ! in_array( $outcome, array( 'sent', 'skipped', 'disabled', 'held' ), true ) );
			}
			if ( wp_next_scheduled( 'easycontact_retry_delivery', array( $post->ID ) ) ) {
				echo '<p>' . esc_html__( 'È programmato un nuovo tentativo per le destinazioni temporaneamente non disponibili.', 'easycontact' ) . '</p>';
			}
			if ( $retry && 'private' === $post->post_status && Review::can_deliver( $post->ID ) ) {
				wp_nonce_field( 'easycontact_retry_' . $post->ID, 'easycontact_retry_nonce', false );
				echo '<input type="hidden" name="easycontact_retry_entry" value="' . esc_attr( (string) $post->ID ) . '">';
				echo '<p><label><input type="checkbox" name="easycontact_retry_confirm" value="1"> ' . esc_html__( 'Ho verificato le destinazioni con esito non confermato e desidero ripetere anche questi invii.', 'easycontact' ) . '</label></p>';
				echo '<p><button type="submit" class="button" name="action" value="easycontact_retry" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . esc_html__( 'Riprova le destinazioni non riuscite', 'easycontact' ) . '</button></p><p class="description">' . esc_html__( 'Usa la configurazione attuale del modulo. Gli invii già accettati vengono conservati. Senza la verifica selezionata, gli esiti non confermati restano sospesi.', 'easycontact' ) . '</p>';
			}
		}
		$url = get_delete_post_link( $post->ID, '', true );
		if ( $url ) {
			echo '<p><a class="submitdelete deletion" href="' . esc_url( $url ) . '">' . esc_html__( 'Elimina definitivamente questo invio', 'easycontact' ) . '</a></p>';
		}
	}

	/**
	 * Run an explicit administrator retry from a private archived entry.
	 *
	 * @return void
	 */
	public static function retry(): void {
		$id = isset( $_POST['easycontact_retry_entry'] ) && is_scalar( $_POST['easycontact_retry_entry'] ) ? absint( $_POST['easycontact_retry_entry'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! $id || Plugin::ENTRY !== get_post_type( $id ) || 'private' !== get_post_status( $id ) ) {
			wp_die( esc_html__( 'Invio non disponibile.', 'easycontact' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'easycontact_retry_' . $id, 'easycontact_retry_nonce' );
		$confirmed = isset( $_POST['easycontact_retry_confirm'] ) && '1' === $_POST['easycontact_retry_confirm'];
		Submission::retry( $id, true, $confirmed );
		wp_safe_redirect( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
		exit;
	}
}
