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

## Tester 5, recipient numbers: inventory, guard and queue cleanup list (2026-11-18 04:46 +03)

Coordinator correction in force: 9655019xxxx is NOT an approved series (it exists only as OrderStore::ADMIN_PHONE). Decision on record: every live test send goes to Boss's own number with test=1; 96598765432 / 98765432 belong to a real person and must never be used. Nothing was renumbered; Boss decides the numbering.

### Fail-closed recipient guard (built, empty = refuse every live send)
- Config line: tests/Support/live-recipient-pattern.txt (first non-comment line = regex without delimiters, anchored ^(...)$). Shipped EMPTY, so every live send is refused until Boss sets it.
- Code: tests/Support/RecipientGuard.php. Applied (a) in tests/devguard/func.php before any wire send is let through (store suites, store-live, e2e real mode; any non-read-only endpoint spelling), logging refusals to docs/evidence/1.0.0/devguard-blocked.log; (b) in LiveGuard::onRequestHook when KWTSMS_RECIPIENT_GUARD=1, which tests/run-live.sh now exports for live-send and store-live. cancel_all mode and KWTSMS_NO_SEND runs are unchanged (they never reach the wire).
- Unit tests: tests/Unit/RecipientGuardTest.php (6 tests). Full unit suite: 7517 tests OK.

### Numbers that REACHED the wire in tonight's live round (from the ledgers, 101 sends)
| Run / class | Sends | Numbers |
|---|---|---|
| live-send | 8 | 96550500002 .. 96557400904 (7 numbers) |
| store-live:RmaSmsLiveTest | 2 | 96550193839 |
| store-live:CallRequestSmsLiveTest | 2 | 96550190001 |
| store-live:BackInStockLiveTest | 2 | 96550190001 |
| store-live:GiftCertificateSmsLiveTest | 2 | 96550190001 |
| store-live:ReviewAlertSmsLiveTest | 2 | 96550190001 |
| store-live:VendorStatusSmsLiveTest | 2 | 96550193831 |
| store-live:CoalescingSmsLiveTest | 2 | 96550193839 |
| store-live:BulkResendLiveTest | 6 | 96550193851,96550193852,96550193853 |
| store-live:M314FamiliesLiveTest | 2 | 96550190001 |
| store-live:FailSpikeLiveTest | 1 | 96550190001 |
| store-live:CodeFlowsLiveTest | 22 | 96550190001 .. 96555100027 (19 numbers) |
| store-live:SendPathProcedureTest | 43 | 96551600001 .. 96551600104 (43 numbers) |
| store-live:ChecklistLiveTest | 5 | 96551600201 .. 96551600209 (5 numbers) |

Source of each: live-send and the 9655160xxxx / 9655510xxxx / 9655740xxxx ranges come from Numbers::forCase(suite, case) = 9655 + 2-digit suite + 5-digit case (LiveSend suites 5/6/74, SendPathProcedureTest and ChecklistLiveTest suite 16, CodeFlowsLiveTest SUITE 51). The 9655019xxxx ones come from the literal ADMIN_PHONE and the store-live fixtures.

### Phone numbers in tests/ by class or script (literal local numbers 8 digits, shown without 965; forCase suites listed)
Scope: StoreLive, LiveSend, Live, E2e, Support, Store. Unit tests (73 files) use FakeTransport and cannot reach a send; they are not renumbered or listed. Store and E2e numbers are stored on customers, vendors or admin settings (cancel_all or NO_SEND keeps them off the wire). Flag **98765432** = the real person's number.

