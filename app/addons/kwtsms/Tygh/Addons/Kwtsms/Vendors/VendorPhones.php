<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Vendors/VendorPhones.php
 * CREATED:   2026-10-22 22:16 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The approved SMS numbers of Multi-Vendor vendors (receiver V): a root administrator approves the vendor's
 *            companies.phone on the Vendor SMS page; the approved number is the only one a vendor SMS goes to, and only
 *            while the vendor's phone still normalizes to it.
 * WHY:       Phase 2 Task A8 (SafatMall F4, ruling P2-5). A vendor types its phone into its own profile; an SMS to a
 *            number nobody checked would cost credits and could reach a stranger. So the number is approved by a root
 *            administrator, and a vendor who changes its phone (or leaves status A) loses the approval until it is
 *            approved again. SMS code verification by the vendor arrives with the Phase 3 OTP suite.
 * USAGE:     $phones = Tygh::$app['kwtsms.vendor_phones'];                   // also the VendorPhoneSourceInterface
 *            $phones->approvedPhone(7)                                       // '96550123401' or ''
 *            $phones->approve(7, $admin_user_id)   // ['ok'=>bool,'reason'=>'','phone'=>'']; reasons: no_company, then
 *                                                                          // PhoneNormalizer's (empty, too_short, ...)
 *            $phones->revoke(7);
 * $phones->state(7)                      // for the page: status none|approved|changed|no_phone, raw, normalized
 *            Hooks (func.php): update_company -> onCompanyUpdated(), delete_company -> onCompanyDeleted(),
 *            change_company_status_pre -> onCompanyStatusChange() (PRD 5.1).
 *            The /validate/ verdict of Admin SMS Alerts (6.6) is shown by Admin\VendorsPage, never here and never
 *            blocking. The database touches are protected methods, so the unit test replaces them.
 * DEPENDS:   Table kwtsms_vendor_phones (schema 1.1.0), ?:companies; Settings\ConfigInterface (default country),
 *            Sms\PhoneNormalizer, Log\DebugLog.
 */

namespace Tygh\Addons\Kwtsms\Vendors;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Notifications\VendorPhoneSourceInterface;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

class VendorPhones implements VendorPhoneSourceInterface
{
    /** The company status of an active vendor (core: A); any other status loses the approval. */
    public const STATUS_ACTIVE = 'A';

    /** @var ConfigInterface */
    private $config;

    /** @var DebugLog|null */
    private $log;

    public function __construct(ConfigInterface $config, ?DebugLog $log = null)
    {
        $this->config = $config;
        $this->log = $log;
    }

    /**
     * The stored number when approved_at > 0 AND the vendor's current companies.phone, normalized, equals it; else ''.
     */
    public function approvedPhone(int $company_id): string
    {
        if ($company_id <= 0) {
            return '';
        }
        $row = $this->readRow($company_id);
        if ($row === null || (int) $row['approved_at'] <= 0 || (string) $row['phone'] === '') {
            return '';
        }
        $company = $this->companyRow($company_id);
        // 2026-10-23 13:17 +03: final review A, Minor 2, task 596. A vendor that is not active has no approved number, whether or not
        // the change_company_status_pre hook ran (it is verified on 4.21.2 only).
        if ($company === null || (string) ($company['status'] ?? 'A') !== 'A') {
            return '';
        }
        $current = $this->normalize($company);

        return $current['ok'] && $current['phone'] === (string) $row['phone'] ? (string) $row['phone'] : '';
    }

