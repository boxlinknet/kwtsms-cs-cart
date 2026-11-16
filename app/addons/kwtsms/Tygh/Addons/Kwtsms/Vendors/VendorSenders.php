<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Vendors/VendorSenders.php
 * CREATED:   2026-11-15 20:31 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Sender ID of one Multi-Vendor vendor (M3.18, G2, P3-75): a root administrator picks it from the
 *            synced Sender ID list on the Vendor SMS page; the vendor SMS (receiver V) and the customer SMS of that
 *            vendor's own order (receiver C) go out under it while the setting vendor_sender_ids is ON.
 * WHY:       A marketplace vendor wants its customers to see the vendor's own approved Sender ID. The value is never
 *            free text (only a Sender ID on the account's synced list, never the KWT-SMS test sender), and it is read
 *            at send time and again at the outbox drain, so a Sender ID that left the list or was banned meanwhile
 *            falls back to the storefront Sender ID and raises the admin alert vendor_sender_invalid (bell and e-mail,
 *            once per vendor per 24 h) instead of stopping the SMS.
 * USAGE:     $senders = Tygh::$app['kwtsms.vendor_senders'];
 *            $senders->forCompany(7, 'SHOP-A')         // the vendor's usable Sender ID, else 'SHOP-A' (the fallback)
 *            $senders->set(7, 'VENDOR7', $admin_id)    // ['ok' => bool, 'reason' => '' | not_in_list |
 *                                                     //  kwtsms_test_sender | not_vendor]
 *            $senders->set(7, '', $admin_id)           // clears: the vendor uses the storefront Sender ID
 *            $senders->stored(7)                       // the stored value as it is (for the page), '' = none
 *            $senders->clear(7)                        // delete_company hook
 *            forCompany() is one primary-key read per call (cached for the request). The database touches are
 *            protected methods, so the unit test replaces them.
 * DEPENDS:   Table kwtsms_vendor_senders (schema 1.4.0), ?:companies; Gateway\GatewayState (synced list, bans),
 *            Alerts\AdminAlerts (optional).
 */

namespace Tygh\Addons\Kwtsms\Vendors;

use Tygh\Addons\Kwtsms\Alerts\AdminAlerts;
use Tygh\Addons\Kwtsms\Gateway\GatewayState;

class VendorSenders
{
    /** The kwtSMS test Sender ID; never a vendor's. */
    public const KWT_TEST_SENDER = 'KWT-SMS';

    /** @var GatewayState */
    private $state;

    /** @var AdminAlerts|null */
    private $alerts;

    /** @var array<int,string> company id => stored value read in this request */
    private $cache = [];

    public function __construct(GatewayState $state, ?AdminAlerts $alerts = null)
    {
        $this->state = $state;
        $this->alerts = $alerts;
    }

    /**
     * The Sender ID to send a vendor's SMS under: its own when it is stored and still usable, else $fallback.
     * Never throws a gateway error; a database failure is the caller's to catch (SmsService::senderFor does).
     */
    public function forCompany(int $company_id, string $fallback): string
    {
        if ($company_id <= 0) {
            return $fallback;
        }
        if (!array_key_exists($company_id, $this->cache)) {
            $this->cache[$company_id] = $this->readSender($company_id);
        }
        $own = $this->cache[$company_id];
        if ($own === '') {
            return $fallback;
        }
        if ($this->usable($own)) {
            return $own;
        }
        $this->invalid($company_id, $own);

        return $fallback;
    }

    /**
     * Saves the vendor's Sender ID ('' clears it). Only a value on the synced list is stored.
     *
     * @return array{ok:bool,reason:string} reason: '', not_in_list, kwtsms_test_sender, not_vendor
     */
    public function set(int $company_id, string $sender_id, int $admin_id): array
    {
        $sender_id = trim($sender_id);
        if ($company_id <= 0 || !$this->companyExists($company_id)) {
            return ['ok' => false, 'reason' => 'not_vendor'];
        }
        if ($sender_id === '') {
            $this->deleteRow($company_id);
            unset($this->cache[$company_id]);

            return ['ok' => true, 'reason' => ''];
        }
        if ($sender_id === self::KWT_TEST_SENDER) {
            return ['ok' => false, 'reason' => 'kwtsms_test_sender'];
        }
        if (!in_array($sender_id, $this->state->senderIds(), true)) {
            return ['ok' => false, 'reason' => 'not_in_list'];
        }
        $this->writeRow($company_id, $sender_id, $admin_id, $this->now());
        unset($this->cache[$company_id]);

        return ['ok' => true, 'reason' => ''];
    }

    /** The stored value as it is, whether it is still usable or not ('' = none). */
    public function stored(int $company_id): string
    {
        return $company_id > 0 ? $this->readSender($company_id) : '';
    }

    /**
     * Whether a stored value is usable now: on the synced list, not banned, never the test sender.
     */
    public function usable(string $sender_id): bool
    {
        return $sender_id !== '' && $sender_id !== self::KWT_TEST_SENDER
            && in_array($sender_id, $this->state->senderIds(), true)
            && !$this->state->isSenderBanned($sender_id);
    }

    /**
     * The Sender IDs a root administrator may pick for a vendor: the synced list without the test sender and without
     * the ones kwtSMS banned.
     *
     * @return string[]
     */
    public function options(): array
    {
        $out = [];
        foreach ($this->state->senderIds() as $id) {
            if ($id !== self::KWT_TEST_SENDER && !$this->state->isSenderBanned($id)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /** The vendor's row goes with the vendor (hook delete_company). */
    public function clear(int $company_id): void
    {
        if ($company_id <= 0) {
            return;
        }
        $this->deleteRow($company_id);
        unset($this->cache[$company_id]);
    }

    private function invalid(int $company_id, string $own): void
    {
        if ($this->alerts === null) {
            return;
        }
        try {
            $this->alerts->raise(
                'vendor_sender_invalid',
                ['[sender]' => $own, '[vendor]' => (string) $company_id],
                true,
                86400,
                'v' . $company_id
            );
        } catch (\Throwable $e) {
            // An alert never stops a send.
        }
    }

    // ------------------------------------------------------------------ the store (replaced by tests)

    /** One primary-key read of kwtsms_vendor_senders. */
    protected function readSender(int $company_id): string
    {
        return (string) db_get_field(
            'SELECT sender_id FROM ?:kwtsms_vendor_senders WHERE company_id = ?i',
            $company_id
        );
    }

    protected function writeRow(int $company_id, string $sender_id, int $admin_id, int $now): void
    {
        db_query('REPLACE INTO ?:kwtsms_vendor_senders ?e', [
            'company_id' => $company_id,
            'sender_id' => $sender_id,
            'updated_by' => max(0, $admin_id),
            'updated_at' => $now,
        ]);
    }

    protected function deleteRow(int $company_id): void
    {
        db_query('DELETE FROM ?:kwtsms_vendor_senders WHERE company_id = ?i', $company_id);
    }

    protected function companyExists(int $company_id): bool
    {
        return (int) db_get_field('SELECT company_id FROM ?:companies WHERE company_id = ?i', $company_id)
            === $company_id;
    }

    protected function now(): int
    {
        return time();
    }
}
