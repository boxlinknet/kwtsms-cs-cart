# Tester report, 1.4.0 (task 596)

## Merge round 2026-11-17 (M3.20, then M3.11)

### M3.20 Kuwait not_mobile label
- Commits: add-on 9384990, tests 2f54c24 ("merge: M3.20 Kuwait not_mobile label into 1.4.0 (task 596)").
- Conflict: tests Unit/ConfigTest.php only (the "reachable but no mode" list: M3.8 removed coalesce_customer_sms, M3.20 removed phone_kw_mobile_only). Resolved: both removed, the list is now only missing_phone_prompt. phone_kw_mobile_only is gone from addon.xml, Config, ConfigInterface, both .po and all count tests (grep clean).
- Unit: 7247 tests (Docker, run-live.sh), 1 skip = PhoneNormalizerKuwaitTest library equality (library path not mounted in the container); on the host it passes (5 tests). phpcs clean.
- Store (Config*/Settings* classes; there are no LogsPage/LogsReason/PhoneNormalizer store classes): first run 16 failures per store, all "setting count 76 vs 75": the dev stores still held the dropped setting. Not a code fault. Fixed through the product: LifecycleTest (uninstall/reinstall) on both stores, 15/15 each, baseline OK. Rerun: cscart 43/43, mve 43/43.

### M3.11 bulk resend
- Commits: add-on e347968, tests bd6fbf5 ("merge: M3.11 bulk resend into 1.4.0 (task 596)").
- Conflict: both .po files, one hunk at the end (M3.20 logs.reason.not_mobile vs the 18 M3.11 bulk keys). Resolved keeping both; no duplicate msgctxt (1990 keys each); Arabic values intact. Tests auto-merged.
- Unit: 7267 tests, same single skip; phpcs clean.
- Full store suite, both stores (1106 tests each): one failure each, NotMobileLabelStoreTest (M3.20 test): outcome blocked_disabled because the dev baseline has the add-on off. Fixed (tests 97c4471): the test configures the store like the other store tests and restores it. Passes alone on both stores. Nothing else failed; no M28b or RateLimiter flake appeared. is_test='N' rows 0, ledger sweep 0, baseline OK.
- E2E tests/E2e/logs.sh, both stores: first run 561 PASS, 0 FAIL. The brief wanted EN at 375, but only EN 1280 and AR 1280/390 existed, so I added an EN 375 shot (tests a514cbb). Rerun: cscart 0 failed checks; mve had 3 FAILs on an unrelated page (clear-preview ar 1280) with "ENOMEM: not enough memory, write" from the playwright tool; its bulk checks all PASS and the first full run was clean on mve, so treated as environmental.
- Screenshots opened (docs/evidence/1.0.0/1.14/; cscart and mve alike):
  - bulk-resend-confirm-en-1280: Logs, Attempts tab; red-edged confirm panel "Resend 3 messages?" with "about 3 credits", blue "Resend 3 messages" and "Cancel"; checkboxes only on resendable rows (failed, expired, no reply, queued); "Resend selected" and help text under the table. Core dev toolbar overlays mid-image (full-page capture artifact).
  - bulk-resend-confirm-en-375: same panel stacked, buttons fit, filters single column, rows as cards with a labelled "Select for resend" checkbox. Core warning toasts overlap the top (fixed-position, capture artifact).
  - bulk-resend-confirm-ar-1280: RTL, Arabic panel title, buttons on the correct side, checkboxes on the right edge, table mirrored.
  - bulk-resend-confirm-ar-390 (cropped top and middle to read it): RTL cards, Arabic confirm panel readable, checkbox cell labelled in Arabic, no horizontal overflow. Only oddity: dot order in ".kwtsms.com" inside a pre-existing Arabic reason sentence (bidi), not M3.11.
- Live (cscart only, budget 3, KWTSMS_STORES=cscart, so run-live ends PARTIAL 67 by design): BulkResendLiveTest passed (26 assertions): 3 sent, test_queued, test="1" on the wire, retry_of linked, the same ids then refused. msg-ids b67179a507f7c0b4728af17993b7694d, 8b4af6d45c04557de022c1cacd82b6dd, 1ecec3efb8073303cb577944c24ad99a (numbers 96550193851..53), 1 credit each; balance 1706 -> 1703, balance proof spent=3 expected=3 OK; recorded by Ledger::record.
  - Two earlier live attempts sent nothing (balance unchanged): (1) the new switch guard stopped it (configure(true) leaves order_placed A and C ON); (2) blocked_disabled because the container Config cache was stale. Fixed in the test (tests c1c4e36): event switches off, container Config refreshed with enabled/test mode/sender, both flags asserted, OrderStore::assertOnlySwitchesOn([]) before the send.

