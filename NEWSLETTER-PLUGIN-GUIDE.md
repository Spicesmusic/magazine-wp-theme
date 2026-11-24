# WordPress Newsletter Plugin: Step-by-Step Build Guide

This guide describes a focused newsletter plugin for the Free Surge newsroom site. Editors can assemble a digest from published stories, while visitors can subscribe and manage their preferences. The existing newsroom assistant creates drafts for editorial review, so the newsletter workflow must only offer published posts.

## Product decisions for the first version

- Build a native WordPress plugin with subscriber and campaign data stored in WordPress.
- Use a configured transactional email provider for delivery. Do not treat the web host's default PHP mail setup as a bulk email service.
- Require confirmation of new subscriptions (double opt-in) and include a working unsubscribe link in every campaign.
- Let editors create a campaign from published posts, preview it, send a test, and then schedule or send it.
- Keep the first release focused: no visual drag-and-drop editor, behavioral tracking, CRM, or automated marketing journeys.

## Step 1: Confirm requirements and constraints

Before coding, agree on the answers to these questions:

1. What name and sender address should readers see?
2. Which mail provider will send campaigns, and what credentials or API configuration does it require?
3. Should the first release send immediately, schedule campaigns, or support both?
4. Which subscriber fields are needed? Start with email and optional first name unless there is a clear use for more.
5. Which consent wording and privacy policy URL should the form show?
6. Should campaigns include all published posts since the previous digest, or only posts selected by an editor?

Record the decisions in the plugin settings and setup documentation. Confirm applicable privacy and email rules for the audience and sender jurisdiction before launch.

## Step 2: Define the first-release user journeys

**Reader:** submits the signup form → receives a confirmation email → confirms → receives only campaigns sent to subscribed contacts → can unsubscribe at any time.

**Editor:** creates a campaign → selects published posts → previews desktop/mobile output → sends a test → sends now or schedules → checks campaign status and results.

Write acceptance criteria for each journey before implementation. In particular, confirm that pending, unsubscribed, and bounced contacts cannot be included in a campaign.

## Step 3: Create the plugin scaffold

Create a directory such as `free-surge-newsletter/` with a main plugin file and separated modules. A reasonable starting layout is:

```text
free-surge-newsletter/
├── free-surge-newsletter.php
├── uninstall.php
├── includes/
│   ├── class-fsn-activator.php
│   ├── class-fsn-subscribers.php
│   ├── class-fsn-forms.php
│   ├── class-fsn-campaigns.php
│   ├── class-fsn-mailer.php
│   ├── class-fsn-queue.php
│   └── class-fsn-admin.php
├── templates/
│   ├── confirmation-email.php
│   └── campaign-email.php
├── assets/
├── languages/
└── readme.txt
```

Use a unique prefix such as `fsn_` for functions, hooks, options, database tables, and CSS classes. Add a plugin header, version constant, activation/deactivation hooks, and an `ABSPATH` guard. Load admin code only in wp-admin and public form code only where needed.

## Step 4: Choose the data model

Use custom database tables for subscribers and campaigns if the plugin needs reliable status transitions, filtering, and a growing list. Use WordPress posts for campaign content only if that makes editorial workflows simpler; otherwise keep campaign records in a custom table. Store only data the plugin needs.

Suggested subscriber fields:

- Internal ID
- Email address (normalized and unique)
- Optional first name
- Status: `pending`, `subscribed`, `unsubscribed`, or `bounced`
- Confirmation token hash and token expiry
- Consent timestamp and form/source identifier
- Created/updated timestamps

Suggested campaign fields:

- Internal ID, subject, preview text, and selected published post IDs
- Status: `draft`, `queued`, `sending`, `sent`, `paused`, or `failed`
- Scheduled/sent timestamps and delivery counters

Add schema versioning and use `dbDelta()` for safe table creation and upgrades. Define a retention and deletion policy before adding campaign tracking data.

## Step 5: Build the signup and confirmation flow

1. Provide a shortcode, and preferably a native block, for the signup form.
2. Validate email addresses on the server; sanitize all submitted fields and escape all output.
3. Add a nonce and rate limiting or another anti-abuse measure.
4. Save a new contact as `pending`, not `subscribed`.
5. Generate a cryptographically secure, expiring confirmation token. Store a hash of the token rather than the raw token.
6. Email a confirmation link and change the status to `subscribed` only after a valid confirmation.
7. Make repeated submissions safe and avoid revealing whether a particular address is already subscribed.
8. Provide clear success, expired-link, and already-confirmed states.

Never subscribe WordPress users automatically. A login or account creation is not newsletter consent.

## Step 6: Implement unsubscribe and subscriber management

