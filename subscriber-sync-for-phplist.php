<?php
/**
 * Plugin Name:       Subscriber Sync for phpList
 * Plugin URI:        https://github.com/albertosantambrogio/subscriber-sync-for-phplist
 * Description:       Subscribes new WordPress users to a phpList list through a signed endpoint, with optional notification digests.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Alberto Santambrogio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       subscriber-sync-for-phplist
 * Domain Path:       /languages
 *
 * @package SubscriberSyncForPhpList
 */

defined( 'ABSPATH' ) || exit;

define( 'WPSS_VERSION', '0.1.0' );
define( 'WPSS_DB_VERSION', 1 );
define( 'WPSS_PROTOCOL', 1 );
define( 'WPSS_FILE', __FILE__ );
define( 'WPSS_DIR', plugin_dir_path( __FILE__ ) );

require_once WPSS_DIR . 'includes/class-wpss-settings.php';
require_once WPSS_DIR . 'includes/class-wpss-jobs.php';
require_once WPSS_DIR . 'includes/class-wpss-client.php';
require_once WPSS_DIR . 'includes/class-wpss-queue.php';
require_once WPSS_DIR . 'includes/class-wpss-notifications.php';
require_once WPSS_DIR . 'includes/class-wpss-privacy.php';
require_once WPSS_DIR . 'includes/class-wpss-plugin.php';

register_activation_hook( __FILE__, array( 'WPSS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPSS_Plugin', 'deactivate' ) );

WPSS_Plugin::boot();

if ( is_admin() ) {
	require_once WPSS_DIR . 'includes/class-wpss-admin.php';
	WPSS_Admin::boot();
}
