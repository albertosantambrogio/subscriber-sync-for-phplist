<?php
/**
 * Plugin Name:       Subscriber Sync for phpList
 * Plugin URI:        https://github.com/albertosantambrogio/subscriber-sync-for-phplist
 * Description:       Subscribes new WordPress users to a phpList list through a signed endpoint, with optional notification digests.
 * Version:           0.1.0-dev
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

define( 'WPSS_VERSION', '0.1.0-dev' );
define( 'WPSS_PROTOCOL', 1 );
define( 'WPSS_FILE', __FILE__ );
define( 'WPSS_DIR', plugin_dir_path( __FILE__ ) );

// Implementation pending: see docs/DESIGN.md.
