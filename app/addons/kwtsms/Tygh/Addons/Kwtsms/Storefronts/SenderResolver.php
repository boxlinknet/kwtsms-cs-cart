<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Storefronts/SenderResolver.php
 * CREATED:   2026-10-22 14:53 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Sender ID of a send: the storefront's own Sender ID when it has a usable one, else the global
 *            Sender ID of the Settings page.
 * WHY:       Phase 2 ruling P2-2 (SafatMall A2): a storefront can send under its own Sender ID. One that is no longer
 *            in the synced Sender ID list, or that kwtSMS banned (ERR008), falls back to the global Sender ID and
 *            raises the admin alert storefront_sender_invalid (bell and e-mail, once per storefront per 24 h), so a
 *            removed Sender ID never stops the shop's SMS.
 * USAGE:     $resolver = Tygh::$app['kwtsms.sender_resolver'];
 *            $resolver->forStorefront(2)                    // 'SHOP-B' or the global Sender ID
 *            $resolver->setSender(2, 'SHOP-B')              // ['ok' => true, 'reason' => '']
 *            $resolver->setSender(2, '')                    // clears: the storefront uses the global Sender ID
 *            setSender() refuses a Sender ID that is not in the synced list ('not_in_list'), and KWT-SMS while test
 *            mode is off unless $kwt_ack is true ('kwtsms_ack'), the same acknowledgement as the global sender.
 *            Storefront 0 always answers the global Sender ID. The stored value is kwtsms_brand_names.sender_id
 *            ('' = the global one); the two database touches are protected methods, so the unit test replaces them.
 * DEPENDS:   Settings\ConfigInterface, Gateway\GatewayState (synced list, bans), Alerts\AdminAlerts (optional);
 *            table kwtsms_brand_names (schema 1.1.0).
 */

namespace Tygh\Addons\Kwtsms\Storefronts;

use Tygh\Addons\Kwtsms\Alerts\AdminAlerts;
use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class SenderResolver
{
    public const KWT_TEST_SENDER = 'KWT-SMS';

    /** @var ConfigInterface */
    private $config;

    /** @var GatewayState */
    private $state;

    /** @var AdminAlerts|null */
    private $alerts;

    /** @var array<int,string> storefront id => resolved Sender ID (this request) */
    private $cache = [];

    public function __construct(ConfigInterface $config, GatewayState $state, ?AdminAlerts $alerts = null)
    {
        $this->config = $config;
        $this->state = $state;
        $this->alerts = $alerts;
    }

    /**
     * The Sender ID to send under for a storefront.
     */
    public function forStorefront(int $storefront_id): string
    {
        $global = $this->config->senderId();
        if ($storefront_id <= 0) {
            return $global;
        }
        if (isset($this->cache[$storefront_id])) {
            return $this->cache[$storefront_id];
        }
        $own = $this->readSender($storefront_id);
        $resolved = $global;
        if ($own !== '') {
            if (in_array($own, $this->state->senderIds(), true) && !$this->state->isSenderBanned($own)) {
                $resolved = $own;
            } else {
                $this->invalid($storefront_id, $own);
            }
        }
        $this->cache[$storefront_id] = $resolved;

        return $resolved;
    }

    /**
     * Drops the per-request cache so the next forStorefront() resolves again (a Sender ID was banned mid-request).
     * 2026-10-23 05:49 +03: added, Cleanup CA1-4 (A2 M1), task 596.
     */
    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * Saves the storefront's Sender ID ('' clears it).
     *
     * @return array{ok:bool,reason:string} reason: '', not_in_list, kwtsms_ack, bad_storefront
     */
    public function setSender(int $storefront_id, string $sender_id, bool $kwt_ack = false): array
    {
        $sender_id = trim($sender_id);
        if ($storefront_id <= 0) {
            return ['ok' => false, 'reason' => 'bad_storefront'];
        }
        if ($sender_id !== '') {
            if (!in_array($sender_id, $this->state->senderIds(), true)) {
                return ['ok' => false, 'reason' => 'not_in_list'];
            }
            if ($sender_id === self::KWT_TEST_SENDER && !$this->config->testMode() && !$kwt_ack) {
                return ['ok' => false, 'reason' => 'kwtsms_ack'];
            }
        }
        $this->writeSender($storefront_id, $sender_id);
        unset($this->cache[$storefront_id]);

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * The Sender ID stored for a storefront as it is ('' = none, use the global one), whether it is still usable or
     * not.
     * 2026-10-22 16:12 +03: added for the Settings page (stale values are shown, not hidden), Task A3, task 596.
     */
    public function storedSender(int $storefront_id): string
    {
        return $storefront_id > 0 ? $this->readSender($storefront_id) : '';
    }

    private function invalid(int $storefront_id, string $own): void
    {
        if ($this->alerts === null) {
            return;
        }
        try {
            $this->alerts->raise(
                'storefront_sender_invalid',
                ['[sender]' => $own, '[storefront]' => (string) $storefront_id],
                true,
                86400,
                'sf' . $storefront_id
            );
        } catch (\Throwable $e) {
            // An alert never stops a send.
        }
    }

    // ------------------------------------------------------------------ the store (replaced by tests)

    protected function readSender(int $storefront_id): string
    {
        return (string) db_get_field(
            'SELECT sender_id FROM ?:kwtsms_brand_names WHERE storefront_id = ?i',
            $storefront_id
        );
    }

    protected function writeSender(int $storefront_id, string $sender_id): void
    {
        db_query(
            'INSERT INTO ?:kwtsms_brand_names (storefront_id, sender_id) VALUES (?i, ?s)'
            . ' ON DUPLICATE KEY UPDATE sender_id = ?s',
            $storefront_id,
            $sender_id,
            $sender_id
        );
    }
}
