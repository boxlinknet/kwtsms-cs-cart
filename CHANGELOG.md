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

<!-- 2026-11-17 15:01 +03: M3.15, task 596. The [Unreleased] entries became the 1.4.0 section; the date stays <date> until the publish day -->
## [Unreleased]

<!-- 2026-11-26 16:23 +03: release date set to the publish day (Boss 2026-11-26: publish 1.4.0 now, SafatMall did not reply); CHANGELOG is not in the package, task 596 -->
## [1.4.0] - 2026-11-26

Upgrading from 1.3.0, 1.2.0, 1.1.0 or 1.0.0: download `kwtsms-upgrade-1.3.0-1.4.0.zip`, `kwtsms-upgrade-1.2.0-1.4.0.zip`,
`kwtsms-upgrade-1.1.0-1.4.0.zip` or `kwtsms-upgrade-1.0.0-1.4.0.zip`, rename it to `kwtsms.zip`, and upload it in
Administration > Upgrade Center > Upload upgrade package (the Upgrade Center takes the package id from the file name).
A fresh install uses `kwtsms-1.4.0.zip`. An upgrade keeps your credentials, settings, switches and edited texts and starts
no new SMS: every new event, and every new option below, is off until you turn it on.

<!-- 2026-11-18 06:34 +03: release prep, the 1.4.0 entry written from the merged work, task 596 -->
### Added

- SMS for the events of other CS-Cart add-ons, each with its own switches, English and Arabic templates and Logs rows,
  and each shown on Templates only while its add-on is active:
  - Returns (RMA): the status of a return, to the customer, the administrator and the vendor.
  - Call requests: an alert to the administrator or the vendor when a visitor asks for a call, from the cart, a product
    page or a vendor page. The public form is protected by the same CAPTCHA scenarios as registration.
  - Back in stock: when a product is restocked, subscribers who are registered customers with a phone number get an SMS
    (template "Back in stock"); other subscribers still get the e-mail. One SMS per customer and product per day.
    Settings > Back in stock SMS sets the most subscribers messaged per restock (default 500, at most 500).
  - Gift certificates: an SMS to the purchaser when the certificate changes status. The certificate code is masked in the
    text and in the Logs.
  - Product reviews, discussion posts and vendor messages: alerts to the administrator and the vendor.
  - Vendor plan changes (Multi-Vendor): a plan alert to the vendor and the administrator.
- Multi-Vendor: vendors can receive the status and update SMS of their orders from an allow-list, and each vendor can
  have its own Sender ID for the SMS of its orders (off until you turn it on in Settings; a vendor without one uses the storefront Sender ID).
- Conditions per event (Templates > Conditions): an SMS is sent only when the order matches the minimum order total,
  payment methods and shipping methods you set, per storefront; staff alerts can also be held during your quiet hours.
- One SMS per order: with "Send one SMS per order when several customer SMS happen in one save" on, a shipment saved with a status change, or two status changes in one
  save, send the customer one SMS for that order. Code, password and security SMS are never combined.
- Templates export and import: download your templates as a file, edit them, and import them back with a preview that
  shows what changes, row by row, before anything is saved.
- Bulk resend from the Logs: tick failed rows and resend them in one step, with an estimate of the credits first (up to
  15 rows at a time).
- Code statistics on the Dashboard while a code feature is on (codes sent over the last 30 days), and an Unlock button for
  a number that has hit the code limit.
- Test SMS safeguards: the Settings test SMS asks for the number twice and for a tick confirming you have permission to
  send to it.
- Ask for a missing phone (off until you turn it on under Settings > Phone and codes): a signed-in customer without a
  mobile number is asked to add one, verified by a code, and can choose Later.
- Kuwait mobile numbers (off until you turn it on under Settings > Phone and codes): refuse Kuwaiti landline and service
  numbers (an 8-digit +965 number must start with 4, 5, 6 or 9) at registration and in the account, and skip them when
  sending.
- Help covers every new feature in English and Arabic: the new events and their limits, the public forms and their
  CAPTCHA scenarios, back in stock, gift certificates, vendor alerts, conditions and combined SMS, template export and
  import, bulk resend, code statistics and unlock, the test SMS safeguards, IP lockdown and the missing phone prompt.
