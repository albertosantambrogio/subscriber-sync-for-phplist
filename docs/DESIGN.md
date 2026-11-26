# Subscriber Sync for phpList — design

Status: implemented in 0.1.0 (except where marked *not in scope*).

## Goal

Automatically subscribe newly created WordPress users to a (usually private)
phpList list, whatever created the user: native registration, Gravity Forms
User Registration, WooCommerce, an admin, WP-CLI…

Server side: the phpList plugin
[phplist-plugin-wp-subscriber-sync](https://github.com/albertosantambrogio/phplist-plugin-wp-subscriber-sync),
which exposes a signed endpoint. The wire format is in its
[`docs/PROTOCOL.md`](https://github.com/albertosantambrogio/phplist-plugin-wp-subscriber-sync/blob/main/docs/PROTOCOL.md).

## Requirements

- PHP 7.4+, WordPress 6.0+ (tested up to current).
- No hard dependency on Gravity Forms or any other plugin.
- No outgoing request until an administrator has configured the endpoint.

## Flow

```
user_register (priority 20)
   └─ eligibility check (role filter + consent rule + wpss_should_subscribe filter)
        └─ insert job row in {prefix}wpss_jobs (status = pending, new request_id)
             └─ schedule single event wpss_process_job (as soon as possible,
                i.e. after the registration request has finished)

wpss_process_job
   └─ reload the user (meta is now complete)
        └─ build payload from field mapping
             └─ POST signed request
                  ├─ 200 subscribed/blacklisted → status = done / blacklisted
                  ├─ 4xx (not 429)              → status = failed (no retry)
                  └─ 429 / 5xx / network        → retry with back-off
```

We hook WordPress' own `user_register`, but never read the user inside that
hook: Gravity Forms User Registration (and many other plugins) call
`wp_insert_user()` first and write user meta afterwards, in the same request.
At `user_register` time first name, last name, consent and any custom meta may
still be missing. The job therefore runs in a separate request, after the
registration one has finished, and reloads the user with all its meta. This
also keeps the HTTP call to phpList out of the visitor's registration.

Scheduling uses WP-Cron only (no Action Scheduler dependency): at the end of
the registration request the plugin schedules `wpss_run_queue` and calls
`spawn_cron()`, so the job runs a moment later in a separate request; a
recurring event every five minutes handles retries. A real system cron is
recommended in the readme because WP-Cron otherwise depends on page views.

Jobs are claimed atomically (`pending` → `running`, with a 5-minute lock), so
two cron runs never send the same job twice. The payload is stored on the
first attempt and resent byte for byte, which keeps retries idempotent on the
phpList side.

## Settings (Settings → phpList Sync)

**Connection**
- Endpoint URL, Key ID, Secret. The secret can be defined in `wp-config.php`
  (`WPSS_SECRET`) instead of being stored in the database; when the constant
  exists the field is hidden. Saving tests the connection.
- "Test connection" button → `ping`; fills the list and attribute drop-downs.

**Subscription**
- Target list (from `ping`).
- Roles to sync (default: `subscriber`).
- Consent rule:
  - *Subscribe every new user* (consent collected at registration, e.g.
    mandatory checkbox);
  - *Only if user meta `<key>` equals `<value>`* — **default** for new
    installs;
  - developers: `wpss_should_subscribe( bool $subscribe, WP_User $user )`.
- Field mapping: rows of *WordPress source* → *phpList attribute*.
  Sources: `user_email`, `first_name`, `last_name`, `display_name`,
  `user_login`, role, any user meta key.
  Filter: `wpss_payload( array $payload, WP_User $user )`.

**Log**
- Table of the last jobs (date, user, email, status, attempts, last error),
  with "Retry" on failed jobs.

**Notifications** — see below.

## Notifications ("Send subscription notifications")

| Setting     | Values                                                     |
|-------------|------------------------------------------------------------|
| Enabled     | on / off                                                   |
| Frequency   | each subscription, daily, weekly (Monday), monthly (1st)   |
| Time        | hour of day for digests (site timezone), default 08:00     |
| Recipients  | comma-separated addresses                                  |
| Subject     | text, placeholders allowed                                 |
| Body        | rich text (wp_editor), placeholders and shortcode allowed  |
| Skip empty  | do not send a digest when there are no new subscribers (default on) |
| Include     | only successful subscriptions (default) / also failed      |
| Send test   | button: sends the current template for the last period now |

Placeholders: `{site_name}`, `{site_url}`, `{period}` (e.g. "1–30 November
2026", or the subscription date for per-user mode), `{count}`.

Shortcode, only evaluated inside the notification body (it is registered just
before rendering the e-mail and removed right after, so it never outputs
personal data on the front end):

```
[wpss_new_subscribers columns="date,email,first_name,last_name" status="subscribed"]
```

- `columns`: any of `date`, `email`, `user_login`, `display_name`,
  `first_name`, `last_name`, `role`, `status`, `meta:<key>`.
  Default `date,email,first_name,last_name`.
- `status`: `subscribed` (default), `blacklisted`, `failed`, `all`.
- Output: an HTML table with inline styles (mail clients ignore `<style>`).
  In per-user mode the table has one row.

The digest period is "since the last digest sent" (stored in an option), not a
fixed window, so a missed cron run never loses rows.

Mail is sent with `wp_mail()` as HTML, so any SMTP plugin applies.

## Data

`{prefix}wpss_jobs`

| column        | type          |
|---------------|---------------|
| id            | bigint PK     |
| user_id       | bigint        |
| email         | varchar(190)  |
| request_id    | char(36)      |
| status        | pending / running / subscribed / blacklisted / skipped / failed |
| attempts      | tinyint       |
| next_attempt  | datetime (UTC)|
| payload       | longtext (JSON sent, frozen at first attempt) |
| last_error    | text (error or note) |
| subscriber_id | bigint (phpList id) |
| notified      | tinyint (per-subscription mail sent) |
| completed_at  | datetime (UTC), used by the digests |
| created_at    | datetime (UTC)|
| updated_at    | datetime (UTC)|

Retention: rows older than N days (default 180) are purged daily.

## Privacy

- Personal data exporter and eraser registered for the jobs table.
- Suggested privacy-policy text via `wp_add_privacy_policy_content()`.
- readme.txt "External services" section describes what is sent, to whom and
  when.

## Not in scope for 0.1

- Bulk sync of existing users (the queue makes it easy: planned for 0.2).
- Propagating e-mail changes / account deletion / unsubscribes back.
- Multiple lists per role.
