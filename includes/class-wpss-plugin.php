<?php
/**
 * Wiring: activation, cron schedules, upgrades.
 *
 * @package SubscriberSyncForPhpList
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
class WPSS_Plugin {

	const HOOK_PURGE = 'wpss_purge';

	/**
	 * Register hooks.
	 */
	public static function boot() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'init' ) );
		add_action( self::HOOK_PURGE, array( __CLASS__, 'purge' ) );
		add_action( WPSS_Queue::HOOK_RUN . '_recurring', array( 'WPSS_Queue', 'run' ) );
		WPSS_Queue::boot();
		WPSS_Notifications::boot();
		WPSS_Privacy::boot();
	}

	/**
	 * Translations, schema upgrades and self-healing of cron events.
	 */
	public static function init() {
		load_plugin_textdomain( 'subscriber-sync-for-phplist', false, dirname( plugin_basename( WPSS_FILE ) ) . '/languages' );
		if ( (int) get_option( 'wpss_db_version' ) < WPSS_DB_VERSION ) {
			WPSS_Jobs::install();
		}
		self::schedule_events();
	}

	/**
	 * Every five minutes, for retries.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['wpss_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (phpList Sync)', 'subscriber-sync-for-phplist' ),
		);

		return $schedules;
	}

	/**
	 * Make sure the recurring events exist.
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( WPSS_Queue::HOOK_RUN . '_recurring' ) ) {
			wp_schedule_event( time() + 60, 'wpss_five_minutes', WPSS_Queue::HOOK_RUN . '_recurring' );
		}
		if ( ! wp_next_scheduled( WPSS_Notifications::HOOK_DIGEST ) ) {
			wp_schedule_event( time() + 120, 'hourly', WPSS_Notifications::HOOK_DIGEST );
		}
		if ( ! wp_next_scheduled( self::HOOK_PURGE ) ) {
			wp_schedule_event( time() + 300, 'daily', self::HOOK_PURGE );
		}
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		WPSS_Jobs::install();
		if ( false === get_option( WPSS_Settings::OPTION ) ) {
			add_option( WPSS_Settings::OPTION, WPSS_Settings::defaults(), '', false );
		}
	}

	/**
	 * Deactivation: remove the cron events (data stays until uninstall).
	 */
	public static function deactivate() {
		foreach ( array( WPSS_Queue::HOOK_RUN, WPSS_Queue::HOOK_RUN . '_recurring', WPSS_Notifications::HOOK_DIGEST, self::HOOK_PURGE ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Daily clean-up of old log rows.
	 */
	public static function purge() {
		WPSS_Jobs::purge( (int) WPSS_Settings::get( 'retention_days' ) );
	}
}