    /**
     * Approves the vendor's current companies.phone (normalized with the vendor's country, else the default country).
     *
     * @return array{ok:bool,reason:string,phone:string}
     */
    public function approve(int $company_id, int $admin_id): array
    {
        $company = $company_id > 0 ? $this->companyRow($company_id) : null;
        if ($company === null) {
            return ['ok' => false, 'reason' => 'no_company', 'phone' => ''];
        }
        // 2026-10-22 22:25 +03: Task A8, task 596. Only an active vendor gets a number approved (a disabled vendor
        // has no orders to
        // text about and onCompanyStatusChange() would drop the approval anyway).
        if ((string) ($company['status'] ?? '') !== self::STATUS_ACTIVE) {
            return ['ok' => false, 'reason' => 'not_active', 'phone' => ''];
        }
        $current = $this->normalize($company);
        if (!$current['ok']) {
            return ['ok' => false, 'reason' => $current['reason'], 'phone' => ''];
        }
        $now = $this->now();
        $this->writeRow($company_id, [
            'phone' => $current['phone'],
            'approved_at' => $now,
            'approved_by' => max(0, $admin_id),
            'updated_at' => $now,
        ]);
        $this->info('vendor_phone.approved', 'vendor number approved', [
            'company_id' => $company_id, 'admin_id' => $admin_id,
        ]);

        return ['ok' => true, 'reason' => '', 'phone' => $current['phone']];
    }

    /**
     * Takes the approval back (the row stays, with approved_at 0). No row: nothing happens.
     */
    public function revoke(int $company_id): void
    {
        $row = $company_id > 0 ? $this->readRow($company_id) : null;
        if ($row === null) {
            return;
        }
        $this->writeRow($company_id, [
            'phone' => (string) $row['phone'],
            'approved_at' => 0,
            'approved_by' => 0,
            'updated_at' => $this->now(),
        ]);
        $this->info('vendor_phone.revoked', 'vendor number approval revoked', ['company_id' => $company_id]);
    }

    /**
     * Hook update_company (fn.companies.php:1052): when the vendor's phone no longer normalizes to the approved
     * number, the approval is dropped for good (changing the phone back does not restore it).
     */
    public function onCompanyUpdated(int $company_id): void
    {
        $row = $company_id > 0 ? $this->readRow($company_id) : null;
        if ($row === null || (int) $row['approved_at'] <= 0) {
            return;
        }
        $company = $this->companyRow($company_id);
        $current = $company === null ? ['ok' => false, 'phone' => ''] : $this->normalize($company);
        if ($current['ok'] && $current['phone'] === (string) $row['phone']) {
            return;
        }
        $this->writeRow($company_id, [
            'phone' => (string) $row['phone'],
            'approved_at' => 0,
            'approved_by' => 0,
            'updated_at' => $this->now(),
        ]);
        $this->info('vendor_phone.changed', 'vendor phone changed: approval dropped', ['company_id' => $company_id]);
    }

    /** Hook delete_company: the row goes with the vendor. */
    public function onCompanyDeleted(int $company_id): void
    {
        if ($company_id > 0) {
            $this->deleteRow($company_id);
        }
    }

    /**
     * Hook change_company_status_pre (PRD 5.1): a vendor that moves to any status but A (disabled, suspended, pending,
     * declined) is not texted, so its approval is dropped; a root administrator approves again when it is back.
     */
    public function onCompanyStatusChange(int $company_id, string $status_to): void
    {
        if ($company_id <= 0 || $status_to === self::STATUS_ACTIVE) {
            return;
        }
        $row = $this->readRow($company_id);
        if ($row === null || (int) $row['approved_at'] <= 0) {
            return;
        }
        $this->writeRow($company_id, [
            'phone' => (string) $row['phone'],
            'approved_at' => 0,
            'approved_by' => 0,
            'updated_at' => $this->now(),
        ]);
        $this->info('vendor_phone.status', 'vendor left status A: approval dropped', [
            'company_id' => $company_id, 'status_to' => $status_to,
        ]);
    }

