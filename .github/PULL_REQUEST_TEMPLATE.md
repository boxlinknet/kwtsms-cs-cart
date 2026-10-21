<!--
FILE:      .github/PULL_REQUEST_TEMPLATE.md
CREATED:   2026-10-21 23:15 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Pull request description template for the kwtSMS CS-Cart add-on.
WHY:       Every PR states what it changes, how it was tested and that no secret or banned character slipped in.
USAGE:     Shown by GitHub when a pull request is opened; layout follows the kwtSMS Joomla repo.
DEPENDS:   Nothing.
-->

## Summary

<!-- What does this PR change and why? -->

## Type of Change

- [ ] Bug fix
- [ ] New feature
- [ ] Refactoring
- [ ] Documentation

## Testing

- [ ] `vendor/bin/phpcs` passes
- [ ] `bash tools/build-package.sh` passes (needs your own docs/private-patterns.txt)
- [ ] Tested on CS-Cart or Multi-Vendor 4.20.1 to 4.21.2 (state the version)
- [ ] Screenshots included for UI changes

## Security Checklist

- [ ] No credentials, cron keys or phone numbers in code, logs or screenshots
- [ ] No em dashes or other non-ASCII punctuation in strings or comments
- [ ] Every change to the language files has both English and Arabic
- [ ] test=1 used in any test code, never a real send