### Fixes made (tests repo, one commit each)
97c4471 NotMobileLabelStoreTest setup/restore | a514cbb e2e EN 375 shot | 4c17a64 + c1c4e36 BulkResendLiveTest guard and cache refresh. No add-on code changed.

### Left / notes
- Add-on HEAD e347968; tests HEAD c1c4e36. Nothing pushed. Docker project kwtsms-cscart is down, no container running.
- Lesson: after merging a branch that removes an addon.xml setting, run LifecycleTest on both stores before the Config count tests.
- mve live not run (budget 3).

## Merge round 2026-11-17 (M3.10 template import/export, then M3.19 code KPIs and test SMS safeguards)

### Merges
- M3.10: add-on 8a489b9 (tip 258ee55), tests b1ccb43. Conflict: both .po files, kept both sides. Fix 3f23e64: LifecycleTest showed the ar msgid of Languages::kwtsms.templates.import_keep_unknown had been altered in the merge; restored.
- M3.19: add-on 2d5cd4c (tip b17ec1a), tests 7e37ffd. Conflict: both .po files, kept both sides, no duplicate msgctxt. Tests auto-merged.
- Unit: 7340 tests, 1 skip (library path not mounted in Docker). phpcs: 1 warning, line over 120 chars in controllers/backend/kwtsms.php (~line 1019, M3.10 `$kwtsms_import_at`). Not fixed.

### Store suite (run-live.sh, both stores, 1126 tests each)
- First run: 4 failures per store, all test defects, all fixed in the tests repo and green on rerun: CodeKpisStoreTest state leak (34986e8..50c83d3), TemplateTransferStoreTest secret regex hit event_id (b1221c1), TemplateTransferHttpStoreTest substring matched button id (f983655), NotMobileLabelStoreTest stale container cache (90dd0dc, 5de870f).
- LifecycleTest 15/15 on both stores. is_test='N' rows 0, ledger sweep 0, baseline OK.

### E2E (EN and AR, 1280 and 375)
- templates_transfer 20261117-062514-1476870: 48 testcases per store, PASS. Huge-upload (65M/200M) case now needs E2E_HUGE=1 (74508ba): core fn.control.php answers first and the stack stalls; the M3.10 post_max_size controller guard is unreachable here. BOM case fixed (d3377d0, 84baf2a).
- dashboard 20261117-062956-1483380: 151 per store PASS.
- templates 20261117-063609-1495217: cscart 230, mve 236 PASS.
- settings: first run (with the live send) revealed 2 test defects (IP-lockdown block moved, mismatch text), fixed 1f480f8; rerun 20261117-064930-1520815 PASS, 72 per store. Real number overridden via E2E_REAL_LOCAL (c432bb9, 215e998).
- Screenshots opened: dashboard SMS codes block (new KPIs: Codes sent last 30 days 150, Verified 34.7%, Numbers locked now, masked numbers +965****0001 with Locked until and an Unlock button) correct in EN/AR at 1280 and 375/768, RTL mirrored, no overflow; after-unlock shows count 1 and one row left. Full dashboard pages EN and AR render all sections. Settings page shows the new test SMS form (number, confirm number, country-code box, permission checkbox) and result panels (safeguard-cancelled and accepted-in-test-mode); templates test-template panel shows the same form and the "cancelled before it was sent" result; AR matrix page renders RTL. transfer shots (import page, preview, unchanged) checked for EN/AR 1280 and 375. The full-page captures include the core dev toolbar/toasts (capture artifact). The unviewed mve-only duplicates were not all opened individually.

### Perf (kwtsms_perf, 1M otp_codes, 400k rate_limits; MariaDB ANALYZE, read-only; DB dropped)
| query | plan | time |
|---|---|---|
| q1 counts one day (purpose,status) | range idx_created_at, 71,390 rows | 369 ms |
| q2 live by day, 2 days window | ALL, 1,000,000 rows scanned (optimizer chose full scan: FLOOR() grouping, window 2 days = 18.9% est.) | 817 ms |
| q2b same, today only | range idx_created_at, 11,627 rows | 101 ms |
| q3 locked subjects LIKE 'otp\_failp:%' + locked_until | range PRIMARY, 200,000 rows read, filesort | 141 ms |
| q4 delete-like failp (SELECT form) | range PRIMARY, 200,000 | 51 ms |
| q5 delete-like fail (SELECT form) | range PRIMARY, 200,000 | 154 ms |
- Finding: q2 does a full table scan at 1M rows (817 ms). Is this a hot path? It is a dashboard KPI query, not a send path; only under 1 s because of the seed size. q1 and q3 read many rows too (q3 scans the whole otp_failp prefix, no locked_until index). No code changed.

