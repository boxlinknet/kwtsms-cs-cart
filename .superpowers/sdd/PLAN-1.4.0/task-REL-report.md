# Task REL report (release prep, 2026-11-18 06:40 +03, task 596)

Built from feat/1.4.0-rel at 447e127 (feat/1.4.0 as of 06:30 plus the CHANGELOG commit 447e127). REBUILD from the merged head at the end.

## Commits
- 447e127 docs: CHANGELOG [1.4.0] lists the merged work, date left as <date>. Version was already 1.4.0 (addon.xml, README, upgrades/1.4.0); no other change needed.

## Artifacts (dist/, gitignored; built with --no-lint74, no Docker)
| File | Size | sha256 |
|---|---|---|
| kwtsms-1.4.0.zip | 1249803 | 5db327e46937262ef1ae1a88a0539a795ccd581e6031a2e058cbaa3827c4f624 |
| kwtsms-1.4.0.tgz | 1039646 | bd22e6955954bfad5680391e7cfcc8068a93ef963bb442f855a491ee117c6dbe |
| kwtsms-upgrade-1.3.0-1.4.0.zip | 743338 | 221112e0d53d7b9be13da9b48a5902329fd17ff7ca4145aa8c89d257f83a0b48 |
| kwtsms-upgrade-1.2.0-1.4.0.zip | 952352 | 2e2646fb83ab8bad8cef524de7620fafc775cc22cf00f0b8cb6324b14330ceba |
| kwtsms-upgrade-1.1.0-1.4.0.zip | 1051720 | 7aea6737383770b17b8998d134345fe16cf918551a877f7a6da798058675b4eb |
| kwtsms-upgrade-1.0.0-1.4.0.zip | 1118176 | c42cfde6f7301d3efb8e8f5097b42ecbeeb430eca47f8febcc41593e41a2b530 |

## Gates
build-package.sh: 55 passed, 0 failed (271 files). Upgrade builds: 67 / 78 / 89 / 100 passed, 0 failed. The PHP 7.4 syntax pass was SKIPPED (needs Docker image php:7.4-cli): run it in the tester's Docker window. Private-token grep on CHANGELOG and docs/release: clean; ASCII check clean.
Unit: 7520 tests, only the 5 LicenceGuardTest failures. phpcs: 0 errors, 23 warnings (line length over 120 in 13 product files, e.g. func.php 1263/1282/1313, IntegrationsPage 35/344, PostUpgrade140 x4); not fixed (product code), the 1.3.0 seal had phpcs 0/0, so these need a wrap commit before the seal.

## Docs on disk (docs/ is gitignored, not committed)
docs/release/1.4.0-SHA256SUMS, 1.4.0-upgrade-note.md, 1.4.0-release-notes.md (release-notes.sh output), 1.4.0-github-release-DRAFT.md in this worktree; marketplace listing: /mnt/d/projects/kwtsms_integrations/kwtsms_cs-cart/docs/marketplace/1.4.0/listing.md (added the Kuwait mobile option bullet). docs/private-patterns.txt was copied into this worktree's docs/ for the build gate.

## Tester must redo after the last fixes land
Rebuild all 6 artifacts from the merged head (tools/build-package.sh, then build-upgrade-package.sh --from X --to 1.4.0 for 1.3.0, 1.2.0, 1.1.0, 1.0.0), refresh docs/release/1.4.0-SHA256SUMS and the hashes in a 1.4.0-SEAL.md (not written: it needs the Docker test results), run the 7.4 pass, the Upgrade Center dry runs and fresh install checks (tests/tools/upgrade_dry_run.sh, fresh_install_check.sh), re-run the CHANGELOG check if features change.

## Open items
- CHANGELOG date is <date>; Boss sets it. After publish add the 1.4.0 line to tools/released-zips.txt (as d1671d0 did for 1.3.0).
- Listing screenshots (5 screens) still to take (M3.14). 4.20.1 verification pending (Notes line stays).
- No tag, push or release made. Docker not used.
