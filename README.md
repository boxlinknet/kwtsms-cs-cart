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

SMS notifications and one-time passwords for CS-Cart and CS-Cart Multi-Vendor stores, delivered
through the [kwtSMS](https://www.kwtsms.com) gateway. Built for Kuwait and the GCC, with English
and Arabic out of the box. Free add-on; SMS credits are bought from kwtSMS.

> **Status:** in development. Not yet installable. The first release will be published on the
> CS-Cart Marketplace and as a GitHub release.

## What it does

The add-on registers **SMS as a native CS-Cart notification channel**. SMS shows up as its own
column on **Administration > Notifications**, next to E-mail and the Notification Center, for
every event that supports it, including order status changes and Multi-Vendor vendor events.

Planned for the first release:

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

- CS-Cart or CS-Cart Multi-Vendor 4.18.1 or later
- PHP 7.4 to 8.3, with the `curl`, `json` and `mbstring` extensions
- A kwtSMS account with API access and an approved Sender ID: https://www.kwtsms.com

## Support

- Help and FAQ: https://www.kwtsms.com/support.html
- Security issues: see [SECURITY.md](SECURITY.md)

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

<!-- 2026-10-20 18:14 +03: licence section added after Boss chose MIT; task 596 -->
## License

MIT. See [LICENSE](LICENSE).