- The Integrations page lists each add-on with its own SMS events: whether the add-on is active, how many switches are
  on, and the SMS accepted today.

### Changed

<!-- 2026-11-18 11:59 +03: fixF, task 596 -->
- The example phone number in texts and placeholders (Settings, Logs, Templates, error and Help texts, English and
  Arabic) is 50000000 (96550000000 with the country code). An upgrade refreshes the unedited old texts and keeps an
  edited one.
- Texts about test messages (Settings, Dashboard, test-mode alert, Help and the test SMS note, English and Arabic) say a
  test message holds credits in your kwtSMS queue until you delete it or it's purged automatically once a month. The
  Dashboard line and the alert now say a message "may still hold" credits. No day or age is named.
- The Logs label "Skipped: CAPTCHA for registration is off." now says "the CAPTCHA for this form" (call requests and
  discussion posts use it too), and a row skipped because its Conditions could not be read has its own label. The
  vendor row of the Templates page says where to turn vendor SMS on. On a narrow screen the switch note no longer runs
  under the page's side tab.
- Hardening of the public forms and the vendor features: improved limits per address, per customer and per vendor
  (a vendor's daily alerts and its share of the monthly quota are capped, a customer gets at most 5 texts a day from
  gift certificate and vendor reply events), the CAPTCHA token is checked before the limits are spent, a number held by
  another account gets the same answer as any other number, a request for a code
  only replaces the codes of the same browser session, and a Resend refuses a row whose add-on is off or whose vendor
  is not active.
- The code table gets an index for the Dashboard figures; the upgrade adds it once.

### Notes

- Tested on CS-Cart 4.21.2. The new add-on events (returns, call requests, back in stock, gift certificates, reviews, discussion, vendor messages, vendor plans) are tested on CS-Cart 4.21.2; 4.20.1 support for them is not yet verified.
- Upgrading from 1.0.0, 1.1.0, 1.2.0 or 1.3.0 goes through the Upgrade Center with the upgrade package for your version; the two new tables start empty, every new setting starts at its default and the new events start off.

<!-- 2026-11-08 05:38 +03: the Unreleased entries became the 1.3.0 release (the date is the build date; the controller sets the publish date), Task M2.9, task 596 -->
<!-- 2026-11-11 20:44 +03: release date set to 2026-11-11, the day 1.3.0 was rebuilt and sealed for publishing, task 596 -->
<!-- 2026-11-15 11:00 +03: release date set to the publish day (Boss: publish Sunday 2026-11-15 11:00); CHANGELOG is not in the package, so the sealed zips are unchanged, task 596 -->
## [1.3.0] - 2026-11-15

Upgrading from 1.2.0, 1.1.0 or 1.0.0: download `kwtsms-upgrade-1.2.0-1.3.0.zip`, `kwtsms-upgrade-1.1.0-1.3.0.zip` or
`kwtsms-upgrade-1.0.0-1.3.0.zip`, rename it to `kwtsms.zip`, and upload it in Administration > Upgrade Center > Upload
upgrade package (the Upgrade Center takes the package id from the file name). A fresh install uses `kwtsms-1.3.0.zip`.
An upgrade keeps your credentials, settings, switches and edited texts, holds nobody at sign-in and starts no new SMS:
every sign-in, two-step, recovery and checkout code feature stays off until you turn it on.

<!-- 2026-11-04 21:20 +03: review fixes of Task M2.1 (sign-in with a phone and an SMS code), task 596 -->
### Added

- Sign-in with a verified phone number and an SMS code (off until you turn it on under Settings > Sign-in and
  security). Whether a number has an account is never revealed: the request answers the same for every number, the
  CAPTCHA asks for a token on the same schedule for every number, and the answer takes the same time.

