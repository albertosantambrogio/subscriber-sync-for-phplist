<?php
/**
 * WordPress privacy tools: exporter, eraser and suggested policy text.
 *
 * @package SubscriberSyncForPhpList
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy integration.
 */
class WPSS_Privacy {

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	/**
	 * Exporter registration.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['subscriber-sync-for-phplist'] = array(
			'exporter_friendly_name' => __( 'phpList synchronisation log', 'subscriber-sync-for-phplist' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Eraser registration.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['subscriber-sync-for-phplist'] = array(
			'eraser_friendly_name' => __( 'phpList synchronisation log', 'subscriber-sync-for-phplist' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export the log rows of an address.
	 *
	 * @param string $email E-mail.
	 * @return array
	 */
	public static function export( $email ) {
		$data = array();
		foreach ( WPSS_Jobs::by_email( $email ) as $row ) {
			$data[] = array(
				'group_id'    => 'wpss-jobs',
				'group_label' => __( 'phpList synchronisation', 'subscriber-sync-for-phplist' ),
				'item_id'     => 'wpss-job-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'E-mail', 'subscriber-sync-for-phplist' ),
						'value' => $row['email'],
					),
					array(
						'name'  => __( 'Status', 'subscriber-sync-for-phplist' ),
						'value' => WPSS_Notifications::status_label( $row['status'] ),
					),
					array(
						'name'  => __( 'Date', 'subscriber-sync-for-phplist' ),
						'value' => $row['completed_at'] ? $row['completed_at'] : $row['created_at'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase the log rows of an address. The phpList subscription itself is
	 * managed in phpList.
	 *
	 * @param string $email E-mail.
	 * @return array
	 */
	public static function erase( $email ) {
		$n = WPSS_Jobs::delete_by_email( $email );

		return array(
			'items_removed'  => $n > 0,
			'items_retained' => false,
			'messages'       => $n > 0 ? array( __( 'The phpList subscription, if any, must be removed in phpList.', 'subscriber-sync-for-phplist' ) ) : array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . __( 'When you create an account on this site, your e-mail address and, depending on the configuration, other profile data (such as first and last name) are sent to our phpList newsletter system to subscribe you to our mailing list. You can unsubscribe at any time using the link included in every message.', 'subscriber-sync-for-phplist' ) . '</p>';
		wp_add_privacy_policy_content( 'Subscriber Sync for phpList', wp_kses_post( $text ) );
	}
}
