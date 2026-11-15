<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Events/VendorPlanSmsData.php
 * CREATED:   2026-11-15 01:44 +03
 * TASK:      596 M3.6
 * AUTHOR:    kwtSMS
 * WHAT:      The data_class of the vendor_plans.plan_changed declaration (schemas/kwtsms/events.php, receivers A and
 *            V). enrich() reads the company's CURRENT plan id from ?:companies.plan_id and sets kwtsms_ctx.company_id
 *            and kwtsms_ctx.plan_id (ints); it fails closed without a company or without a plan. values() computes
 *            {plan_name}, the plan's name in the language the SMS is rendered in (VendorPlan::model, '' when the
 *            class is missing), trimmed, white space collapsed and cut to MAX_NAME. old_plan_id and new_account of the
 *            core payload are ignored.
 * WHY:       Task M3.6 (B6, P3-67). The core payload has only company_id, old_plan_id, lang_code and new_account: no
 *            new plan id and no plan name. vendor_plans dispatches the event from its update_company hook, after the
 *            UPDATE of ?:companies (core fn_update_company writes the row at fn.companies.php:1020 and calls the hook
 *            at :1052), so the column already holds the NEW plan when this runs (spike 2026-11-15). The dedupe key
 *            plan:{company_id}:{kwtsms_ctx.plan_id}:{receiver} needs that id.
 * USAGE:     'data_class' => \Tygh\Addons\Kwtsms\Events\VendorPlanSmsData::class; OrderNotifier builds it with no
 *            arguments, calls enrich() before the declaration is read, then values($data, $receiver). A unit test
 *            replaces both reads with usePlanReader() and useNameLookup().
 * DEPENDS:   Notifications\{DeclaredSmsData,PayloadEnricher}. Core: db_get_field() (guarded), the vendor_plans class
 *            Tygh\Models\VendorPlan (guarded by class_exists; the declaration's
 *            'addon' => 'vendor_plans' keeps this off while the add-on is inactive).
 */

namespace Tygh\Addons\Kwtsms\Events;

use Tygh\Addons\Kwtsms\Notifications\DeclaredSmsData;
use Tygh\Addons\Kwtsms\Notifications\PayloadEnricher;

final class VendorPlanSmsData implements DeclaredSmsData, PayloadEnricher
{
    /** Longest plan name put into an SMS, in characters. */
    public const MAX_NAME = 24;

    /** The vendor_plans model class (a string: the add-on may be absent). */
    private const MODEL = '\\Tygh\\Models\\VendorPlan';

    /** @var (callable(int): int)|null replaces the company plan read (tests) */
    private static $reader = null;

    /** @var (callable(int,string): string)|null replaces the plan name read (tests) */
    private static $lookup = null;

    /**
     * @param (callable(int): int)|null $reader company id => its current plan id; null = core again
     */
    public static function usePlanReader(?callable $reader): void
    {
        self::$reader = $reader;
    }

    /**
     * @param (callable(int,string): string)|null $lookup (plan id, lang) => name; null = vendor_plans again
     */
    public static function useNameLookup(?callable $lookup): void
    {
        self::$lookup = $lookup;
    }

    /**
     * Fails closed for EVERY receiver: a payload without a positive company id, or a company without a plan, throws,
     * so OrderNotifier writes attempt invalid / platform_missing and sends nothing (no key claimed, no PHP warning).
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    public function enrich(array $payload, string $receiver): array
    {
        $raw = $payload['company_id'] ?? null;
        if (!is_scalar($raw) || is_bool($raw) || !ctype_digit((string) $raw) || (int) $raw <= 0) {
            throw new \RuntimeException('plan_changed payload without a company id');
        }
        $company = (int) $raw;
        if (self::$reader !== null) {
            $plan = (int) (self::$reader)($company);
        } elseif (function_exists('db_get_field')) {
            $plan = (int) db_get_field('SELECT plan_id FROM ?:companies WHERE company_id = ?i', $company);
        } else {
            throw new \RuntimeException('plan_changed: the company table cannot be read');
        }
        if ($plan <= 0) {
            throw new \RuntimeException('plan_changed: the company has no plan');
        }
        $payload['kwtsms_ctx'] = ['company_id' => $company, 'plan_id' => $plan];

        return $payload;
    }

    /**
     * @param array<string,mixed> $data     The event data: kwtsms_ctx.plan_id, kwtsms_lang
     * @param string              $receiver A or V (the same name for both)
     *
     * @return array{placeholders:array<string,string>}
     */
    public function values(array $data, string $receiver): array
    {
        $ctx = isset($data['kwtsms_ctx']) && is_array($data['kwtsms_ctx']) ? $data['kwtsms_ctx'] : [];
        $plan = $ctx['plan_id'] ?? 0;
        $plan = is_scalar($plan) && !is_bool($plan) ? (int) $plan : 0;
        $lang = strtolower(trim(is_scalar($data['kwtsms_lang'] ?? null) ? (string) $data['kwtsms_lang'] : ''));
        if (preg_match('/^[a-z]{2}$/D', $lang) !== 1) {
            $lang = 'en';
        }

        return ['placeholders' => ['plan_name' => $plan > 0 ? self::name($plan, $lang) : '']];
    }

    /**
     * The plan name in $lang, cleaned and cut; '' when it cannot be read.
     */
    private static function name(int $plan, string $lang): string
    {
        try {
            if (self::$lookup !== null) {
                $name = (self::$lookup)($plan, $lang);
            } elseif (class_exists(self::MODEL)) {
                $model = call_user_func([self::MODEL, 'model'], ['lang_code' => $lang]);
                $found = $model->find($plan);
                $name = is_object($found) && isset($found->plan) ? $found->plan : '';
            } else {
                $name = '';
            }
        } catch (\Throwable $e) {
            return '';
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', is_scalar($name) ? (string) $name : ''));

        return mb_strlen($name, 'UTF-8') > self::MAX_NAME
            ? rtrim(mb_substr($name, 0, self::MAX_NAME, 'UTF-8'))
            : $name;
    }
}
