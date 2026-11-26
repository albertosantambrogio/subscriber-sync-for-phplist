<?php
/**
 * The jobs table: one row per user to subscribe, used as queue, log and
 * source of the notification digests.
 *
 * @package SubscriberSyncForPhpList
 */

defined( 'ABSPATH' ) || exit;

// The plugin's own queue table: direct queries are the only way to reach it, and
// results must never come from the object cache (jobs change between requests).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Data access for {prefix}wpss_jobs.
 */
class WPSS_Jobs {

	const STATUSES = array( 'pending', 'running', 'subscribed', 'blacklisted', 'skipped', 'failed' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'wpss_jobs';
	}

	/**
	 * Create or upgrade the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		// dbDelta wants two spaces after PRIMARY KEY and one field per line.
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  email varchar(190) NOT NULL DEFAULT '',
  request_id char(36) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_attempt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  payload longtext NULL,
  last_error text NULL,
  subscriber_id bigint(20) unsigned NOT NULL DEFAULT 0,
  notified tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  completed_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status_next (status,next_attempt),
  KEY completed (completed_at),
  KEY email (email),
  KEY user_id (user_id)
) {$charset};"
		);
		update_option( 'wpss_db_version', WPSS_DB_VERSION, false );
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	public static function now( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
	}

	/**
	 * Add a job for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   E-mail at creation time.
	 * @return int Job ID.
	 */
	public static function add( $user_id, $email ) {
		global $wpdb;
		$now = self::now();
		$wpdb->insert(
			self::table(),
			array(
				'user_id'      => (int) $user_id,
				'email'        => substr( (string) $email, 0, 190 ),
				'request_id'   => wp_generate_uuid4(),
				'status'       => 'pending',
				'next_attempt' => $now,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * One job.
	 *
	 * @param int $id Job ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
	}

	/**
	 * Update fields of a job.
	 *
	 * @param int   $id     Job ID.
	 * @param array $fields Column => value.
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = self::now();
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) );
	}

	/**
	 * Atomically move a due job from pending to running.
	 *
	 * @param int $id Job ID.
	 * @return bool True if this process owns the job now.
	 */
	public static function claim( $id ) {
		global $wpdb;
		$now = self::now();
		// next_attempt doubles as the lock expiry while running.
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'running', next_attempt = %s, updated_at = %s WHERE id = %d AND status = 'pending' AND next_attempt <= %s",
				self::table(),
				self::now( 300 ),
				$now,
				$id,
				$now
			)
		);

		return 1 === $rows;
	}

	/**
	 * IDs of jobs that are due, oldest first. Running jobs whose lock expired
	 * (the process died) are released first.
	 *
	 * @param int $limit Max jobs.
	 * @return int[]
	 */
	public static function due( $limit = 20 ) {
		global $wpdb;
		$now = self::now();
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'pending' WHERE status = 'running' AND next_attempt < %s", self::table(), $now ) );

		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'pending' AND next_attempt <= %s ORDER BY id LIMIT %d", self::table(), $now, $limit ) )
		);
	}

	/**
	 * Whether anything is waiting (to decide if a quick extra run is worth it).
	 *
	 * @return bool
	 */
	public static function has_due() {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE status = 'pending' AND next_attempt <= %s LIMIT 1", self::table(), self::now() ) );
	}

	/**
	 * Jobs completed in a time window, for notifications.
	 *
	 * @param string   $from     UTC, exclusive.
	 * @param string   $to       UTC, inclusive.
	 * @param string[] $statuses Statuses to include.
	 * @return array[]
	 */
	public static function completed_between( $from, $to, array $statuses ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE completed_at > %s AND completed_at <= %s ORDER BY completed_at, id', self::table(), $from, $to ), ARRAY_A );

		// A notification window holds few rows: filter by status here, keeping the SQL static.
		return array_values(
			array_filter(
				(array) $rows,
				function ( $row ) use ( $statuses ) {
					return in_array( $row['status'], $statuses, true );
				}
			)
		);
	}

	/**
	 * Page of jobs for the log screen.
	 *
	 * @param string $status   Filter ('' = all).
	 * @param int    $page     1-based page.
	 * @param int    $per_page Rows per page.
	 * @return array [rows, total]
	 */
	public static function page( $status, $page, $per_page ) {
		global $wpdb;
		$table  = self::table();
		$limit  = (int) $per_page;
		$offset = (int) ( max( 1, $page ) - 1 ) * $limit;
		if ( in_array( $status, self::STATUSES, true ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, $status ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d', $table, $status, $limit, $offset ), ARRAY_A );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $table, $limit, $offset ), ARRAY_A );
		}

		return array( (array) $rows, $total );
	}

	/**
	 * Count per status.
	 *
	 * @return array status => count
	 */
	public static function counts() {
		global $wpdb;
		$out = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', self::table() ), ARRAY_A ) as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Delete finished rows older than the retention period.
	 *
	 * @param int $days Retention in days.
	 */
	public static function purge( $days ) {
		global $wpdb;
		$days = max( 1, (int) $days );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status IN ('subscribed','blacklisted','skipped','failed') AND updated_at < %s", self::table(), self::now( -$days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Rows for an e-mail address (privacy tools).
	 *
	 * @param string $email E-mail.
	 * @return array[]
	 */
	public static function by_email( $email ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id', self::table(), $email ), ARRAY_A );
	}

	/**
	 * Delete rows for an e-mail address (privacy eraser).
	 *
	 * @param string $email E-mail.
	 * @return int Rows deleted.
	 */
	public static function delete_by_email( $email ) {
		global $wpdb;

		return (int) $wpdb->delete( self::table(), array( 'email' => $email ), array( '%s' ) );
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