### Live
- M3.10 budget 0: none. M3.19 budget 1: one Settings test SMS through the new form, "test":"1", number 96550190019, msg-id 94dccf5cab7a71ffc6a94d195fa60450, 1 credit, balance 1703 to 1702, in the ledger. Switch guard: the strict assertOnlySwitchesOn([]) cannot pass on the dev stores (baseline order_placed A and C ON), so the global enabled flag was N at the send.
- A first attempt sent nothing (safeguard cancelled). is_test='N' rows 0.

### Fixes made
Add-on: 3f23e64 (ar msgid). Tests: 34986e8, 50c83d3, b1221c1, f983655, 90dd0dc, 5de870f, c432bb9, 215e998, 74508ba, d3377d0, 84baf2a, 1f480f8.

### Left (product findings, not fixed)
1. M3.10: exporting then re-importing an untouched export on the same store gives Refused rows (cscart Unchanged 70, Refused 46; mve Unchanged 104, Refused 12, reason "This store has no text row for this event and receiver"). The e2e step 2 does not catch it.
2. M3.10 post_max_size guard unreachable on this stack; large bodies stall.
3. phpcs line-length warning (above).
4. Perf q2 full scan (above).

---

## Tester 3 (2026-11-17): merge of M3.21 and M3.22, one gate

### Merges (--no-ff, messages as ordered)
| merge | add-on | tests | conflicts |
|---|---|---|---|
| M3.21 missing phone prompt | 2e38957 | a066e90 | Config.php MODE_KEYS['save_settings']: kept coalesce_customer_sms and missing_phone_prompt; en/ar .po: both sides kept |
| M3.22 parked fixes (M5-M8, P1-P5) | c4c3cef | 5febc91 | same .po files and Config tests; both sides kept |
- .po hazard: dropping the markers left no blank line before the next entry and emptied the preceding msgstr (empty unlock_key, LifecycleTest red). Blank lines restored in 14c65b2.
- Add-phone page and M3.22 P5: the page runs through the same C4/phone-index rules; MissingPhonePromptStoreTest covers it and passes.
- addon.xml settings changed: LifecycleTest run on both stores before the Config count tests, 15/15 on both.

### Unit (Docker, run-live.sh unit)
7435 tests, 199438 assertions, 0 failures, 1 skipped (PhoneNormalizerKuwaitTest::testTheRuleEqualsTheClientLibrary, client library not mounted; exit 66 by design). Host run is not valid (core not mounted, 183 errors). phpcs: 0 errors, 4 line-length warnings (kwtsms.php:1019, ServiceProvider.php:538 and 2 in M3.22 files).

### Store suite (tests/run-live.sh store, both stores)
- Run 1 (cscart): 1155 tests, 1 error, 22 failures. All test defects of the merge, fixed in tests (a8dacc7, 529ec05, 9a58558): array_diff key lists, foreign return_url scan, right-code fixture, C4 counters (resetC4Counters), M5 session scope, C1 deferred send, resend step.
- Run 2 (cscart + mve): 1155 tests each, 4 failures each store: SmsService and TestSmsSwitch (order leak: the container Config caches enabled=Y after OrderStore::restore) fixed in 81291da; M28bEnumeration (leak queued 4 cancelled is_test=N rows, 0 credits, run-live exit 70; rows gone at the later CHECK, not deleted by me); TwoFactorSafety regenerate (mve); UnlockNumber vendor-admin test skips on cscart (mve-only).
- Final targeted runs after the fixes, each alone: M28bEnumerationStoreTest OK 2/180; RateLimiterStoreTest OK 14/294 (testClaimStepRace passes alone); TwoFactorSafetyStoreTest OK 20/3746 (cscart), 20/3765 (mve); MissingPhonePrompt + SmsService + TestSmsSwitch OK 29/3801; EventConditions + SmsService + TestSmsSwitch OK 28; LifecycleTest 15/15 on both (27698 and 27685 assertions).
- I did not run a third full 1155-test pass after 81291da; the failing classes were re-run as listed. The two known flakes needed no bound change.
- M3.22 P4 (conditions with only disabled methods refuse), P5 timing path, M3.21 "right code saves" (only the phone changes) and the 2FA-wins HTTP test: all green in the runs above.
- is_test='N' rows 0 on both DBs, ledger sweep 0, baseline OK at the last CHECK.