- <!-- 2026-11-05 16:44 +03: Task M2.3 (account recovery by an SMS code), task 596 -->
  Account recovery by an SMS code (off until you turn it on under Settings > Sign-in and security). The login form gets
  a link "Forgot your password? Use your mobile number"; a customer with a number verified by a code asks for a code,
  types it, and presses Continue; the store then signs them in on the profile page to choose a new password, through
  core's own recovery key (one key, used once, valid only in the browser session that proved the number, never in a
  link). Whether a number has an account is never revealed (same answer, same time, with or without CAPTCHA). The
  e-mail recovery and the recovery link by SMS are unchanged. A customer with a two-step sign-in meets the code step
  again unless the recovery number is the two-step number. With a different two-step number the recovery key is used
  up and the customer signs in with the password and a code to the two-step number instead (Help says so).
  <!-- 2026-11-05 20:43 +03: M2.3-fix (security review Minors), task 596. The recovery key guard refuses a bound key
  when core cannot read it, the key limit is checked before the code is used up, and no key is minted without a
  session id. -->

- <!-- 2026-11-05 21:04 +03: Task M2.4 (SMS code before an order is placed), task 596 -->
  SMS code before an order is placed (off until you choose a mode under Settings > Sign-in and security): every
  order, the orders of guests, or the orders paid with the cash on delivery payments you tick. The customer asks for a
  code on the mobile number of the order and types it on the checkout page ("SMS code for this order"). A code works
  for that number, that browser session and one order. A signed-in customer whose account number a code already
  verified is not asked. A refusal never loses the cart; orders placed in the admin panel, through the API with an
  administrator or vendor key, by the console and by the payment provider of the payment in the cart that returns to the
  store are never asked, and in a Multi-Vendor checkout one code covers the parent and its child orders. Paying an order
  again with another payment (the "pay again" form of the order page) is asked too and has the code box. A customer's
  own REST API order is accepted only with the verified account phone (403 otherwise); Settings warns while the
  storefront REST API add-on is active. In the cash on delivery mode the code is bound to the shipping phone when one
  is entered (the number the courier calls). After a verified guest order the "create account" block of the order page
  creates the account with that verified number. Texts in English and Arabic.

