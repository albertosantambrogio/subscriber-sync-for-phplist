<?php
/**
 * Settings storage: one option array plus a few internal options.
 *
 * @package SubscriberSyncForPhpList
 */

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the plugin settings.
 */
class WPSS_Settings {

	const OPTION      = 'wpss_settings';
	const SECRET      = 'wpss_secret';
	const PING_CACHE  = 'wpss_ping_cache';
	const LAST_DIGEST = 'wpss_last_digest';

	/** Notification frequencies. */
	const FREQUENCIES = array( 'each', 'daily', 'weekly', 'monthly' );

	/** Columns accepted by the [wpss_new_subscribers] shortcode (plus meta:<key>). */
	const COLUMNS = array( 'date', 'email', 'user_login', 'display_name', 'first_name', 'last_name', 'role', 'status' );

	/**
	 * Default values. Consent defaults to the meta rule with no key, so a fresh
	 * install never subscribes anybody until the administrator decides how.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'endpoint_url'       => '',
			'key_id'             => '',
			'list_id'            => 0,
			'roles'              => array( 'subscriber' ),
			'consent_mode'       => 'meta',
			'consent_meta_key'   => '',
			'consent_meta_value' => '1',
			'mapping'            => array(),
			'retention_days'     => 180,
			'notify_enabled'     => false,
			'notify_frequency'   => 'daily',
			'notify_hour'        => 8,
			'notify_recipients'  => '',
			'notify_subject'     => '',
			'notify_body'        => '',
			'notify_skip_empty'  => true,
			'notify_include'     => 'subscribed',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $name Setting name.
	 * @return mixed
	 */
	public static function get( $name ) {
		$all = self::all();

		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/**
	 * Save settings (already sanitized).
	 *
	 * @param array $values Values to merge into the stored settings.
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	/**
	 * The HMAC secret: the WPSS_SECRET constant wins over the stored value.
	 *
	 * @return string
	 */
	public static function secret() {
		if ( defined( 'WPSS_SECRET' ) && is_string( WPSS_SECRET ) && WPSS_SECRET !== '' ) {
			return WPSS_SECRET;
		}

		return (string) get_option( self::SECRET, '' );
	}

	/**
	 * Whether the secret comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function secret_is_constant() {
		return defined( 'WPSS_SECRET' ) && is_string( WPSS_SECRET ) && WPSS_SECRET !== '';
	}

	/**
	 * Endpoint, key and secret are all set: only then the plugin talks to phpList.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return self::get( 'endpoint_url' ) !== '' && self::get( 'key_id' ) !== '' && self::secret() !== '';
	}

	/**
	 * Default notification subject, translated.
	 *
	 * @return string
	 */
	public static function default_subject() {
		/* translators: placeholders are replaced when the e-mail is sent; keep them as they are. */
		return __( '[{site_name}] New subscribers: {count}', 'subscriber-sync-for-phplist' );
	}

	/**
	 * Default notification body, translated.
	 *
	 * @return string
	 */
	public static function default_body() {
		return '<p>' . __( 'New users subscribed to the phpList list ({period}):', 'subscriber-sync-for-phplist' ) . '</p>' . "\n\n"
			. '[wpss_new_subscribers columns="date,email,first_name,last_name"]';
	}
}