Include a per-subscriber unsubscribe link in every campaign. Use a random, unguessable token; support one-click unsubscribe where appropriate; and make the action idempotent. A successful unsubscribe should take effect immediately and block future sends.

In wp-admin, let authorized users search, filter by status, export necessary fields, and delete contacts. Protect every state-changing admin action with capability checks, nonces, validation, and prepared SQL. Avoid exposing subscriber addresses to users without the required capability.

## Step 7: Build editor campaign creation

Create a campaign screen with:

1. Subject and preview text.
2. A recipient summary based only on currently subscribed contacts.
3. A searchable list of **published** posts, with title, excerpt, date, and permalink.
4. Reordering and removal of selected stories.
5. A preview that uses the same renderer as the eventual email.
6. Test-send, save-draft, schedule, and send actions.

Query for `post_status=publish`; do not accept post IDs from the browser without rechecking status and permissions on the server. Render email HTML with conservative, responsive markup and a plain-text alternative. Escape post fields and use WordPress-generated permalinks.

## Step 8: Integrate a delivery provider and queue

Define a small mailer interface so the campaign logic is not tied to a single provider. Implement one provider first, using its supported API or a well-configured SMTP service. Keep credentials out of source control and avoid displaying secrets after they are saved.

Do not send a large recipient list in one web request. Queue recipients in batches, use WP-Cron or a reliable server scheduler to process batches, and record success/failure per recipient. Make retries bounded and idempotent so a timeout does not cause duplicate messages. Add pause/resume and failure reporting. Explain that WP-Cron runs when site traffic triggers it; recommend a real server cron for predictable schedules.

Before campaign sending is enabled, require sender identity and provider configuration, and show a test-send action. Document SPF, DKIM, and DMARC setup with the chosen mail provider.

## Step 9: Add privacy-conscious reporting

For the MVP, report totals such as queued, sent, and failed. Avoid open tracking by default: image-based open tracking is imprecise and creates additional privacy obligations. If click tracking is considered later, make it explicit, configurable, and documented. Never log confirmation or unsubscribe tokens, provider secrets, or full email bodies unnecessarily.

## Step 10: Secure and harden the plugin

- Enforce capabilities for all admin pages and actions.
- Use nonces for forms and AJAX actions, but do not mistake a nonce for authorization.
- Use `$wpdb->prepare()` for variable SQL and escape output for its context.
- Validate status transitions on the server; never trust hidden fields or client-side filtering.
- Protect public forms from automated abuse without blocking legitimate readers.
- Keep API keys in protected WordPress options and redact them from logs and UI.
- Make unsubscribe, deletion, and export workflows predictable and documented.
- Follow WordPress coding standards, internationalize user-visible strings, and test with supported PHP and WordPress versions.

## Step 11: Verify each workflow

Use a local or staging WordPress site and a mail sandbox/provider test mode. Check at least:

- Valid and invalid signup input, duplicate signup, confirmation, expired confirmation, and resend behavior.
- A pending or unsubscribed contact never receives a campaign.
- Unsubscribe works from the email and blocks subsequent sends.
- Draft, private, scheduled, and deleted posts cannot be added as newsletter stories.
- Test-send and scheduled-send behavior, partial provider failure, retry, pause, and resume.
- Admin permissions, nonce failures, escaping, and database upgrade behavior.
- Mobile email layout, plain-text content, and all links.

Do not run a real bulk campaign until provider configuration, authentication records, unsubscribe behavior, and a test campaign have been reviewed on staging.

## Step 12: Package and release

1. Add a `readme.txt` with description, installation, configuration, FAQ, changelog, and privacy notes.
2. Include uninstall behavior that preserves subscriber data by default unless the administrator explicitly chooses full deletion.
3. Remove development files, credentials, and test data from the release package.
4. Install the ZIP on a clean staging WordPress site and verify activation, upgrade, and uninstall behavior.
5. Review the WordPress.org plugin guidelines if distributing through the directory.
6. Back up the database before the first production migration and start with a small, editor-approved campaign.

## Suggested implementation order

Build in small reviewable milestones: **plugin scaffold → subscriber schema → double opt-in form → unsubscribe/admin management → campaign composer → provider integration → queued sending → reporting and documentation**. Keep sending disabled until the signup, consent, and unsubscribe flows are complete.

## Research references

- [Newsletter plugin on WordPress.org](https://wordpress.org/plugins/newsletter/)
- [MailPoet on WordPress.org](https://wordpress.org/plugins/mailpoet/)
- [FluentCRM on WordPress.org](https://wordpress.org/plugins/fluent-crm/)
- [Brevo WordPress plugin documentation](https://help.brevo.com/hc/en-us/articles/208849249-Brevo-plugin-for-WordPress-Connect-your-WordPress-site-with-Brevo)