- <!-- 2026-11-06 07:52 +03: Task M2.5 (country selector on phone fields), task 596 -->
  Country selector on the phone fields (off until you turn it on under Settings > Phone and codes; it needs "Check
  customer phone numbers"). Registration, the account page, checkout and the code sign-in and recovery forms offer only
  the countries you allow, with the default country preselected: the core country list is filtered in the "phone
  number with country selection" mode, and a compact select is added in the "international format" and "any digits"
  modes. The server still decides: the chosen calling code is only the first hint for a number typed without one, it is
  ignored when the country is not allowed, and every number is checked and saved in the +965... form as before.

- <!-- 2026-11-06 18:48 +03: Task M2.6 (vendors confirm their SMS number with a code), task 596 -->
  Multi-Vendor: vendors confirm their own SMS number with a code (off until you turn it on under Settings > Phone and
  codes). A vendor administrator opens Settings > Confirm SMS number in the vendor panel, asks for a code to the
  company phone and types it; the company then receives its order SMS. The company comes from the signed-in vendor, never
  from the request, and the code is bound to that company, its phone and the browser session. Root approval on the Vendor
  SMS page stays as an override ("Approve without code"); the page shows "Waiting for the vendor's code" and
  "Confirmed by the vendor's code on [date]". A changed company phone still has to be confirmed again.

- <!-- 2026-11-06 22:25 +03: Task M2.11 (send-failure spike alert, P3-41), task 596 -->
  Alert when SMS sends start failing (off until you turn it on under Settings > Send failure alert). When more sends
  fail within 15 minutes than the number you set (10 by default), the add-on raises a notice in the admin bell and sends
  an e-mail, at most once an hour, with the count and nothing else (no phone number, no reason). An SMS to the Admin SMS
  alerts numbers is available too under Administration > Notifications ("Send failure alert", off by default). The SMS
  about failing SMS is not counted as a failure itself, so it cannot raise the alert again.

- Two-step sign-in with an SMS code after the password, for customers, vendor administrators (Multi-Vendor) and
  administrators (off until you turn it on under Settings > Sign-in and security; customers and the others opt in on
  their own profile with a code to their own mobile number). Every other way into an account (password recovery link, one-time password, the remember-me session, social login,
  the storefront REST API token) is held for the code as well. Break-glass: add `define('KWTSMS_2FA_OFF', true);` to
  `config.local.php` to turn every second step off, or run
  `php admin.php --dispatch=kwtsms_twofa.cli_reset --user_id=<id>` (one account) or `--types=admins|vendors|customers`
  (one switch off) on the server; the command fails with a non-zero status on a bad option or an unknown account.

<!-- 2026-11-05 08:11 +03: Task M2.2b, task 596 -->
- Backup codes for administrators and vendor administrators: ten one-time codes, shown once, stored only as salted
  hashes, replaced as a set after a code. They sign in an enrolled administrator while the code SMS cannot be sent.
  <!-- 2026-11-05 11:38 +03: Task M2.2b fix 1 (review I2, M1, M3), task 596 -->
  Wrong backup codes are counted in a limit of their own (per address and account), so SMS-code failures never block
  them; ten wrong backup codes for one account within an hour raise the bell alert twofa_backup_guessing. The root
  administrator who saves Required is enrolled on the phone the code proved and is shown ten backup codes at once.
- "Required" for administrators and vendors (Settings > Sign-in and security). For administrators it asks for a code
  sent to the saver's own mobile number and a tick when administrators without a mobile number exist; the list is shown.
  Under Required an account without a mobile number is refused, except the last root administrator, who is let in with
  the password only and an alert (twofa_degraded). The alerts twofa_disabled_by_config and twofa_reset, and a "Reset the
  second step" button for a root administrator on another account's profile, were added.
- A second-step code to an administrator or vendor is no longer blocked by the list of allowed customer countries.
<!-- 2026-11-05 11:38 +03: Task M2.2b fix 1 (review I1, M2, M3), task 596 -->
- Core's add-on settings form and the REST settings entity can no longer change the three two-step switches; only the
  guarded save on the kwtSMS Settings page does. Saving Required for vendors lists the vendor administrators without a
  mobile number (name and company) and needs a tick. The console reset raises the twofa_reset alert (bell and e-mail)
  like the web reset.

<!-- 2026-11-07 02:46 +03: Task M2.7 (Help, Integrations, Dashboard, languages for 1.3.0), task 596 -->
- Integrations: the "Coming in 1.3" list is now a live "Sign-in and security" section. One row each for sign-in by
  code (state, sign-ins with a code today), two-step sign-in (the switch for customers, administrators and, in
  Multi-Vendor, vendor administrators; accounts with two-step on by type; a warning with the number of administrators
  who have fewer than 3 backup codes left), recovery by code and the code before an order (state, codes issued today),
  the country selector (on, or on but waiting for the country check) and, in Multi-Vendor, the vendor phone
  confirmation (approved phones). Every row links to its control in Settings. No number is read for a switch that is off.
- Help (English and Arabic): sections for sign-in and recovery by code (verified numbers only; why "no SMS" is not an
  error), two-step sign-in (enrolling, Required and its check, backup codes, the break-glass line and the exact command
  of this install, the last root administrator rule, the alerts), the code before an order (modes, cash on delivery
  payments, who is never asked), the country selector and phone checks, vendor phone confirmation (Multi-Vendor) and the
  send failure alert.
- Dashboard: the go-live list gets "Backup codes saved" while two-step sign-in is on for administrators. It is done when
  an administrator is enrolled and every enrolled administrator has 3 or more backup codes left.

<!-- 2026-11-07 19:10 +03: whole-branch review of 1.3.0 (fix round 1), task 596: the entries the earlier lists missed -->
- Upgrade to 1.3.0 from 1.0.0, 1.1.0 and 1.2.0 (including the 1.2.0 release candidates): two new tables (the two-step
  sign-in row of an account, and its backup codes) and the new hidden settings, all of them off. The upgrade keeps
  every stored value, edited text and switch, turns nothing on, enrols no account and sends no SMS.
- GDPR: a customer's two-step sign-in row and the count of their backup codes (never the codes) are part of their
  personal data export; anonymizing the customer deletes both.
- Multi-Vendor: the code a vendor administrator asks for to confirm the company number is limited per company (3 an
  hour, 10 a day), per vendor user and to 2 different numbers per company a day, whatever the address. It counts
  against the shared per-number hourly limit only after the company has confirmed a number once, and approving a
  number approves only the number the code was verified for.

<!-- 2026-11-08 00:10 +03: M2.10 CS-Cart 4.20.1 compatibility guards, task 596 -->
- Compatibility guards for CS-Cart 4.20.1 (checked by feature, never by version number). A security feature is refused
  with a red note on the Settings page, and stays off, when this CS-Cart can't carry it: two-step sign-in without a
  `login_user_pre` hook that passes the user by reference (or without the password sign-in functions), recovery by
  code without `fn_generate_ekey` taking its data parameter and the recovery key functions, the checkout code without
  the `pre_place_order` and `place_order` hooks, and the checkout code or sign-in by code when a storefront theme lacks
  a template hook the code box or link hangs on (`checkout:final_section_customer_notes`, `orders:repay`,
  `index:login_buttons`). A feature already on shows the same note. The 1.3.0 upgrade refuses to run its settings step
  on a core whose `fn_update_addon_settings` could drop stored values, and reads the stored version from the database
  when the core has no `fn_get_addon_version`. The sign-in hold logs once and skips its first check when a core
  password function is missing.

### Changed

<!-- 2026-11-07 19:10 +03: whole-branch review of 1.3.0 (fix round 1), task 596 -->
- The two-step code no longer shares the hourly limit of codes per phone number (5 by default) with sign-in, recovery,
  checkout and sign-up codes. Before this change, anyone could ask for recovery or checkout codes to a customer's number
  and then keep that customer waiting at the second step for up to an hour. The two-step code now has a limit for
  each account: 6 an hour and 20 a day (counted per request; the answer is the same neutral "wait").
- The public `send()` interface ignores the internal context key `staff_code`; only the add-on's own code sender can
  use it. README lists it as internal.
- With every two-step switch and recovery by code off, the profile box, the "Reset the second step" button check and
  the recovery-key check on the login pages run no database query. A recovery key handed over by recovery by code in
  the last 15 minutes before that switch is turned off works like an e-mail recovery key (core still needs its secret).
- Help (English and Arabic): codes to one number share its hourly limit; one account per phone number is recommended
  with sign-in by code and recovery by code (with it off, a number saved on two accounts gets no code for either).
<!-- 2026-11-11 17:10 +03: task 596, the Upgrade Center language note was wrong for a header mismatch -->
- Help (English and Arabic), Upgrade: when the Upgrade Center marks a language FAILED, CS-Cart's red notice names the
  broken .po file only for a syntax error. Help now says so and tells you to look for a .po file whose `Language:`
  header doesn't match its folder (it said the error always names the file).
  <!-- 2026-11-11 21:36 +03: task 596, the 1.3.0 seal run found the old paragraph kept on stores upgraded from 1.2.0 -->
  An upgrade from 1.2.0 replaces the old paragraph with the new one in both languages, unless you edited it.

<!-- 2026-11-07 02:46 +03: Task M2.7, task 596 -->
- The SMS about failing SMS (Administration > Notifications, "Send failure alert") uses the placeholder {window_minutes}
  for its 15 minutes instead of {minutes}, with its own label ("Minutes of the failure window") and preview sample (15);
  {minutes} stays the lifetime of a code. A text you edited before this release keeps working only if it uses
  {window_minutes}; reset it to the default to get the new wording.
- Administrator alert e-mails in Arabic are right to left.
- The two-step pages show the masked number (+965****1234) in its own left-to-right element, so it no longer reads
  reversed in Arabic; the sentence before it ends in a colon.
- Country selector: the label of the select is no longer escaped twice, and the select points at its phone field
  (aria-describedby). README notes that the international format mode has no real country picker.

### Notes

<!-- 2026-11-07 19:10 +03: whole-branch review of 1.3.0 (fix round 1), task 596 -->
- When an account is deleted, the add-on's own `post_delete_user` hook (after core's delete) removes its two-step row
  and backup codes whatever the switches say, and its phone index row and code rows while a phone feature is on. The
  two-step cleanup is deliberate: the table is empty on a store that never enrolled, and no row of an account that no
  longer exists may stay.