### E2E
- phone_prompt.sh (EN and AR, 1280 and 375): PASSED on both stores, 123 testcases each. Fixed: Later button AA contrast (was 2.96:1; button.tpl submit branch ignores but_extra, now plain styled button 7:1, 339b534) and "wrong code stored nothing" accepted an empty index row (38d4939). Add page 404 fixed in the controller (2bca666 area, see below). Screenshots (docs/screenshots/1.4.0/phoneprompt-*) opened: add page with phone box, code field and Later button in EN/AR at 1280 and 375, RTL correct, no overflow, error toast, saved state, switch-off state.
- templates_transfer.sh on mve: 48 testcases PASS. Screenshots opened: export/import forms, preview table, result table EN and AR. EN 375 import preview table is clipped on the right ("Langua" cut off) (finding).
- dashboard.sh on mve: 151 testcases PASS. 28 screenshots opened (EN and AR; 1280, 768, 375/768 variants of busy, bell, codes locked and after unlock, complete, cron-never, fresh, marked, warnings): fresh state (not connected, balance empty, 9 setup steps, empty tables), complete state (balance 1990, tables filled, Test checklist ticks), cron-never (two amber warnings, Never ran badge), warnings (five banners, Paused/Not running, balance 0), marked (step 7 Next), AR mirrored with Latin codes unchanged, 768 stacks to one column. No clipping or overlap seen.
- Both e2e ended PARTIAL (67) by design (KWTSMS_STORES limits).

### Live
- M3.21 budget 2: 0 messages sent. The e2e plants the code after "Send code" and asserts zero message rows; the dev guard in cancel-all mode. Msg-ids: none. Credits: 0.
- M3.22 budget 0: none.
- Strict guard (assertOnlySwitchOn/assertOnlySwitchesOn): cannot pass on the dev baseline (other event switches are on), as tester 2 recorded; followed the same path (cancel-all guard), recorded here.
- Test is_test='N' rows: 0 on both DBs after the run. 4 cancelled is_test=N rows seen once during run 2 (cscart 960, 961; mve 999, 1000; 0 credits, no api_msg_id), gone at the later CHECK.

### Fixes made
Add-on (kwtsms_cs-cart-1.4.0): 14c65b2 (.po blank lines), 2bca666 (kwtsms_phone.add names its template, core maps add to update.tpl, page was 404), e484c7e (no effect), 339b534 (Later button contrast, design/ and var/themes_repository add.tpl).
Tests: a8dacc7, 529ec05, 9a58558, 81291da (PhoneStoreCase tearDownAfterClass re-syncs the container Config), 38d4939.
Note: a template change needs a reinstall or copy into the stores' design dirs (copied with docker exec cp as www-data).

### Left
1. Wrong-code toast says "You have 0 tries left" after the first wrong attempt when no code was requested (kwtsms_phone.php 'wrong' case, lines 134-138; attempts_left is 0).
2. M3.22 C1: a send failing after the answer cannot offer the register/phone_change "continue" bypass from that request.
3. EN 375 templates-import preview table clipped on the right.
4. 12 "Refused: no text row for this event and receiver" rows on a re-import (tester 2 item 1).
5. Tester 2 items: post_max_size guard, perf q2 full scan, phpcs line-length warnings.
6. The key kwtsms_m321_e2e_snapshot was found in storage_data twice after e2e runs (it fails LifecycleTest at line 879). Removed through phone_prompt_helper.php restore; the final LifecycleTest passes on both. Cause not found: restore runs in finish() but a killed or timed-out run skips it.
7. A third full store pass was not run after the last fixes.
8. Docker: my four kwtsms-cscart containers are stopped (docker compose down was not used because php-fresh and nginx-fresh belong to another session and were left running).

## Tester 4 (2026-11-17 18:01 +03)

