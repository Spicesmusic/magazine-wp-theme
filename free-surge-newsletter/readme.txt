=== Free Surge Newsletter ===
Contributors: freesurge
Tags: newsletter, subscribers, subscription, signup form
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collects confirmed newsletter subscribers and provides self-service unsubscribe tools.

== Description ==

Free Surge Newsletter adds a signup form, double opt-in confirmation, a subscriber screen in WordPress admin, and an unsubscribe flow. It does not create campaigns or send bulk mail in this release.

== Installation ==

1. Upload the `free-surge-newsletter` folder to `/wp-content/plugins/` or install its ZIP through Plugins > Add New Plugin > Upload Plugin.
2. Activate **Free Surge Newsletter**.
3. Add `[fsn_newsletter_form]` to a page or a Shortcode block.
4. Ensure the site can send transactional mail with `wp_mail()`. Configure a trusted SMTP or API mail plugin/provider for confirmation messages if the host's mail is not reliable.
5. Set a privacy policy page in Settings > Privacy so the form can link to it.

== Subscriber flow ==

The form collects an email address, optional first name, and explicit consent. New records remain pending until the reader opens the confirmation email and submits the confirmation form. Confirmation links expire after 48 hours. The plugin stores the consent and confirmation timestamps and exposes subscriber personal data to the WordPress privacy exporter and eraser.

Subscribers can unsubscribe using the link shown after confirmation. The same link is emailed to them after confirmation so it can be used later. Opening an unsubscribe link only displays a confirmation screen; a POST action applies the unsubscribe request.

== Admin ==

Administrators can view the 100 most recent subscriber records, filter by status, and delete an individual record. Subscriber management does not currently include CSV export, search, or bulk actions.

== Privacy ==

The plugin stores subscriber email, optional first name, subscription status, consent/confirmation dates, consent source/version, and record timestamps. Confirmation and unsubscribe tokens are stored as SHA-256 hashes. A short-lived, site-salted rate-limit key derived from the request IP is stored as a transient to reduce signup abuse. Pending subscriptions expire after 48 hours.

Review and adapt the site's privacy policy and consent wording for the applicable laws and the site's actual email practices. Do not add people to the list without their consent.

== Uninstall ==

Subscriber data is preserved when the plugin is uninstalled. To explicitly delete all plugin data on uninstall, set the WordPress option `fsn_delete_data_on_uninstall` to boolean `true` before uninstalling.

== Changelog ==

= 0.1.0 =
* Initial release: shortcode signup form, consent capture, double opt-in, subscriber list, individual deletion, and unsubscribe flow.

