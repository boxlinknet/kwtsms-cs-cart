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
<!-- 2026-10-21 05:25 +03: entries for the Templates page fix round 1 (Task 1.12), task 596 -->
- Template editor: "Send a test of this template" sends the typed text, filled with the sample data, to one number
  through the same send pipeline and the same limit (10 test SMS an hour for each administrator) as the Settings test
  SMS; it needs the gateway permission, and the result shows next to the editor.
- SMS brand name editor above the matrix, for the language of the tab: one entry for each storefront (the marketplace's
  own storefronts in Multi-Vendor, never a vendor), a live count of the stored name out of 20, the storefront name and
  then the company name as the placeholder (an empty field uses it), and a warning when an English name is not plain
  text, because every English SMS that carries it would then be Unicode.
- The counter, the warnings and the length on the Templates page use the text as it is stored, which is what gets
  rendered and sent (characters above U+FFFF are dropped, a name only inside a comment is gone); the browser decodes named
  HTML entities itself. A refused save no longer creates an empty template row, and every field of a save is read from
  the POST body only. The Multi-Vendor checkout text that falls back to the single "Order placed" text says so, and the
  worst-case estimate counts the long name of a custom status.
<!-- 2026-10-21 04:57 +03: entries for the Dashboard and the add-on's own health alerts (Task 1.11), task 596 -->
- Dashboard (Add-ons > kwtSMS): the setup checklist and the go-live checklist with the state of every step; SMS, connection
  (naming what fails), test mode, pilot mode, Sender ID, balance with the days it lasts, last sync, cron and outbox;
  counters for today, 7 and 30 days, live and test apart; problems of the last 7 days by result, kwtSMS code and country,
  each linking to the filtered log; credits by event and receiver for this month and last month; the last 10 messages.
  Opening it sends the due outbox rows (up to 20) and syncs when the last sync is stale, so a store without a cron still
  works.
- Notices in the admin bell about the add-on itself: test mode on while SMS are on (at once when SMS are switched on, then
  daily), the cron not run for 2 hours and outbox rows waiting over 10 minutes (notices only, until fixed). A balance of
  zero ("Customer SMS have stopped", with an e-mail, once a day) is now also raised when a send is blocked for it, by
  kwtSMS error ERR010 and when a refresh finds it; a refresh that finds the balance under the alert level raises the
  low-balance alert once per crossing.
- Attempt log rows carry the test flag of their send, so skipped and deferred messages count under live or test.
<!-- 2026-10-21 05:58 +03: entries for the Dashboard fix round 1 (Task 1.11), task 596 -->
- Dashboard setup checklist: "Default country", "SMS brand name" and "Review the SMS switches" have a "Mark as done" button
  (needs the template permission), and the default country starts as to do; saving the Settings form with a country, or
  marking it, finishes that step. The checklist counts as complete, and goes away, once every step is done or SMS are on,
  test mode is off and at least one live message was accepted.
- A cron that never ran is now reported: the add-on keeps the time it first saw the cron missing with SMS on, and the
  Dashboard's own fallback sync no longer hides a dead cron. When the outbox drain on opening the Dashboard used more than
  3 seconds, that open skips the sync. Outbox rows deferred for quiet hours count as waiting again once their time has
  passed.
- "Customer SMS have stopped" is no longer raised for a balance that was never read. The test-mode notice no longer says
  "0 test messages hold about 0 credits". The weak encryption key warning says that changing the key makes encrypted
  values, including the saved API password, unreadable. Last 10 shows "Accepted by kwtSMS" with the note that delivery
  is not reported back.
<!-- 2026-10-21 06:22 +03: entry for the Dashboard fix round 2 (Task 1.11), task 596 -->
- The sync the Dashboard runs when it is opened with a stale last sync uses the short web timeouts (3 seconds to connect,
  8 seconds in all for each call) instead of the admin ones, so a slow gateway holds the page back less.