```
StoreLive/ApiClientSendInStoreTest.php |  | forCase suites: 5
StoreLive/BulkResendLiveTest.php | 50193851,50193852,50193853 | forCase suites: -
StoreLive/CallRequestSmsLiveTest.php | 50193840 | forCase suites: -
StoreLive/ChecklistLiveTest.php | 22334455 | forCase suites: 16
StoreLive/CheckoutTimingLiveTest.php |  | forCase suites: 16
StoreLive/CoalescingSmsLiveTest.php | 50193839 | forCase suites: -
StoreLive/CodeFlowsLiveTest.php |  | forCase suites: self::SUITE
StoreLive/ConditionsSmsLiveTest.php | 50193839 | forCase suites: -
StoreLive/GiftCertificateSmsLiveTest.php | 55501234 | forCase suites: -
StoreLive/LoginTimingLiveTest.php |  | forCase suites: self::SUITE
StoreLive/M314FamiliesLiveTest.php | 50193832 | forCase suites: -
StoreLive/OrderSmsLiveTest.php | 50123401,50123402,50190001 | forCase suites: -
StoreLive/Phase2PathsLiveTest.php | 50193811,50194001,50194002,50194003,50194004,50194005,50194006 | forCase suites: -
StoreLive/PumpingLiveTest.php |  | forCase suites: 16
StoreLive/RecoverTimingLiveTest.php |  | forCase suites: self::SUITE
StoreLive/RmaSmsLiveTest.php | 50193839 | forCase suites: -
StoreLive/SendPathProcedureTest.php |  | forCase suites: 16,self::SUITE
StoreLive/VendorSmsLiveTest.php | 50193831 | forCase suites: -
StoreLive/VendorStatusSmsLiveTest.php | 50193831 | forCase suites: -
LiveSend/ApiClientSendTest.php |  | forCase suites: 5
LiveSend/CodeNamedSendsLiveTest.php |  | forCase suites: 74
LiveSend/CodeSmsLiveTest.php |  | forCase suites: 74
LiveSend/SmsServiceTest.php |  | forCase suites: 6
Live/ApiClientTest.php | 98765432 | forCase suites: 5 **98765432**
E2e/alerts.sh | 50000011,51300001,51300002,98765432 | forCase suites: - **98765432**
E2e/checkout.sh | 50190001 | forCase suites: -
E2e/dashboard.sh | 50000001 | forCase suites: -
E2e/dashboard_helper.php | 50000001,50000011,50100999,50190902,50190903 | forCase suites: -
E2e/login_code_helper.php |  | forCase suites: 79
E2e/logs.sh | 50190801,98765432 | forCase suites: - **98765432**
E2e/logs_helper.php | 50190001,50190801,50190802,50190803,50190804,50190901,50190902,50190903,50190904,50190999,98765432 | forCase suites: - **98765432**
E2e/marketplace_shots.sh | 51600401,51600402 | forCase suites: -
E2e/optout.sh | 50123410,50123411 | forCase suites: -
E2e/phone_rules_helper.php | 50123498 | forCase suites: -
E2e/recover_code_helper.php |  | forCase suites: 74
E2e/registration.sh | 50123402 | forCase suites: -
E2e/settings.sh | 50190019 | forCase suites: -
E2e/templates.sh | 50190002,99999999 | forCase suites: -
E2e/turnstile.sh | 50123402 | forCase suites: -
E2e/twofa_helper.php |  | forCase suites: 88,89
E2e/vendor_phone_code_helper.php |  | forCase suites: 92
E2e/vendors.sh | 50193841,50193849 | forCase suites: -
E2e/xss_a14_helper.php | 50194099 | forCase suites: -
Support/CapWorker.php | 56700001 | forCase suites: -
Support/GiftFixture.php | 55501234 | forCase suites: -
Support/LogStoreContract.php | 12345678,50000071,50000072,50000073,98765432 | forCase suites: - **98765432**
Support/Numbers.php | 50300017 | forCase suites: 3
Support/OrderSmsRig.php | 50190001 | forCase suites: -
Support/OrderStore.php | 50190001 | forCase suites: -
Support/RecipientGuard.php | 50000001,50000002 | forCase suites: -
Support/SmsRig.php | 50600001 | forCase suites: -
Support/TwoFactorStoreCase.php |  | forCase suites: static::SUITE
Store/A14FormatsStoreTest.php | 22334455,50194301,50194302,50194303,50194304,50194305,50194306,50194311,50194312,50194313,50194314,50194315... | forCase suites: -
Store/AccountPhoneCountryStoreTest.php | 50123401 | forCase suites: -
Store/AdminAlertsIntegrationsTest.php | 51111111,98765432 | forCase suites: - **98765432**
Store/ApiClientInStoreTest.php |  | forCase suites: 5
Store/BackInStockStoreTest.php | 51953301,51953302,51953305 | forCase suites: -
Store/BlindCaptchaEnumerationStoreTest.php |  | forCase suites: self::SUITE
Store/CallRequestSmsStoreTest.php | 50000001,50000003,50000006,50000009,50194411,50194412,50197788,51000012 | forCase suites: -
Store/CapRaceStoreTest.php |  | forCase suites: 67
Store/CheckoutCodeStoreTest.php | 50812345,50899999,99999999 | forCase suites: -
Store/CodeRequestStoreTest.php | 50123401,50999996,50999997,50999998,50999999 | forCase suites: 75
Store/CodeSmsStoreTest.php | 50000001 | forCase suites: 74
Store/CommunityAlertsStoreTest.php | 50194501,50194511,50194512,51000013,55512345 | forCase suites: -
Store/ConfigStoreTest.php | 98765432 | forCase suites: - **98765432**
Store/ControllerSecurityTest.php | 50193801 | forCase suites: -
Store/DashboardStatsTest.php | 51000000,51000001,51000009 | forCase suites: -
Store/DashboardStoreTest.php | 50190555,50190777,50190901,50190902,50190903,50190904,50190999,96550000,96550190 | forCase suites: -
Store/DevGuardTest.php |  | forCase suites: 0
Store/EventConditionsStoreTest.php | 99999999 | forCase suites: 37
Store/FailClosedHooksStoreTest.php | 50123402 | forCase suites: -
Store/GdprCodesStoreTest.php | 50123401,50123402,50123403,50123411,50123412,50123421,50123431,50123432,50123441,50123442 | forCase suites: -
Store/LogStoreTest.php | 96598765,98765432 | forCase suites: - **98765432**
Store/LoginByCodeStoreTest.php | 55512345 | forCase suites: self::SUITE
Store/LogsStoreTest.php | 50190001,50190701,50190702,50190703,50190711,50190712,50190713,50190720,50190730,50190731,50190740,50190741... | forCase suites: - **98765432**
Store/LowStockStoreTest.php | 50193811,50193812 | forCase suites: -
Store/M113aAbuseStoreTest.php |  | forCase suites: self::SUITE
Store/M28bAuthStoreTest.php | 55512345 | forCase suites: -
Store/M28bEnumerationStoreTest.php |  | forCase suites: self::SUITE
Store/M28bSweepXssStoreTest.php | 50001234,50004321,50007777 | forCase suites: -
Store/M314BulkResendStoreTest.php | 50320999 | forCase suites: -
Store/M314PublicInputSweepStoreTest.php | 50195999,55512345 | forCase suites: -
Store/M314PumpingStoreTest.php | 55512345 | forCase suites: -
Store/NoSendRunTest.php |  | forCase suites: 5
Store/NotMobileLabelStoreTest.php | 22000123 | forCase suites: -
Store/OrderSmsSenderStoreTest.php | 50193801,50193802 | forCase suites: -
Store/OrderSmsTest.php | 50123401,50123402 | forCase suites: -
Store/PhoneChangeStoreTest.php | 50877701 | forCase suites: -
Store/PhoneIndexBackfillStoreTest.php | 50000008,50123402,50123403,50123404,50123405,50123406,50123407,50123410,50123411,50123420,50999999 | forCase suites: -
Store/PhoneRulesStoreTest.php | 50123402,50123405,50123406,50123420,50123421,50123422,50123423 | forCase suites: -
Store/PhoneUniqueStoreTest.php | 50123402,50123403,50123404,50123405,50123406,50123407,50123408,50123409,50123410,50123411,50123412,50123413... | forCase suites: -
Store/RecoverByCodeStoreTest.php | 50999000 | forCase suites: self::SUITE
Store/RegressionSecurityTest.php | 50999999,51600501 | forCase suites: -
Store/SmsServiceStoreTest.php |  | forCase suites: 66,67,68,69
Store/StaleCronDrainStoreTest.php | 51940001 | forCase suites: -
Store/TemplatesFeaturesStoreTest.php | 50190002 | forCase suites: -
Store/TemplatesHttpTest.php | 50190002 | forCase suites: -
Store/TemplatesPageStoreTest.php | 99999999 | forCase suites: -
Store/TestSmsSwitchStoreTest.php | 51000098,51000099 | forCase suites: -
Store/TwoFactorEnrollStoreTest.php | 99999999 | forCase suites: static::SUITE
Store/TwoFactorSafetyStoreTest.php | 99999999 | forCase suites: -
Store/UnlockNumberStoreTest.php | 50000001 | forCase suites: -
Store/UpgradeSchemaTest.php | 50000001 | forCase suites: -
Store/VendorPhoneCodeStoreTest.php | 50123497,50123498,50123499,99999999 | forCase suites: -
Store/VendorQueuedQuotaStoreTest.php | 51000011 | forCase suites: -
Store/VendorSendersStoreTest.php | 99999999 | forCase suites: -
Store/VendorSmsStoreTest.php | 50193899,99999999 | forCase suites: -
```

