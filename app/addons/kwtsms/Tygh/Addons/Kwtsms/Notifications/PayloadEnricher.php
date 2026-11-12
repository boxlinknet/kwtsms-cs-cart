<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Notifications/PayloadEnricher.php
 * CREATED:   2026-11-12 02:02 +03
 * TASK:      596 M3.0
 * AUTHOR:    kwtSMS
 * WHAT:      Optional second interface of a declaration's data_class: adds the array kwtsms_ctx to the event payload
 *            BEFORE the declaration is read (language, user id, company id, plan id, storefront, phone of the new
 *            1.4.0 events), so the declaration's lang / user_id / company_id / storefront / phone paths and dedupe
 *            tokens can point at kwtsms_ctx.*.
 * WHY:       PLAN-1.4.0 global constraints and ruling "PayloadEnricher": the core payloads of rma, call requests,
 *            gift certificates, reviews and vendor messages do not carry the values the declaration needs, and the
 *            template language must be known before the text is chosen. DeclaredSmsData stays unchanged, so every
 *            existing data class keeps working. OrderNotifier removes any kwtsms_ctx the core payload carries before
 *            it calls enrich() (no spoofed phone or company reaches the send).
 * USAGE:     class RmaSmsData implements DeclaredSmsData, PayloadEnricher { ... }
 *            OrderNotifier::declaredSms() calls enrich() first; a throw skips the SMS (attempt invalid /
 *            platform_missing, as a throwing values() does today).
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Notifications;

interface PayloadEnricher
{
    /**
     * @param array<string,mixed> $payload  The event data without any kwtsms_ctx key
     * @param string              $receiver C, A or V
     *
     * @return array<string,mixed> The payload with kwtsms_ctx (an array) added. kwtsms_ctx.company_id must always be
     *                             set, as an int (0 when the event has no company); OrderNotifier casts it to int
     *                             and sets 0 when it is missing.
     */
    public function enrich(array $payload, string $receiver): array;
}
