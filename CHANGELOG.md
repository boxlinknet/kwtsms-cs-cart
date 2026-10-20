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
