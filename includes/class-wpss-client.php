<?php
/**
 * HTTP client for the phpList endpoint (protocol v1, see the phpList plugin's
 * docs/PROTOCOL.md).
 *
 * @package SubscriberSyncForPhpList
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signs and sends requests.
 */
class WPSS_Client {

	/**
	 * Send a body (already JSON-encoded) and classify the answer.
	 *
	 * @param string $raw Raw JSON body; on retries it must be byte-identical.
	 * @return array {
	 *     @type string $outcome    ok | retry | fail
	 *     @type int    $code       HTTP code (0 on network errors)
	 *     @type array  $body       Decoded response, or empty
	 *     @type string $error      Human-readable error
	 *     @type int    $retry_after Seconds suggested by the server, or 0
	 * }
	 */
	public static function send( $raw ) {
		$url    = WPSS_Settings::get( 'endpoint_url' );
		$key_id = WPSS_Settings::get( 'key_id' );
		$secret = WPSS_Settings::secret();
		if ( '' === $url || '' === $key_id || '' === $secret ) {
			return self::result( 'fail', 0, array(), __( 'The connection is not configured.', 'subscriber-sync-for-phplist' ) );
		}
		$ts       = (string) time();
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'     => 'application/json',
					'X-WPSS-Key'       => $key_id,
					'X-WPSS-Timestamp' => $ts,
					'X-WPSS-Signature' => hash_hmac( 'sha256', $ts . "\n" . $raw, $secret ),
				),
				'body'        => $raw,
				'user-agent'  => 'SubscriberSyncForPhpList/' . WPSS_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::result( 'retry', 0, array(), $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();
		$msg  = isset( $body['message'] ) ? (string) $body['message'] : '';
		if ( isset( $body['error'] ) ) {
			$msg = $body['error'] . ( '' !== $msg ? ': ' . $msg : '' );
		}

		if ( $code >= 200 && $code < 300 && isset( $body['status'] ) && 'error' !== $body['status'] ) {
			return self::result( 'ok', $code, $body, '' );
		}
		if ( 429 === $code ) {
			$after = (int) wp_remote_retrieve_header( $response, 'retry-after' );

			return self::result( 'retry', $code, $body, $msg ? $msg : 'rate_limited', $after );
		}
		if ( $code >= 500 || 0 === $code ) {
			return self::result( 'retry', $code, $body, $msg ? $msg : sprintf( 'HTTP %d', $code ) );
		}
		if ( $code >= 200 && $code < 300 ) {
			// 2xx without a JSON status: probably not our endpoint (a login page, a proxy).
			return self::result( 'fail', $code, $body, __( 'Unexpected answer: is the endpoint URL right?', 'subscriber-sync-for-phplist' ) );
		}

		return self::result( 'fail', $code, $body, $msg ? $msg : sprintf( 'HTTP %d', $code ) );
	}

	/**
	 * Build the envelope common to every request.
	 *
	 * @param string $action     ping | subscribe.
	 * @param string $request_id UUID.
	 * @param array  $extra      Additional fields.
	 * @return array
	 */
	public static function envelope( $action, $request_id, array $extra = array() ) {
		return array_merge(
			array(
				'protocol'   => WPSS_PROTOCOL,
				'action'     => $action,
				'request_id' => $request_id,
				'site'       => home_url( '/' ),
			),
			$extra
		);
	}

	/**
	 * Test the connection and cache the lists/attributes the key may use.
	 *
	 * @return array Same shape as send().
	 */
	public static function ping() {
		$result = self::send( wp_json_encode( self::envelope( 'ping', wp_generate_uuid4() ) ) );
		if ( 'ok' === $result['outcome'] ) {
			update_option(
				WPSS_Settings::PING_CACHE,
				array(
					'time'           => time(),
					'server'         => isset( $result['body']['server'] ) ? (string) $result['body']['server'] : '',
					'confirm_policy' => isset( $result['body']['confirm_policy'] ) ? (string) $result['body']['confirm_policy'] : '',
					'lists'          => isset( $result['body']['lists'] ) && is_array( $result['body']['lists'] ) ? $result['body']['lists'] : array(),
					'attributes'     => isset( $result['body']['attributes'] ) && is_array( $result['body']['attributes'] ) ? $result['body']['attributes'] : array(),
				),
				false
			);
		}

		return $result;
	}

	/**
	 * Result array.
	 *
	 * @param string $outcome     ok | retry | fail.
	 * @param int    $code        HTTP code.
	 * @param array  $body        Decoded body.
	 * @param string $error       Error text.
	 * @param int    $retry_after Seconds.
	 * @return array
	 */
	private static function result( $outcome, $code, array $body, $error, $retry_after = 0 ) {
		return array(
			'outcome'     => $outcome,
			'code'        => (int) $code,
			'body'        => $body,
			'error'       => (string) $error,
			'retry_after' => (int) $retry_after,
		);
	}
}
