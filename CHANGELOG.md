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
