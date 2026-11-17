<!--
FILE:      README.md
CREATED:   2026-10-20 13:27 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Project overview of the kwtSMS add-on for CS-Cart and Multi-Vendor.
WHY:       Entry point for store owners, reviewers and contributors on GitHub.
USAGE:     Rendered by GitHub.
DEPENDS:   SECURITY.md, CHANGELOG.md, CONTRIBUTING.md.
CHANGED:   2026-10-21 23:15 +03: restructured to the layout of the kwtSMS WHMCS README (badge row, About kwtSMS, Features,
           Requirements, Installation, Configuration, Support, License); every fact kept, task 596.
           2026-10-22 12:39 +03: release badge (as the Joomla README has, pre-releases included), status line names the
           v1.0.0-rc.1 pre-release, Installation no longer mentions a Marketplace listing, task 596.
           2026-10-22 23:08 +03: status line names the v1.0.0 release; Requirements records the merchant test of the
           rc.1 build on Multi-Vendor Plus 4.20.1, task 596.
-->

# kwtSMS for CS-Cart and Multi-Vendor

[![CI](https://github.com/boxlinknet/kwtsms-cs-cart/actions/workflows/ci.yml/badge.svg)](https://github.com/boxlinknet/kwtsms-cs-cart/actions/workflows/ci.yml)
[![Latest Release](https://img.shields.io/github/v/release/boxlinknet/kwtsms-cs-cart?include_prereleases)](https://github.com/boxlinknet/kwtsms-cs-cart/releases)
[![PHP 7.4 to 8.3](https://img.shields.io/badge/PHP-7.4%20to%208.3-777BB4?logo=php&logoColor=white)](https://php.net)
[![CS-Cart 4.20.1 to 4.21.2](https://img.shields.io/badge/CS--Cart-4.20.1%20to%204.21.2-4a9ed6)](https://www.cs-cart.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![kwtSMS API](https://img.shields.io/badge/kwtSMS-API%20v4.1-FFA200?logo=data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHRleHQgeD0iNCIgeT0iMTgiIGZvbnQtc2l6ZT0iMTYiIGZpbGw9IndoaXRlIj5TTVk8L3RleHQ+PC9zdmc+)](https://www.kwtsms.com)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](https://github.com/boxlinknet/kwtsms-cs-cart/pulls)

Send automated SMS notifications to your CS-Cart and Multi-Vendor customers and administrators through kwtSMS. Free and open source.

## About kwtSMS

[kwtSMS](https://www.kwtsms.com) is a Kuwait-based SMS gateway that delivers messages to 200+ countries. It supports dedicated sender IDs, Arabic messages, balance tracking, and a REST API built for developers.

[Sign up for a kwtSMS account](https://www.kwtsms.com/signup/) to get started. SMS credits are bought from kwtSMS.

<!-- 2026-10-21 16:36 +03: status for 1.0.0, Task 1.17, task 596 -->
<!-- 2026-10-21 23:17 +03: status line no longer promises a Marketplace listing (not decided); GitHub Releases is
     the download channel from the first tag, task 596. -->
<!-- 2026-10-22 23:08 +03: v1.0.0 released (Boss 23:01), the rc.1 build passed a merchant test on 4.20.1, task 596 -->
<!-- 2026-10-23 14:11 +03: status for 1.1.0 release candidate, Task A15, task 596 -->
<!-- 2026-10-29 18:29 +03: status for 1.2.0 release candidate, Task M1.13b, task 596 -->
> **Status:** version 1.2.0 release candidate. The installable zip is on
> [GitHub Releases](https://github.com/boxlinknet/kwtsms-cs-cart/releases) as the v1.2.0-rc.1 pre-release.

## Features

The add-on registers **SMS as a native CS-Cart notification channel**. SMS shows up as its own column on
**Administration > Notifications**, next to E-mail and the Notification Center, for every event that supports it,
including order status changes and Multi-Vendor vendor events.

- SMS to customers when an order is placed and when its status changes
- SMS alerts to store administrators for new orders and status changes
- Editable message templates in English and Arabic with placeholders such as `{order_id}`
- Gateway login with live balance, approved Sender IDs and country coverage
- Test mode (messages are queued at kwtSMS but never delivered)
<!-- 2026-10-23 04:26 +03: 1.1.0 features, Task A13, task 596 -->
- SMS for order updates and shipments, new customers, password recovery (reset link masked in the logs), low stock, and low kwtSMS balance
- Per-storefront switches, Sender ID and texts
- Vendor SMS in Multi-Vendor with approved phones and a monthly quota per vendor
<!-- 2026-11-06 18:49 +03: Task M2.6 (vendors confirm their number with a code), task 596 -->
- Vendors confirm their own SMS number with a code in the vendor panel (off until you turn it on); root approval stays as an override
- Customer opt-out of order SMS, and a manual SMS from the order page
<!-- 2026-11-06 22:25 +03: Task M2.11 (send-failure spike alert), task 596 -->
- Alert in the admin bell and by e-mail (and optionally SMS) when sends start failing in bulk (off until you turn it on)
<!-- 2026-10-29 04:17 +03: Task M1.12, task 596. The 1.2.0 features. -->
- Phone rules: a country check and one account per phone, each off until you turn it on
- SMS codes to create an account and to change the account phone number, with limits per phone number and per
  address, a daily cap for code SMS, trusted proxies, a Cloudflare Turnstile or reCAPTCHA check, and a rate-limited
  SMS to the old number after a change
<!-- 2026-11-07 02:46 +03: Task M2.7 (1.3.0 features), task 596 -->
- Sign-in with a verified phone number and an SMS code, and password recovery by code (1.3.0, each off until you turn it
  on); neither reveals whether a number has an account
- Two-step sign-in with an SMS code for customers, administrators and vendor administrators (1.3.0, off by default):
  Optional or Required, backup codes, a rule that never locks out the last root administrator, and break-glass options
  (a config constant and a command line)
- An optional SMS code before an order (all orders, guests only, or cash on delivery only), a country selector on phone
  fields, and Integrations rows and a Dashboard line for all of them (1.3.0, off by default)
<!-- 2026-11-17 07:29 +03: Task M3.13 (1.4.0 features), task 596 -->
- SMS for returns (RMA), call requests, back in stock, gift certificates, product reviews, discussion posts and vendor
  messages (1.4.0, every switch off until you turn it on), with throttles on the public forms, order conditions, one
  combined SMS per order, template export and import, bulk resend from the Logs, and an Integrations page that shows
  each add-on with its switches and the SMS sent today
- Admin pages: Dashboard, Settings, Templates, Integrations, Admin SMS alerts, Vendor SMS (Multi-Vendor), Logs, Help
- A full send log, an attempt log and an optional debug log
- A daily sync of balance, Sender IDs and coverage, run by a cron job

<!-- 2026-10-23 05:33 +03: Task A13 fix round 1: only what is still planned after 1.1.0, task 596 -->
<!-- 2026-10-29 05:14 +03: Task M1.12 fix round 1 (I2), task 596. 1.2.0 ships the country check, registration and phone change codes and Turnstile; they leave this list. -->
<!-- 2026-11-07 02:46 +03: Task M2.7, task 596. 1.3.0 ships sign-in by code, two-step sign-in, recovery by code, the code at checkout and the country selector; they leave this list. -->
<!-- 2026-11-17 07:29 +03: Task M3.13, task 596. 1.4.0 ships returns, call requests, back in stock, gift certificates, reviews, discussion and vendor messages; they leave this list. -->
Planned for later releases: abandoned cart reminders, bulk SMS (sending to groups) and scheduled SMS. Bulk resend of failed log rows is already shipped.

## Requirements

- CS-Cart or CS-Cart Multi-Vendor **4.20.1 to 4.21.2** (`addon.xml` declares this range). **Verified on 4.21.2**, both
  CS-Cart (Ultimate) and Multi-Vendor. 4.20.1 is the lowest version the add-on allows; a merchant tested the release
  candidate on CS-Cart Multi-Vendor Plus 4.20.1 (Arabic and English) with no issues. The add-on does not claim any version
  below 4.20.1.
  <!-- 2026-10-22 23:08 +03: 4.20.1 merchant test of the rc.1 build recorded, task 596 -->
- PHP 7.4 to 8.3 (the code is linted on 7.4, 8.0, 8.1, 8.2 and 8.3), with the `curl`, `json` and `mbstring` extensions
- A kwtSMS account with API access and an approved Sender ID: https://www.kwtsms.com

## Installation

<!-- 2026-10-23 14:11 +03: zip name 1.1.0, Task A15, task 596 -->
<!-- 2026-10-29 18:30 +03: zip name 1.2.0, Task M1.13b, task 596 -->
1. Download `kwtsms-1.2.0.zip` from [GitHub Releases](https://github.com/boxlinknet/kwtsms-cs-cart/releases).
2. Manual installation: Administration > Add-ons > Manage add-ons > the gear icon > Manual installation > Local > pick the zip > Upload & install.

Uninstalling (Add-ons > kwtSMS > Uninstall) removes the add-on's tables, settings, language variables, privileges and
admin notices.

## Configuration

1. Add-ons > kwtSMS > Settings: log in with your kwtSMS API user (the API username, not your mobile number), pick a
   Sender ID, then turn SMS on.
   Test mode is on by default; turn it off only when you are ready for customers to receive messages.
2. Administration > Notifications: tick the SMS column for the events you want.

The Help page inside the add-on has the full setup guide.

<!-- 2026-11-04 12:37 +03: Task RC2 item 2 (1.2.0-rc.2), task 596. The public send interface, documented as stable. -->
## For developers

Other add-ons can send SMS through the add-on's own pipeline, so their texts pass the same switches, limits, number
checks, balance checks and logs as everything else the store sends.

```php
/** @var \Tygh\Addons\Kwtsms\Sms\SendResult $result */
$result = \Tygh::$app['kwtsms.sms']->send($phones, string $message, array $context = []);
```

Signature: `SmsService::send($phones, string $message, array $context = []): SendResult`. It never throws; every
failure is an outcome in the result. Guard the call with `isset(\Tygh::$app['kwtsms.sms'])` (and
`Registry::get('addons.kwtsms.status') === 'A'`) when your add-on may run without kwtSMS.

**Stability promise.** This method, the `SendResult` accessors listed below and the context keys listed below are a
public interface. A change to any of them is announced in the release notes and in CHANGELOG.md. A contract test
(`tests/Unit/PublicInterfaceContractTest.php`) fails when the signature, the accessors or the documented keys change.

### Phone numbers (`$phones`)

An array of strings, or one string that lists several numbers separated by `,` `;` or line breaks. Any other value is
read as an empty number. At most 1000 numbers per call through `fn_kwtsms_send()`; `send()` itself splits the call into
gateway requests of at most 200 numbers. Accepted formats: international with or without `+` or `00`
(`+965 5012 3401`, `00965 50123401`, `96550123401`), spaces, dashes and brackets, Arabic-Indic and Persian digits, a
leading trunk zero after the country code, and a local number with no country code whose length is the default
country's local length (default country: Settings, 965 preselected), for example `50123401`. A number that fails the
local check is not sent: its outcome is `invalid` (reasons: `empty`, `email`, `too_short`, `too_long`, `bad_length`,
`not_mobile`). Repeated numbers are sent once (`duplicate`).

### Context (`$context`)

Every key is optional. A key not in this table is dropped. A string value is trimmed and cut to 128 characters, an
integer key reads a non-numeric value as 0.

| Key | Default | Meaning |
| --- | --- | --- |
| `source` | `manual` | `event`, `manual`, `api`, `cron` or `otp`; anything else is read as `manual`. See below. |
| `event_id` | empty | A label for the Logs page, for example `myaddon.order_verified`. |
| `receiver` | empty | `C` customer, `A` admin, `V` vendor. `C`, `A` and `V` sends are capped per number. Quiet hours (Settings) hold only a `C` send, and of those only one with source `manual`, `api` or `cron`, or any source other than `otp` whose `audience` is `admin` (so `event` + `admin` is held, `event` + `storefront` is not). Never held: an `otp` send, a send with a `ttl`, an `event_id` starting `kwtsms.order_placed`, an `A` or `V` send. |
| `audience` | `storefront` | `admin` or `storefront` (anything else is `storefront`). Decides how an error is worded for an interactive caller. |
| `interactive` | `false` | `true` when a person waits for the answer: `errorMessage()` then carries text to show. |
| `order_id`, `parent_order_id`, `company_id`, `storefront_id`, `user_id`, `actor_user_id`, `actor_company_id` | `0` | Ids stored with the log row so the send shows up on the right order, vendor and customer. |
| `lang_code` | empty | Two-letter language of the text, stored with the log row. |
| `country_iso` | empty | ISO2 country of the customer, used as the hint for a number typed without a country code. |
| `ip` | empty | Visitor address, stored with the attempt row. |
| `secret_values` | `[]` | Strings (a one-time code) that are masked as `******` in every stored copy: the message, the API request and response, the attempt, debug and outbox rows. The text still goes out in full. |
| `ttl` | `0` | Seconds the text stays worth sending if it has to wait in the queue (0 = 1800); a send with a ttl is never held for quiet hours. |
| `caller` | empty | Name of your add-on, stored in the log context. |

Internal keys, not for external use (they are read, but their meaning can change without notice): `outbox_id` (always
dropped), `retry_of`, `resend_outbox_id`, `staff_code`, `vendor_sender`, `staff_quiet`. The stored source `otp_code` is reserved for the code SMS this add-on sends
itself; passing it is read as `manual`. `SmsService::sendCode()` is the sender of that code SMS; it is public in PHP
only because the add-on's own classes call it, it is internal and not part of the interface above, and it can change
without notice. Call `send()`. `vendor_sender` is not checked against the caller: PHP code that passes it with a vendor `company_id` and receiver `V` or `C` sends under that vendor's Sender ID (when the switch is on), so treat it as add-on internal. `staff_quiet` (set to `1` by the order notifier from the event conditions of a row) holds an A or V SMS for the quiet hours; it is not part of the interface.

**Hook handlers are trusted code.** `send()` runs the `kwtsms_send_pre` hook first; a handler of that hook (any active
add-on) can edit the phones, the text and the whole context, including `source`, `receiver` and `secret_values`, or
cancel the send. Only install add-ons you trust; the context you pass is a request, not a guarantee.

### Sources

- `event`: a notification about something that happened (an order, a status). Limited to the page count set in Settings.
- `manual`: a person sent it. Capped per number and counted in the per-number bucket.
- `api`: a program sent it (this is what `fn_kwtsms_send()` uses). Counted in its own per-number bucket, so a bulk
  job does not use up the customer caps.
- `cron`: a scheduled job.
- `otp`: a one-time code of your add-on. The values in `secret_values` are masked in every stored copy, the send is
  exempt from quiet hours (a code must arrive now), it may use 2 pages whatever the notification page limit is (the
  2-page floor applies only when `secret_values` is non-empty; an `otp` send without secret values keeps the
  notification page limit), and a Logs resend of the row is refused. It keeps the ordinary caps: the per-number hourly and daily caps, the store
  hourly and daily caps. It is not counted by, and not blocked by, the daily cap on the code SMS this add-on sends
  itself (Settings > Phone and codes).

### The result (`SendResult`)

- `status(): string`: `sent`, `partial`, `failed`, `skipped` (every number was blocked locally) or `deferred` (queued
  for later).
- `acceptedCount(): int`: numbers the gateway accepted (outcomes `accepted`, `test_queued`, `partial_accept`).
- `outcomes(): array`: raw number => outcome. Outcomes include `accepted`, `test_queued`, `partial_accept`,
  `duplicate`, `invalid`, `no_phone`, `blocked_*`, `rate_limited`, `no_coverage`, `deferred`, `rejected`, `failed`,
  `unknown`, `expired`, `cancelled`.
- `recipients(): array`: one entry per number with `phone_raw`, `phone`, `outcome`, `reason`, `code`, `attempt_id`,
  `message_id`, `outbox_id`.
- `messageIds(): string[]`, `errorCode(): ?string`, `errorMessage(): string`, `pointsCharged(): float`,
  `balanceAfter(): ?float`, `isTest(): bool`, `toArray(): array`.

### Test mode

While Test mode is on in Settings, every call goes to kwtSMS with `test=1`: the outcome is `test_queued`, `isTest()` is
<!-- 2026-11-12 06:59 +03: M3.17 (P3-74), task 596: the monthly automatic purge, no day or age named -->
true, nothing is delivered, and the message waits in the kwtSMS queue until you delete it there or it's purged
automatically once a month (credits are held until then). Your code does not need to know: the same call works in both modes.

### Example

```php
if (isset(\Tygh::$app['kwtsms.sms'])) {
    $result = \Tygh::$app['kwtsms.sms']->send(
        $order_info['phone'],
        'Your verification code is ' . $code,
        [
            'source' => 'otp',
            'event_id' => 'myaddon.checkout_code',
            'receiver' => 'C',
            'secret_values' => [$code],
            'order_id' => (int) $order_info['order_id'],
            'storefront_id' => (int) $order_info['storefront_id'],
            'ttl' => 300,
            'caller' => 'myaddon',
        ]
    );
    if ($result->acceptedCount() === 0) {
        // $result->status(), $result->errorCode(): tell the customer to try later
    }
}
```

### CAPTCHA providers for the SMS code form

The code form is protected by whichever driver is the active antibot driver of CS-Cart's image verification
(`Tygh::$app['antibot']->getDriver()`), once "SMS code request" is ticked under Settings > Image verification:

- Cloudflare Turnstile (this add-on's driver) and the core reCAPTCHA add-on, as before.
- Any other add-on that registers a driver with `Tygh::$app->extend('antibot', ...)` and `setDriver()`, and whose
  `isSetUp()` is true. The code request calls its `validateHttpRequest($_REQUEST)` on every request, exactly like the
  reCAPTCHA path; no driver, or a driver that returns false, refuses the request (fail closed). Settings, Integrations
  and the Dashboard name it (the add-on id found from the folder of its class under `app/addons/<id>/`, else the class
  name) and never show the red "no CAPTCHA" warning while it is the active driver. The Integrations CAPTCHA row prints
  the driver class so a merchant can report it.
- An active add-on whose id contains `captcha` (for example `google_recaptcha`) that did not register as the driver
  does not protect the form: Settings and Integrations say so.

Cloudflare Turnstile installs itself as the driver only when no third-party driver is set up; it never replaces one. With a
third-party driver active, a saved Turnstile setup stays idle and Settings says so. (With the core reCAPTCHA add-on,
Turnstile steps aside only when reCAPTCHA has its keys saved, as before.)

### Security notes for phones and SMS codes

<!-- 2026-11-04 21:20 +03: review fixes M2 and M5 of Task M2.1, task 596 -->
- Sign-in with a phone and an SMS code (1.3.0, off until you turn it on under Settings > Sign-in and security) consults
  only this add-on's own two-step sign-in. A customer with a two-step sign-in from this add-on is refused the code
  sign-in, silently; the two-step sign-in of a third-party add-on is not consulted, so with such an add-on active the
  code sign-in can bypass it. Turn the code sign-in off if a third-party two-step add-on must always apply.
<!-- 2026-11-05 16:44 +03: Task M2.3 (account recovery by an SMS code), task 596 -->
- Account recovery by an SMS code (1.3.0, off until you turn it on under Settings > Sign-in and security) hands the
  customer core's own recovery key in a form on the answer page, never in a link. The key is valid once, for the
  recovery time core sets, and only in the browser session that proved the number; the e-mail key is unchanged. A
  customer with this add-on's two-step sign-in passes the code step only when the recovery number is the two-step
  number. Like the code sign-in, it does not consult a third-party two-step add-on.
<!-- 2026-11-05 21:05 +03: Task M2.4 (SMS code before an order is placed), task 596 -->
- The SMS code before an order (1.3.0, off until you choose a mode under Settings > Sign-in and security) is checked
  when the storefront checkout places an order (`checkout.place_order`, and any other storefront code that calls
  `fn_place_order()`, such as a one-click buy add-on, which has no code box and is refused while the mode applies to
  it). Paying an order again with another payment (`orders.repay`, which core does without `fn_place_order()`) is asked
  too, with the code box on the repay form. It is not asked for orders placed in the admin panel (the order editor),
  by the console, through the REST API with an administrator or vendor key, and by a payment provider returning to the
  store (`payment_notification`) for the processor of the payment that is in the cart (any other "return" runs the
  normal check), because refusing there would lose an order that is already paid. An order a customer places through
  the REST API with their own token (add-on `storefront_rest_api`) is covered: it is accepted only when its phone is
  the customer's verified account phone, otherwise the API answers 403 with a clear message (a token has no browser
  session, so no code can be typed for it); Settings warns while that add-on is active. A refusal happens before the
  order exists; the cart is kept. One code works for one order, for
  the number it was sent to and for the browser session that asked for it; a Multi-Vendor checkout uses it once for the
  parent order and its child orders. In the cash on delivery mode the number the courier calls is the shipping phone,
  so when the customer enters a shipping phone (a valid mobile) the code is bound to that number, not to the account
  phone; with no shipping phone, or the same one, it is the account phone.
<!-- 2026-11-06 07:52 +03: Task M2.5 (country selector on phone fields), task 596 -->
- The country selector (1.3.0, off until you turn it on; Settings refuses it while "Check customer phone numbers" is
  off) loads `js/addons/kwtsms/phone_country.js` and a JSON block (allowed countries with their names in the page
  language, the default calling code, the core phone mode) in the page head. In the core mode "phone number with
  country selection" it hides the other countries of core's list and preselects the default one on an empty field; in
  "international format" and "any digits" it puts a `select` named `kwtsms_cc[<field>]` before the phone input (the
  code sign-in and recovery forms get it too). The server reads a posted `kwtsms_cc[phone]`, `kwtsms_cc[b_phone]` or
  `kwtsms_cc[s_phone]` only while the selector is active, only when it is one of the allowed calling codes, and only as
  the country of a number typed without its own code; the country check and the canonical saved form (+965...) are
  unchanged, so a Saudi number is refused when only Kuwait is allowed even if the option is forced into the page. Country
  names come from core's country descriptions of the page language; where a store has only English names the select
  shows English names.
<!-- 2026-11-07 02:46 +03: Task M2.7 (M2.5 review minor), task 596 -->
- In the "international format" mode there is no real country picker: the field already holds the number with its own
  country code, so the select changes only the placeholder and follows the code the customer types; it does not write
  the prefix into the field. The select has an `aria-label` and points at its phone input with `aria-describedby`.
<!-- 2026-11-05 20:43 +03: M2.3-fix item 4 (security review Minor), task 596 -->
- A customer whose two-step number differs from the number they recovered with: core uses the recovery key up and the
  customer lands on the code page for the two-step number, with no way back to the key. They sign in with the password
  and a code instead. Recovering the account of a two-step customer with the same number as the two-step number counts
  the recovery code as the second step; that is deliberate (a SIM swap that takes the number takes both factors).
- With one account per phone on, the code to create an account answers "already in use" when another account has the
  number. That tells a visitor that the number has an account. It is a known and accepted path, because a customer
  has to learn to sign in instead of creating a second account. To slow a bot that probes numbers, set a CAPTCHA
  provider (Cloudflare Turnstile or the core reCAPTCHA add-on) and keep the per-network limits. The sign-in and
  recovery code requests answer the same for every number and do not have this path.
<!-- 2026-11-05 02:38 +03: review fix 1 of Task M2.2a (M3, I2, C1), task 596 -->
- Two-step sign-in with an SMS code after the password (1.3.0, off until you turn it on). Every code belongs to one
  account and one number, so another account that saved the same number cannot cancel, replace or lock the code of an
  administrator. A number that another account uses for its second step, or that belongs to an administrator or vendor
  account, is refused at opt-in.
- Break-glass: add `define('KWTSMS_2FA_OFF', true);` to `config.local.php` to turn every second step off, or run
  `php admin.php --dispatch=kwtsms_twofa.cli_reset --user_id=ID` on the server (`--types=admins|vendors|customers`
  turns one switch off). The command prints one line per change and exits non-zero on a bad option or an unknown
  account; on the web the mode answers 404 whatever the session.
- Backup codes (administrators and vendor administrators; 2026-11-05 08:11 +03, task 596): ten one-time codes are shown once when the
  second step is turned on, stored only as salted hashes, and
  replaced as a set from the own two-step page after a code. Wrong backup codes have a limit of their own (per address
  and account; 2026-11-05 11:38 +03, review I2): SMS-code failures never block them, and ten wrong ones for one account
  within an hour raise the bell alert twofa_backup_guessing. An enrolled administrator signs in with one while the code
  SMS cannot be sent (empty balance, gateway down).
- "Required for administrators" asks for a code sent to your own mobile number and a tick when administrators without a
  mobile number exist. Such an administrator is refused ("Ask a store administrator to add your mobile number"); only
  the last root administrator is let in with the password, with an alert, so the store can always be reached. A root
  administrator with the gateway privilege can reset another account's second step, never the own.
- The commerceml add-on signs in with an e-mail and password over HTTP Basic, outside the sign-in form. This add-on
  refuses (401) an account that needs a second step when commerceml is installed. The REST API (e-mail and API key,
  a separate secret) is not held for a code. The commerceml add-on exists only from CS-Cart 4.21.1, so this refusal
  applies from that version.
- Red notes on the Settings page (2026-11-08 00:10 +03, task 596): a feature whose note is red can't be carried by
  this CS-Cart and stays off. Two-step sign-in needs the `login_user_pre` hook and the password sign-in functions.
  Recovery by code needs `fn_generate_ekey` with its data parameter and the recovery key functions. The checkout code
  needs the hooks `pre_place_order` and `place_order`. The checkout code and sign-in by code also need template hooks
  in the storefront theme: `checkout:final_section_customer_notes` (views/checkout/components/final_section.tpl),
  `orders:repay` (views/orders/details.tpl) and `index:login_buttons` (views/auth/login_form.tpl). A custom theme that
  removed one of them needs it back.

## Build the package

`bash tools/build-package.sh` runs the release gates (security greps, language parity, `php -l`, package content
checks) and writes `dist/kwtsms-<version>.zip` and `.tgz` with a `SHA256SUMS` file. It fails, and writes no archive, when
any gate fails. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Support

- Help and FAQ: https://www.kwtsms.com/support.html
- Other integrations: https://www.kwtsms.com/integrations.html
- Bugs and feature requests: [GitHub issues](https://github.com/boxlinknet/kwtsms-cs-cart/issues)
- Security issues: see [SECURITY.md](SECURITY.md)
- Release history: [CHANGELOG.md](CHANGELOG.md)

<!-- 2026-10-20 18:14 +03: licence section added after Boss chose MIT; task 596 -->
## License

MIT. See [LICENSE](LICENSE).
