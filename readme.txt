=== Subscriber Sync for phpList ===
Contributors: albertosantambrogio
Tags: phplist, newsletter, subscribers, registration, mailing list
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically subscribe new WordPress users to a phpList list, and get notified about new subscribers.

== Description ==

Work in progress — not released yet.

Subscriber Sync for phpList adds every new WordPress user (from any registration
form: native, Gravity Forms User Registration, WooCommerce…) to a phpList list,
including private lists that are not shown on public subscribe pages.

* Works with any plugin that creates WordPress users.
* Signed requests (HMAC-SHA256); the phpList side decides which lists a site may use.
* Background queue with automatic retries: registration never fails because phpList is down.
* Consent rule: subscribe everyone, or only users whose meta matches a value.
* Field mapping to phpList attributes.
* Notifications to the site owner: per subscription, daily, weekly or monthly digest,
  with a `[wpss_new_subscribers]` shortcode that renders the table of new subscribers.

Requires the companion phpList plugin:
https://github.com/albertosantambrogio/phplist-plugin-wp-subscriber-sync

== External services ==

This plugin connects to the phpList installation configured by the site
administrator (Settings → phpList Sync). No request is made until an endpoint
URL and key are saved.

When a new user is created and is eligible for subscription, the plugin sends
to that phpList server: the user's e-mail address, the attributes the
administrator mapped (for example first and last name), the target list ID,
and the site URL. The "Test connection" button sends only the site URL.

The phpList server is operated by the site owner or their provider, not by the
plugin author; its terms and privacy policy are those of whoever runs it.

== Changelog ==

= 0.1.0 =
* First release.
