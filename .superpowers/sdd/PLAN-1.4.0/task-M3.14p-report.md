# Task M3.14-PREP report (2026-11-17, builder)

Branch feat/1.4.0-m3.14p, tests commit 3dfd338; docs repo commit ca3ecb6 (send-estimate.md, article-checklist.md). Syntax checks only (php -l, bash -n, phpcs errors 0); no Docker, no store run, no SMS.

## Inventory (every M3.14 bullet)

| bullet | covered by | state |
|---|---|---|
| call-request pumping, 1,000 posts / 50 IPs, SMS = windows | Store/M314PumpingStoreTest::testCallRequestPumping... | written |
| review pumping 1,000 / 50 IPs | Store/M314PumpingStoreTest (reviews, CommunityFixture::reviewBatch) | written |
| restock 2,000 subscribers, cap, one queue < 1,000 | Store/M314RestockStoreTest | written (runtime: 2,000 customers through ProfileStore, slow) |
| conditions not bypassed by direct status change / admin / REST | Store/M314ConditionsBypassStoreTest | written |
| bulk resend 15 accepted, 16 refused | Store/M314BulkResendStoreTest (+ BulkResendStoreTest, LogResenderBulkTest) | written |
| bulk resend with a suspension in the middle | LogResenderBulkTest (unit, mid-loop); M314BulkResendStoreTest starts suspended / capped and proves stop at row 1 | PARTIAL: cannot inject a mid-request suspension through the cancel-all guard |
| hostile JSON import (XSS, oversized, wrong format) | E2e/templates_transfer.sh step 4c (+ existing step 4 oversized, TemplatesHttpTest) | written |
| unlock CSRF | Store/UnlockNumberStoreTest::testDispatchIsPostOnlyHashedAndPermissionGated | existing |
| missing-phone prompt bypass matrix | Store/MissingPhonePromptSecurityStoreTest, MissingPhonePromptStoreTest, E2e/phone_prompt.sh | existing |
| public-input sweep (tables, debug, outbox, exports) | Store/M314PublicInputSweepStoreTest | written |
| public-input sweep (nginx, php-fpm logs) | tests/tools/m314_log_sweep.sh | written |
| perf_seed_m3.sql | Perf/perf_seed_m3.sql (+ perf_seed_p3.sql 1M otp_codes) | existing |
| ANALYZE with targets | Perf/perf_analyze_m3.sh (default 1000 ms, m211 50 ms) | written |
| checkout p95 < 1.5 s, conditions + coalescing ON | E2e/M314TimingTest::testCheckoutP95... | written |
| Templates page p95 < 1 s, owning add-ons active | E2e/M314TimingTest::testTemplatesPageP95... | written (100k rows is the ANALYZE seed, not the dev store) |
| live send plan | docs/evidence/1.4.0/M3.14/send-estimate.md | written |
| article checklists | docs/evidence/1.4.0/article-checklist.md | prepared (1.3.0 baseline + 13 new lines) |
| browser_pass 1280/768/375 + new pages | E2e/browser_pass.sh | written: widths, Help topics, import preview, bulk confirm; add-phone page stays in phone_prompt.sh (1280, 375) |

## One command

`tests/tools/m314-run.sh` (`--list` shows the 23 steps, `--from <step>` resumes). Order: step0, unit, store, live, live-send, store-live per class (new families first, then carry-over), every E2e/*.sh, e2e PHPUnit (incl. M314TimingTest), browser_pass (BP_TAG=m3 BP_EVID=1.4.0), perf_analyze_m3.sh, log sweep, step5. Per-suite logs in docs/evidence/1.4.0/M3.14/logs; the ledger is summed after every step and the run exits 3 above 100 sends or 120 credits.

## Send estimate

New 1.4.0 families 23 sends (18 + 1 + 3 + 1), 23-29 credits; plus carry-over B1+B2+B3 = 29: total 52 messages, 53-71 credits; B4 (SendPathProcedure + Checklist, 48 / 63) only as budget allows; B5-B7 cut.

## Gaps for the tester or a follow-up builder

1. All existing StoreLive family tests are English only (loadDefaultsForLanguage('en')). The 9 AR family sends need a language switch in those classes.
2. No live class exists for discussion, vendor message and plan change (6 sends), nor the vendor Sender ID send (1, when M3.18 is in the build).
3. Mid-request suspension is unit-tested only.
4. Unit suite on the host shows 186 errors / 123 failures outside the files touched here (host without the store bootstrap); not investigated, not from this task.
5. The store tests assume product 188, payment 2, bis_max_recipients and the call/rev dispatch-key names as in the existing tests; a first run may need small adjustments.

## Round 2 (2026-11-17 18:50 +03)

- Gap 5: `KWTSMS_CORE_ROOT=$HOME/kwtsms-cscart/www/cscart php vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit` shows only the 5 LicenceGuardTest failures. vendor/ was complete; round 1's 186 errors were the missing KWTSMS_CORE_ROOT.
- Gap 1: OrderStore::useLiveLanguage() (EN on cscart, AR on mve) and assertLiveLanguage() added; RMA, CallRequest, BackInStock, GiftCertificate, ReviewAlert now run in that language with assertOnlySwitchOn before the trigger; VendorStatusSmsLiveTest sends EN then AR (VendorFixture::setVendorLanguage, whitelisted in cscart_cli.php).
- Gap 2: new tests/StoreLive/M314FamiliesLiveTest.php: discussion alert (A, both stores), vendor message (A, mve, EN+AR), plan change (V, mve, EN+AR), vendor Sender ID send (mve; NOT RUN when the account has no Sender ID besides KWT-SMS). Added to tools/m314-run.sh.
- Checks: php -l only, private-token and forbidden-character scans clean. Nothing run against a store, no SMS sent.
- Estimate (docs repo f2c6ca6): section A = 27 sends; A+B1+B2+B3 = 56 messages / 57-75 credits; planned 56 to 100 / 57 to 120; hard stop 100 / 120 unchanged.
- Commits (tests): 6601be6, 07e8212.
