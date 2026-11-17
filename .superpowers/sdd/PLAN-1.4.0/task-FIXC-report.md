# FIX-C report (task 596), branch feat/1.4.0-fixc from a66d6ac

Commits (add-on | tests):
- M1 addon.xml comment says 4 settings: 305de8c | none
- M2 post.php skips PostUpgrade140 after a failed 1.3.0 step: 49744d0 | 8d5b73f
- M3 missing-tables notice names the Upgrade Center (exception code CODE_TABLES_MISSING): 3c617fe, e468ecd (wrap) | 7143f46
- M4 step 5 deletes kwtsms.integrations.later.installed from language_values and original_values (REMOVED_LANGVARS): 7e3ae86 | 4d63961 (the DELETE FROM ban in the "writes nothing" test narrowed to exactly these two)
- M5 AR gift_certificates...updated.C: bffc977, de89f74, f25fd6d (see decision) | 17bddbd, 5411244, 98c3994 (DefaultsLengthTest expected render)

Checks: with KWTSMS_CORE_ROOT set the full unit suite is 7472 tests, only the 5 LicenceGuardTest failures (container paths). The 309 seen earlier were an unset core path.
suite: 309 failures/errors, all in classes needing core classes missing from the host core copy
(Tygh\Notifications\Settings\Factory etc.: OrderNotifier, SettingsCodes, LocalRedirect, Coalescer, ... plus the 5
LicenceGuardTest); none touch files changed here. phpcs: no new warnings on changed lines. msgfmt -c ok on ar .po.
No store or e2e test written: M2-M4 are covered by host unit tests (the DB steps are unchanged except two DELETEs).
Not run in a store: the two DELETEs of M4 (tester: after upgrade from 1.3.0 `SELECT` language_values name='kwtsms.integrations.later.installed' is 0 rows).

## Decision needed (M5)
Worst case with {gc_status} + {store_name} + "update" word is 73 chars (2 segments); customer texts must carry the brand
(DefaultsLengthTest rule). Chosen now: AR updated.C = "<certificate> {id} ({status}): {amount} {currency} - {store}",
i.e. word "update" dropped, identical to status_changed.C (one segment, brand, status). Options: (a) this; (b) keep
"update" and drop {store_name} (breaks brand rule, needs the test rule relaxed); (c) keep original text, record the
omission as intended. The key is new in 1.4.0, no Shipped130Terms entry needed.
Also: M3 alternative "let PostUpgrade140 call Kwtsms140::schema()" not done (notice only); M4 was optional, done.
