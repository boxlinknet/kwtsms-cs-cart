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

<!-- 2026-10-29 04:16 +03: Task M1.12 (1.2.0 Added and Changed entries), task 596 -->
### Added

- Phone rules (Settings > Phone and codes), each off until you turn it on: a country check that accepts only mobile
  numbers from the countries you choose, and one account per phone, which starts after the cron job has indexed every
  customer phone. Only a new or changed number is checked, so a customer with an old number can still save a new name
  or check out.
- SMS codes: a code to create an account and a code to change the account phone number, each with its own switch. A
  code has 6 digits, works once in the browser that asked for it, and expires after 5 minutes by default. Wrong
  tries are limited per phone number and network, with a lockout, and the wait between codes grows from 60 seconds to
  5 minutes. Codes need a private transactional Sender ID: the shared KWT-SMS Sender ID is refused outside test mode.
- An optional SMS to the old number after every storefront change of the phone number.
- Per-address limits on code requests (with trusted proxies for Cloudflare and load balancers), a store hourly cap, and
  a CAPTCHA provider for code requests: Cloudflare Turnstile, or the core reCAPTCHA add-on.
- Pause or continue when the store cannot send SMS, with an alert in both cases.
- Integrations page: phone rules and SMS codes with the index progress and today's numbers, the CAPTCHA provider, and a
  "Coming in 1.3" list. The Dashboard go-live list gains "Set a CAPTCHA provider for SMS codes" while a code feature
  is on. Help gains sections for SMS codes, phone numbers, limits and proxies, CAPTCHA, when SMS cannot be sent, and
  upgrading, in English and Arabic.

<!-- 2026-10-29 00:16 +03: Task M1.11s (SafatMall test notes on 1.1.0-rc.1), task 596 -->
### Upgrade notes

- The Upgrade Center requests its own restore script over HTTP before it installs a package. A store behind a login
  wall (Cloudflare Access, HTTP basic auth, an IP allow list) fails that pre-check: allow the request, or lift the wall,
  for the time of the upgrade.
- A broken `.po` file of another add-on makes the Upgrade Center report errors for that language. The error names the
  other add-on's file, not kwtSMS; fix or remove that file and run the upgrade again.

### Fixed

- Templates page: the event names and order status names follow the language of the administration panel. They were
  shown in the content language, so an English panel with Arabic content read Arabic names. The language tabs still
  edit each language's text.
- The "Send me SMS about my order status" box on registration and in the profile is hidden while no customer SMS for
  an order status change, an order update or a shipment is on for the storefront. A customer's saved choice is kept.

### Changed

- Shorter password recovery SMS (English: "{store_name}: Reset password: {reset_url} Valid {valid_minutes} min.",
  Arabic likewise): the reset link is most of the length. The upgrade replaces the stored recovery text only where it is
  still the 1.1.0 default; an edited text stays.
- The "Other add-ons" list on the Integrations page now says "Coming in 1.4".
- If you use Turnstile, allow `https://challenges.cloudflare.com` in `script-src` and `frame-src` of your
  Content-Security-Policy header.

<!-- 2026-10-23 14:11 +03: the Unreleased entries became the 1.1.0 release, Task A15, task 596 -->
## [1.1.0] - 2026-10-23

Upgrading from 1.0.0: download `kwtsms-upgrade-1.0.0-1.1.0.zip`, rename it to `kwtsms.zip`, and upload it in
Administration > Upgrade Center > Upload upgrade package (the Upgrade Center takes the package id from the file name).
A fresh install uses `kwtsms-1.1.0.zip`. An upgrade keeps your credentials, settings, switches and edited texts, and
starts no new SMS: every new event stays off until you turn it on. Release candidate pre-release: v1.1.0-rc.1.

<!-- 2026-10-23 04:26 +03: the 1.1.0 entries, Task A13, task 596 -->
### Added

- Per-storefront SMS: a storefront can turn an event off for itself, have its own Sender ID and its own texts. A
  storefront text beats the All storefronts text in the same language. A Sender ID that leaves the synced list or is
  banned falls back to the main one and raises an alert.
