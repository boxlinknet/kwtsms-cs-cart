<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/CheckoutCode.php
 * CREATED:   2026-11-05 20:52 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The SMS code before an order is placed (SafatMall B6, P3-54): mode all, guest or cod (checkout_code).
 *            refusal() is the one decision used by the pre_place_order hook: null = place the order, a text = do not
 *            (the cart is untouched, core answers "order was not placed"). consume() uses the verified code up in the
 *            place_order hook for the parent order only and remembers, for the session, which phone was verified for
 *            which order (kwtsms_checkout_verified), which is what lets checkout.create_profile of THAT order pass the
 *            registration code check afterwards.
 * WHY:       Task M2.4. Orders with a phone nobody proved cost shops money (cash on delivery that is never collected,
 *            guest spam). The code is bound to the phone the order is placed with and to the session, so a code for
 *            phone A never places an order with phone B, and one code places one order. This is a money path: a
 *            refusal happens before the order row exists (nothing is lost), the admin order editor, the payment
 *            gateway returns, the API and the child orders of a Multi-Vendor checkout are never asked, and an
 *            internal error refuses with the generic "could not check" text (fail closed).
 * USAGE:     $c = Tygh::$app['kwtsms.checkout_code'];
 *            $text = $c->refusal($cart, $auth, $session_hash, $ip, $lang);     // pre_place_order
 *            $c->consume($order_id, $cart, $auth, $session_hash);               // place_order, parent order only
 *            $c->applies($cart, $auth): bool;  $c->satisfied($cart, $auth, $session_hash): bool;
 *            $c->verifiedForOrder($order_id, $phone): bool;  $c->forgetOrder($order_id): void
 *            Test seams (constructor, all optional): $posted fn(): string, the code the form posted;
 *            $session_get fn(): array / $session_set fn(array): void, the session map kwtsms_checkout_verified;
 *            $skip_until fn(string $phone): int, the "continue" bypass; $clock fn(): int; $account_phone
 *            fn(int $user_id): string, the canonical account phone; $proof fn(int $user_id, string $phone): void, tells
 *            the phone index that a customer proved the unchanged account phone (P3-49); $exempt fn(): bool, true in a
 *            request that must never be asked (admin order editor, payment return, API).
 * DEPENDS:   Settings\ConfigInterface, Codes\{OtpService,OtpResult,CodeRequest}, Phones\{AccountPhone,PhoneIndex},
 *            Log\DebugLog (optional), __().
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Codes\OtpResult;
use Tygh\Addons\Kwtsms\Codes\OtpService;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Phones\AccountPhone;
use Tygh\Addons\Kwtsms\Phones\PhoneFormat;
use Tygh\Addons\Kwtsms\Phones\PhoneIndex;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class CheckoutCode
{
    public const PURPOSE = 'checkout';

    /** The session key: [canonical phone] => order id the phone was verified for. */
    public const SESSION_KEY = 'kwtsms_checkout_verified';

    /** The form field the code box posts under (the same box as the registration code). */
    public const POST_FIELD = 'kwtsms_code';

    /** At most this many verified phones are remembered in a session. */
    private const MAX_REMEMBERED = 20;

    /** @var ConfigInterface */
    private $config;

    /** @var OtpService */
    private $otp;

    /** @var PhoneIndex */
    private $index;

    /** @var callable fn(): string, the store default calling code */
    private $default_cc;

    /** @var callable|null */
    private $posted;

    /** @var callable|null */
    private $session_get;

    /** @var callable|null */
    private $session_set;

    /** @var callable|null */
    private $skip_until;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $account_phone;

    /** @var callable|null */
    private $proof;

    /** @var callable|null */
    private $exempt;

    /** @var DebugLog|null */
    private $log;

    public function __construct(
        ConfigInterface $config,
        OtpService $otp,
        PhoneIndex $index,
        callable $default_cc,
        ?callable $posted = null,
        ?callable $session_get = null,
        ?callable $session_set = null,
        ?callable $skip_until = null,
        ?callable $clock = null,
        ?callable $account_phone = null,
        ?callable $proof = null,
        ?callable $exempt = null,
        ?DebugLog $log = null
    ) {
        $this->config = $config;
        $this->otp = $otp;
        $this->index = $index;
        $this->default_cc = $default_cc;
        $this->posted = $posted;
        $this->session_get = $session_get;
        $this->session_set = $session_set;
        $this->skip_until = $skip_until;
        $this->clock = $clock;
        $this->account_phone = $account_phone;
        $this->proof = $proof;
        $this->exempt = $exempt;
        $this->log = $log;
    }

    /**
     * Does the mode ask for a code for this cart? The parent order only (a Multi-Vendor child order is never asked),
     * never in a request that is exempt, and only for the guest (mode guest) or a listed payment (mode cod).
     *
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $auth
     */
    public function applies(array $cart, array $auth): bool
    {
        $mode = $this->config->checkoutCode();
        if ($mode === 'off' || $this->isExempt($cart) || !empty($cart['parent_order_id'])) {
            return false;
        }
        switch ($mode) {
            case 'all':
                return true;
            case 'guest':
                return (int) ($auth['user_id'] ?? 0) === 0;
            case 'cod':
                return in_array((int) ($cart['payment_id'] ?? 0), $this->config->checkoutCodPaymentIds(), true);
        }

        return false;
    }

    /**
     * True when the order may be placed: (a) a signed-in customer whose cart phone is the account phone verified in
     * the index (Open 6), (b) a verified code of this session for the cart phone and the shopper, (c) the "continue"
     * bypass, (d) the order being paid again (repay) was verified for this phone in this session.
     *
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $auth
     */
    public function satisfied(array $cart, array $auth, string $session_hash): bool
    {
        $phone = $this->phoneOf($cart);
        if ($phone === '') {
            return false;
        }
        $user_id = (int) ($auth['user_id'] ?? 0);
        if ($user_id > 0 && $this->index->isVerified($user_id, $phone)) {
            return true;
        }
        if (!empty($cart['order_id']) && $this->verifiedForOrder((int) $cart['order_id'], $phone)) {
            return true;
        }
        if ($session_hash !== '' && $this->otp->hasVerified(self::PURPOSE, $phone, $user_id, $session_hash)) {
            return true;
        }

        return $this->bypassed($phone);
    }

    /**
     * The decision of the pre_place_order hook: null = place the order; a text = refuse (nothing is saved).
     *
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $auth
     */
    public function refusal(array $cart, array $auth, string $session_hash, string $ip, string $lang): ?string
    {
        if (!$this->applies($cart, $auth)) {
            return null;
        }
        $required = (string) __('kwtsms.checkout.code_required', [], $lang);
        $phone = $this->phoneOf($cart);
        if ($phone === '') {
            $this->debug('checkout.code_required', ['reason' => 'no_phone']);

            return $required;
        }
        $user_id = (int) ($auth['user_id'] ?? 0);
        if ($this->satisfied($cart, $auth, $session_hash)) {
            return null;
        }
        $code = $this->postedCode();
        if ($code !== '' && $session_hash !== '') {
            $result = $this->otp->verify(self::PURPOSE, $phone, $code, $user_id, $session_hash, $ip);
            if ($result->status === OtpResult::OK) {
                return null;
            }
            $this->debug('checkout.code_required', ['reason' => 'code_' . $result->status]);

            return $this->message($result, $phone, $lang);
        }
        $this->debug('checkout.code_required', ['reason' => 'no_code']);

        return $required;
    }

    /**
     * The place_order hook, parent order only: uses the verified code up, remembers the phone for this order, clears a
     * "continue" bypass, and (a signed-in customer who proved the unchanged account phone) tells the phone index.
     * Never throws.
     *
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $auth
     */
    public function consume(int $order_id, array $cart, array $auth, string $session_hash): void
    {
        if ($order_id <= 0) {
            return;
        }
        try {
            // Only an order the mode asks about uses a code up (a code the order did not need stays as it is): not
            // off, not a child order, not an exempt request, and the guest or payment the mode names.
            if (!$this->applies($cart, $auth)) {
                return;
            }
            $phone = $this->phoneOf($cart);
            if ($phone === '') {
                return;
            }
            $user_id = (int) ($auth['user_id'] ?? 0);
            $used = $session_hash !== '' && $this->otp->consume(self::PURPOSE, $phone, $user_id, $session_hash);
            if ($used) {
                $this->remember($phone, $order_id);
                if ($user_id > 1 && $this->proof !== null && $this->accountPhone($user_id) === $phone) {
                    call_user_func($this->proof, $user_id, $phone);
                }
            } elseif (!empty($cart['order_id']) && $this->verifiedForOrder((int) $cart['order_id'], $phone)) {
                $this->remember($phone, $order_id);
            }
            $this->clearBypass($phone);
        } catch (\Throwable $e) {
            $this->debug('checkout.consume_error', ['error' => get_class($e)]);
        }
    }

    /**
     * Was $phone verified in this session for exactly this order?
     */
    public function verifiedForOrder(int $order_id, string $phone): bool
    {
        if ($order_id <= 0 || $phone === '') {
            return false;
        }
        $map = $this->map();

        return isset($map[$phone]) && (int) $map[$phone] === $order_id;
    }

    /**
     * Drops the remembered verification of an order (after its account was created).
     */
    public function forgetOrder(int $order_id): void
    {
        $map = $this->map();
        foreach ($map as $phone => $id) {
            if ((int) $id === $order_id) {
                unset($map[$phone]);
            }
        }
        $this->saveMap($map);
    }

    /**
     * The canonical phone the order is placed with: the account phone field of the cart (phone, else b_phone) read
     * with the calling code of its country field. '' = none.
     *
     * @param array<string,mixed> $cart
     */
    public function phoneOf(array $cart): string
    {
        $user_data = isset($cart['user_data']) && is_array($cart['user_data']) ? $cart['user_data'] : [];
        $cc = (string) call_user_func($this->default_cc);
        $phone = AccountPhone::canonicalOf($user_data, [], $cc);

        // 2026-11-06 03:22 +03: Task M2.4 fix 1 (security review M1), task 596. Mode cod: the number the courier calls
        // is the shipping phone, so when one is entered (and is a valid mobile) the code is bound to IT, not to the
        // account phone. A shipping phone that is not a valid mobile cannot be verified and leaves the account phone.
        if ($this->config->checkoutCode() === 'cod') {
            $raw = isset($user_data['s_phone']) && is_scalar($user_data['s_phone'])
                ? trim((string) $user_data['s_phone']) : '';
            if ($raw !== '') {
                $courier = PhoneFormat::fromRaw($raw, AccountPhone::hint('s_phone', $user_data, [], $cc));
                if ($courier !== '') {
                    return $courier;
                }
            }
        }

        return $phone;
    }

    /**
     * 2026-11-06 02:54 +03: Task M2.4 fix 1 (security review C1), task 596. The cart-shaped view of an order that is
     * being paid again (orders.repay switches its payment without fn_place_order): the order id (so a phone verified
     * for this very order in this session counts), the payment it is switched to, and the phone fields of the order, so
     * applies(), satisfied() and refusal() judge a repay exactly as they judge a checkout.
     *
     * @param array<string,mixed> $order An order row or fn_get_order_info() result
     *
     * @return array<string,mixed>
     */
    public static function repayCart(array $order, int $payment_id): array
    {
        $user_data = [];
        foreach (['phone', 'b_phone', 's_phone', 'b_country', 's_country'] as $field) {
            $user_data[$field] = isset($order[$field]) && is_scalar($order[$field]) ? (string) $order[$field] : '';
        }

        return ['order_id' => (int) ($order['order_id'] ?? 0), 'payment_id' => $payment_id, 'user_data' => $user_data];
    }

    /**
     * 2026-11-05 21:10 +03: Task M2.4 (P3-54), task 596. The requests that are never asked for a code: the admin order
     * editor (ORDER_MANAGEMENT), the API, the console, any area but the storefront, and a payment provider returning to
     * the store (controller payment_notification: the customer already paid, refusing there would lose the order).
     * Also used by the hook handler when the service itself cannot be built.
     */
    public static function requestExempt(array $cart = []): bool
    {
        // 2026-11-06 03:03 +03: Task M2.4 fix 1 (security reviews I1, I2), task 596. The decision moved to
        // CheckoutRequest::exempt(): a payment return is exempt only for the processor of the cart's own payment (and
        // never for a listed cash-on-delivery one), and an API call only for an administrator or a vendor; a customer's
        // API order and a shopper-made "payment return" run the normal check. An error reads as "not exempt".
        try {
            $cod_ids = isset(\Tygh\Tygh::$app['kwtsms.config'])
                ? \Tygh\Tygh::$app['kwtsms.config']->checkoutCodPaymentIds() : [];

            return CheckoutRequest::exempt($cart, CheckoutRequest::env(), static function (int $payment_id): string {
                $processor = function_exists('fn_get_processor_data') ? fn_get_processor_data($payment_id) : [];

                return is_array($processor) ? (string) ($processor['processor_script'] ?? '') : '';
            }, $cod_ids);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 2026-11-06 03:03 +03: Task M2.4 fix 1 (I1), task 596. The cart goes to the exempt callable: a payment return is
     * exempt only for the processor of the cart's own payment.
     *
     * @param array<string,mixed> $cart
     */
    private function isExempt(array $cart): bool
    {
        return $this->exempt !== null && (bool) call_user_func($this->exempt, $cart);
    }

    private function accountPhone(int $user_id): string
    {
        return $this->account_phone === null ? '' : (string) call_user_func($this->account_phone, $user_id);
    }

    private function postedCode(): string
    {
        if ($this->posted !== null) {
            return trim((string) call_user_func($this->posted));
        }
        $value = $_REQUEST[self::POST_FIELD] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function bypassed(string $phone): bool
    {
        if ($this->config->otpUnreachable() !== 'continue') {
            return false;
        }
        $now = $this->clock === null ? time() : (int) call_user_func($this->clock);
        if ($this->skip_until !== null) {
            return (int) call_user_func($this->skip_until, $phone) > $now;
        }
        try {
            $session = \Tygh\Tygh::$app['session'];
            $all = isset($session[CodeRequest::BYPASS_KEY]) && is_array($session[CodeRequest::BYPASS_KEY])
                ? $session[CodeRequest::BYPASS_KEY] : [];
        } catch (\Throwable $e) {
            return false;
        }

        return (int) ($all[self::PURPOSE][$phone] ?? 0) > $now;
    }

    private function clearBypass(string $phone): void
    {
        if ($this->skip_until !== null) {
            return;
        }
        try {
            $session = \Tygh\Tygh::$app['session'];
            $all = isset($session[CodeRequest::BYPASS_KEY]) && is_array($session[CodeRequest::BYPASS_KEY])
                ? $session[CodeRequest::BYPASS_KEY] : [];
            if (isset($all[self::PURPOSE][$phone])) {
                unset($all[self::PURPOSE][$phone]);
                $session[CodeRequest::BYPASS_KEY] = $all;
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * @return array<string,int>
     */
    private function map(): array
    {
        try {
            if ($this->session_get !== null) {
                $raw = call_user_func($this->session_get);
            } else {
                $session = \Tygh\Tygh::$app['session'];
                $raw = isset($session[self::SESSION_KEY]) ? $session[self::SESSION_KEY] : [];
            }
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $phone => $order_id) {
            if (is_string($phone) && $phone !== '' && is_numeric($order_id) && (int) $order_id > 0) {
                $out[$phone] = (int) $order_id;
            }
        }

        return $out;
    }

    /**
     * @param array<string,int> $map
     */
    private function saveMap(array $map): void
    {
        if (count($map) > self::MAX_REMEMBERED) {
            $map = array_slice($map, -self::MAX_REMEMBERED, null, true);
        }
        if ($this->session_set !== null) {
            call_user_func($this->session_set, $map);

            return;
        }
        $session = \Tygh\Tygh::$app['session'];
        $session[self::SESSION_KEY] = $map;
    }

    private function remember(string $phone, int $order_id): void
    {
        $map = $this->map();
        unset($map[$phone]);
        $map[$phone] = $order_id;
        $this->saveMap($map);
    }

    /**
     * The refusal text of a verify result.
     */
    private function message(OtpResult $r, string $phone, string $lang): string
    {
        switch ($r->status) {
            case OtpResult::WRONG:
                return (string) __('kwtsms.code.wrong', ['[n]' => (int) $r->attempts_left], $lang);
            case OtpResult::LOCKED:
                return (string) __('kwtsms.code.locked', [
                    '[minutes]' => (int) max(1, ceil($r->retry_after / 60)),
                ], $lang);
            case OtpResult::IP_LIMITED:
                return (string) __('kwtsms.code.ip_limited', [], $lang);
            case OtpResult::EXPIRED:
                return (string) __('kwtsms.code.expired', [], $lang);
        }

        return (string) __('kwtsms.code.enter_code', [
            '[phone]' => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
        ], $lang);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function debug(string $action, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log('debug', $action, $action, $context);
        }
    }
}
