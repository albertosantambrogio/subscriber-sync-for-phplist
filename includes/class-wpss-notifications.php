<?php
/**
 * Notification e-mails to the site owner: one per subscription or a
 * daily/weekly/monthly digest, with the [wpss_new_subscribers] shortcode.
 *
 * @package SubscriberSyncForPhpList
 */

defined( 'ABSPATH' ) || exit;

/**
 * Notifications.
 */
class WPSS_Notifications {

	const HOOK_DIGEST = 'wpss_digest';
	const SHORTCODE   = 'wpss_new_subscribers';

	/**
	 * Rows available to the shortcode while an e-mail is being rendered.
	 *
	 * @var array[]|null
	 */
	private static $rows = null;

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_action( 'wpss_job_finished', array( __CLASS__, 'on_job_finished' ), 10, 2 );
		add_action( self::HOOK_DIGEST, array( __CLASS__, 'maybe_send_digest' ) );
	}

	/**
	 * Statuses included by the "include" setting.
	 *
	 * @return string[]
	 */
	public static function included_statuses() {
		return 'all' === WPSS_Settings::get( 'notify_include' )
			? array( 'subscribed', 'blacklisted', 'failed' )
			: array( 'subscribed' );
	}

	/**
	 * Per-subscription mode.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Final status.
	 */
	public static function on_job_finished( $job_id, $status ) {
		$s = WPSS_Settings::all();
		if ( empty( $s['notify_enabled'] ) || 'each' !== $s['notify_frequency'] || ! in_array( $status, self::included_statuses(), true ) ) {
			return;
		}
		$job = WPSS_Jobs::get( $job_id );
		if ( ! $job || (int) $job['notified'] ) {
			return;
		}
		$when = strtotime( $job['completed_at'] . ' UTC' );
		if ( self::send( array( $job ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) ) ) {
			WPSS_Jobs::update( $job_id, array( 'notified' => 1 ) );
		}
	}

	/**
	 * Hourly check: send the digest once per period, at or after the chosen hour.
	 *
	 * @param bool $force Ignore period and hour (used by tests).
	 * @return bool Whether a digest was sent.
	 */
	public static function maybe_send_digest( $force = false ) {
		$s = WPSS_Settings::all();
		if ( empty( $s['notify_enabled'] ) || ! in_array( $s['notify_frequency'], array( 'daily', 'weekly', 'monthly' ), true ) ) {
			return false;
		}
		$now  = time();
		$last = get_option( WPSS_Settings::LAST_DIGEST, array() );
		$last = is_array( $last ) ? $last : array();
		$key  = self::period_key( $s['notify_frequency'], $now );
		if ( ! $force ) {
			if ( isset( $last['key'] ) && $last['key'] === $key ) {
				return false;
			}
			if ( (int) wp_date( 'G', $now ) < (int) $s['notify_hour'] ) {
				return false;
			}
		}
		$from = isset( $last['time'] ) ? (int) $last['time'] : $now - self::period_seconds( $s['notify_frequency'] );
		$rows = WPSS_Jobs::completed_between( gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $now ), self::included_statuses() );

		$sent = true;
		if ( $rows || empty( $s['notify_skip_empty'] ) ) {
			$sent = self::send( $rows, self::period_label( $from, $now ) );
		}
		// The window only moves forward when the mail went out, so nothing is lost.
		if ( $sent ) {
			update_option(
				WPSS_Settings::LAST_DIGEST,
				array(
					'key'  => $key,
					'time' => $now,
				),
				false
			);
		}

		return $sent && (bool) $rows;
	}

	/**
	 * "Send a test now": the rows since the last digest (or the last period),
	 * without moving the digest window.
	 *
	 * @return bool
	 */
	public static function send_test() {
		$s    = WPSS_Settings::all();
		$now  = time();
		$last = get_option( WPSS_Settings::LAST_DIGEST, array() );
		$freq = in_array( $s['notify_frequency'], array( 'daily', 'weekly', 'monthly' ), true ) ? $s['notify_frequency'] : 'monthly';
		$from = ( is_array( $last ) && isset( $last['time'] ) ) ? (int) $last['time'] : $now - self::period_seconds( $freq );
		$rows = WPSS_Jobs::completed_between( gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $now ), self::included_statuses() );

		return self::send( $rows, self::period_label( $from, $now ), true );
	}

	/**
	 * Render and send one e-mail.
	 *
	 * @param array[] $rows   Job rows.
	 * @param string  $period Text for {period}.
	 * @param bool    $test   Prefix the subject with [TEST].
	 * @return bool
	 */
	public static function send( array $rows, $period, $test = false ) {
		$s          = WPSS_Settings::all();
		$recipients = self::recipients( $s['notify_recipients'] );
		if ( ! $recipients ) {
			return false;
		}
		$subject = '' !== trim( $s['notify_subject'] ) ? $s['notify_subject'] : WPSS_Settings::default_subject();
		$body    = '' !== trim( $s['notify_body'] ) ? $s['notify_body'] : WPSS_Settings::default_body();
		$vars    = array(
			'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'  => home_url( '/' ),
			'{period}'    => $period,
			'{count}'     => (string) count( $rows ),
		);

		$subject = strtr( $subject, $vars );
		if ( $test ) {
			$subject = '[TEST] ' . $subject;
		}
		$html = self::render_body( $body, $rows, $vars );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return (bool) wp_mail( $recipients, wp_strip_all_tags( $subject ), $html, $headers );
	}

	/**
	 * Body → HTML document. The shortcode exists only during this call.
	 *
	 * @param string  $body Template.
	 * @param array[] $rows Rows for the shortcode.
	 * @param array   $vars Placeholders.
	 * @return string
	 */
	public static function render_body( $body, array $rows, array $vars ) {
		$escaped = array_map( 'esc_html', $vars );
		$body    = strtr( $body, $escaped );

		self::$rows = $rows;
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		// Shortcode first: wpautop() then treats the table as a block and does
		// not wrap it in a paragraph.
		$html = wpautop( do_shortcode( $body ) );
		remove_shortcode( self::SHORTCODE );
		self::$rows = null;

		return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
			. '<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1d2327">'
			. $html . '</body></html>';
	}

	/**
	 * [wpss_new_subscribers columns="date,email,first_name" status="subscribed"]
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		if ( null === self::$rows ) {
			return ''; // Never outside a notification e-mail.
		}
		$atts = shortcode_atts(
			array(
				'columns' => 'date,email,first_name,last_name',
				'status'  => '',
			),
			$atts,
			self::SHORTCODE
		);

		$columns = array();
		foreach ( array_map( 'trim', explode( ',', $atts['columns'] ) ) as $col ) {
			if ( in_array( $col, WPSS_Settings::COLUMNS, true ) || ( 0 === strpos( $col, 'meta:' ) && strlen( $col ) > 5 ) ) {
				$columns[] = $col;
			}
		}
		if ( ! $columns ) {
			$columns = array( 'date', 'email' );
		}

		$rows   = self::$rows;
		$status = trim( (string) $atts['status'] );
		if ( '' !== $status && 'all' !== $status ) {
			$wanted = array_map( 'trim', explode( ',', $status ) );
			$rows   = array_values(
				array_filter(
					$rows,
					function ( $r ) use ( $wanted ) {
						return in_array( $r['status'], $wanted, true );
					}
				)
			);
		}

		if ( ! $rows ) {
			return '<p><em>' . esc_html__( 'No new subscribers in this period.', 'subscriber-sync-for-phplist' ) . '</em></p>';
		}

		$th   = 'style="text-align:left;padding:6px 10px;border-bottom:2px solid #c3c4c7;background:#f6f7f7"';
		$td   = 'style="padding:6px 10px;border-bottom:1px solid #dcdcde;vertical-align:top"';
		$html = '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:8px 0 16px">';
		$html .= '<thead><tr>';
		foreach ( $columns as $col ) {
			$html .= '<th ' . $th . '>' . esc_html( self::column_label( $col ) ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$user  = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : false;
			$html .= '<tr>';
			foreach ( $columns as $col ) {
				$html .= '<td ' . $td . '>' . esc_html( self::cell( $col, $row, $user ) ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Column header.
	 *
	 * @param string $col Column.
	 * @return string
	 */
	public static function column_label( $col ) {
		$labels = array(
			'date'         => __( 'Date', 'subscriber-sync-for-phplist' ),
			'email'        => __( 'E-mail', 'subscriber-sync-for-phplist' ),
			'user_login'   => __( 'Username', 'subscriber-sync-for-phplist' ),
			'display_name' => __( 'Name', 'subscriber-sync-for-phplist' ),
			'first_name'   => __( 'First name', 'subscriber-sync-for-phplist' ),
			'last_name'    => __( 'Last name', 'subscriber-sync-for-phplist' ),
			'role'         => __( 'Role', 'subscriber-sync-for-phplist' ),
			'status'       => __( 'Status', 'subscriber-sync-for-phplist' ),
		);
		if ( isset( $labels[ $col ] ) ) {
			return $labels[ $col ];
		}

		return substr( $col, 5 );
	}

	/**
	 * Human-readable status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'pending'     => __( 'Pending', 'subscriber-sync-for-phplist' ),
			'running'     => __( 'Running', 'subscriber-sync-for-phplist' ),
			'subscribed'  => __( 'Subscribed', 'subscriber-sync-for-phplist' ),
			'blacklisted' => __( 'Unsubscribed earlier', 'subscriber-sync-for-phplist' ),
			'skipped'     => __( 'Skipped', 'subscriber-sync-for-phplist' ),
			'failed'      => __( 'Failed', 'subscriber-sync-for-phplist' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Cell value.
	 *
	 * @param string        $col  Column.
	 * @param array         $row  Job row.
	 * @param WP_User|false $user User, if it still exists.
	 * @return string
	 */
	private static function cell( $col, array $row, $user ) {
		switch ( $col ) {
			case 'date':
				$ts = strtotime( ( $row['completed_at'] ? $row['completed_at'] : $row['created_at'] ) . ' UTC' );

				return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
			case 'email':
				return $row['email'];
			case 'status':
				return self::status_label( $row['status'] );
		}
		return $user ? WPSS_Queue::source_value( $user, $col ) : '';
	}

	/**
	 * Valid addresses from a comma/semicolon/space separated string.
	 *
	 * @param string $list Raw list.
	 * @return string[]
	 */
	public static function recipients( $list ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $list ) as $addr ) {
			$addr = sanitize_email( $addr );
			if ( $addr && is_email( $addr ) ) {
				$out[] = $addr;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Identifies the current period, so a digest goes out once per period.
	 *
	 * @param string $freq daily | weekly | monthly.
	 * @param int    $ts   Timestamp.
	 * @return string
	 */
	public static function period_key( $freq, $ts ) {
		switch ( $freq ) {
			case 'weekly':
				return wp_date( 'o-\WW', $ts );
			case 'monthly':
				return wp_date( 'Y-m', $ts );
			default:
				return wp_date( 'Y-m-d', $ts );
		}
	}

	/**
	 * Length of a period, for the first digest.
	 *
	 * @param string $freq Frequency.
	 * @return int
	 */
	private static function period_seconds( $freq ) {
		switch ( $freq ) {
			case 'weekly':
				return WEEK_IN_SECONDS;
			case 'monthly':
				return MONTH_IN_SECONDS;
			default:
				return DAY_IN_SECONDS;
		}
	}

	/**
	 * "26 November 2026 08:00 – 27 November 2026 08:00".
	 *
	 * @param int $from Start.
	 * @param int $to   End.
	 * @return string
	 */
	public static function period_label( $from, $to ) {
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return wp_date( $fmt, $from ) . ' – ' . wp_date( $fmt, $to );
	}
}