- SMS when an order is updated and when a shipment is created, with the tracking number, carrier, and tracking URL.
- SMS to a customer who registers, an alert to administrators for each registration, and a password recovery SMS
  whose reset link is masked in every log. These are sent only while CAPTCHA for registration is on.
- Low stock SMS to administrators, and to vendors in Multi-Vendor, grouped per request.
- Low kwtSMS balance SMS to administrators.
- Vendor SMS in Multi-Vendor: a root administrator approves each vendor's phone on the new Vendor SMS page, with a
  monthly quota of recipients per vendor (default 100, optional limit per vendor plan, 0 turns it off).
- Customers can stop order status, order updated, and shipment SMS from their profile. The order confirmation and
  password recovery SMS are never stopped by this choice.
- Send an SMS to the customer from the order page (Tools > Send SMS) with a live character, page, and credit count.
- Integrations page: what sends SMS, with the storefront count, the core low stock threshold, and the vendor phone
  and quota numbers. Back in stock and product reviews join the list of add-ons coming in 1.2.
- Help sections for storefronts, vendor SMS, customer opt-out, password recovery, and manual SMS, in English and
  Arabic.
- Dashboard: an optional "Vendor phones approved" step in Multi-Vendor.
- An alert when an order SMS expires in the queue before any attempt to send it.

### Changed

- Every new event is off until you turn it on. Upgrading never switches a message on, and never changes an edited
  text, switch, or setting.

<!-- 2026-10-21 16:34 +03: the Unreleased entries became the 1.0.0 release, Task 1.17, task 596 -->
<!-- 2026-10-22 23:08 +03: release date set to the tag date; 4.20.1 merchant test recorded, task 596 -->
## [1.0.0] - 2026-10-22

First release. Verified on CS-Cart 4.21.2 and CS-Cart Multi-Vendor 4.21.2 (fresh installs of the packaged zip, an
upgrade through the Upgrade Center, uninstall). The add-on declares 4.20.1 as the lowest supported version; a merchant
tested the release candidate on CS-Cart Multi-Vendor Plus 4.20.1 (Arabic and English) with no issues.

### Added

- Repository scaffolding: README, SECURITY, CHANGELOG, .gitignore, .gitattributes, .editorconfig.
<!-- 2026-10-21 23:15 +03: GitHub community files listed, task 596 -->
- GitHub community files: CONTRIBUTING, issue forms, pull request template, Dependabot, CI (php -l on PHP 7.4 to 8.3,
  phpcs, package build), CodeQL and stale workflows.
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
<!-- 2026-10-21 07:12 +03: entry for the Admin SMS alerts, Integrations and Help fix round 1 (Tasks 1.13 and 1.15), task 596 -->
- Admin SMS alerts: the kwtSMS verdict is also shown as a warning notice ("kwtSMS cannot route 3 numbers", "Could not check
  with kwtSMS now") next to the badge in the table, and Check numbers never says "Not saved" (it saves nothing).
- Integrations: the readiness labels use the names core gives its own setting ("Supported phone numbers", "International
  phone number", "Any digits and +-()"), in English and Arabic, and "Open checkout fields" says that it opens the storefront
  block manager in edit mode.
- Muted text on the Admin SMS alerts, Integrations and Help pages meets the 4.5:1 contrast of WCAG AA.
- Settings and Help build the crontab line with one helper, so a path with a space is quoted the same way on both pages.
<!-- 2026-10-21 17:42 +03: the "Fixed" section of things never released is folded in here (Task 1.17 review Minor 5), and the
     final fix round entries added, task 596 -->
- Uninstalling removes everything the add-on wrote, the admin bell notices it raised included, and deletes only its own
  storage keys by name (another add-on's keys survive).
- Order SMS of a storefront request go out after the page is sent under PHP-FPM and LiteSpeed; on other servers they wait
  for the cron while it runs, else they are sent during the checkout with at most 1 s of waiting for the API slot.
- Resend asks for confirmation (with the kwtSMS archive warning for an SMS whose outcome is unknown) and refuses a second
  resend of the same SMS within 60 seconds.
- A kwtSMS page opened with a storefront selected (Ultimate) says "Select All stores to manage kwtSMS" and opens in All
  stores.
- Counts read in the right singular or plural form in English and Arabic (Logs rows, Clear, the e-mail and bell alerts).
