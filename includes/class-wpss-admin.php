<?php
/**
 * Settings → phpList Sync: connection, subscription rules, notifications, log.
 *
 * @package SubscriberSyncForPhpList
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens. Every change goes through admin-post.php with a nonce and
 * the manage_options capability.
 */
class WPSS_Admin {

	const PAGE = 'wpss';
	const CAP  = 'manage_options';

	/** Mapping sources offered in the UI (plus meta:<key>). */
	const SOURCES = array( 'first_name', 'last_name', 'display_name', 'user_login', 'user_url', 'role', 'user_registered' );

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice_not_configured' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPSS_FILE ), array( __CLASS__, 'action_links' ) );
		foreach ( array( 'save', 'ping', 'run', 'retry', 'test_mail' ) as $action ) {
			add_action( 'admin_post_wpss_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_options_page(
			__( 'phpList Sync', 'subscriber-sync-for-phplist' ),
			__( 'phpList Sync', 'subscriber-sync-for-phplist' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * "Settings" link in the plugin list.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'subscriber-sync-for-phplist' ) . '</a>' );

		return $links;
	}

	/**
	 * Reminder until the connection is configured (plugins screen only).
	 */
	public static function notice_not_configured() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( self::CAP ) || WPSS_Settings::is_configured() ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . wp_kses_post(
			sprintf(
				/* translators: %s: settings URL */
				__( 'Subscriber Sync for phpList is not connected yet. <a href="%s">Configure it</a>.', 'subscriber-sync-for-phplist' ),
				esc_url( self::url() )
			)
		) . '</p></div>';
	}

	/**
	 * Settings page URL.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args More query args.
	 * @return string
	 */
	public static function url( $tab = 'connection', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'options-general.php' ) );
	}

	// ------------------------------------------------------------------ handlers

	/**
	 * Nonce + capability check.
	 *
	 * @param string $action Action name.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'subscriber-sync-for-phplist' ), 403 );
		}
		check_admin_referer( 'wpss_' . $action );
	}

	/**
	 * Redirect back with a message.
	 *
	 * @param string $tab  Tab.
	 * @param string $msg  Message code.
	 * @param array  $args More args.
	 */
	private static function back( $tab, $msg, array $args = array() ) {
		wp_safe_redirect( self::url( $tab, array_merge( array( 'wpss_msg' => $msg ), $args ) ) );
		exit;
	}

	/**
	 * Request value as string.
	 *
	 * @param string $name Field.
	 * @return string
	 */
	private static function post( $name ) {
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? trim( wp_unslash( $_POST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in guard(), sanitized by callers.
	}

	/**
	 * Save one tab.
	 */
	public static function handle_save() {
		self::guard( 'save' );
		$tab = self::post( 'tab' );
		$old = WPSS_Settings::all();
		$new = array();

		if ( 'connection' === $tab ) {
			$new['endpoint_url'] = esc_url_raw( self::post( 'endpoint_url' ), array( 'https', 'http' ) );
			$key                 = self::post( 'key_id' );
			$new['key_id']       = preg_match( '/^[a-z0-9_]{1,32}$/', $key ) ? $key : '';
			$secret              = strtolower( self::post( 'secret' ) );
			if ( '' !== $secret && ! WPSS_Settings::secret_is_constant() ) {
				if ( ! preg_match( '/^[0-9a-f]{32,128}$/', $secret ) ) {
					self::back( $tab, 'bad_secret' );
				}
				update_option( WPSS_Settings::SECRET, $secret, false );
			}
			if ( $new['endpoint_url'] !== $old['endpoint_url'] || $new['key_id'] !== $old['key_id'] || '' !== $secret ) {
				delete_option( WPSS_Settings::PING_CACHE );
			}
			WPSS_Settings::update( $new );
			// Test straight away: it also fills the list and attribute choices.
			if ( WPSS_Settings::is_configured() ) {
				$r = WPSS_Client::ping();
				self::back( $tab, 'ok' === $r['outcome'] ? 'saved_ping_ok' : 'saved_ping_fail', array( 'wpss_err' => rawurlencode( substr( $r['error'], 0, 200 ) ) ) );
			}
			self::back( $tab, 'saved' );
		}

		if ( 'subscription' === $tab ) {
			$new['list_id'] = absint( self::post( 'list_id' ) );
			$roles          = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['roles'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$new['roles']   = array_values( array_intersect( $roles, array_keys( wp_roles()->roles ) ) );
			$mode           = self::post( 'consent_mode' );
			$new['consent_mode']       = 'all' === $mode ? 'all' : 'meta';
			$new['consent_meta_key']   = sanitize_text_field( self::post( 'consent_meta_key' ) );
			$new['consent_meta_value'] = sanitize_text_field( self::post( 'consent_meta_value' ) );
			$new['retention_days']     = max( 1, min( 3650, absint( self::post( 'retention_days' ) ) ) );
			$new['mapping']            = array();
			$sources = isset( $_POST['map_source'] ) && is_array( $_POST['map_source'] ) ? wp_unslash( $_POST['map_source'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$attrs   = isset( $_POST['map_attribute'] ) && is_array( $_POST['map_attribute'] ) ? wp_unslash( $_POST['map_attribute'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( $sources as $i => $source ) {
				$source = sanitize_text_field( (string) $source );
				$attr   = isset( $attrs[ $i ] ) ? sanitize_text_field( (string) $attrs[ $i ] ) : '';
				if ( '' === $source || '' === $attr ) {
					continue;
				}
				if ( ! in_array( $source, self::SOURCES, true ) && ! ( 0 === strpos( $source, 'meta:' ) && strlen( $source ) > 5 ) ) {
					// A bare word is taken as a user meta key.
					$source = 'meta:' . $source;
				}
				$new['mapping'][] = array(
					'source'    => $source,
					'attribute' => $attr,
				);
			}
			WPSS_Settings::update( $new );
			self::back( $tab, 'saved' );
		}

		if ( 'notifications' === $tab ) {
			$new['notify_enabled']    = '1' === self::post( 'notify_enabled' );
			$freq                     = self::post( 'notify_frequency' );
			$new['notify_frequency']  = in_array( $freq, WPSS_Settings::FREQUENCIES, true ) ? $freq : 'daily';
			$new['notify_hour']       = max( 0, min( 23, (int) self::post( 'notify_hour' ) ) );
			$new['notify_recipients'] = implode( ', ', WPSS_Notifications::recipients( self::post( 'notify_recipients' ) ) );
			$new['notify_subject']    = sanitize_text_field( self::post( 'notify_subject' ) );
			$new['notify_body']       = wp_kses_post( self::post( 'notify_body' ) );
			$new['notify_skip_empty'] = '1' === self::post( 'notify_skip_empty' );
			$new['notify_include']    = 'all' === self::post( 'notify_include' ) ? 'all' : 'subscribed';

			// Start the digest window now, so the first digest covers the next
			// full period and not the whole history.
			$last = get_option( WPSS_Settings::LAST_DIGEST, array() );
			if ( $new['notify_enabled'] && 'each' !== $new['notify_frequency']
				&& ( ! is_array( $last ) || ! isset( $last['time'] ) || $new['notify_frequency'] !== $old['notify_frequency'] || ! $old['notify_enabled'] ) ) {
				update_option(
					WPSS_Settings::LAST_DIGEST,
					array(
						'key'  => WPSS_Notifications::period_key( $new['notify_frequency'], time() ),
						'time' => ( is_array( $last ) && isset( $last['time'] ) ) ? (int) $last['time'] : time(),
					),
					false
				);
			}
			WPSS_Settings::update( $new );
			self::back( $tab, $new['notify_enabled'] && ! $new['notify_recipients'] ? 'saved_no_recipients' : 'saved' );
		}

		self::back( 'connection', 'error' );
	}

	/**
	 * Test the connection.
	 */
	public static function handle_ping() {
		self::guard( 'ping' );
		$r = WPSS_Client::ping();
		self::back( 'connection', 'ok' === $r['outcome'] ? 'ping_ok' : 'ping_fail', array( 'wpss_err' => rawurlencode( substr( $r['error'], 0, 200 ) ) ) );
	}

	/**
	 * Process the queue now.
	 */
	public static function handle_run() {
		self::guard( 'run' );
		$n = WPSS_Queue::run();
		self::back( 'log', 'run', array( 'wpss_n' => $n ) );
	}

	/**
	 * Retry a failed or skipped job (rules are evaluated again).
	 */
	public static function handle_retry() {
		self::guard( 'retry' );
		$id  = absint( self::post( 'job' ) );
		$job = WPSS_Jobs::get( $id );
		if ( $job && in_array( $job['status'], array( 'failed', 'skipped' ), true ) ) {
			WPSS_Jobs::update(
				$id,
				array(
					'status'       => 'pending',
					'attempts'     => 0,
					'payload'      => null,
					// A new request ID: the payload may now differ from the one phpList saw.
					'request_id'   => wp_generate_uuid4(),
					'next_attempt' => WPSS_Jobs::now(),
					'last_error'   => '',
					'completed_at' => null,
					'notified'     => 0,
				)
			);
			if ( WPSS_Jobs::claim( $id ) ) {
				WPSS_Queue::process( $id );
			}
		}
		self::back( 'log', 'retried' );
	}

	/**
	 * Send a test notification.
	 */
	public static function handle_test_mail() {
		self::guard( 'test_mail' );
		self::back( 'notifications', WPSS_Notifications::send_test() ? 'mail_ok' : 'mail_fail' );
	}

	// ------------------------------------------------------------------ rendering

	/**
	 * Page.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tabs = array(
			'connection'    => __( 'Connection', 'subscriber-sync-for-phplist' ),
			'subscription'  => __( 'Subscription', 'subscriber-sync-for-phplist' ),
			'notifications' => __( 'Notifications', 'subscriber-sync-for-phplist' ),
			'log'           => __( 'Log', 'subscriber-sync-for-phplist' ),
		);
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'connection';

		echo '<div class="wrap"><h1>' . esc_html__( 'Subscriber Sync for phpList', 'subscriber-sync-for-phplist' ) . '</h1>';
		self::render_message();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( $id ) ), $id === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
	}

	/**
	 * Feedback after a redirect.
	 */
	private static function render_message() {
		$msg = isset( $_GET['wpss_msg'] ) ? sanitize_key( wp_unslash( $_GET['wpss_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err = isset( $_GET['wpss_err'] ) ? sanitize_text_field( wp_unslash( $_GET['wpss_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$n   = isset( $_GET['wpss_n'] ) ? absint( $_GET['wpss_n'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = array(
			'saved'               => array( 'success', __( 'Settings saved.', 'subscriber-sync-for-phplist' ) ),
			'saved_ping_ok'       => array( 'success', __( 'Settings saved. Connection to phpList OK.', 'subscriber-sync-for-phplist' ) ),
			/* translators: %s: error message */
			'saved_ping_fail'     => array( 'error', sprintf( __( 'Settings saved, but the connection test failed: %s', 'subscriber-sync-for-phplist' ), $err ) ),
			'ping_ok'             => array( 'success', __( 'Connection to phpList OK.', 'subscriber-sync-for-phplist' ) ),
			/* translators: %s: error message */
			'ping_fail'           => array( 'error', sprintf( __( 'Connection test failed: %s', 'subscriber-sync-for-phplist' ), $err ) ),
			'bad_secret'          => array( 'error', __( 'The secret must be the hexadecimal string shown by phpList. Nothing was saved.', 'subscriber-sync-for-phplist' ) ),
			/* translators: %d: number of jobs */
			'run'                 => array( 'success', sprintf( _n( '%d job processed.', '%d jobs processed.', $n, 'subscriber-sync-for-phplist' ), $n ) ),
			'retried'             => array( 'success', __( 'Job processed again.', 'subscriber-sync-for-phplist' ) ),
			'mail_ok'             => array( 'success', __( 'Test e-mail sent.', 'subscriber-sync-for-phplist' ) ),
			'mail_fail'           => array( 'error', __( 'The test e-mail could not be sent: check the recipients and the site mail configuration.', 'subscriber-sync-for-phplist' ) ),
			'saved_no_recipients' => array( 'warning', __( 'Settings saved, but there is no valid recipient: no notification will be sent.', 'subscriber-sync-for-phplist' ) ),
			'error'               => array( 'error', __( 'Something went wrong.', 'subscriber-sync-for-phplist' ) ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
		}
	}

	/**
	 * Open a form posting to admin-post.php.
	 *
	 * @param string $action Action.
	 * @param string $tab    Tab.
	 */
	private static function form_open( $action, $tab = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wpss_' . esc_attr( $action ) . '">';
		if ( '' !== $tab ) {
			echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		}
		wp_nonce_field( 'wpss_' . $action );
	}

	/**
	 * Connection tab.
	 */
	private static function tab_connection() {
		$s     = WPSS_Settings::all();
		$cache = get_option( WPSS_Settings::PING_CACHE, array() );

		echo '<p>' . esc_html__( 'Create a key for this site in phpList (Subscribers → WordPress sync keys, provided by the WP Subscriber Sync plugin for phpList) and paste the values here.', 'subscriber-sync-for-phplist' ) . '</p>';
		self::form_open( 'save', 'connection' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="wpss-url">' . esc_html__( 'Endpoint URL', 'subscriber-sync-for-phplist' ) . '</label></th><td>'
			. '<input type="url" class="large-text code" id="wpss-url" name="endpoint_url" value="' . esc_attr( $s['endpoint_url'] ) . '" placeholder="https://example.org/lists/?pi=WpSubscriberSync&amp;p=endpoint">';
		if ( '' !== $s['endpoint_url'] && 0 !== strpos( $s['endpoint_url'], 'https://' ) ) {
			echo '<p class="description" style="color:#b32d2e">' . esc_html__( 'The address is not HTTPS: phpList refuses plain HTTP unless explicitly allowed for development.', 'subscriber-sync-for-phplist' ) . '</p>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wpss-key">' . esc_html__( 'Key ID', 'subscriber-sync-for-phplist' ) . '</label></th><td>'
			. '<input type="text" class="regular-text code" id="wpss-key" name="key_id" value="' . esc_attr( $s['key_id'] ) . '" placeholder="wpss_…" autocomplete="off"></td></tr>';
		echo '<tr><th scope="row"><label for="wpss-secret">' . esc_html__( 'Secret', 'subscriber-sync-for-phplist' ) . '</label></th><td>';
		if ( WPSS_Settings::secret_is_constant() ) {
			echo '<p>' . esc_html__( 'Defined in wp-config.php (WPSS_SECRET).', 'subscriber-sync-for-phplist' ) . '</p>';
		} else {
			$has = '' !== WPSS_Settings::secret();
			echo '<input type="password" class="regular-text code" id="wpss-secret" name="secret" value="" autocomplete="new-password" placeholder="' . esc_attr( $has ? __( 'saved — leave empty to keep it', 'subscriber-sync-for-phplist' ) : '' ) . '">';
			echo '<p class="description">' . esc_html__( 'Stored in the database. To keep it out of the database, define WPSS_SECRET in wp-config.php instead.', 'subscriber-sync-for-phplist' ) . '</p>';
		}
		echo '</td></tr></table>';
		submit_button( __( 'Save and test', 'subscriber-sync-for-phplist' ) );
		echo '</form>';

		if ( WPSS_Settings::is_configured() ) {
			self::form_open( 'ping' );
			submit_button( __( 'Test connection', 'subscriber-sync-for-phplist' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		if ( is_array( $cache ) && ! empty( $cache['time'] ) ) {
			echo '<h2>' . esc_html__( 'Last successful test', 'subscriber-sync-for-phplist' ) . '</h2><table class="widefat striped" style="max-width:800px"><tbody>';
			echo '<tr><th>' . esc_html__( 'When', 'subscriber-sync-for-phplist' ) . '</th><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $cache['time'] ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Server', 'subscriber-sync-for-phplist' ) . '</th><td>' . esc_html( $cache['server'] ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'New subscribers are', 'subscriber-sync-for-phplist' ) . '</th><td>' . esc_html( 'confirmed' === $cache['confirm_policy'] ? __( 'confirmed immediately', 'subscriber-sync-for-phplist' ) : __( 'left unconfirmed', 'subscriber-sync-for-phplist' ) ) . ' <span class="description">' . esc_html__( '(decided in phpList)', 'subscriber-sync-for-phplist' ) . '</span></td></tr>';
			$lists = array();
			foreach ( $cache['lists'] as $l ) {
				$lists[] = esc_html( $l['name'] ) . ' <code>#' . (int) $l['id'] . '</code>' . ( ! empty( $l['default'] ) ? ' <em>' . esc_html__( 'default', 'subscriber-sync-for-phplist' ) . '</em>' : '' );
			}
			echo '<tr><th>' . esc_html__( 'Lists', 'subscriber-sync-for-phplist' ) . '</th><td>' . ( $lists ? implode( '<br>', $lists ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			$attrs = array();
			foreach ( $cache['attributes'] as $a ) {
				$attrs[] = esc_html( $a['name'] ) . ' <span class="description">(' . esc_html( $a['type'] ) . ')</span>';
			}
			echo '<tr><th>' . esc_html__( 'Attributes', 'subscriber-sync-for-phplist' ) . '</th><td>' . ( $attrs ? implode( '<br>', $attrs ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			echo '</tbody></table>';
		}
	}

	/**
	 * Subscription tab.
	 */
	private static function tab_subscription() {
		$s     = WPSS_Settings::all();
		$cache = get_option( WPSS_Settings::PING_CACHE, array() );
		$lists = is_array( $cache ) && isset( $cache['lists'] ) ? $cache['lists'] : array();
		$attrs = is_array( $cache ) && isset( $cache['attributes'] ) ? $cache['attributes'] : array();

		self::form_open( 'save', 'subscription' );
		echo '<table class="form-table" role="presentation">';

		// List.
		echo '<tr><th scope="row"><label for="wpss-list">' . esc_html__( 'phpList list', 'subscriber-sync-for-phplist' ) . '</label></th><td>';
		if ( $lists ) {
			echo '<select id="wpss-list" name="list_id"><option value="0">' . esc_html__( 'Default list(s) of the key', 'subscriber-sync-for-phplist' ) . '</option>';
			foreach ( $lists as $l ) {
				printf( '<option value="%d"%s>%s (#%d)</option>', (int) $l['id'], selected( (int) $s['list_id'], (int) $l['id'], false ), esc_html( $l['name'] ), (int) $l['id'] );
			}
			echo '</select>';
		} else {
			echo '<input type="number" min="0" id="wpss-list" name="list_id" value="' . (int) $s['list_id'] . '"> <p class="description">' . esc_html__( 'Test the connection to choose from the lists allowed for this site. 0 = default list(s) of the key.', 'subscriber-sync-for-phplist' ) . '</p>';
		}
		echo '</td></tr>';

		// Roles.
		echo '<tr><th scope="row">' . esc_html__( 'Roles', 'subscriber-sync-for-phplist' ) . '</th><td><fieldset>';
		foreach ( wp_roles()->get_names() as $role => $name ) {
			printf( '<label><input type="checkbox" name="roles[]" value="%s"%s> %s</label><br>', esc_attr( $role ), checked( in_array( $role, (array) $s['roles'], true ), true, false ), esc_html( translate_user_role( $name ) ) );
		}
		echo '<p class="description">' . esc_html__( 'Only new users with one of these roles are subscribed. None selected = every role.', 'subscriber-sync-for-phplist' ) . '</p></fieldset></td></tr>';

		// Consent.
		echo '<tr><th scope="row">' . esc_html__( 'Consent', 'subscriber-sync-for-phplist' ) . '</th><td><fieldset>';
		printf( '<label><input type="radio" name="consent_mode" value="all"%s> %s</label><br>', checked( $s['consent_mode'], 'all', false ), esc_html__( 'Subscribe every new user (consent is collected at registration, e.g. a mandatory checkbox)', 'subscriber-sync-for-phplist' ) );
		printf( '<label><input type="radio" name="consent_mode" value="meta"%s> %s</label><br>', checked( $s['consent_mode'], 'meta', false ), esc_html__( 'Only if a user meta field has a given value', 'subscriber-sync-for-phplist' ) );
		printf(
			'<p style="margin:6px 0 0 24px"><label>%s <input type="text" class="regular-text code" name="consent_meta_key" value="%s" placeholder="newsletter_consent"></label> <label>%s <input type="text" class="small-text code" name="consent_meta_value" value="%s"></label></p>',
			esc_html__( 'Meta key', 'subscriber-sync-for-phplist' ),
			esc_attr( $s['consent_meta_key'] ),
			esc_html__( 'equals', 'subscriber-sync-for-phplist' ),
			esc_attr( $s['consent_meta_value'] )
		);
		echo '<p class="description">' . esc_html__( 'With Gravity Forms User Registration, map the consent field to a user meta in the registration feed and use that key here.', 'subscriber-sync-for-phplist' ) . '</p>';
		echo '</fieldset></td></tr>';

		// Mapping.
		echo '<tr><th scope="row">' . esc_html__( 'Fields', 'subscriber-sync-for-phplist' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'The e-mail address is always sent. Choose what else to copy into phpList attributes. For a user meta field, type its key (e.g. meta:profession).', 'subscriber-sync-for-phplist' ) . '</p>';
		echo '<datalist id="wpss-sources">';
		foreach ( self::SOURCES as $src ) {
			echo '<option value="' . esc_attr( $src ) . '">';
		}
		echo '</datalist><datalist id="wpss-attrs">';
		foreach ( $attrs as $a ) {
			echo '<option value="' . esc_attr( $a['name'] ) . '">';
		}
		echo '</datalist>';
		echo '<table class="widefat" style="max-width:700px;margin-top:8px"><thead><tr><th>' . esc_html__( 'WordPress', 'subscriber-sync-for-phplist' ) . '</th><th>' . esc_html__( 'phpList attribute', 'subscriber-sync-for-phplist' ) . '</th></tr></thead><tbody>';
		$rows = (array) $s['mapping'];
		$rows = array_merge( $rows, array_fill( 0, 3, array( 'source' => '', 'attribute' => '' ) ) );
		foreach ( $rows as $row ) {
			printf(
				'<tr><td><input type="text" class="regular-text code" list="wpss-sources" name="map_source[]" value="%s"></td><td><input type="text" class="regular-text" list="wpss-attrs" name="map_attribute[]" value="%s"></td></tr>',
				esc_attr( $row['source'] ),
				esc_attr( $row['attribute'] )
			);
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Empty rows are ignored; save to get more empty rows.', 'subscriber-sync-for-phplist' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="wpss-retention">' . esc_html__( 'Keep the log for', 'subscriber-sync-for-phplist' ) . '</label></th><td><input type="number" min="1" max="3650" class="small-text" id="wpss-retention" name="retention_days" value="' . (int) $s['retention_days'] . '"> ' . esc_html__( 'days', 'subscriber-sync-for-phplist' ) . '</td></tr>';
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	/**
	 * Notifications tab.
	 */
	private static function tab_notifications() {
		$s       = WPSS_Settings::all();
		$subject = '' !== $s['notify_subject'] ? $s['notify_subject'] : WPSS_Settings::default_subject();
		$body    = '' !== $s['notify_body'] ? $s['notify_body'] : WPSS_Settings::default_body();

		self::form_open( 'save', 'notifications' );
		echo '<table class="form-table" role="presentation">';
		printf( '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="notify_enabled" value="1"%s> %s</label></td></tr>', esc_html__( 'Send subscription notifications', 'subscriber-sync-for-phplist' ), checked( $s['notify_enabled'], true, false ), esc_html__( 'Enabled', 'subscriber-sync-for-phplist' ) );

		$freqs = array(
			'each'    => __( 'For every subscription', 'subscriber-sync-for-phplist' ),
			'daily'   => __( 'Daily summary', 'subscriber-sync-for-phplist' ),
			'weekly'  => __( 'Weekly summary (Monday)', 'subscriber-sync-for-phplist' ),
			'monthly' => __( 'Monthly summary (1st of the month)', 'subscriber-sync-for-phplist' ),
		);
		echo '<tr><th scope="row">' . esc_html__( 'Frequency', 'subscriber-sync-for-phplist' ) . '</th><td><fieldset>';
		foreach ( $freqs as $val => $label ) {
			printf( '<label><input type="radio" name="notify_frequency" value="%s"%s> %s</label><br>', esc_attr( $val ), checked( $s['notify_frequency'], $val, false ), esc_html( $label ) );
		}
		echo '<p>' . esc_html__( 'Summaries are sent at or after', 'subscriber-sync-for-phplist' ) . ' <select name="notify_hour">';
		for ( $h = 0; $h < 24; $h++ ) {
			printf( '<option value="%d"%s>%02d:00</option>', (int) $h, selected( (int) $s['notify_hour'], $h, false ), (int) $h );
		}
		echo '</select> <span class="description">' . esc_html__( '(site time zone; WP-Cron needs visits or a system cron)', 'subscriber-sync-for-phplist' ) . '</span></p>';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="wpss-rcpt">' . esc_html__( 'Recipients', 'subscriber-sync-for-phplist' ) . '</label></th><td><input type="text" class="large-text" id="wpss-rcpt" name="notify_recipients" value="' . esc_attr( $s['notify_recipients'] ) . '" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '"><p class="description">' . esc_html__( 'Separate several addresses with commas.', 'subscriber-sync-for-phplist' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpss-subj">' . esc_html__( 'Subject', 'subscriber-sync-for-phplist' ) . '</label></th><td><input type="text" class="large-text" id="wpss-subj" name="notify_subject" value="' . esc_attr( $subject ) . '"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Message', 'subscriber-sync-for-phplist' ) . '</th><td>';
		wp_editor(
			$body,
			'wpss_notify_body',
			array(
				'textarea_name' => 'notify_body',
				'textarea_rows' => 10,
				'media_buttons' => false,
			)
		);
		echo '<p class="description">' . wp_kses_post(
			__( 'Placeholders: <code>{site_name}</code>, <code>{site_url}</code>, <code>{period}</code>, <code>{count}</code>.<br>Table of new subscribers: <code>[wpss_new_subscribers columns="date,email,first_name,last_name"]</code>. Columns: <code>date</code>, <code>email</code>, <code>user_login</code>, <code>display_name</code>, <code>first_name</code>, <code>last_name</code>, <code>role</code>, <code>status</code>, <code>meta:your_key</code>. Optional <code>status="subscribed"</code> (or <code>blacklisted</code>, <code>failed</code>, <code>all</code>).', 'subscriber-sync-for-phplist' )
		) . '</p></td></tr>';

		printf( '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="notify_skip_empty" value="1"%s> %s</label></td></tr>', esc_html__( 'Empty summaries', 'subscriber-sync-for-phplist' ), checked( $s['notify_skip_empty'], true, false ), esc_html__( 'Do not send a summary when there are no new subscribers', 'subscriber-sync-for-phplist' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Include', 'subscriber-sync-for-phplist' ) . '</th><td><fieldset>';
		printf( '<label><input type="radio" name="notify_include" value="subscribed"%s> %s</label><br>', checked( $s['notify_include'], 'subscribed', false ), esc_html__( 'Only successful subscriptions', 'subscriber-sync-for-phplist' ) );
		printf( '<label><input type="radio" name="notify_include" value="all"%s> %s</label>', checked( $s['notify_include'], 'all', false ), esc_html__( 'Also addresses that had unsubscribed earlier and failed subscriptions', 'subscriber-sync-for-phplist' ) );
		echo '</fieldset></td></tr></table>';
		submit_button();
		echo '</form>';

		self::form_open( 'test_mail' );
		echo '<p>' . esc_html__( 'Sends the saved template now, with the subscriptions since the last summary (nothing is marked as sent).', 'subscriber-sync-for-phplist' ) . '</p>';
		submit_button( __( 'Send a test now', 'subscriber-sync-for-phplist' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Log tab.
	 */
	private static function tab_log() {
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 50;
		$counts   = WPSS_Jobs::counts();
		list( $rows, $total ) = WPSS_Jobs::page( $status, $paged, $per_page );

		echo '<ul class="subsubsub">';
		$links = array( '<li><a href="' . esc_url( self::url( 'log' ) ) . '"' . ( '' === $status ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'subscriber-sync-for-phplist' ) . ' <span class="count">(' . (int) array_sum( $counts ) . ')</span></a>' );
		foreach ( $counts as $st => $n ) {
			if ( $n > 0 ) {
				$links[] = '<li><a href="' . esc_url( self::url( 'log', array( 'status' => $st ) ) ) . '"' . ( $st === $status ? ' class="current"' : '' ) . '>' . esc_html( WPSS_Notifications::status_label( $st ) ) . ' <span class="count">(' . (int) $n . ')</span></a>';
			}
		}
		echo implode( ' | </li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		self::form_open( 'run' );
		echo '<p style="float:right;margin:4px 0">';
		submit_button( __( 'Process the queue now', 'subscriber-sync-for-phplist' ), 'secondary', 'submit', false );
		echo '</p></form>';

		echo '<table class="widefat striped" style="clear:both"><thead><tr>'
			. '<th>' . esc_html__( 'Date', 'subscriber-sync-for-phplist' ) . '</th>'
			. '<th>' . esc_html__( 'User', 'subscriber-sync-for-phplist' ) . '</th>'
			. '<th>' . esc_html__( 'E-mail', 'subscriber-sync-for-phplist' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'subscriber-sync-for-phplist' ) . '</th>'
			. '<th>' . esc_html__( 'Attempts', 'subscriber-sync-for-phplist' ) . '</th>'
			. '<th>' . esc_html__( 'Notes', 'subscriber-sync-for-phplist' ) . '</th>'
			. '<th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'Nothing here yet.', 'subscriber-sync-for-phplist' ) . '</td></tr>';
		}
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		foreach ( $rows as $r ) {
			$user  = $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : false;
			$who   = $user ? '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->user_login ) . '</a>' : '<em>' . esc_html__( 'deleted', 'subscriber-sync-for-phplist' ) . '</em>';
			$notes = (string) $r['last_error'];
			if ( 'pending' === $r['status'] && (int) $r['attempts'] > 0 ) {
				/* translators: %s: date and time */
				$notes .= ( '' !== $notes ? ' — ' : '' ) . sprintf( __( 'next attempt %s', 'subscriber-sync-for-phplist' ), wp_date( $fmt, strtotime( $r['next_attempt'] . ' UTC' ) ) );
			}
			$retry = '';
			if ( in_array( $r['status'], array( 'failed', 'skipped' ), true ) ) {
				ob_start();
				self::form_open( 'retry' );
				echo '<input type="hidden" name="job" value="' . (int) $r['id'] . '">';
				submit_button( __( 'Retry', 'subscriber-sync-for-phplist' ), 'small', 'submit', false );
				echo '</form>';
				$retry = ob_get_clean();
			}
			echo '<tr><td>' . esc_html( wp_date( $fmt, strtotime( $r['created_at'] . ' UTC' ) ) ) . '</td>'
				. '<td>' . $who . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				. '<td>' . esc_html( $r['email'] ) . '</td>'
				. '<td>' . esc_html( WPSS_Notifications::status_label( $r['status'] ) ) . '</td>'
				. '<td>' . (int) $r['attempts'] . '</td>'
				. '<td>' . esc_html( $notes ) . '</td>'
				. '<td>' . $retry . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping above.
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::url( 'log', $status ? array( 'status' => $status ) : array() ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}
		echo '<p class="description">' . esc_html__( 'Failed requests are retried automatically after 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours. "Retry" evaluates the rules again (useful after changing the consent rule or the roles).', 'subscriber-sync-for-phplist' ) . '</p>';
	}
}