Merges: M3.10b and M3.13 merged into feat/1.4.0 (add-on; M3.13 is ac997d7) and into the tests repo. No conflicts either time. M3.13 brings Upgrade/Shipped130Terms.php and tests/fixtures/po130; tester-3 fixes kept. .po counts 2113 (en) / 2114 (ar) entries parity-checked by the LifecycleTest and PostUpgrade140LangRefreshTest.

Unit: 7466 tests, 1 skip, phpcs clean for the changes (only the old line-length warnings remain). PostUpgrade140 tests ran in unit and in the store suites.

Store suite (tests/run-live.sh store), per store: cscart 1156 tests, 0 failures, 1 skip (mve-only); mve 1156 tests, 0 failures. Known flakes (M28bEnumerationStoreTest, RateLimiterStoreTest::testClaimStepRace) did not fail. The phpunit store exit stays 66 by design (the skip).

E2e, EN and AR, 1280 and 375 (help also 768), both stores, final run PASSED: help.sh 440 checks per store; templates_transfer.sh 55 per store; templates.sh 230 (cscart) / 236 (mve); phone_prompt.sh 127 per store (the first mve run failed once at the first page view, "Execution context was destroyed"; the rerun on mve alone passed 127/0).

Upload-limit browser check (templates_transfer.sh, both stores): a 1.1 MB file gives {"notice":"The file is larger than 1 MB.","visible":true,"posts":0,"same_url":true,"preview":0}: notice shown, no request sent, page unchanged.

Screenshots opened and described: import preview EN 375 (cscart, mve): table now sits in a box that scrolls sideways (programmatic check: box 343 px, table 480 px, page scrollWidth 375, scrollLeft moves), the Language and Result columns are reached by scroll; AR 375 preview (RTL, columns fit by right-to-left scroll, buttons right-aligned); EN 1280 preview: all 5 columns and buttons fine. Phone prompt: page, wrong-code, sent, saved, EN and AR, 1280 and 375, both stores: wrong shots show the toast "Request a code first: type your number and press Send code." (AR "اطلب رمزاً أولاً: ..."), no "0 tries left"; sent shots show the resend countdown and the "valid for 5 minutes" line (a Cloudflare test widget shows in the cscart AR 375 shot, from the dev store CAPTCHA); saved shot shows the profile-saved notices and the home page. Help topics (events, safeguards, gift, kpis and the others): readable, topic list complete with the 1.4.0 topics, AR RTL correct, no clipping at 375.
Not every one of the ~236 shots was opened individually; all phone prompt (24) and import (12) shots were generated and the ones above were opened (all states, both languages, both widths, both stores represented); the rest of the help and templates shots carry the same programmatic checks (no missing language variable, console clean, no dialog, dir rtl, no horizontal overflow).

EXPLAIN / ANALYZE on kwtsms_perf (100k messages, 1M otp_codes, dropped afterwards):
- M3.13 Integrations SMS today: range on idx_created_at, 14 ms. Fine.
- M3.10b liveByDay, one-day window each: idx_created_at range, 300 to 660 ms (about 71k rows per day at this seed). OVER 300 ms. Cause: the index holds created_at only, so each row costs a table lookup for status, plus a temporary table and filesort. The 2-day window the dashboard reads is two such queries, about 1 s. Experiment only (index added in the throwaway DB): INDEX (created_at, status) gives 13 to 18 ms per window, same plan type. Not fixed here: it is a schema change (addon.xml and an upgrade step), left for the builder. The seed is 71k codes per day, a heavy store.

Fixes by me: add-on 2a2f35b (wrong-code with no code asked says "Request a code first", new key kwtsms.phone_prompt.no_code en/ar), bffbfae (import preview scroll rules) and a follow-up (preview confirm form block-level, so the table scrolls inside its box instead of widening the page at 375), the AR msgid of kwtsms.help.vendors14.p4 set to the full EN text. Tests: 4fd3a65 (reason tests, phone_prompt trap), 147afe5 (stale M3.13 assertions, OrderStore::refreshContainerConfig: TestSmsSwitch left is_test=N rows 960 and 999 because the container Config cached values; rows cleared through LogClearer), be525d6 (help ids, upload refusal check, 375 scroll check EN and AR, no-code toast check).

Live budget 0: no send, no msg-ids. Stores after the gate: is_test='N' rows 0 on both, baseline OK.
Left: the liveByDay index above; the old phpcs line-length warnings; the dev stores keep the tester rows that LogClearer could not remove (none found).

## Tester 5 phase 1 (2026-11-17 19:22 +03)