<!-- 2026-10-21 04:50 +03: entries for the Admin SMS alerts and Integrations pages (Task 1.13), task 596 -->
- Admin SMS alerts page: up to 10 administrator numbers, each checked on its own with the reason when one is refused
  (one typo no longer costs the other nine), the alert language, and the kwtSMS verdict for each number as a warning
  ("kwtSMS cannot route this number", "Could not check with kwtSMS now"), also when kwtSMS can't be reached. "Check
  numbers" asks kwtSMS without saving. The admin switches are shown read-only with a link to the Templates page, and
  the page says plainly that SMS uses this list while the Receivers setting on Administration > Notifications applies
  to e-mail.
- Integrations page: three phone readiness checks, each with a link to the core setting that fixes it (a phone field
  required at checkout, phone validation with country selection, the store default country equal to the SMS country),
  the statuses that count as a new order, the default country (read-only), the Multi-Vendor settings (Multi-Vendor
  only, with "Vendor SMS: not included in 1.0, planned for 1.1"), a warning when the core SMS notifications
  (Clickatell) add-on is active, and a recommendation to turn on CAPTCHA at checkout.
<!-- 2026-10-21 04:50 +03: entries for the Help page (Task 1.15), task 596 -->
- Help page in English and Arabic: what the add-on does, the kwtSMS account (the API user is not the account mobile
  number), transactional and promotional Sender IDs with their fees and about 5 working days, international sending and
  its fraud risk and daily limit (ERR035), setup step by step, the go-live checklist, SMS switches against the Notify
  boxes, placeholders, what an SMS costs (why one Arabic letter or a long brand name can double it), cron, test mode
  and the credits it holds, IP restrictions and proxies (ERR024, ERR034), "message not received", what to send support,
  and the kwtSMS links.
- The Help page shows the exact crontab line of this store, built from its folder and its admin script name; the URL
  mode (POST only, the key in a header, a 504 from a 60-second proxy is not always a failure); and a warning when the
  core lock backend is "dummy", which gives no mutual exclusion between two cron runs.
<!-- 2026-10-21 06:44 +03: entries for the Logs page, the order SMS tab, export and GDPR (Task 1.14), task 596 -->
- Logs page with Messages, Attempts and Debug tabs: store time and kwtSMS time (Kuwait) on every row, a TEST badge and a
  Live or test filter, one canonical list of outcome labels ("Accepted by kwtSMS", never "Delivered"), filters by date,
  status, event, receiver, order (with its parent), storefront, vendor, phone (98765432 finds 96598765432) and text (3
  characters and at most 31 days). Filters come from a fixed key map, 10 to 200 rows per page, created_at the only sort.
- A filter that no index reads in time order searches the newest 50,000 rows of the period and says so, so every list
  and count stays under a second on a store with a million attempts.
- Detail view with the raw request (login removed) and reply in left-to-right blocks, the flag of a reply cut at 64 KB,
  the recipients of a message, and "Copy details for kwtSMS support" (API username, msg-id, Kuwait time, Sender ID,
  numbers, text, test flag, code; never the password).
- Resend of a failed, queued, expired or unanswered SMS through every send rule, linked to the original attempt; never a
  masked text, a row still in the queue or sent on a later try, and never twice.
- Clear the whole log (TRUNCATE), rows older than N days or the rows of a filter (5,000 rows per statement), always
  showing the row count first; every clear writes an audit row that no clear and no retention under 365 days removes.
- CSV export of the Messages log with the current filters: UTF-8 with a BOM, formula cells guarded, masked texts stay
  masked, never the raw request or reply.
- Read-only SMS tab on the admin order page with every SMS attempt of the order and of its parent checkout.
- GDPR: a customer's SMS rows (messages, attempts and queued SMS) are part of their personal data, and anonymizing the
  customer replaces their numbers with one-way tokens, the texts with "[anonymized]" and clears the IP address.
- Indexes for the msg-id lookup, the recipients of a message, index-only Dashboard counters and the Debug log by action.
