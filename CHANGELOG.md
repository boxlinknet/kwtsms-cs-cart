<!--
FILE:      CHANGELOG.md
CREATED:   2026-10-20 13:27 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Release history of the kwtSMS CS-Cart add-on.
WHY:       Marketplace packages and GitHub releases need a human-readable list of changes.
USAGE:     Add an entry under [Unreleased] with every change; move it under a version on release.
DEPENDS:   Nothing.
-->

# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html), as the CS-Cart Marketplace requires.

## [Unreleased]

### Added

- Repository scaffolding: README, SECURITY, CHANGELOG, .gitignore, .gitattributes, .editorconfig.
- MIT license.
<!-- 2026-10-20 21:57 +03: entries for the settings, gateway state and log services (Task 1.4), task 596 -->
- Typed settings with one allow-listed save mode per admin form; the API password is stored encrypted with a
  version prefix and a changed crypt key reads as "log in again" instead of sending a wrong password.
- Cached gateway state (balance, Sender IDs, coverage, suspension, transport-failure breaker) and the
  "configured" verdict with the exact reason it is not.
- Message, attempt and debug logs: credentials never stored in any encoding, one-time codes and reset links
  masked, 4-byte characters removed for the 3-byte database connection, newest-first lists read straight off
  the created_at index.
<!-- 2026-10-20 22:52 +03: entries for the transport, the API guard and the API client (Task 1.5), task 596 -->
- Own hardened HTTPS client for the kwtSMS API: certificate and host name verified, TLS 1.2 or newer, HTTPS only,
  no redirects, hard connect and total timeouts, no request logging. The core HTTP client is not used because it
  turns certificate checks off and writes request bodies, with the API password, into the core logs.
- API client for balance, Sender IDs, coverage, number validation and sending: the reply is read whatever the
  HTTP status (kwtSMS answers some errors with 403), errors carry a code and where it came from, sends are split
  into calls of at most 200 numbers and 2500 characters, and the API password never reaches a log, a dump or a
  serialized object.
- One 5-requests-per-second slot clock for each store database, and a circuit breaker: after a failed connection
  the rest of the request is deferred, three failing requests in a row pause sending for 5 minutes.
<!-- 2026-10-20 23:20 +03: fix round 1 of Task 1.5 (R19: per database; M8: the breaker opening is logged), task 596 -->
- Plain-language reasons for every kwtSMS error code and for the add-on's own failures, in English and Arabic.
<!-- 2026-10-21 00:40 +03: entries for the send pipeline, the outbox and the service wiring (Task 1.6), task 596 -->
- One send pipeline for every SMS: global switch, configured account, suspension, abuse caps (per number and
  store-wide per day), number checks, duplicates, allowed countries, coverage, one cleaning of the final text,
  length, credits (one live balance check at most every 10 minutes), pilot mode, quiet hours and a budget of 20
  API calls per page request. Every recipient gets one plain outcome and one log row; every API call one message
  row, with one-time codes masked and no credential.
- Outbox for sends that cannot go out now (gateway unreachable, queue full, account suspended, quiet hours,
  request budget): retried with back-off of 30 s, 1, 2 and 5 minutes, expired after 30 minutes, never sent twice.
- Admin notice and e-mail when the store-wide daily SMS limit is reached (once per day), when the balance drops
  under the alert level, and when the account is suspended.
- `fn_kwtsms_send()` for other add-ons, with the hooks `kwtsms_send_pre` (edit or cancel) and `kwtsms_send_post`.
<!-- 2026-10-21 01:37 +03: entries for login, sync, suspension and the cron (Task 1.7), task 596 -->
- Login to kwtSMS with one `/balance/` call: the username and the encrypted password are saved only when it is OK,
  and a wrong password, a blocked account or an unreachable gateway each get their own plain message. Changing to
  another account forgets everything cached for the old one.
- Daily sync of balance, Sender IDs and coverage, validated before it is stored. A failed sync keeps what is cached
  and tries again after 15 minutes, 1 hour and 6 hours. A Sender ID that is no longer on the account, or that kwtSMS
  banned, pauses SMS and tells the administrators.
- A suspended account is checked every 15 minutes (hourly when blocked) and resumes by itself when kwtSMS answers
  again; a coverage mismatch (ERR026) asks for one coverage refresh an hour.
- Cron job `kwtsms_cron.run` for the command line (`php admin.php --dispatch=kwtsms_cron.run`, every minute) and an
  optional URL mode: it sends the queued SMS that are due, syncs daily, prunes the logs by retention (and the sent,
  expired and unknown outbox rows after 30 days, for at most 2 minutes a run) and repeats the test-mode,
  zero-balance, suspension and Sender ID notices daily. One run at a time (core lock). The URL mode takes POST only,
  needs the cron key in a header or the POST body (a GET with the right key gets 405), and the answer is only
  `{"ok":true}`; its outbox drain has no page-request call budget and uses the command-line timeouts.
