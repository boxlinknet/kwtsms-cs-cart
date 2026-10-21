<!--
FILE:      README.md
CREATED:   2026-10-20 13:27 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Project overview of the kwtSMS add-on for CS-Cart and Multi-Vendor.
WHY:       Entry point for store owners, reviewers and contributors on GitHub.
USAGE:     Rendered by GitHub.
DEPENDS:   SECURITY.md, CHANGELOG.md.
-->

# kwtSMS for CS-Cart and Multi-Vendor

SMS notifications for CS-Cart and CS-Cart Multi-Vendor stores, delivered
through the [kwtSMS](https://www.kwtsms.com) gateway. Built for Kuwait and the GCC, with English
and Arabic out of the box. Free add-on; SMS credits are bought from kwtSMS.

<!-- 2026-10-21 16:36 +03: status, install and verified versions for 1.0.0, Task 1.17, task 596 -->
> **Status:** version 1.0.0 (release candidate). Free add-on, MIT licence. It is published on the CS-Cart
> Marketplace and as a GitHub release once approved.

## What it does

The add-on registers **SMS as a native CS-Cart notification channel**. SMS shows up as its own
column on **Administration > Notifications**, next to E-mail and the Notification Center, for
every event that supports it, including order status changes and Multi-Vendor vendor events.

Included in 1.0.0:

- SMS to customers when an order is placed and when its status changes
- SMS alerts to store administrators for new orders and status changes
- Editable message templates in English and Arabic with placeholders such as `{order_id}`
- Gateway login with live balance, approved Sender IDs and country coverage
- Test mode (messages are queued at kwtSMS but never delivered)
- A full send log, an attempt log and an optional debug log
- A daily sync of balance, Sender IDs and coverage

Later releases: OTP login and registration by phone, password reset by SMS, low stock alerts,
shipment updates, abandoned cart reminders and vendor notifications.

## Requirements

- CS-Cart or CS-Cart Multi-Vendor **4.20.1 to 4.21.2** (`addon.xml` declares this range). **Verified on 4.21.2**, both
  CS-Cart (Ultimate) and Multi-Vendor. 4.20.1 is the lowest version the add-on allows; it has not been verified yet, and the
  add-on does not claim any version below it.
- PHP 7.4 to 8.3, with the `curl`, `json` and `mbstring` extensions
- A kwtSMS account with API access and an approved Sender ID: https://www.kwtsms.com

## Install

1. Download `kwtsms-1.0.0.zip` (GitHub release or the Marketplace).
2. Administration > Add-ons > Manage add-ons > the gear icon > Manual installation > Local > pick the zip > Upload & install.
3. Add-ons > kwtSMS > Settings: log in with your kwtSMS API user, pick a Sender ID, then turn SMS on.
   Test mode is on by default; turn it off only when you are ready for customers to receive messages.
4. Administration > Notifications: tick the SMS column for the events you want.

Uninstalling (Add-ons > kwtSMS > Uninstall) removes the add-on's tables, settings, language variables, privileges and
admin notices.

## Build the package

`bash tools/build-package.sh` runs the release gates (security greps, language parity, `php -l`, package content
checks) and writes `dist/kwtsms-<version>.zip` and `.tgz` with a `SHA256SUMS` file. It fails, and writes no archive, when
any gate fails.

## Support

- Help and FAQ: https://www.kwtsms.com/support.html
- Security issues: see [SECURITY.md](SECURITY.md)

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

<!-- 2026-10-20 18:14 +03: licence section added after Boss chose MIT; task 596 -->
## License

MIT. See [LICENSE](LICENSE).
