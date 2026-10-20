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

Email **support@kwtsms.com** with the subject line `Security: kwtSMS CS-Cart add-on`.
Support portal: https://www.kwtsms.com/support.html

Please include:

- A description of the vulnerability and its impact
- Steps to reproduce, with the CS-Cart or Multi-Vendor version and the add-on version
- Any proof-of-concept code or screenshots

We acknowledge reports within 48 hours. Please do not disclose the issue publicly until a fixed
version has been released.

## Supported Versions

| Add-on version | Supported |
|----------------|-----------|
| 1.x            | Yes       |

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