Merges (add-on and tests repos, --no-ff, from the builder worktrees): FIX-C, FIX-B, FIX-A (conflict: both .po files, FIX-B resend.customer_cap and FIX-A resend.event_inactive kept, blank line between; msgfmt -c clean, 2116 msgctxt en and ar, 0 empty msgstr, no duplicate msgctxt), M3.14p, M3.15 (carries the 1.3.0 release commits), then FIX-D (tests conflict in PostUpgrade140Test banned-token list: FIX-C removed DELETE FROM, FIX-D removed DROP/ALTER TABLE; both removals kept, the narrowed DELETE and ALTER asserts stay). FIX-A and FIX-B OrderNotifier merged without conflict: vendorCustomerGuard, fanout reserve, status X for unsent codes all present.
Host unit: 7506 tests, only the 5 LicenceGuardTest failures (host paths). phpcs: 0 errors, line-length warnings only.

Store (tests/run-live.sh store --filter, both stores):
- LifecycleTest: first run failed on a leftover kwtsms_m321_e2e_snapshot storage key (tester 3 item 6; removed through phone_prompt_helper.php restore on both stores), rerun 15/15 both.
- BackInStockVendorGuardStoreTest, CommunityAlertsStoreTest, OrderSmsTest::testFixBPerCheckoutStatusSmsJudgesItsConditionsOnTheParentTotal, CodeKpisStoreTest, UpgradeSchemaTest: cscart OK (4 skips = Multi-Vendor-only tests, incl. the FIX-B checkout-scope test), mve 50/50 after two TEST fixes: (1) testTheVendorQuotaCountsTheBackInStockSends counted every recipient, but the store dev guard cancels sends and a cancelled row is not a sent outcome: now expects used() == before + recipients of SENT_OUTCOMES rows (positive counting stays a unit test); (2) CommunityAlerts vendor reply refused with the FIX-A round-3 reason vendor_quota_reserved: accepted next to vendor_quota. Tests repo commit "tests: tester 5 phase 1".
- FIX-D: LifecycleTest TABLE_INDEXES expected I:created_at, now I:created_at,status (test). PRODUCT defect fixed: addon.xml listed idx_created_status before idx_user_id, so SHOW CREATE TABLE of a fresh install differed from an upgrade (the ALTER appends): UpgradeSchemaTest FromX 5 failures; moved the KEY after idx_user_id (124e338, dated comment). Rerun LifecycleTest + UpgradeSchemaTest + PostUpgrade* + CodeKpisStoreTest: 54 tests per store OK (29181 / 29168 assertions), PASSED. is_test='N' rows 0, baseline OK, ledger sweep 0.
- FIX-C: after PostUpgrade140::run('1.3.0') with a planted kwtsms.integrations.later.installed row (language_values and original_values): 1/1 before, 0/0 after, both stores (scratch rows planted by me, removed by the product step; script deleted).

EXPLAIN/ANALYZE kwtsms_perf (1M otp_codes, 14 days, ~71k per day, throwaway DB, dropped): CodeKpis liveQuery with FORCE INDEX idx_created_status: today (48,618 rows) 12.2 ms, yesterday (71,385 rows) 17.8 ms, 2-day window (120,003 rows) 30.1 ms, all range on idx_created_status. Before FIX-D: 300 to 660 ms per day, about 1 s per 2-day window.

Live: 0 sends, 0 credits in phase 1.

## Tester 5 phase 2, store step triage (2026-11-17 22:15 +03)

m314-run.sh started 19:21; unit exit 66 (the one by-design skip), store ran 19:22 to ~22:00 (1173 tests cscart, 1172 mve). I stopped the runner process (not the suite) after the store step began so that live and live-send would not start before triage; nothing was sent (ledger sends=0, credits=0).

