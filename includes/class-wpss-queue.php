<?php
/**
 * From user_register to phpList: enqueue, decide, build the payload, send,
 * retry.
 *
 * @package SubscriberSyncForPhpList
 */

defined( 'ABSPATH' ) || exit;

/**
 * The queue.
 */
class WPSS_Queue {

	const HOOK_RUN = 'wpss_run_queue';

	/** Delay before attempt n+1 (seconds). After the last one the job fails. */
	const BACKOFF = array( 60, 300, 1800, 7200, 43200 );

	/** Jobs processed per run. */
	const BATCH = 20;

	/**
	 * Whether this request added a job (to kick the queue at shutdown).
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 20, 1 );
		add_action( self::HOOK_RUN, array( __CLASS__, 'run' ) );
		add_action( 'shutdown', array( __CLASS__, 'kick' ) );
	}

	/**
	 * A user was created. Only record it here: many plugins (Gravity Forms
	 * User Registration among them) write the user meta after this hook, so
	 * the user is read again when the job runs, in a later request.
	 *
	 * @param int $user_id New user ID.
	 */
	public static function on_user_register( $user_id ) {
		if ( ! WPSS_Settings::is_configured() ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		WPSS_Jobs::add( $user_id, $user->user_email );
		self::$enqueued = true;
	}

	/**
	 * End of the registration request: make the queue run in a separate
	 * request right away, instead of waiting for the next visitor.
	 */
	public static function kick() {
		if ( ! self::$enqueued ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_RUN ) ) {
			wp_schedule_single_event( time(), self::HOOK_RUN );
		}
		if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Process due jobs. Runs from WP-Cron (every 5 minutes and right after a
	 * registration) or from the "Run now" button.
	 *
	 * @return int Jobs processed.
	 */
	public static function run() {
		$done = 0;
		foreach ( WPSS_Jobs::due( self::BATCH ) as $id ) {
			if ( WPSS_Jobs::claim( $id ) ) {
				self::process( $id );
				++$done;
			}
		}
		if ( self::BATCH === $done && WPSS_Jobs::has_due() ) {
			wp_schedule_single_event( time() + 10, self::HOOK_RUN );
		}

		return $done;
	}

	/**
	 * Process one claimed job.
	 *
	 * @param int $id Job ID.
	 */
	public static function process( $id ) {
		$job = WPSS_Jobs::get( $id );
		if ( ! $job ) {
			return;
		}

		// The payload is built once and then reused byte for byte, so retries
		// are idempotent on the phpList side even if the user changes meanwhile.
		$raw = (string) $job['payload'];
		if ( '' === $raw ) {
			$user = get_userdata( (int) $job['user_id'] );
			if ( ! $user ) {
				self::finish( $id, 'skipped', __( 'User no longer exists.', 'subscriber-sync-for-phplist' ) );

				return;
			}
			$reason = self::ineligible_reason( $user );
			if ( null !== $reason ) {
				self::finish( $id, 'skipped', $reason );

				return;
			}
			$payload = self::payload( $user, $job['request_id'] );
			$raw     = wp_json_encode( $payload );
			WPSS_Jobs::update(
				$id,
				array(
					'payload' => $raw,
					'email'   => substr( $payload['email'], 0, 190 ),
				)
			);
		}

		$result   = WPSS_Client::send( $raw );
		$attempts = (int) $job['attempts'] + 1;

		if ( 'ok' === $result['outcome'] ) {
			$status = ( isset( $result['body']['status'] ) && 'blacklisted' === $result['body']['status'] ) ? 'blacklisted' : 'subscribed';
			$note   = '';
			if ( 'blacklisted' === $status ) {
				$note = __( 'The address unsubscribed from phpList earlier: not subscribed again.', 'subscriber-sync-for-phplist' );
			} elseif ( ! empty( $result['body']['ignored_attributes'] ) ) {
				/* translators: %s: comma-separated attribute names */
				$note = sprintf( __( 'Attributes ignored by phpList: %s', 'subscriber-sync-for-phplist' ), implode( ', ', array_map( 'strval', (array) $result['body']['ignored_attributes'] ) ) );
			}
			self::finish(
				$id,
				$status,
				$note,
				array(
					'attempts'      => $attempts,
					'subscriber_id' => isset( $result['body']['subscriber_id'] ) ? (int) $result['body']['subscriber_id'] : 0,
				)
			);

			return;
		}

		if ( 'retry' === $result['outcome'] && $attempts <= count( self::BACKOFF ) ) {
			$delay = max( self::BACKOFF[ $attempts - 1 ], (int) $result['retry_after'] );
			WPSS_Jobs::update(
				$id,
				array(
					'status'       => 'pending',
					'attempts'     => $attempts,
					'next_attempt' => WPSS_Jobs::now( $delay ),
					'last_error'   => self::error_text( $result ),
				)
			);

			return;
		}

		self::finish( $id, 'failed', self::error_text( $result ), array( 'attempts' => $attempts ) );
	}

	/**
	 * Close a job.
	 *
	 * @param int    $id     Job ID.
	 * @param string $status Final status.
	 * @param string $note   Message for the log.
	 * @param array  $extra  More columns.
	 */
	private static function finish( $id, $status, $note, array $extra = array() ) {
		WPSS_Jobs::update(
			$id,
			array_merge(
				$extra,
				array(
					'status'       => $status,
					'last_error'   => $note,
					'completed_at' => WPSS_Jobs::now(),
				)
			)
		);
		/**
		 * A job reached a final status.
		 *
		 * @param int    $id     Job ID.
		 * @param string $status subscribed | blacklisted | skipped | failed.
		 */
		do_action( 'wpss_job_finished', $id, $status );
	}

	/**
	 * Why this user must not be subscribed, or null if it must.
	 *
	 * @param WP_User $user User.
	 * @return string|null
	 */
	public static function ineligible_reason( WP_User $user ) {
		$roles = (array) WPSS_Settings::get( 'roles' );
		if ( $roles && ! array_intersect( $roles, (array) $user->roles ) ) {
			$reason = __( 'Role not selected for synchronisation.', 'subscriber-sync-for-phplist' );
		} elseif ( 'all' === WPSS_Settings::get( 'consent_mode' ) ) {
			$reason = null;
		} else {
			$key = (string) WPSS_Settings::get( 'consent_meta_key' );
			if ( '' === $key ) {
				$reason = __( 'Consent rule not configured.', 'subscriber-sync-for-phplist' );
			} else {
				$value  = get_user_meta( $user->ID, $key, true );
				$expect = (string) WPSS_Settings::get( 'consent_meta_value' );
				$reason = ( is_scalar( $value ) && (string) $value === $expect ) ? null
					/* translators: %s: user meta key */
					: sprintf( __( 'No consent (user meta "%s" does not match).', 'subscriber-sync-for-phplist' ), $key );
			}
		}

		/**
		 * Final say on whether a user is subscribed.
		 *
		 * @param bool    $subscribe Result of the built-in rules.
		 * @param WP_User $user      The user.
		 */
		$subscribe = (bool) apply_filters( 'wpss_should_subscribe', null === $reason, $user );
		if ( $subscribe ) {
			return null;
		}

		return null !== $reason ? $reason : __( 'Excluded by the wpss_should_subscribe filter.', 'subscriber-sync-for-phplist' );
	}

	/**
	 * Request body for a user.
	 *
	 * @param WP_User $user       User.
	 * @param string  $request_id Job request ID.
	 * @return array
	 */
	public static function payload( WP_User $user, $request_id ) {
		$attributes = array();
		foreach ( (array) WPSS_Settings::get( 'mapping' ) as $row ) {
			if ( empty( $row['source'] ) || empty( $row['attribute'] ) ) {
				continue;
			}
			$value = self::source_value( $user, $row['source'] );
			if ( '' !== $value ) {
				$attributes[ $row['attribute'] ] = $value;
			}
		}
		$extra = array( 'email' => strtolower( trim( $user->user_email ) ) );
		$list  = (int) WPSS_Settings::get( 'list_id' );
		if ( $list > 0 ) {
			$extra['lists'] = array( $list );
		}
		if ( $attributes ) {
			$extra['attributes'] = $attributes;
		}
		$payload = WPSS_Client::envelope( 'subscribe', $request_id, $extra );

		/**
		 * Change the request sent to phpList for a user. Keep the envelope
		 * (protocol, action, request_id, site) intact.
		 *
		 * @param array   $payload Request body.
		 * @param WP_User $user    The user.
		 */
		return (array) apply_filters( 'wpss_payload', $payload, $user );
	}

	/**
	 * Value of a mapping source for a user.
	 *
	 * @param WP_User $user   User.
	 * @param string  $source first_name | last_name | display_name | user_login | user_url | role | user_registered | meta:<key>.
	 * @return string
	 */
	public static function source_value( WP_User $user, $source ) {
		switch ( $source ) {
			case 'first_name':
			case 'last_name':
				$value = get_user_meta( $user->ID, $source, true );
				break;
			case 'display_name':
			case 'user_login':
			case 'user_url':
				$value = $user->$source;
				break;
			case 'role':
				$value = $user->roles ? reset( $user->roles ) : '';
				break;
			case 'user_registered':
				$value = substr( (string) $user->user_registered, 0, 10 );
				break;
			default:
				if ( 0 === strpos( $source, 'meta:' ) ) {
					$value = get_user_meta( $user->ID, substr( $source, 5 ), true );
				} else {
					$value = '';
				}
		}
		if ( is_array( $value ) ) {
			// Multiple choices (e.g. checkbox fields stored as arrays).
			$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Readable error for the log.
	 *
	 * @param array $result WPSS_Client::send() result.
	 * @return string
	 */
	private static function error_text( array $result ) {
		$prefix = $result['code'] ? 'HTTP ' . $result['code'] . ' ' : '';

		return trim( $prefix . $result['error'] );
	}
}