    /**
     * What the Vendor SMS page shows for one vendor. status: approved | changed (approved once, the phone moved) |
     * no_phone (companies.phone is empty) | invalid (not a mobile number) | none (not approved yet).
     *
     * @return array{status:string,raw:string,normalized:string,reason:string,approved_at:int,approved_by:int}
     */
    public function state(int $company_id): array
    {
        $company = $this->companyRow($company_id);
        $raw = $company === null ? '' : trim((string) $company['phone']);
        $current = $company === null
            ? ['ok' => false, 'phone' => '', 'reason' => 'no_company']
            : $this->normalize($company);
        $row = $this->readRow($company_id);
        $approved_at = $row === null ? 0 : (int) $row['approved_at'];

        if ($raw === '') {
            $status = 'no_phone';
        } elseif ($approved_at > 0 && $current['ok'] && $current['phone'] === (string) $row['phone']) {
            $status = 'approved';
        } elseif ($approved_at > 0 || $this->phoneMoved($row, $current)) {
            $status = 'changed';
        } elseif (!$current['ok']) {
            $status = 'invalid';
        } else {
            $status = 'none';
        }

        return [
            'status' => $status,
            'raw' => $raw,
            'normalized' => $current['ok'] ? $current['phone'] : '',
            'reason' => $current['ok'] ? '' : $current['reason'],
            'approved_at' => $approved_at,
            'approved_by' => $row === null ? 0 : (int) $row['approved_by'],
            // 2026-10-23 08:34 +0300: cleanup CA2-4 (A8 copy), task 596. The number that was approved ('' when none).
            'approved_phone' => $approved_at > 0 && $row !== null ? (string) $row['phone'] : '',
        ];
    }

    // ------------------------------------------------------------------ rules

    /**
     * True when an earlier approval left a number in the row and the vendor's valid phone is a different one.
     *
     * @param array<string,mixed>|null $row
     * @param array{ok:bool,phone:string,reason:string} $current
     */
    private function phoneMoved(?array $row, array $current): bool
    {
        return $row !== null && (string) $row['phone'] !== '' && $current['ok']
            && (string) $row['phone'] !== $current['phone'];
    }

    /**
     * companies.phone normalized with the vendor's country (companies.country, ISO2), else the default country.
     *
     * @param array<string,string> $company phone, country
     *
     * @return array{ok:bool,phone:string,reason:string}
     */
    private function normalize(array $company): array
    {
        $hint = PhoneNormalizer::callingCodeForIso((string) ($company['country'] ?? ''));
        $hint = $hint !== null ? $hint : $this->config->defaultCountryCode();
        list($valid, $reason, $normalized) = PhoneNormalizer::validateInput((string) ($company['phone'] ?? ''), $hint);

        return ['ok' => (bool) $valid, 'phone' => $valid ? (string) $normalized : '', 'reason' => (string) $reason];
    }

    // ------------------------------------------------------------------ the store (replaced by tests)

    /**
     * @return array<string,mixed>|null phone, approved_at, approved_by, updated_at
     */
    protected function readRow(int $company_id): ?array
    {
        $row = db_get_row(
            'SELECT phone, approved_at, approved_by, updated_at FROM ?:kwtsms_vendor_phones WHERE company_id = ?i',
            $company_id
        );

        return empty($row) ? null : $row;
    }

    /**
     * @param array<string,mixed> $row phone, approved_at, approved_by, updated_at
     */
    protected function writeRow(int $company_id, array $row): void
    {
        db_query('REPLACE INTO ?:kwtsms_vendor_phones ?e', ['company_id' => $company_id] + $row);
    }

    protected function deleteRow(int $company_id): void
    {
        db_query('DELETE FROM ?:kwtsms_vendor_phones WHERE company_id = ?i', $company_id);
    }

    /**
     * @return array<string,string>|null phone, country, status; null when the company does not exist
     */
    protected function companyRow(int $company_id): ?array
    {
        $row = db_get_row('SELECT phone, country, status FROM ?:companies WHERE company_id = ?i', $company_id);

        return empty($row) ? null : $row;
    }

    protected function now(): int
    {
        return time();
    }

    /**
     * @param array<string,mixed> $context
     */
    private function info(string $action, string $message, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log('info', $action, $message, $context);
        }
    }
}
