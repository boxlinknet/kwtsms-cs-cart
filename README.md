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
> **Status:** version 1.0.0, the first release. The installable zip is on
> [GitHub Releases](https://github.com/boxlinknet/kwtsms-cs-cart/releases).

## Features

The add-on registers **SMS as a native CS-Cart notification channel**. SMS shows up as its own column on
**Administration > Notifications**, next to E-mail and the Notification Center, for every event that supports it,
including order status changes and Multi-Vendor vendor events.

- SMS to customers when an order is placed and when its status changes
- SMS alerts to store administrators for new orders and status changes
- Editable message templates in English and Arabic with placeholders such as `{order_id}`
- Gateway login with live balance, approved Sender IDs and country coverage
- Test mode (messages are queued at kwtSMS but never delivered)
- Admin pages: Dashboard, Settings, Templates, Integrations, Admin SMS alerts, Logs, Help
- A full send log, an attempt log and an optional debug log
- A daily sync of balance, Sender IDs and coverage, run by a cron job

Planned for later releases: OTP login and registration by phone, password reset by SMS, low stock alerts,
shipment updates, abandoned cart reminders and vendor notifications. None of these is in 1.0.0.

## Requirements

- CS-Cart or CS-Cart Multi-Vendor **4.20.1 to 4.21.2** (`addon.xml` declares this range). **Verified on 4.21.2**, both
  CS-Cart (Ultimate) and Multi-Vendor. 4.20.1 is the lowest version the add-on allows; a merchant tested the release
  candidate on CS-Cart Multi-Vendor Plus 4.20.1 (Arabic and English) with no issues. The add-on does not claim any version
  below 4.20.1.
  <!-- 2026-10-22 23:08 +03: 4.20.1 merchant test of the rc.1 build recorded, task 596 -->
- PHP 7.4 to 8.3 (the code is linted on 7.4, 8.0, 8.1, 8.2 and 8.3), with the `curl`, `json` and `mbstring` extensions
- A kwtSMS account with API access and an approved Sender ID: https://www.kwtsms.com

## Installation

1. Download `kwtsms-1.0.0.zip` from [GitHub Releases](https://github.com/boxlinknet/kwtsms-cs-cart/releases).
2. Manual installation: Administration > Add-ons > Manage add-ons > the gear icon > Manual installation > Local > pick the zip > Upload & install.

Uninstalling (Add-ons > kwtSMS > Uninstall) removes the add-on's tables, settings, language variables, privileges and
admin notices.

## Configuration

1. Add-ons > kwtSMS > Settings: log in with your kwtSMS API user (the API username, not your mobile number), pick a
   Sender ID, then turn SMS on.
   Test mode is on by default; turn it off only when you are ready for customers to receive messages.
2. Administration > Notifications: tick the SMS column for the events you want.

The Help page inside the add-on has the full setup guide.

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