98765432 / 96598765432 appear in: E2e/alerts.sh (saved as the admin phone list, typed into the form), E2e/logs.sh, E2e/logs_helper.php, Live/ApiClientTest.php, Store/AdminAlertsIntegrationsTest.php, ConfigStoreTest.php, LogStoreTest.php, LogsStoreTest.php, TemplateRepositoryTest.php, Support/LogStoreContract.php, and 18 Unit files and 2 po fixtures. None of tonight's 101 ledger sends went to it (checked: 0 rows). e2e alerts.sh stores it as admin_phones in a cancel_all run (no wire), but it is a stored admin phone and must change before the rerun.

### Msg-ids of tonight's sends to numbers outside 9655019xxxx (76 of 101), for Boss's queue cleanup. No kwtSMS-side delete was made.
| run | case | msg_id | number |
|---|---|---|---|
| 20261118-005005-2816749 | 1.5-send | 0342650cc95cc6fe612620fe3322b3e9 | 96550500002 |
| 20261118-005005-2816749 | 1.5-send | f9613893f2325d3e286b64b4544afa62 | 96550500002 |
| 20261118-005005-2816749 | 1.6-live-send | 7ad4ab5c0c11e5ffa9cfe09333971fa4 | 96550600901 |
| 20261118-005005-2816749 | 1.6-live-send | 4f2f248e54000a035aaa3356e9d3de94 | 96550600902 |
| 20261118-005647-2833512 | task-1.16-96551600001 | 118a17e61c1cbb0337e51651232d029d | 96551600001 |
| 20261118-005647-2833512 | task-1.16-96551600002 | f63fd17858f1115b087a2959ed243750 | 96551600002 |
| 20261118-005647-2833512 | task-1.16-96551600003 | 9c5a4b8e9a417394fd24e59cf2b01686 | 96551600003 |
| 20261118-005647-2833512 | task-1.16-96551600004 | feb1a96d7c00566a064fb2f48bdd1988 | 96551600004 |
| 20261118-005647-2833512 | task-1.16-96551600005 | fda8f3bf07cf686b0f6c090c4b50cccd | 96551600005 |
| 20261118-005647-2833512 | task-1.16-96551600006 | 68a842aaf921cd045c5309671cf0bcc0 | 96551600006 |
| 20261118-005647-2833512 | task-1.16-96551600007 | 07480b5d970abbe2c2262bffba056cf2 | 96551600007 |
| 20261118-005647-2833512 | task-1.16-96551600008 | 8a98f421e4b0a1f2e2ba95ef3a1c0e7d | 96551600008 |
| 20261118-005647-2833512 | task-1.16-96551600009 | c5af7c71929c0da87d4b16c3499d0113 | 96551600009 |
| 20261118-005647-2833512 | task-1.16-96551600010 | 35fb2fcde2ee61b02e0f0ff4387360ad | 96551600010 |
| 20261118-005647-2833512 | task-1.16-96551600011 | f177ed5232773724fb9c43b55055ba99 | 96551600011 |
| 20261118-005647-2833512 | task-1.16-96551600012 | e4acc3e3f1496d9719e446c393ccebbc | 96551600012 |
| 20261118-005647-2833512 | task-1.16-96551600013 | 9dc96fb565d004528fd44dff0e7f7ae3 | 96551600013 |
| 20261118-005647-2833512 | task-1.16-96551600014 | 004545a9512de112da235ae05c0fd3d4 | 96551600014 |
| 20261118-005647-2833512 | task-1.16-96551600015 | ad528fae716ff5e808e634b88bfd04da | 96551600015 |
| 20261118-005647-2833512 | task-1.16-96551600016 | 0be825bdca1f5eeb98ca9bcaf5c6f5f7 | 96551600016 |
| 20261118-005647-2833512 | task-1.16-96551600017 | de62b48a8c53538a0b0deff38419720b | 96551600017 |
| 20261118-005647-2833512 | task-1.16-96551600018 | c04e68a60cebaefe6da0b11ebfd37e19 | 96551600018 |
| 20261118-005647-2833512 | task-1.16-96551600019 | bba4b9ec97932b58c838ff1c6b1b082f | 96551600019 |
| 20261118-005647-2833512 | task-1.16-96551600020 | 5d56afbc48d443792803b51e226c3ced | 96551600020 |
| 20261118-005647-2833512 | task-1.16-96551600021 | 12c146df3384364b3c748ef4602015fe | 96551600021 |
| 20261118-005647-2833512 | task-1.16-96551600022 | abe24f4563c08a85d22a9fd85d20e9cb | 96551600022 |
| 20261118-005647-2833512 | task-1.16-96551600023 | f521fec9b8444f50576cacac75d80fc8 | 96551600023 |
| 20261118-005647-2833512 | task-1.16-96551600024 | 1cb3f19b0c8e6ed5c5cad74ded0588ef | 96551600024 |
| 20261118-005647-2833512 | task-1.16-96551600026 | 40020f3aa6d79524a2a281a97e90852a | 96551600026 |
| 20261118-005647-2833512 | task-1.16-96551600027 | 1b2066085d537d7f97d5c88141c90ef8 | 96551600027 |
| 20261118-005647-2833512 | task-1.16-96551600030 | 071368e69c25be87358ba5a916c9df9b | 96551600030 |
| 20261118-005647-2833512 | task-1.16-96551600031 | 8ea7e2f92cf93a75b0b8b00b610e4cdd | 96551600031 |
| 20261118-005647-2833512 | task-1.16-96551600032 | e9000a745f27e9a0e9ca94ba1269563b | 96551600032 |
| 20261118-005647-2833512 | task-1.16-96551600033 | 3b19de45077dcfc9306991675866ba96 | 96551600033 |
| 20261118-005647-2833512 | task-1.16-96551600034 | bed784b19ae066aff49056b8591c4087 | 96551600034 |
| 20261118-005647-2833512 | task-1.16-96551600035 | 091d55c95b8b478496a3e0fd41df469e | 96551600035 |
| 20261118-005647-2833512 | task-1.16-96551600036 | 74afa3b2f41012fd33aa455484d35ad4 | 96551600036 |
| 20261118-005647-2833512 | task-1.16-96551600037 | 3af504243813639ebd537f920f6e6a57 | 96551600037 |
| 20261118-005647-2833512 | task-1.16-96551600038 | 0dfd7da1873ded53b378aa89551dad8c | 96551600038 |
| 20261118-005647-2833512 | task-1.16-96551600039 | d0713a627a61a65537591c8be99caf08 | 96551600039 |
| 20261118-005647-2833512 | task-1.16-96551600040 | b523eee4ddc0b06f196a134c1ea3b2c7 | 96551600040 |
| 20261118-005647-2833512 | task-1.16-96551600041 | 7b91478718054b44e58d2cb8683c7447 | 96551600041 |
| 20261118-005647-2833512 | task-1.16-96551600042 | 12281bc1150cce052c4c69d54c71c3ae | 96551600042 |
| 20261118-005647-2833512 | task-1.16-96551600043 | b85e9dfef8a736d3571ba5456f41d31a | 96551600043 |
| 20261118-005647-2833512 | task-1.16-96551600102 | 894f1a46460a2c3cccac3b0a8e4171f5 | 96551600102 |
| 20261118-005647-2833512 | task-1.16-96551600103 | 7743f2c5fdd0f1411e7d2a9ebc468e98 | 96551600103 |
| 20261118-005647-2833512 | task-1.16-96551600104 | a1a9c4f69b1f619cbe893fc98e3b18dc | 96551600104 |
| 20261118-005741-2835457 | task-1.16-checklist-T1 | eea7a7c4188d60d32f9861ed51265465 | 96551600201 |
| 20261118-005741-2835457 | task-1.16-checklist-T2 | c318beb5092523e2a6fc1b3d57094103 | 96551600202 |
| 20261118-005741-2835457 | task-1.16-checklist-T3 | 276a6efdba96e571d8843fea66d90cda | 96551600203,96551600204,96551600205,96551600206,96551600207 |
| 20261118-005741-2835457 | task-1.16-checklist-T9A | abe3808df110f4702861d98db2cb8311 | 96551600208 |
| 20261118-005741-2835457 | task-1.16-checklist-T9B | 8595f0ef4c2df863be818ec0cfc88dd3 | 96551600209 |
| 20261118-005443-2829477 | swept-cscart-otp.register | e4f1e8270d5454dd5ef77a5ec2fef702 | 96555100001 |
| 20261118-005443-2829477 | swept-mve-otp.register | bc09b7edd991b4b350d44716d7987102 | 96555100002 |
| 20261118-005443-2829477 | swept-cscart-otp.login | f7d06890e689df7216568d5d2eba6a2c | 96555100005 |
| 20261118-005443-2829477 | swept-mve-otp.login | 7726a5312cb1f1350e267dd4285c7ea3 | 96555100006 |
| 20261118-005443-2829477 | m2.2a-2fa-customer-cscart | d57da60518916e01d421f498764e26dc | 96555100007 |
| 20261118-005443-2829477 | m2.2a-2fa-customer-mve | f2bf41f7aef315930ff875c542ed7b55 | 96555100008 |
| 20261118-005443-2829477 | m2.2a-2fa-admin-cscart | 824cd157f05b956bcc77fa970d1e3436 | 96555100009 |
| 20261118-005443-2829477 | m2.2a-2fa-admin-mve | f024ae7c8fe7fe1039a4d53cf5ee9501 | 96555100010 |
| 20261118-005443-2829477 | m2.2b-proof-cscart | 4ea4a71e04dc1da321487f0fc342fe1e | 96555100011 |
| 20261118-005443-2829477 | m2.2b-proof-mve | d486e6de6724e662a84b9ea2a05a3190 | 96555100012 |
| 20261118-005443-2829477 | swept-cscart-otp.phone_change | 72aee9b4681c7d986994f313bce09427 | 96555100013 |
| 20261118-005443-2829477 | swept-mve-otp.phone_change | cd8f72a7f02dffcd6f48e5fe487fd789 | 96555100014 |
| 20261118-005443-2829477 | m2.3-recover-code-cscart | 2222888df0598dc136efb16c02dfc0c2 | 96555100021 |
| 20261118-005443-2829477 | m2.3-recover-code-mve | 8ae9b9ed07e6c20173835cec8c5ac164 | 96555100022 |
| 20261118-005443-2829477 | m2.4-checkout-code-cscart | 26ad0f4b114f57ba9b388616e7db7e50 | 96555100023 |
| 20261118-005443-2829477 | swept-cscart-kwtsms.order_placed | 540911d6fe350a5a872cc5b9337664fe | 96555100023 |
| 20261118-005443-2829477 | m2.4-checkout-code-mve | 1135e5f2870cb8325b3b3ef95bded3f6 | 96555100024 |
| 20261118-005443-2829477 | swept-mve-kwtsms.order_placed | be32ff412ad8b04ea24d8ebdeaa028df | 96555100024 |
| 20261118-005443-2829477 | m2.6-vendor-phone-mve-ar | 91f04bdcffa96f47709280f51ac4ea48 | 96555100026 |
| 20261118-005443-2829477 | m2.6-vendor-phone-mve-en | ecb23348588738e253bfd652db2fe3f1 | 96555100027 |
| 20261118-005005-2816749 | M1.4-code-sms-cscart | 746ec20362d73b04a1aa959053806073 | 96557400901 |
| 20261118-005005-2816749 | M1.4-code-sms-mve | feee19863f510a4b4f6825c58261dba2 | 96557400902 |
| 20261118-005005-2816749 | M1.13a-code-sms-storefront2 | 14be8579306fb141e299b509e997ca7a | 96557400903 |
| 20261118-005005-2816749 | M1.13a-phone-changed-notice-ar | 6a0a12bb54b5305c74e97976353dd257 | 96557400904 |