<!-- 2026-11-05 02:38 +03: review fix 1 of Task M2.2a, task 596 -->
- Two-step sign-in: a code and its wrong-attempt count belong to one account, so another account that saved the same
  number can no longer cancel the code of an administrator or lock the administrator out. A number that another account
  uses for its second step, or that belongs to an administrator or vendor account, is refused at opt-in.
- The commerceml add-on's HTTP Basic sign-in is refused (401) for an account that needs a second step. The REST API
  (e-mail and API key, a separate secret) is not held for a code. An exception while the sign-in is held can no longer
  let core log the account in, and core's "successfully logged in" notice is dropped for a held account.
- An administrator who opted in under Optional signs in with a backup code while the code SMS cannot be sent; Help
  and README name the break-glass constant and command.

<!-- 2026-11-04 23:19 +03: Task M2.2a, task 596. The code sign-in now asks the one two-step policy. -->
- Sign-in by code asks the same two-step policy as the password sign-in: an account that must use the second step
  (it opted in, or its type requires it) cannot sign in by code. A third-party add-on's own two-step sign-in is still
  not consulted.
- With one account per phone on, the code to create an account still answers "already in use" for a number that
  another account has (the 1.2.0 behaviour). It is a known, accepted path; set a CAPTCHA provider to slow probing.

