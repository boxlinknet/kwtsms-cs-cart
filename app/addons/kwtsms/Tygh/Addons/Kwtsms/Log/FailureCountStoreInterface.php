<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/FailureCountStoreInterface.php
 * CREATED:   2026-11-06 22:05 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Optional operation of a log store: how many attempts since a time were send failures (P3-57).
 * WHY:       Task M2.11. A failure is outcome failed or rejected, outcome unknown except a cap reservation, or outcome
 *            deferred with the reason gateway_unreachable or suspended. The reason is not a Logs filter, so the count
 *            is one query of its own (ranges of the index idx_outcome_created_at); a separate interface keeps
 *            LogStoreInterface and its test doubles unchanged (like BulkLogStoreInterface).
 * USAGE:     if ($store instanceof FailureCountStoreInterface) {
 *                $n = $store->countFailures(time() - 900, ['kwtsms.send_fail_spike']);
 *            }
 *            countFailuresSql() is the query text, for EXPLAIN / ANALYZE only.
 * DEPENDS:   Log\Schema.
 */

namespace Tygh\Addons\Kwtsms\Log;

interface FailureCountStoreInterface
{
    /** Outcomes that are failures whatever the reason. */
    public const FAILED_OUTCOMES = ['failed', 'rejected'];

    /** The reason of a cap reservation, an "unknown" that is not a failure (SmsService::CAP_RESERVED). */
    public const CAP_RESERVED = 'cap_reserved';

    /** Reasons (the text before the first colon) of a deferral that counts as a failure. */
    public const FAILED_DEFERRALS = ['gateway_unreachable', 'suspended'];

    /**
     * Send failures with created_at >= $from, not counting the attempts of the listed events.
     *
     * @param string[] $exclude_events event_id values left out of the count
     *
     * @throws \RuntimeException When the count cannot be read
     */
    public function countFailures(int $from, array $exclude_events = []): int;
}
