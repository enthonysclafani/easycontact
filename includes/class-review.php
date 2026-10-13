<?php
/**
 * Private, mutually exclusive entry categories and explicit human review.
 *
 * @package EasyContact
 */

namespace EasyContact;

use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Anti-spam categories of archived entries and the human review that overrides them.
 */
final class Review {
	public const TAXONOMY = 'easycontact_safety';

	/**
	 * Category labels, keyed by term slug.
	 */
	public static function labels(): array {
		return array(
			'accepted' => __( 'Accettato', 'easycontact' ),
			'suspect'  => __( 'Probabile spam', 'easycontact' ),
			'spam'     => __( 'Spam', 'easycontact' ),
		);
	}

	/**
	 * Register the private taxonomy and create its three terms once.
	 */
	public static function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			Plugin::ENTRY,
			array(
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => false,
				'show_in_rest'          => false,
				'show_in_nav_menus'     => false,
				'rewrite'               => false,
				'query_var'             => false,
				'hierarchical'          => false,
				'capabilities'          => array_fill_keys( array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' ), 'manage_options' ),
				'update_count_callback' => '_update_generic_term_count',
			)
		);
		if ( '1' !== get_option( 'easycontact_review_terms_version' ) ) {
			$complete = true;
			foreach ( self::labels() as $slug => $label ) {
				$term = term_exists( $slug, self::TAXONOMY );
				if ( ! $term ) {
					$term = wp_insert_term( $label, self::TAXONOMY, array( 'slug' => $slug ) );
				}
				$complete = $complete && ! is_wp_error( $term );
			}
			if ( $complete ) {
				update_option( 'easycontact_review_terms_version', '1', false );
			}
		}
	}

	/**
	 * Admin-only hooks for the archive list, the review box and the review actions.
	 */
	public static function boot(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'manage_' . Plugin::ENTRY . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . Plugin::ENTRY . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'views_edit-' . Plugin::ENTRY, array( self::class, 'views' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter' ) );
		add_action(
			'add_meta_boxes',
			static function (): void {
				add_meta_box( 'easycontact-review', __( 'Classificazione anti-spam', 'easycontact' ), array( self::class, 'box' ), Plugin::ENTRY, 'side', 'high' );
			}
		);
		add_filter( 'bulk_actions-edit-' . Plugin::ENTRY, array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . Plugin::ENTRY, array( self::class, 'bulk' ), 10, 3 );
		add_action( 'admin_post_easycontact_classify', array( self::class, 'action' ) );
		add_action( 'admin_post_easycontact_approve', array( self::class, 'action' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * The entry's single category, or an empty string when it has none or several.
	 *
	 * @param int $id Entry ID.
	 */
	public static function category( int $id ): string {
		$terms = wp_get_object_terms( $id, self::TAXONOMY, array( 'fields' => 'slugs' ) );
		return ! is_wp_error( $terms ) && 1 === count( $terms ) && isset( self::labels()[ $terms[0] ] ) ? $terms[0] : '';
	}

	/**
	 * A manual category change never releases a held delivery. Approval is a separate action.
	 *
	 * @param int $id Entry ID.
	 */
	public static function held( int $id ): bool {
		$value = get_post_meta( $id, '_easycontact_held', true );
		return '' !== $value && '0' !== $value;
	}

	/**
	 * Whether destinations may receive the entry now.
	 *
	 * @param int $id Entry ID.
	 */
	public static function can_deliver( int $id ): bool {
		if ( self::held( $id ) || get_post_meta( $id, '_easycontact_initializing', true ) ) {
			return false;
		}
		$category = self::category( $id );
		// Existing entries have no assessment. Preserve their established recovery behavior.
		return 'accepted' === $category || ( '' === $category && ! get_post_meta( $id, '_easycontact_review', true ) );
	}

	/**
	 * Record the first automatic assessment, exactly once per entry.
	 *
	 * @param int   $id         Entry ID.
	 * @param array $assessment Result of AntiSpam::assess().
	 */
	public static function initialize( int $id, array $assessment ): bool {
		if ( ! add_post_meta( $id, '_easycontact_antispam', $assessment, true ) ) {
			return false;
		}
		return self::write( $id, $assessment['status'], 'automatic', 'accepted' !== $assessment['status'] );
	}

	/**
	 * The caller must hold retry_ID, shared with both initial and recovered deliveries.
	 *
	 * @param int    $id       Entry ID.
	 * @param string $category Category slug.
	 * @param string $source   automatic or manual.
	 * @param bool   $held     Whether deliveries stay held after the write.
	 */
	public static function write( int $id, string $category, string $source, bool $held ): bool {
		if ( ! isset( self::labels()[ $category ] ) || Plugin::ENTRY !== get_post_type( $id ) ) {
			return false;
		}
		$previous = get_post_meta( $id, '_easycontact_review', true );
		if ( 'automatic' === $source && is_array( $previous ) && 'manual' === ( $previous['source'] ?? '' ) ) {
			return true;
		}
		$term = term_exists( $category, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		// Fail closed if a partial write occurs: mark the hold before changing the category.
		update_post_meta( $id, '_easycontact_held', '1' );
		if ( '1' !== get_post_meta( $id, '_easycontact_held', true ) ) {
			return false;
		}
		$result = wp_set_object_terms( $id, array( (int) ( is_array( $term ) ? $term['term_id'] : $term ) ), self::TAXONOMY, false );
		if ( is_wp_error( $result ) || self::category( $id ) !== $category ) {
			return false;
		}
		$record = array(
			'status'   => $category,
			'source'   => $source,
			'time'     => gmdate( 'c' ),
			'reviewer' => 'manual' === $source ? get_current_user_id() : 0,
		);
		update_post_meta( $id, '_easycontact_review', $record );
		if ( get_post_meta( $id, '_easycontact_review', true ) !== $record ) {
			return false;
		}
		if ( 'manual' === $source ) {
			$history   = get_post_meta( $id, '_easycontact_review_history', true );
			$history   = is_array( $history ) ? $history : array();
			$history[] = $record;
			update_post_meta( $id, '_easycontact_review_history', array_slice( $history, -10 ) );
		}
		update_post_meta( $id, '_easycontact_held', $held ? '1' : '0' );
		if ( get_post_meta( $id, '_easycontact_held', true ) !== ( $held ? '1' : '0' ) ) {
			return false;
		}
		if ( $held ) {
			Submission::cancel( $id );
		}
		return true;
	}

	/**
	 * A manual category change, serialized with deliveries through the entry's retry lease.
	 *
	 * @param int    $id       Entry ID.
	 * @param string $category Category slug.
	 * @return true|WP_Error
	 */
	public static function change( int $id, string $category ) {
		$lease = 'retry_' . $id;
		$owner = State::claim( $lease );
		if ( null === $owner ) {
			return new WP_Error( 'busy', __( 'Questo invio è in elaborazione. Riprova tra qualche secondo.', 'easycontact' ) );
		}
		try {
			if ( 'private' !== get_post_status( $id ) || get_post_meta( $id, '_easycontact_initializing', true ) ) {
				return new WP_Error( 'busy', __( 'Questo invio non è disponibile per la revisione. Riprova tra qualche secondo.', 'easycontact' ) );
			}
			if ( ! self::write( $id, $category, 'manual', 'accepted' !== $category || self::held( $id ) ) ) {
				return new WP_Error( 'save', __( 'Non è stato possibile aggiornare la classificazione.', 'easycontact' ) );
			}
			return true;
		} finally {
			State::release( $lease, $owner );
		}
	}

	/**
	 * Keep approval available if an interrupted attempt left some destinations held.
	 *
	 * @param int $id Entry ID.
	 */
	public static function requires_approval( int $id ): bool {
		if ( self::held( $id ) || in_array( self::category( $id ), array( 'suspect', 'spam' ), true ) ) {
			return true;
		}
		$status = get_post_meta( $id, '_easycontact_delivery', true );
		return is_array( $status ) && in_array( 'held', (array) ( $status['outcomes'] ?? array() ), true );
	}

	/**
	 * Add the classification column to the archive list.
	 *
	 * @param array $columns List table columns.
	 */
	public static function columns( array $columns ): array {
		$columns['easycontact-review'] = __( 'Classificazione', 'easycontact' );
		return $columns;
	}

	/**
	 * Print the classification column.
	 *
	 * @param string $column Column key.
	 * @param int    $id     Entry ID.
	 */
	public static function column( string $column, int $id ): void {
		if ( 'easycontact-review' !== $column ) {
			return;
		}
		$category = self::category( $id );
		echo '<span class="easycontact-badge easycontact-badge-' . esc_attr( '' !== $category ? $category : 'unknown' ) . '">' . esc_html( self::labels()[ $category ] ?? __( 'Da classificare', 'easycontact' ) ) . '</span>';
		$record = get_post_meta( $id, '_easycontact_review', true );
		if ( is_array( $record ) ) {
			echo '<br><small>' . esc_html( 'manual' === $record['source'] ? __( 'Revisione manuale', 'easycontact' ) : __( 'Controllo automatico', 'easycontact' ) ) . '</small>';
		}
		if ( self::held( $id ) ) {
			echo '<br><small>' . esc_html__( 'Inoltro sospeso', 'easycontact' ) . '</small>';
		}
	}

	/**
	 * One view per category, plus entries that predate classification.
	 *
	 * @param array $views List table views.
	 */
	public static function views( array $views ): array {
		unset( $views['private'] );
		$current = isset( $_GET['easycontact_review'] ) && is_string( $_GET['easycontact_review'] ) ? sanitize_key( wp_unslash( $_GET['easycontact_review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only archive filter.
		foreach ( self::labels() as $slug => $label ) {
			$query                           = new WP_Query(
				array(
					'post_type'      => Plugin::ENTRY,
					'post_status'    => 'private',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => self::TAXONOMY,
							'field'    => 'slug',
							'terms'    => $slug,
						),
					),
				)
			); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Three bounded private archive counts.
			$views[ 'easycontact-' . $slug ] = '<a href="' . esc_url(
				add_query_arg(
					array(
						'post_type'          => Plugin::ENTRY,
						'easycontact_review' => $slug,
					),
					admin_url( 'edit.php' )
				)
			) . '"' . ( $current === $slug ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $query->found_posts . ')</span></a>';
		}
		$unclassified = new WP_Query(
			array(
				'post_type'      => Plugin::ENTRY,
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => self::TAXONOMY,
						'operator' => 'NOT EXISTS',
					),
				),
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Private count for entries predating classification.
		if ( $unclassified->found_posts || 'unclassified' === $current ) {
			$views['easycontact-unclassified'] = '<a href="' . esc_url(
				add_query_arg(
					array(
						'post_type'          => Plugin::ENTRY,
						'easycontact_review' => 'unclassified',
					),
					admin_url( 'edit.php' )
				)
			) . '"' . ( 'unclassified' === $current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'Da classificare', 'easycontact' ) . ' <span class="count">(' . (int) $unclassified->found_posts . ')</span></a>';
		}
		if ( $current && isset( $views['all'] ) ) {
			$views['all'] = str_replace( array( 'class="current"', 'aria-current="page"' ), '', $views['all'] );
		}
		return $views;
	}

	/**
	 * Restrict the archive list to the selected category.
	 *
	 * @param WP_Query $query Admin list query.
	 */
	public static function filter( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || Plugin::ENTRY !== $query->get( 'post_type' ) ) {
			return;
		}
		$category = isset( $_GET['easycontact_review'] ) && is_string( $_GET['easycontact_review'] ) ? sanitize_key( wp_unslash( $_GET['easycontact_review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only archive filter.
		if ( isset( self::labels()[ $category ] ) || 'unclassified' === $category ) {
			$tax   = $query->get( 'tax_query' );
			$tax   = is_array( $tax ) ? $tax : array();
			$tax[] = 'unclassified' === $category ? array(
				'taxonomy' => self::TAXONOMY,
				'operator' => 'NOT EXISTS',
			) : array(
				'taxonomy' => self::TAXONOMY,
				'field'    => 'slug',
				'terms'    => $category,
			);
			$query->set( 'tax_query', $tax );
		}
	}

	/**
	 * Review box on the entry screen.
	 *
	 * @param WP_Post $post Entry.
	 */
	public static function box( WP_Post $post ): void {
		$category = self::category( $post->ID );
		wp_nonce_field( 'easycontact_review_' . $post->ID, 'easycontact_review_nonce', false );
		echo '<input type="hidden" name="easycontact_review_entry" value="' . esc_attr( (string) $post->ID ) . '">';
		echo '<p><label for="easycontact-review-status">' . esc_html__( 'Categoria', 'easycontact' ) . '</label><br><select id="easycontact-review-status" name="easycontact_review_status">';
		if ( ! $category ) {
			echo '<option value="">' . esc_html__( 'Da classificare', 'easycontact' ) . '</option>';
		}
		foreach ( self::labels() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $category, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p><button class="button" type="submit" name="action" value="easycontact_classify" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . esc_html__( 'Aggiorna categoria', 'easycontact' ) . '</button></p>';
		$assessment = get_post_meta( $post->ID, '_easycontact_antispam', true );
		if ( is_array( $assessment ) ) {
			/* translators: 1: anti-spam score, 2: protection level. */
			echo '<p>' . esc_html( sprintf( __( 'Punteggio: %1$d/100 · Livello %2$d', 'easycontact' ), $assessment['score'], $assessment['level'] ) ) . '</p>';
			if ( ! empty( $assessment['observe'] ) ) {
				/* translators: %s: category detected in observation mode. */
				echo '<p>' . esc_html( sprintf( __( 'Modalità osservazione: rilevato come %s.', 'easycontact' ), self::labels()[ $assessment['detected'] ] ) ) . '</p>';
			}
			echo '<ul>';
			foreach ( $assessment['reasons'] as $reason ) {
				if ( isset( AntiSpam::reason_labels()[ $reason ] ) ) {
					echo '<li>' . esc_html( AntiSpam::reason_labels()[ $reason ] ) . '</li>';
				}
			}
			echo '</ul>';
		}
		$record = get_post_meta( $post->ID, '_easycontact_review', true );
		if ( is_array( $record ) && 'manual' === $record['source'] ) {
			echo '<p>' . esc_html__( 'La revisione manuale ha precedenza sul controllo automatico.', 'easycontact' ) . '</p>';
		}
		if ( self::requires_approval( $post->ID ) ) {
			echo '<p>' . esc_html__( 'Inoltro sospeso. Cambiare categoria non invia notifiche.', 'easycontact' ) . '</p><p><button class="button button-primary" type="submit" name="action" value="easycontact_approve" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . esc_html__( 'Approva e invia', 'easycontact' ) . '</button></p>';
		}
	}

	/**
	 * One bulk action per category.
	 *
	 * @param array $actions List table bulk actions.
	 */
	public static function bulk_actions( array $actions ): array {
		foreach ( self::labels() as $slug => $label ) {
			/* translators: %s: classification label. */
			$actions[ 'easycontact-classify-' . $slug ] = sprintf( __( 'Segna come %s', 'easycontact' ), $label );
		}
		return $actions;
	}

	/**
	 * Apply a bulk classification to at most one hundred entries.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Bulk action key.
	 * @param array  $ids      Selected entry IDs.
	 */
	public static function bulk( string $redirect, string $action, array $ids ): string {
		$category = substr( $action, strlen( 'easycontact-classify-' ) );
		if ( ! str_starts_with( $action, 'easycontact-classify-' ) || ! isset( self::labels()[ $category ] ) ) {
			return $redirect;
		}
		check_admin_referer( 'bulk-posts' );
		$updated = 0;
		if ( current_user_can( 'manage_options' ) ) {
			foreach ( array_slice( $ids, 0, 100 ) as $id ) {
				if ( Plugin::ENTRY === get_post_type( $id ) && 'private' === get_post_status( $id ) && ! is_wp_error( self::change( (int) $id, $category ) ) ) {
					++$updated;
				}
			}
		}
		/* translators: 1: updated entries, 2: selected entries. */
		self::queue_notice( sprintf( __( 'Classificazione aggiornata per %1$d invii su %2$d selezionati.', 'easycontact' ), $updated, count( $ids ) ), count( $ids ) !== $updated );
		return $redirect;
	}

	/**
	 * Handle the review box: change the category or approve the held delivery.
	 */
	public static function action(): void {
		$id = isset( $_POST['easycontact_review_entry'] ) && is_scalar( $_POST['easycontact_review_entry'] ) ? absint( $_POST['easycontact_review_entry'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! $id || Plugin::ENTRY !== get_post_type( $id ) || 'private' !== get_post_status( $id ) ) {
			wp_die( esc_html__( 'Invio non disponibile.', 'easycontact' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'easycontact_review_' . $id, 'easycontact_review_nonce' );
		$action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( 'easycontact_approve' === $action ) {
			$result  = Submission::retry( $id, true, false, true );
			$message = __( 'Invio approvato. Controlla gli esiti delle destinazioni nel dettaglio.', 'easycontact' );
		} else {
			$category = isset( $_POST['easycontact_review_status'] ) && is_string( $_POST['easycontact_review_status'] ) ? sanitize_key( wp_unslash( $_POST['easycontact_review_status'] ) ) : '';
			$result   = isset( self::labels()[ $category ] ) ? self::change( $id, $category ) : new WP_Error( 'category', __( 'Seleziona una categoria valida.', 'easycontact' ) );
			$message  = __( 'Classificazione aggiornata.', 'easycontact' );
		}
		self::queue_notice( is_wp_error( $result ) ? $result->get_error_message() : $message, is_wp_error( $result ) );
		wp_safe_redirect( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
		exit;
	}

	/**
	 * Keep the outcome for the next admin screen of the same user.
	 *
	 * @param string $message Notice text.
	 * @param bool   $error   Whether the notice reports a failure.
	 */
	private static function queue_notice( string $message, bool $error ): void {
		set_transient(
			'easycontact_review_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'error'   => $error,
			),
			90
		);
	}

	/**
	 * Print and clear the queued outcome.
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key    = 'easycontact_review_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			echo '<div class="notice notice-' . ( $notice['error'] ? 'error' : 'success' ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
			delete_transient( $key );
		}
	}
}