<!-- 2026-10-29 18:29 +03: the Unreleased entries became the 1.2.0 release, Task M1.13b, task 596 -->
## [1.2.0] - 2026-10-29

Upgrading from 1.1.0 or 1.0.0: download `kwtsms-upgrade-1.1.0-1.2.0.zip` or `kwtsms-upgrade-1.0.0-1.2.0.zip`, rename it
to `kwtsms.zip`, and upload it in Administration > Upgrade Center > Upload upgrade package (the Upgrade Center takes
the package id from the file name). A fresh install uses `kwtsms-1.2.0.zip`. An upgrade keeps your credentials,
settings, switches and edited texts, indexes no phone number and starts no new SMS: every phone rule and code feature
stays off until you turn it on. Release candidate pre-release: v1.2.0-rc.1.

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
- An optional SMS to the old number after every storefront change of the phone number. It counts against the
  per-address limits and an account gets at most 1 such SMS an hour.
- Per-address limits on code requests (with trusted proxies for Cloudflare and load balancers), a store hourly cap, and
  a CAPTCHA provider for code requests: Cloudflare Turnstile, or the core reCAPTCHA add-on.
<!-- 2026-11-04 12:36 +03: Task RC2 item 1 (1.2.0-rc.2), task 596: the cap counts only this add-on's own code SMS -->
- A store-wide daily cap for code SMS this add-on sends (default 300, in Settings), checked before the shared daily
  cap so a flood of code requests cannot use up the order SMS budget. Codes that other add-ons send through the SMS
  interface (source `otp`) are not counted and not capped by it. A code request refused by any limit is never let through under
  "When SMS codes can't be sent: Continue".
<!-- 2026-11-04 14:41 +03: Task RC2 fix1 item 7, task 596 -->
- Release candidate testers only: on the day a store moves from 1.2.0-rc.1 to 1.2.0-rc.2, code SMS sent under rc.1
  are not counted by the daily code cap (rc.1 stored them as `otp`); the count is right from the next day.
<!-- 2026-11-04 12:40 +03: Task RC2 item 2 (1.2.0-rc.2), task 596 -->
- A documented public interface for other add-ons: `Tygh::$app['kwtsms.sms']->send($phones, $message, $context)`
  returns a `SendResult`. README "For developers" lists the number formats, every context key, the sources (including
  what `otp` does), the result accessors and test mode. **Stability promise:** changes to this interface are
  announced in the release notes and in this CHANGELOG, and a contract test fails when the signature, the result
  accessors or the context keys change.
<!-- 2026-11-04 12:45 +03: Task RC2 item 3 (1.2.0-rc.2), task 596 -->
- Any active CAPTCHA provider counts. Settings, Integrations and the Dashboard now read the active antibot driver: a
  CAPTCHA add-on that registers with CS-Cart's image verification protects the SMS code form (validated through its
  driver on every request, fail closed), is named in the CAPTCHA row with its driver class, and the red "no CAPTCHA"
  warning no longer shows. An active CAPTCHA add-on that did not register as the driver (for example
  `google_recaptcha`) is named in an info line. Cloudflare Turnstile never replaces a set-up third-party driver. A third-party driver that is
  active but not set up protects nothing: the red warning stays and names the add-on.
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
  status, event, receiver, order (with its parent), storefront, vendor, phone (50000000 finds 96550000000) and text (3
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
