<!--
FILE:      SECURITY.md
CREATED:   2026-10-20 13:27 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Security policy and vulnerability reporting process for the kwtSMS CS-Cart add-on.
WHY:       Public repository and marketplace listing need a private disclosure channel.
USAGE:     Rendered by GitHub on the repository Security tab.
DEPENDS:   Nothing.
-->

# Security Policy

## Reporting a Vulnerability

**Do not open a public GitHub issue for security vulnerabilities.** Report privately, either way:

1. **GitHub Security Advisory** (preferred): [create a private advisory](https://github.com/boxlinknet/kwtsms-cs-cart/security/advisories/new)
   on the Security tab of this repository.
2. **Email** **support@kwtsms.com** with the subject line `Security: kwtSMS CS-Cart add-on`.
   Support portal: https://www.kwtsms.com/support.html

<!-- 2026-10-21 23:15 +03: GitHub private advisory added as the preferred channel, as kwtsms-php and kwtsms-rust do; task 596 -->
Never put the API password or the cron key in a report.

Please include:

- A description of the vulnerability and its impact
- Steps to reproduce, with the CS-Cart or Multi-Vendor version and the add-on version
- Any proof-of-concept code or screenshots

We acknowledge reports within 48 hours. Please do not disclose the issue publicly until a fixed
version has been released. Once a fix is out, the issue is described in the GitHub release notes with credit to the
reporter, unless you ask to stay anonymous.

## Supported Versions

| Add-on version | Supported |
|----------------|-----------|
| 1.x            | Yes       |

Only the latest release receives security fixes. Supported platforms are CS-Cart and CS-Cart Multi-Vendor
4.20.1 to 4.21.2.

## Scope

- SQL injection through add-on controllers, settings or templates
- Cross-site scripting (XSS) in admin or storefront templates
- Cross-site request forgery on add-on actions
- API credential exposure in logs, HTML output, JavaScript or exports
- Account or phone number enumeration through OTP, login or password reset responses
- OTP brute force, bypass of rate limits or CAPTCHA on SMS-sending forms
- SMS balance drain (toll fraud) through unauthenticated endpoints

## Out of Scope

- Vulnerabilities in CS-Cart core or third-party add-ons (report those to their vendors)
- Issues that require administrator access to exploit
- Delivery delays or carrier-side filtering of SMS messages