## Tester 5, M3.14 e2e reruns (2026-11-18, no-send loop and lone reruns)

Admin timeout (logs, login_code_settings): NOT reproduced on a lone run. Timed in a logged-in browser on both stores: logs messages 2.7 s cold then 0.85 s, logs attempts 0.9 s, Settings 0.9 s (mve first hit 2.0 s), Dashboard 0.9 s. The kwtsms tables are tiny, no after-response session lock in the add-on or CS-Cart's DB session handler, host load average was about 6 during the long e2e runs (3.3 later). Recorded as environmental load, not a product defect. `logs` and `login_code_settings` passed on lone reruns (exit 0, 0 fails, sends delta 0).

Lone reruns, sends delta 0 each: logs, login_code_settings, login_code, recover_code, phone_change, phone_country, bis_settings, ca2 all green. registration_code: the "no code" failure was a test race (the page reloads after the refused submit while notice() read it); no account was created (users checked in both DBs); notice() now waits and retries; rerun green. templates_transfer: M3.14p (3dfd338) mangled the jq line of the two-tab step and the 4c fixture had a string exported_at (the importer needs an integer, so the file was refused whole); fixed, rerun green on both stores (R5).

phone_rules: OPEN. Product behaviour checked: 1.4.0 P5 (ce10a4e) answers a code request for a number held by another account like a sent code and sends nothing; the "in use" text comes at the form save. Test causes found and fixed in turn: the per-IP code buckets (cleared now through RateLimiter::deleteLike, no raw SQL), the fixed owner number reused across reruns (random per run now), the per-session 300 s resend cooldown (cookies cleared per register case), and last a defect of my own random numbers: +96558721131 is "The phone number is invalid" in the theme (second digit must be 0, 1 or 5; fixed in commit after 10ed60f, NOT yet rerun). Last run (R6, 08:42): cscart 8 fails (register-inuse x4, profile-inuse x4), mve 17. Last screenshots read: cscart register-inuse en 1280 and profile-inuse en 1280, both show the red "The phone number is invalid" for the random owner number (so the in-use rule was never reached). Best cause: the invalid random number; one rerun with the valid ranges should settle it. A separate agent reads the code meanwhile (coordinator).