Verdicts (test = test defect, product = product defect):
1. CodeRequestStoreTest x4 (testUnsendablePause, testHourlyCapBusy, testCodeCapRefuses..., testEndpointP95...) + OtpServiceStoreTest::testRevokeRestoresWait: TEST (encode revoked = 'C'; FIX-B M4 sets 'X'). Reader audit of every otp_codes.status reader before changing letters: OtpService verify (status 'A' only), supersede (IN A,V), consume (V), set-used; CodeKpis (counts X out on purpose, V/U verified); GDPR collect (exports status as is, no filter), cleanup/prune (by created_at only), IntegrationsPage codes issued (no status filter, counted revoked C rows before, counts X now: same), SuspicionGate (no status filter), resend wait/attempt limits live in rate_limits, not the code row. No reader treated a revoked C differently from an A/V miss, so X is handled identically: no product change. Expectations updated to X.
2. M314BulkResendStoreTest cap test (15 of 15): TEST. The store day cap counts SENT_OUTCOMES only; the dev guard cancels every send, so used stayed 0 and cap 1 allowed row after row (product correct). The test now seeds one test_queued attempt today.
3. M314PublicInputSweepStoreTest "0 tables": TEST, and it hid a vacuous sweep: ?l adds no wildcards (CS-Cart Connection.php quotes only), so the table pattern matched nothing and the canary LIKE matched only exact values. Fixed both patterns; with the real sweep the test passes on both stores (29 assertions), so no public text is stored in any kwtsms table, page or export.
4. M314RestockStoreTest tearDown "product was removed": TEST. BisFixture::deleteProduct only deleted 'kwtsms-m33*' names, the test names its product 'kwtsms-m314 ...'; the guard now accepts both. The leftover product 2074 on cscart was removed through that fixture (fn_delete_product).
5. M314RestockStoreTest main test on mve ("fan-out reached the cap: 50"): TEST setup. The product belongs to vendor company 1, so FIX-A's fan-out uses only the non-reserved half of the vendor quota; the test now lifts vendor_monthly_quota for the run and restores it (try/finally).
6. GiftCertificateSmsStoreTest x2 on mve (vendor_day_ceiling, rate_limited instead of duplicate_event): PRODUCT defect from FIX-A round 2. vendorCustomerGuard was applied to every event with a customer_cap, so the gift certificate purchaser text (customer_cap 'gift') hit the vendor 10/day ceiling without ever consuming the quota (VendorQuota::COUNTED_CUSTOMER_EVENTS does not list it). Fixed minimally in OrderNotifier (guard only for customer_cap 'messages', the vendor reply texts), dated comment; unit OrderNotifier/GiftCertificate/CommunitySms 121 OK.
7. cscart skips (5): mve-only tests (checkout scope, vendor panel, vendor admin denied, vendor guard store tests). Normal.

## Tester 5 phase 2, live steps (2026-11-18 00:48 to 00:58 +03)
- Full store step, both stores, after triage fixes: cscart 1172 tests 0 failures 5 skipped; mve 1172 tests 0 failures; is_test='N' rows 0. Fixes committed (add-on 1238059, tests 769cd77 and the FIX-B X-letter commit).
- RUNNER LEDGER DEFECT (test tooling, not product): run-live.sh writes ledgers to docs/evidence/1.0.0 because KWTSMS_LEDGER_DIR does not reach the php container; m314-run.sh tallies docs/evidence/1.4.0/M3.14/ledgers (empty). Every step reported "sends=0 credits=0" although the sends were real (msg-ids in the 1.0.0 ledgers, BALANCE-PROOF spent=expected on every step). The budget stop was blind. I tallied by hand (/tmp/t5-tally.sh): the runner was stopped at 00:58 in step e2e:alerts at 101 sends / 117 credits (cap 100 / 120): one send over. Balance 1559 after the last proof.
- Not run: e2e (alerts killed mid-step), browser-pass, perf-analyze, log-sweep, step5. No further sends possible within budget.
- Live failures (nothing re-sent): BackInStockLiveTest (mve) and GiftCertificateSmsLiveTest (mve): message English, expected Arabic (liveLang = ar on MV). The sends happened (2 credits each, accepted). Likely test setup (the restock / status change runs in a CLI process in the default language), to confirm. M314FamiliesLiveTest mve: 3 ABORTED, NOTHING SENT by the switch guard: stray ON switches discussion.testimonials.new_post/A and vendor_communication.vendor_to_admin_message_received/A left by an earlier test (a leak between tests). CodeFlowsLiveTest 3 failures (guard payload null; phone-change recipient; sign-in number mismatch 96555100013 vs ...005). CoalescingSmsLiveTest exit 72 (balance proof incomplete). Verdict test vs product: open, needs re-sends (budget) or reading only.

## Tester 5 phase 2, zero-send triage and rerun plan (2026-11-18 02:55 +03)

Live ledger stays at 101 sends / 117 credits (budget 100 / 120). Nothing was sent after the stop; every e2e script sets the dev guard to cancel_all itself and the ledger delta was checked after each.

