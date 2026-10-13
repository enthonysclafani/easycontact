<?php
/**
 * Submission orchestration and bounded recovery using the optional native archive.
 *
 * @package EasyContact
 */

namespace EasyContact\Submissions;

use EasyContact\AntiSpam\AntiSpam;
use EasyContact\Delivery\Delivery;
use EasyContact\Forms\Forms;
use EasyContact\Forms\Schema;
use EasyContact\Plugin;
use EasyContact\Review\Review;
use EasyContact\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Tracks each destination separately and preserves already accepted deliveries. */
final class Submission {

	/**
	 * Register background recovery and removal of jobs when their archive is deleted.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'easycontact_retry_delivery', array( self::class, 'retry' ) );
		add_action( 'before_delete_post', array( self::class, 'cancel_entry' ) );
		add_action( 'wp_trash_post', array( self::class, 'cancel_entry' ) );
	}

	/**
	 * Execute a validated submission under exclusive ownership.
	 *
	 * @param int    $form_id Published form ID.
	 * @param array  $definition Validated form definition.
	 * @param array  $values Sanitized visible field values.
	 * @param string $ticket Signed submission ticket.
	 * @param string $captcha Turnstile response token.
	 * @param string $input_fingerprint Keyed fingerprint of the original submitted fields.
	 * @param array  $context Request signals for the anti-spam assessment.
	 * @return array|WP_Error Result of this operation.
	 */
	public static function process( int $form_id, array $definition, array $values, string $ticket, string $captcha, string $input_fingerprint, array $context = array() ) {
		$key         = substr( hash( 'sha256', $ticket ), 0, 40 );
		$lease       = 'submit_' . $key;
		$owner       = State::claim( $lease );
		$entry_lease = '';
		$entry_owner = null;
		if ( null === $owner ) {
			return new WP_Error( 'busy', __( 'L’invio è in corso. Attendi qualche secondo.', 'easycontact' ), array( 'status' => 429 ) );
		}
		try {
			$fingerprint = hash_hmac( 'sha256', wp_json_encode( $values ), wp_salt( 'auth' ) );
			$state       = State::read( $key );
			if ( $state && ( ! hash_equals( $state['fingerprint'], $fingerprint ) || ! hash_equals( $state['input_fingerprint'], $input_fingerprint ) ) ) {
				return new WP_Error( 'used', __( 'Questo invio è già stato elaborato. Ricarica il modulo per un nuovo messaggio.', 'easycontact' ), array( 'status' => 409 ) );
			}
			// Recheck the committed receipt after acquiring ownership, never before the lease.
			if ( isset( $state['response'] ) ) {
				return $state['response'];
			}
			$hash = self::definition_hash( $definition );
			if ( $state && $state['hash'] !== $hash ) {
				return new WP_Error( 'changed', __( 'Il modulo è stato aggiornato. Ricarica la pagina prima di un nuovo invio.', 'easycontact' ), array( 'status' => 409 ) );
			}
			if ( ! $state && ! State::rate_allowed( $form_id, AntiSpam::level( $definition['config'] ) === 3 ? 6 : 10 ) ) {
				return new WP_Error( 'rate', __( 'Troppi invii ravvicinati. Attendi qualche minuto.', 'easycontact' ), array( 'status' => 429 ) );
			}
			if ( ! $state ) {
				if ( $definition['config']['captcha'] ) {
					$result = Delivery::captcha( $form_id, $captcha, explode( '.', $ticket )[1] );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				$assessment = AntiSpam::assess( $form_id, $definition, $values, $context );
				if ( 'accepted' !== $assessment['status'] ) {
					if ( ! Entries::stores_quarantine( $definition['config'] ) ) {
						return new WP_Error( 'spam', __( 'Non è stato possibile accettare l’invio. Controlla i dati e il messaggio oppure usa un altro contatto del sito.', 'easycontact' ), array( 'status' => 422 ) );
					}
					$limit = max( 1, (int) Settings::get()['quarantine_limit'] );
					$count = State::count( 'quarantine_budget', 'site', DAY_IN_SECONDS, $limit + 1 );
					if ( null === $count || $count > $limit ) {
						return new WP_Error( 'spam', __( 'Non è stato possibile accettare l’invio. Usa un altro contatto del sito.', 'easycontact' ), array( 'status' => 422 ) );
					}
				}
				$state = array(
					'fingerprint'       => $fingerprint,
					'input_fingerprint' => $input_fingerprint,
					'hash'              => $hash,
					'entry'             => 0,
					'attempts'          => 0,
					'outcomes'          => array(),
					'assessment'        => $assessment,
				);
			}
			$expires = (int) explode( '.', $ticket )[0] + HOUR_IN_SECONDS;
			$persist = static function () use ( $key, &$state, $expires, $lease, $owner, &$entry_lease, &$entry_owner ): bool {
				return State::owns( $lease, $owner ) && ( ! $state['entry'] || 'private' === get_post_status( $state['entry'] ) ) && ( null === $entry_owner || State::owns( $entry_lease, $entry_owner ) ) && State::write( $key, $state, $expires );
			};
			if ( $state['attempts'] >= 3 || ! $persist() ) {
				return self::uncertain_error();
			}
			++$state['attempts'];
			$assessment = $state['assessment'] ?? array(
				'status'   => 'accepted',
				'detected' => 'accepted',
				'level'    => 1,
				'score'    => 0,
				'reasons'  => array(),
				'observe'  => false,
			);
			$held       = 'accepted' !== $assessment['status'];
			$store      = $held ? Entries::stores_quarantine( $definition['config'] ) : Entries::stores_entries( $definition['config'] );
			if ( $store && ! isset( $state['outcomes']['archive'] ) ) {
				$state['outcomes']['archive'] = 'archive_uncertain';
				if ( ! $persist() ) {
					return self::uncertain_error();
				}
				$entry                        = Entries::store( $form_id, $definition, $values, $assessment );
				$state['entry']               = is_wp_error( $entry ) ? 0 : $entry;
				$state['outcomes']['archive'] = is_wp_error( $entry ) ? 'archive_failed' : 'saved';
				if ( ! $persist() ) {
					return self::uncertain_error();
				}
			}
			if ( $state['entry'] > 0 ) {
				$entry_lease = 'retry_' . $state['entry'];
				$entry_owner = State::claim( $entry_lease );
				if ( null === $entry_owner ) {
					return self::uncertain_error();
				}
				// An administrator/cron may have recovered a previous interrupted attempt.
				$current = get_post_meta( $state['entry'], '_easycontact_delivery', true );
				if ( is_array( $current ) && ( $current['key'] ?? '' ) === $key ) {
					$state['outcomes'] = $current['outcomes'];
				}
				$held = ! Review::can_deliver( $state['entry'] );
			}
			if ( $held ) {
				if ( ! $state['entry'] ) {
					return new WP_Error( 'delivery', __( 'Non è stato possibile conservare il messaggio per la verifica. Riprova più tardi.', 'easycontact' ), array( 'status' => 503 ) );
				}
				foreach ( array( 'mail', 'mailerlite', 'slack' ) as $adapter ) {
					if ( $definition['config'][ $adapter . '_enabled' ] && ! in_array( $state['outcomes'][ $adapter ] ?? '', array( 'sent', 'skipped' ), true ) ) {
						$state['outcomes'][ $adapter ] = 'held';
					}
				}
				self::status( $form_id, $state['entry'], $state['outcomes'], $key, true );
				$state['response'] = array(
					'success' => true,
					'held'    => true,
					'message' => __( 'Il messaggio è stato ricevuto ed è in attesa di verifica.', 'easycontact' ),
				);
				return $persist() ? $state['response'] : self::uncertain_error();
			}
			foreach ( array( 'mail', 'mailerlite', 'slack' ) as $adapter ) {
				$outcome = $state['outcomes'][ $adapter ] ?? '';
				if ( ! $definition['config'][ $adapter . '_enabled' ] || in_array( $outcome, array( 'sent', 'skipped' ), true ) || ( '' !== $outcome && ! self::retryable( $adapter, $outcome ) ) ) {
					continue;
				}
				// Persist an uncertain marker before the external side effect. A crashed worker cannot silently resend it.
				$state['outcomes'][ $adapter ] = $adapter . '_uncertain';
				if ( ! $persist() ) {
					return self::uncertain_error();
				}
				self::status( $form_id, $state['entry'], $state['outcomes'], $key, true );
				$state['outcomes'][ $adapter ] = self::send( $adapter, $form_id, $definition, $values );
				if ( ! $persist() ) {
					return self::uncertain_error();
				}
			}
			self::status( $form_id, $state['entry'], $state['outcomes'], $key, true );
			$accepted = $state['entry'] > 0 || in_array( 'sent', $state['outcomes'], true );
			if ( $state['entry'] > 0 ) {
				self::schedule( $state['entry'], $hash, $state['outcomes'] );
			}
			if ( ! $accepted ) {
				foreach ( $state['outcomes'] as $outcome ) {
					if ( str_ends_with( $outcome, '_uncertain' ) ) {
						return self::uncertain_error();
					}
				}
				return new WP_Error( 'delivery', __( 'Non è stato possibile acquisire il messaggio. Riprova più tardi o usa un altro contatto.', 'easycontact' ), array( 'status' => 503 ) );
			}
			$state['response'] = array(
				'success' => true,
				'message' => $definition['config']['success_message'],
			);
			return $persist() ? $state['response'] : self::uncertain_error();
		} finally {
			if ( null !== $entry_owner ) {
				State::release( $entry_lease, $entry_owner );
			}
			State::release( $lease, $owner );
		}
	}

	/**
	 * Retry only failed destinations from an explicitly enabled local archive.
	 *
	 * @param int  $entry_id Private archived entry ID.
	 * @param bool $manual Whether an administrator explicitly requested recovery.
	 * @param bool $confirm_uncertain Whether an administrator checked the uncertain destinations before explicitly resending.
	 * @param bool $approve Whether a human explicitly releases a quarantined entry.
	 * @return bool|WP_Error|null Outcome of the recovery attempt.
	 */
	public static function retry( int $entry_id, bool $manual = false, bool $confirm_uncertain = false, bool $approve = false ) {
		$lease = 'retry_' . $entry_id;
		$owner = State::claim( $lease );
		if ( null === $owner ) {
			return new WP_Error( 'busy', __( 'Questo invio è in elaborazione. Riprova tra qualche secondo.', 'easycontact' ) );
		}
		try {
			wp_clear_scheduled_hook( 'easycontact_retry_delivery', array( $entry_id ) );
			$entry = get_post( $entry_id );
			if ( ! $entry || Plugin::ENTRY !== $entry->post_type || 'private' !== $entry->post_status ) {
				return new WP_Error( 'entry', __( 'Invio non disponibile.', 'easycontact' ) );
			}
			if ( ! $approve && ! Review::can_deliver( $entry_id ) ) {
				self::cancel( $entry_id );
				return new WP_Error( 'held', __( 'L’invio è sospeso. Usa Approva e invia dopo la verifica.', 'easycontact' ) );
			}
			if ( get_post_meta( $entry_id, '_easycontact_initializing', true ) ) {
				return new WP_Error( 'busy', __( 'Questo invio è in elaborazione. Riprova tra qualche secondo.', 'easycontact' ) );
			}
			if ( $approve && ( ! $manual || ! Review::requires_approval( $entry_id ) ) ) {
				return new WP_Error( 'approved', __( 'Questo invio è già stato approvato oppure non è in quarantena.', 'easycontact' ) );
			}
			$form_id    = (int) get_post_meta( $entry_id, '_easycontact_form_id', true );
			$definition = Forms::definition( $form_id );
			$job        = get_post_meta( $entry_id, '_easycontact_retry', true );
			$status     = get_post_meta( $entry_id, '_easycontact_delivery', true );
			if ( ! is_array( $status ) || ! $definition || 'publish' !== get_post_status( $form_id ) || ( ! $manual && ! Entries::stores_quarantine( $definition['config'] ) ) ) {
				self::cancel( $entry_id );
				return new WP_Error( 'form', __( 'Il modulo originale non è disponibile per l’inoltro.', 'easycontact' ) );
			}
			$hash          = self::definition_hash( $definition );
			$status['key'] = $status['key'] ?? hash( 'sha256', 'entry_' . $entry_id );
			if ( ! $manual && ( ! is_array( $job ) || $job['hash'] !== $hash || $job['attempts'] >= 3 || $job['expires'] < time() ) ) {
				self::cancel( $entry_id );
				return;
			}
			$validated = Schema::validate( $definition, (array) get_post_meta( $entry_id, '_easycontact_values', true ), array(), true );
			if ( $validated['errors'] ) {
				self::cancel( $entry_id );
				return new WP_Error( 'validation', __( 'I dati archiviati non rispettano la configurazione attuale del modulo.', 'easycontact' ) );
			}
			$job = $manual ? array(
				'hash'     => $hash,
				'attempts' => 0,
				'expires'  => time() + DAY_IN_SECONDS,
			) : $job;
			++$job['attempts'];
			update_post_meta( $entry_id, '_easycontact_retry', $job );
			if ( get_post_meta( $entry_id, '_easycontact_retry', true ) !== $job ) {
				return new WP_Error( 'save', __( 'Non è stato possibile preparare l’inoltro.', 'easycontact' ) );
			}
			if ( $approve && ( ! State::owns( $lease, $owner ) || ! Review::write( $entry_id, 'accepted', 'manual', false ) ) ) {
				return new WP_Error( 'save', __( 'Non è stato possibile approvare l’invio.', 'easycontact' ) );
			}
			$outcomes = $status['outcomes'];
			foreach ( array( 'mail', 'mailerlite', 'slack' ) as $adapter ) {
				$outcome = $outcomes[ $adapter ] ?? '';
				if ( $approve && 'held' === $outcome && ! $definition['config'][ $adapter . '_enabled' ] ) {
					$outcomes[ $adapter ] = 'disabled';
				}
				$uncertain = str_ends_with( $outcome, '_uncertain' ) && 'mailerlite' !== $adapter;
				if ( ! $definition['config'][ $adapter . '_enabled' ] || ( '' === $outcome && ! $approve ) || ( 'held' === $outcome && ! $approve ) || ( $uncertain && ! ( $manual && $confirm_uncertain ) ) || in_array( $outcome, array( 'sent', 'skipped' ), true ) || ( ! $manual && ! self::retryable( $adapter, $outcome ) ) ) {
					continue;
				}
				if ( ! State::owns( $lease, $owner ) || ! get_post( $entry_id ) || ! Review::can_deliver( $entry_id ) ) {
					return;
				}
				$outcomes[ $adapter ] = $adapter . '_uncertain';
				self::status( $form_id, $entry_id, $outcomes, $status['key'], false );
				if ( ( get_post_meta( $entry_id, '_easycontact_delivery', true )['outcomes'] ?? array() ) !== $outcomes ) {
					return;
				}
				$outcomes[ $adapter ] = self::send( $adapter, $form_id, $definition, $validated['values'] );
				self::status( $form_id, $entry_id, $outcomes, $status['key'], false );
			}
			self::status( $form_id, $entry_id, $outcomes, $status['key'], false );
			self::schedule( $entry_id, $hash, $outcomes, $job );
			return true;
		} finally {
			State::release( $lease, $owner );
		}
	}

	/**
	 * Limit removal hooks to plugin entries so unrelated post changes do not touch cron or metadata.
	 *
	 * @param int $post_id Native WordPress post ID being removed.
	 * @return void
	 */
	public static function cancel_entry( int $post_id ): void {
		if ( Plugin::ENTRY === get_post_type( $post_id ) ) {
			self::cancel( $post_id );
		}
	}

	/**
	 * Cancel scheduled recovery, including expired or deleted entry jobs.
	 *
	 * @param int $entry_id Private archived entry ID.
	 * @return void
	 */
	public static function cancel( int $entry_id ): void {
		wp_clear_scheduled_hook( 'easycontact_retry_delivery', array( $entry_id ) );
		delete_post_meta( $entry_id, '_easycontact_retry' );
	}

	/**
	 * Resume pending archive jobs after an update or activation, in bounded native metadata batches.
	 *
	 * @param int $after_id Last processed ID for keyset pagination.
	 * @return int|null Cursor of the next batch, or null when no job is left; Maintenance keeps it between runs.
	 */
	public static function resume( int $after_id = 0 ): ?int {
		// Keyset pagination: retries that finish between batches delete their job, which would shift an offset.
		// Only the posts table has an ID column, so the condition stays unambiguous with the meta join.
		$after = static fn( string $where ): string => $where . ' AND ID > ' . $after_id;
		add_filter( 'posts_where', $after );
		$ids   = ( new \WP_Query(
			array(
				'post_type'              => Plugin::ENTRY,
				'post_status'            => 'any',
				'meta_key'               => '_easycontact_retry', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded batch, only after an update.
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => 50,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		) )->posts;
		remove_filter( 'posts_where', $after );
		foreach ( $ids as $entry_id ) {
			$job = get_post_meta( $entry_id, '_easycontact_retry', true );
			if ( is_array( $job ) && ! wp_next_scheduled( 'easycontact_retry_delivery', array( (int) $entry_id ) ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'easycontact_retry_delivery', array( (int) $entry_id ) );
			}
		}
		return count( $ids ) === 50 ? (int) end( $ids ) : null;
	}

	/**
	 * Determine which failures are safe to retry automatically.
	 *
	 * @param string $adapter Supported destination name.
	 * @param string $outcome Safe operational status code.
	 * @return bool Result of this operation.
	 */
	public static function retryable( string $adapter, string $outcome ): bool {
		if ( 'mail' === $adapter ) {
			return false;
		}
		if ( 'mailerlite' === $adapter ) {
			return in_array( $outcome, array( 'mailerlite_network', 'mailerlite_uncertain', 'mailerlite_http_429' ), true ) || 1 === preg_match( '/^mailerlite_http_5[0-9]{2}$/D', $outcome );
		}
		return 'slack_http_429' === $outcome;
	}

	/**
	 * Fingerprint delivery configuration so background retries cannot change recipients silently.
	 *
	 * @param array $definition Validated form definition.
	 * @return string Result of this operation.
	 */
	private static function definition_hash( array $definition ): string {
		return hash_hmac( 'sha256', get_current_blog_id() . '|' . wp_json_encode( $definition ), wp_salt( 'auth' ) );
	}

	/**
	 * Convert provider results to safe status codes without leaking response bodies.
	 *
	 * @param string $adapter Supported destination name.
	 * @param int    $form_id Published form ID.
	 * @param array  $definition Validated form definition.
	 * @param array  $values Sanitized visible field values.
	 * @return string Result of this operation.
	 */
	private static function send( string $adapter, int $form_id, array $definition, array $values ): string {
		try {
			$result = 'mailerlite' === $adapter ? Delivery::mailerlite( $definition['config'], $values ) : Delivery::$adapter( $form_id, $definition, $values );
			return is_wp_error( $result ) ? $result->get_error_code() : ( true === $result ? 'sent' : 'skipped' );
		} catch ( \Throwable $error ) {
			// Another mail/HTTP extension may throw after sending; preserve the uncertainty marker.
			return $adapter . '_uncertain';
		}
	}

	/**
	 * Record operational metadata without overwriting a newer form submission during a retry.
	 *
	 * @param int    $form_id Published form ID.
	 * @param int    $entry_id Private archived entry ID.
	 * @param array  $outcomes Status codes keyed by destination.
	 * @param string $key Internal identifier.
	 * @param bool   $initial Whether this is the initial submission.
	 * @return void
	 */
	private static function status( int $form_id, int $entry_id, array $outcomes, string $key, bool $initial ): void {
		$status = array(
			'time'     => current_time( 'mysql' ),
			'key'      => $key,
			'outcomes' => $outcomes,
		);
		$latest = get_post_meta( $form_id, '_easycontact_last_delivery', true );
		if ( $initial || ( is_array( $latest ) && ( $latest['key'] ?? '' ) === $key ) ) {
			update_post_meta( $form_id, '_easycontact_last_delivery', $status );
		}
		if ( $entry_id > 0 && get_post( $entry_id ) ) {
			update_post_meta( $entry_id, '_easycontact_delivery', $status );
		}
	}

	/**
	 * Schedule at most three retries; cron arguments contain only the native entry ID.
	 *
	 * @param int    $entry_id Private archived entry ID.
	 * @param string $hash Keyed form definition fingerprint.
	 * @param array  $outcomes Status codes keyed by destination.
	 * @param array  $job Bounded retry job metadata.
	 * @return void
	 */
	private static function schedule( int $entry_id, string $hash, array $outcomes, array $job = array() ): void {
		if ( ! Review::can_deliver( $entry_id ) ) {
			self::cancel( $entry_id );
			return;
		}
		$pending = false;
		foreach ( $outcomes as $adapter => $outcome ) {
			$pending = $pending || self::retryable( $adapter, $outcome );
		}
		$job = $job ? $job : array(
			'hash'     => $hash,
			'attempts' => 0,
			'expires'  => time() + DAY_IN_SECONDS,
		);
		if ( ! $pending || $job['attempts'] >= 3 || $job['expires'] < time() ) {
			self::cancel( $entry_id );
			return;
		}
		update_post_meta( $entry_id, '_easycontact_retry', $job );
		if ( get_post_meta( $entry_id, '_easycontact_retry', true ) !== $job ) {
			return;
		}
		$delay = array( MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS )[ $job['attempts'] ];
		if ( ! wp_next_scheduled( 'easycontact_retry_delivery', array( $entry_id ) ) ) {
			wp_schedule_single_event( time() + $delay, 'easycontact_retry_delivery', array( $entry_id ) );
		}
	}

	/**
	 * Advise contact with the site when confirmation is ambiguous rather than risking another send.
	 *
	 * @return WP_Error Result of this operation.
	 */
	private static function uncertain_error(): WP_Error {
		return new WP_Error( 'delivery_uncertain', __( 'Non è stato possibile confermare l’invio. Contatta il sito prima di inviare di nuovo il messaggio.', 'easycontact' ), array( 'status' => 503 ) );
	}
}