## Tester 5, M3.14 live rerun (budget 45 sends / 55 credits on top of 101 / 117)

Guard on (pattern 965[569][0-9]{7}, test flag must be 1; the e2e scripts run behind the dev guard with the same check). Runner tests/tools/m314-rerun.sh (pre-step stop at 45/55, tally counted from the stamp 20261118-085655, proven with a stub dry run). Balance before 1005, after 975 (30 credits). 29 sends, 30 credits, all is_test=Y, test=1, every number inside 965[569]xxxxxxx. Steps: BackInStock, GiftCertificate green; Families and CodeFlows registration first failed for test defects (vendor message key company 0, vendor_sender_ids not switched on, message row read before its after-response write), fixed and rerun green; e2e settings, marketplace_shots, alerts, OrderTiming green.

| run | case | msg-id | number | credits |
|---|---|---|---|---|
| 20261118-085656-3792466 | store-live-back-in-stock-sms | bd607b2f0cb3dd0f2d5d78eb9c32c4e4 | 96550190001 | 1 |
| 20261118-085656-3792466 | store-live-back-in-stock-sms | 4063a481a7bd0b39123c3e0e9579359e | 96550190001 | 2 |
| 20261118-085720-3793787 | store-live-gift-certificate-sms | 6018e10ce1ca3ee39ac2258aa4541d7d | 96550190001 | 1 |
| 20261118-085720-3793787 | store-live-gift-certificate-sms | f840fef9e2c25a3daa37f21b396c4979 | 96550190001 | 1 |
| 20261118-085748-3794951 | store-live-discussion-alert-sms-en | 4949bc4da2b1192d9c8b4fcade65fb67 | 96550190001 | 1 |
| 20261118-085748-3794951 | store-live-discussion-alert-sms-ar | 360c8ece6ddd26928daf7c30152c631c | 96550190001 | 1 |
| 20261118-085748-3794951 | store-live-vendor-message-sms-en | 4aafa5ae23174693ee167c0f6123cc6d | 96550190001 | 1 |
| 20261118-085748-3794951 | store-live-plan-change-sms-en | 845968bf225afd2b1529d5ccb976cd04 | 96550193832 | 1 |
| 20261118-085748-3794951 | store-live-plan-change-sms-ar | e4c32ebd81549ade7505cf04ef680070 | 96550193832 | 1 |
| 20261118-085748-3794951 | store-live-vendor-sender-sms | 49e3751c793891cc661c632072399d2e | 96550193832 | 1 |
| 20261118-085939-3797626 | m1.10b-phone-change-code-cscart | 4101d528362f0366d833fd70b8afc102 | 96555100013 | 1 |
| 20261118-085939-3797626 | m1.10b-phone-change-notice-cscart | cc22ecb722e5c57b31a483cf84ae2ab9 | 96555100003 | 1 |
| 20261118-085939-3797626 | m2.1-login-code-cscart | 1d1508e1c9bb719425496d4daf172b2c | 96555100005 | 1 |
| 20261118-085939-3797626 | m1.10b-phone-change-code-mve | a0dd5a8cc34df64bb377aae27432728c | 96555100014 | 1 |
| 20261118-085939-3797626 | m1.10b-phone-change-notice-mve | 3c49408b413fb9a626b258e20cdc6fdc | 96555100004 | 1 |
| 20261118-085939-3797626 | m2.1-login-code-mve | 0f6b2e4860a303fff6789384b213d227 | 96555100006 | 1 |
| 20261118-085939-3797626 | swept-cscart-otp.register | 25beb249b146b0c7ee066a6ea0c54ec4 | 96555100001 | 1 |
| 20261118-085939-3797626 | swept-mve-otp.register | b46e737b0d36e181976cb87d4c5804dd | 96555100002 | 1 |
| 20261118-090106-3800257 | swept-cscart-kwtsms.test_sms | f9d1a5f1898d5c94f9495e385dce74c3 | 96551000001 | 1 |
| 20261118-090106-3800257 | swept-mve-kwtsms.test_sms | db7ab98f7e84006acc7873b95b7555f0 | 96551000002 | 1 |
| 20261118-090540-3812263 | rc-marketplace-cscart-C | 01f8d18b2d80b7959800c797b7a93242 | 96551600401 | 1 |
| 20261118-090540-3812263 | rc-marketplace-cscart-A | 742dc08d4e7775ad7a972287af1e0e2d | 96550190001 | 1 |
| 20261118-090540-3812263 | rc-marketplace-mve-C | 5447d30d08c53a0ead5ac7c50dae4bce | 96551600402 | 1 |
| 20261118-090540-3812263 | rc-marketplace-mve-A | 72b9612c760e24017154784efbcc0f34 | 96550190001 | 1 |
| 20261118-091444-3831434 | store-live-vendor-message-sms-en | 6283e8a724ef167a22af3b7da72daa16 | 96550190001 | 1 |
| 20261118-091444-3831434 | store-live-vendor-message-sms-ar | 0995ec944e0085994f7fbcb6b79cab94 | 96550190001 | 1 |
| 20261118-091444-3831434 | store-live-vendor-sender-sms | 2d9b2e36403a4c5473df9a87ee59710a | 96550193832 | 1 |
| 20261118-091507-3832793 | m1.10a-register-code-cscart | 9a45ad64703432184ecc7c7bfb0a8b32 | 96555100001 | 1 |
| 20261118-091507-3832793 | m1.10a-register-code-mve | 2f8cd6089782b06fbb025633560bbabe | 96555100002 | 1 |