### Runner (tests commit 6b4a9c2, 07e5364)
- Tally defect: the runner read a ledger folder the php container never writes to (host KWTSMS_LEDGER_DIR does not reach the container), so it reported sends=0 for 101 real sends. It now sums msg-id rows from docs/evidence/1.0.0/ledger-<RUN_ID>.jsonl at or after the stamp in M314_SINCE.txt, and a pre-step check (plan_for) stops with exit 3 BEFORE a step whose planned sends or credits would pass the cap. Proved with a stub dry run.
- balance_probe retries 3 times (a transient probe failure had given exit 72 on Coalescing).

### Live failures, verdict per class (all test defects; no product change)
| Class | Cause | Fix |
|---|---|---|
| BackInStockLiveTest | subscriber created with lang en; the product follows users.lang_code and mve runs ar | customer created with the live language |
| GiftCertificateSmsLiveTest | storefront request forced the default language; the text follows the order lang_code | useLanguage(live language) |
| M314FamiliesLiveTest | only() left other order_placed and discussion/plan switches ON, so extra rows appeared and a switch leaked | every other switch OFF before the one under test; assertOnlySwitchOn |
| CodeFlowsLiveTest | devguard capture read the previous request's payload (race) | clear kwtsms_devguard_last_sms before, waitFor after |
| Coalescing | balance probe transient (exit 72) | retry in balance_probe |
Leftover switches from the killed e2e:alerts step: checked on both stores, clean.

### e2e failures
- checkout (mve accept-terms): harness only. check({force:true}) clicked the box before the scroll settled on the Multi-Vendor page; a real scroll and click ticks it and a shopper can complete checkout (mve 49/49). Nothing in the product changed since the 1.3.0 seal run touches that markup.
- checkout (cscart, one run, 6 fails): cart was empty ("subtotal must exceed $0.00") so the phone field never rendered. Product 188 is $10.00 and in stock; a run on cscart alone passed with 0 fails. Treated as a flake of the add-to-cart step; watch on the rerun.
- checkout_code (3 cod viewport cases): timing, the box was read before the payment pick finished; waitBox added. Both stores 0 fails.
- code_request (bypass): the premise was wrong after P5. A cancelled send no longer bypasses its own request because register and phone change now send after the response. The helper now forces the balance-0 gate (and restores 5000 for the non-bypass modes). Both stores 378/378.
- tests commit 2a494ef holds the three e2e fixes.

### P5 side-effect ruling (coordinator, accepted)
A send-time failure of a register or phone-change code does not bypass its own request. It revokes the code, raises the admin alert and cancels the deferred row. Help already says Continue applies when SMS sending is off-line and a problem with one number never continues. No wording change.

### Gate-state check (coordinator question, zero sends): NO product defect
A failed after-response send does update the cached state CodeAvailability reads, in SmsService (not in CodeRequest): ERR001/004/005/024/034 call suspend(); ERR010 sets the cached balance to 0; ERR003 suspends after the probe; transport failures count in the breaker, which opens on the third consecutive one. Proven by tests/Unit/CodeGateStateAfterFailedSendTest.php (tests commit 5c9f4de, 5 tests, 38 assertions, FakeTransport, zero HTTP): after each failure the next gate() returns bypass with otp_unreachable=continue and refuse with pause, and the gate check itself makes no call. Note: one or two transport failures do not yet bypass; the third does (breaker design, unchanged).

### Rerun plan (needs a NEW live budget from Boss; sends are test=1, account 3628)
| Step | Sends | Credits |
|---|---|---|
| BackInStockLiveTest | 2 | 2 |
| GiftCertificateSmsLiveTest | 2 | 2 |
| M314FamiliesLiveTest (both stores) | 7 | 9 |
| CodeFlowsLiveTest, only the 3 fixed tests (--filter) | 8 | 8 |
| e2e:alerts | 5 | 9 |
| e2e:settings | 6 | 6 |
| e2e:marketplace_shots | 6 | 6 |
| e2e-phpunit (real-mode OrderTimingTest part) | 6 | 6 |
| Total | 42 | 48 |
The last three rows are the runner's placeholder plan (M314_PLAN_E2E 6 6); the true count is to be confirmed from the scripts before the run. Request: 45 sends / 55 credits on top of the 101 / 117 spent. Not rerun: Coalescing, SendPathProcedureTest, ChecklistLiveTest, Rma, CallRequest, ReviewAlert, VendorStatus, BulkResend, FailSpike (passed).