<!-- 2026-10-21 02:04 +03: fix round 1 of Task 1.7 (POST only, outbox prune, drain context, ban lifted on a new sender), task 596 -->
- A banned Sender ID is forgiven as soon as another one is saved; changing the account clears the selected Sender ID
  of the old account; an unreachable suspended account is retried once per quarter hour, not on every cron run.
<!-- 2026-10-21 02:56 +03: entries for the admin controller and the Settings page (Task 1.10), task 596 -->
- Admin pages under Add-ons > kwtSMS for root administrators only. Every page checks, before any work: GET or POST
  only (anything else gets 405), a known page or action answering its own method, no storefront or vendor selected,
  the page's own privilege, and on every POST the form's security hash, also when the store's CSRF protection is
  switched off.
- Settings page: log in with the API user (the password is never shown again and the fields are never filled by
  the browser), the connected account with Refresh balance, Sync now, Change account and Disconnect (asks first,
  turns SMS off), Sender ID from the account's list with nothing preselected and a warning for the KWT-SMS test
  sender, SMS switch (refused with the reason while the account is not ready), test mode (turning it off with
  KWT-SMS needs a ticked confirmation), pilot mode, default country (Kuwait preselected) and customer countries,
  limits and "Resume sending", quiet hours, log detail and retention, the crontab line and the cron key, and a test
  SMS form (at most 10 an hour per administrator) whose result shows the number, the outcome, the message ID, the
  credits, the balance after and, in test mode, how to get the credits back. English and Arabic, 1280 and 768 px.
<!-- 2026-10-21 03:18 +03: entries for the SMS notification transport and the order events (Task 1.9), task 596 -->
- SMS is a notification transport of the store: Administration > Notifications shows a kwtSMS column for every
  order status (custom statuses included) and for the new event "New order placed", for customers and
  administrators, and the store's own Notify customer / Notify orders department boxes apply. Every SMS switch is
  OFF until an administrator ticks it (an upgrade, a new status or another add-on's event never starts sending on
  its own); "New order placed" is ON for the customer and the administrators.
- One "order received" SMS per order, whatever the way it was placed: storefront checkout, online payment
  confirmed later, repay after a failed payment, or an order created by an administrator (with the administrator's
  Notify boxes). A failed, declined or canceled payment is no order; editing an order never sends it again; the
  status SMS of the same change is replaced by it. A status sent once is not sent again for 24 hours.
- Multi-Vendor: one customer SMS and one administrator SMS for a checkout of several vendors, listing the vendor
  order numbers (never the parent order number), or one per vendor order; status SMS per checkout (when the last
  vendor order reaches the status) or per vendor order, "auto" following the marketplace fulfilment; SMS caused by
  a vendor can be switched off.
- Every order SMS that did not go out has a reason in the attempts log (switch off, Notify box unticked, replaced
  by the order-received SMS, already sent, no number, no text).
- Third-party add-ons get SMS for their own notification events by declaring them in
  `schemas/kwtsms/events.post.php` (receivers, placeholders, phone).
- Storefront order SMS go out after the page has been sent, so a checkout never waits for the SMS gateway.
<!-- 2026-10-21 04:38 +03: entries for the Templates page (Task 1.12), task 596 -->
- Templates page: one row for each order status and receiver (custom statuses included), the text of each row per
  language with a badge (edited, default text, uses the generic text, "Using English text" only when the text really
  is the default-language text, no text), its length as an SMS with sample data, and the SMS switch of the row. The
  switch is the core notification switch, so Administration > Notifications shows it and the other way round;
  changing it needs the core "manage notification settings" privilege.
- Template editor: the direction of the language, a live counter and a seven-segment SMS meter that follow the gateway's
  rules (Unicode above U+007F, UTF-16 length, 160 / 153 and 70 / 67 per SMS, at most 7 SMS), a worst-case estimate (a
  20-character store name, a 10-character Arabic first name, 6-digit order numbers, an 8-digit total), placeholder
  chips, and a preview with sample data or an order number that sends nothing.
- Saving a text: a customer text must keep {store_name}; an unknown placeholder needs "Save anyway"; the characters
  cleaning removed are listed; text between angle brackets, touching placeholders, "&", "#" or ";" next to a
  placeholder and promotional wording get a warning. "Reset to default text" and "Load default texts for <language>".
