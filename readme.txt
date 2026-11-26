=== Subscriber Sync for phpList ===
Contributors: qiqqio
Tags: phplist, newsletter, subscribers, registration, mailing list
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically subscribe new WordPress users to a phpList list, and get notified about new subscribers.

== Description ==

Subscriber Sync for phpList adds every new WordPress user (from any registration
form: native, Gravity Forms User Registration, WooCommerce…) to a phpList list,
including private lists that are not shown on public subscribe pages.

* Works with any plugin that creates WordPress users.
* Signed requests (HMAC-SHA256); the phpList side decides which lists a site may use and whether new subscribers are confirmed.
* Background queue with automatic retries: registration never fails or slows down because phpList is down.
* People who unsubscribed in phpList are never subscribed again.
* Consent rule: subscribe everyone, or only users whose meta matches a value.
* Field mapping to phpList attributes.
* Notifications to the site owner: per subscription, daily, weekly or monthly digest,
  with a `[wpss_new_subscribers]` shortcode that renders the table of new subscribers.

= Requirements =

This plugin does nothing on its own: it talks to a phpList installation (https://www.phplist.org/) that you run or that your provider runs for you.

* phpList 3.6 or later (tested with 3.7.0), reachable over HTTPS.
* The free companion phpList plugin **WP Subscriber Sync**, installed on the phpList server. It is not distributed through WordPress.org because it runs inside phpList, not WordPress. Source code, installation instructions and the wire protocol: https://github.com/albertosantambrogio/phplist-plugin-wp-subscriber-sync
* Administrator access to phpList, to create a key for each WordPress site.

This plugin is not affiliated with, endorsed or sponsored by phpList Ltd. phpList is a trademark of its respective owner.

== Installation ==

1. In phpList, install the companion plugin from https://github.com/albertosantambrogio/phplist-plugin-wp-subscriber-sync (copy its `plugins/` folder into the phpList plugin directory, then enable it in Config → Manage plugins) and create a key for this site (Subscribers → WordPress sync keys).
2. In WordPress, install and activate this plugin, then open Settings → phpList Sync.
3. Connection: paste the endpoint URL, the key ID and the secret, then save (the connection is tested).
4. Subscription: choose the list, the roles, the consent rule and the fields to copy.
5. Optional: Notifications.

For reliable timing, disable the page-view cron (`define( 'DISABLE_WP_CRON', true );`) and run `wp-cron.php` from a system cron every few minutes.

== Frequently Asked Questions ==

= Does it work with Gravity Forms? =

Yes, with the Gravity Forms User Registration add-on (or any other plugin that creates WordPress users). The subscription is processed after the registration has finished, so the user meta written by the add-on (first name, last name, consent…) is available. To subscribe only users who gave consent, map the consent field to a user meta in the registration feed and use the "Only if a user meta field has a given value" rule.

= What happens if phpList is unreachable? =

The registration is not affected. The subscription is retried after 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours; every attempt is listed in the Log tab, with a Retry button for failed ones.

= Why is the phpList plugin not on WordPress.org? =

Because it is a phpList plugin: it runs inside phpList, on the phpList server. It is free software (AGPL-3.0, like phpList) and is published on GitHub together with the protocol both plugins use.

= Are existing users subscribed? =

Not in this version: only users created after the plugin is configured.

= Hooks for developers =

* `wpss_should_subscribe( bool $subscribe, WP_User $user )`
* `wpss_payload( array $payload, WP_User $user )`
* `wpss_job_finished( int $job_id, string $status )` (action)

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
phpList itself is open-source software (AGPL-3.0): https://www.phplist.org/.
Requests are signed with a key created in
phpList and sent only to the endpoint URL configured by the administrator.

== Development ==

Source code and issue tracker: https://github.com/albertosantambrogio/subscriber-sync-for-phplist

== Changelog ==

= 0.1.0 =
* First release: queue with retries, consent rules, field mapping, per-subscription and digest notifications, privacy exporter/eraser.
