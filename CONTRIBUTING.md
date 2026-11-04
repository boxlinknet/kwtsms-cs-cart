<!--
FILE:      CONTRIBUTING.md
CREATED:   2026-10-21 23:15 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      How to set up, check, package and submit changes to the kwtSMS CS-Cart add-on.
WHY:       Public repository: contributors need the checks a pull request must pass before review.
USAGE:     Linked by GitHub from the new issue and new pull request pages.
DEPENDS:   composer.json, .phpcs.xml.dist, tools/build-package.sh. Layout follows the kwtSMS WordPress CONTRIBUTING.md.
CHANGED:   2026-10-22 12:45 +03: Releasing names the release-candidate tag and the notes from tools/release-notes.sh,
           task 596.
-->

# Contributing to kwtSMS for CS-Cart

## Prerequisites

- PHP 7.4 or later (the add-on must run unchanged on 7.4 to 8.3)
- Composer
- A CS-Cart or Multi-Vendor 4.20.1 to 4.21.2 store for manual testing
- A kwtSMS account (only to try the gateway; use test mode, see below)

## Setup

```bash
git clone https://github.com/boxlinknet/kwtsms-cs-cart.git
cd kwtsms-cs-cart
composer install
```

The add-on ships without Composer dependencies; `composer install` only fetches the dev tooling (PHP_CodeSniffer,
PHPCompatibility, PHPUnit).

## Checks

```bash
# Coding standard: PSR-12 plus PHPCompatibility 7.4 to 8.3, scanning app/addons/kwtsms
vendor/bin/phpcs

# Syntax on the PHP version you run
find app/ -name '*.php' -print0 | xargs -0 -n1 php -l
```

CI runs `php -l` on PHP 7.4, 8.0, 8.1, 8.2 and 8.3, phpcs, and CodeQL on the JavaScript and the workflows.

## Building the package

```bash
bash tools/build-package.sh
```

The script runs the release gates (security greps, language parity, `php -l`, package content checks) and writes
`dist/kwtsms-<version>.zip`, `.tgz` and `SHA256SUMS`. It needs a gitignored `docs/private-patterns.txt` with one
string per line that must never appear in the package (the maintainers keep the real list as the `PRIVATE_PATTERNS`
repository secret); for a local run a single placeholder line such as `zzz-no-such-string` is enough.
The PHP 7.4 pass needs Docker; add `--no-lint74` to skip it.

## Testing against kwtSMS

Always use **test mode** (`test=1`): the message is queued at kwtSMS but never delivered. Credits are still held, so the
queue has to be cleaned from your kwtSMS dashboard afterwards. Never commit credentials, a `.env` file, a log or a
screenshot that shows the API password, the cron key or a customer phone number.

## Branch Naming

- `feature/short-description` for new features
- `fix/short-description` for bug fixes
- `docs/short-description` for documentation changes

## Pull Request Checklist

- [ ] `vendor/bin/phpcs` passes
- [ ] `bash tools/build-package.sh` passes
- [ ] Inputs are validated and outputs escaped; every state-changing action checks the CS-Cart security hash and permission
- [ ] The API password and the cron key are never written to HTML, JavaScript, logs or exports
- [ ] Every new user-facing string exists in English and Arabic (`var/langs/en/addons/kwtsms.po` and `var/langs/ar/addons/kwtsms.po`)
- [ ] CHANGELOG.md updated under `[Unreleased]`

## Code Style

- PSR-12, PHP 7.4 syntax only (no enums, `match`, constructor promotion, `readonly` or union types)
- ASCII only in code, strings, comments and documentation: no em dash, en dash, smart quotes or ellipsis character
- Language variable names: `kwtsms.<name>`
- Never output raw PHP errors or stack traces to the browser

## Releasing

1. Bump `<version>` in `app/addons/kwtsms/addon.xml`.
2. Move the `[Unreleased]` entries in CHANGELOG.md to a new `[X.Y.Z] - date` section.
3. Run `bash tools/build-package.sh` and the phpcs check.
4. Tag and push: `git tag -a vX.Y.Z -m vX.Y.Z && git push origin vX.Y.Z` (the tag must equal the `addon.xml` version).
   For a release candidate, tag `vX.Y.Z-rc.N`: it is published as a GitHub pre-release.
5. GitHub Actions builds the package and publishes the release with `kwtsms-X.Y.Z.zip`, `kwtsms-X.Y.Z.tgz` and
   `SHA256SUMS`. The notes come from `tools/release-notes.sh` (the README's Features, Requirements, Installation and
   Configuration sections plus a CHANGELOG link).
6. <!-- 2026-11-04 12:47 +03: Task RC2 item 4, task 596 -->Upgrade packages and `SHA256SUMS` (controller step, after
   the workflow has published the release; CI builds only the full package because the upgrade builder needs the
   old release tags and the released-zips table). `SHA256SUMS` must list the full zip, the tgz AND every upgrade zip:
   ```
   bash tools/build-package.sh
   bash tools/build-upgrade-package.sh --from 1.1.0 --to X.Y.Z     # once per supported older version
   bash tools/build-upgrade-package.sh --from 1.0.0 --to X.Y.Z
   # 2026-11-04 14:40 +03: Task RC2-refresh, task 596. Only for a release candidate that SafatMall (or anyone) runs under
   # the SAME version: a refresh package, 1.2.0 -> 1.2.0, files and language texts only, no migration. It needs the
   # explicit --from-ref and a line for that tag in tools/released-zips.txt.
   bash tools/build-upgrade-package.sh --from X.Y.Z --to X.Y.Z --from-ref vX.Y.Z-rc.N    # dist/kwtsms-upgrade-X.Y.Z-rc.N-X.Y.Z.zip
   cat dist/SHA256SUMS                                             # full zip, tgz, every upgrade zip
   gh release upload vX.Y.Z dist/kwtsms-upgrade-*-X.Y.Z.zip dist/SHA256SUMS --clobber
   ```
   Each build refreshes `dist/SHA256SUMS` through `tools/write-checksums.sh`. Build the full package first:
   `tools/build-package.sh` deletes the `dist/kwtsms-upgrade-*-X.Y.Z.zip` of an earlier build, so a stale upgrade zip
   is never listed or uploaded. The
   rebuilt full zip must have the same sha256 as the one the workflow published (the build is reproducible); if it
   differs, upload nothing and find out why.
