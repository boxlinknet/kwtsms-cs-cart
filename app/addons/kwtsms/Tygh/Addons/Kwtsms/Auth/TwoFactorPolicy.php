<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/TwoFactorPolicy.php
 * CREATED:   2026-11-04 22:38 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Who is asked for an SMS code after the password (P3-48, P3-52 (5)): requirement($user) answers none, code,
 *            enroll, refuse or let_in; typeSwitch($user) names the switch (off, optional, required) of the account's
 *            type. The constant KWTSMS_2FA_OFF (config.local.php) answers none for everybody and reads nothing.
 * WHY:       Task M2.2a (SafatMall B3). One policy for every second-step source: the sign-in hold, the completion of
 *            the code page and (M2.1 review ruling) the sign-in by code. A switch that is off costs no database read
 *            and never holds anybody; every other branch that cannot give a code fails closed ('refuse') except the
 *            last root administrator (let_in, so the store can always be reached).
 * USAGE:     $policy = Tygh::$app['kwtsms.twofa_policy'];
 *            $policy->requirement(['user_id' => 5, 'user_type' => 'A', 'company_id' => 0, 'is_root' => 'Y',
 *                                  'phone' => '+96550123401']);
 *            none    nobody to ask (switch off, optional without an enabled row, other user types, break-glass)
 *            code    an enabled row with a phone: a code goes to that phone
 *            enroll  required, no enabled row, the account has a usable mobile: the first code proves it and enrols
 *            refuse  the account needs a code and cannot get one (no usable mobile, enabled row without a phone)
 *            let_in  refuse's exception: a root administrator while no other active root administrator is enrolled
 *            Customers: off | optional. Vendors (user_type V) and administrators (user_type A, any company): off |
 *            optional | required. Any other user type is never covered. A database error propagates (the caller fails
 *            closed). Test seam: the protected breakGlass().
 * DEPENDS:   Settings\ConfigInterface (twofaCustomers, twofaVendors, twofaAdmins, defaultCountryCode), TwoFactorStore,
 *            Phones\{PhoneIndex,PhoneFormat}.
 */

namespace Tygh\Addons\Kwtsms\Auth;

use Tygh\Addons\Kwtsms\Phones\PhoneFormat;
use Tygh\Addons\Kwtsms\Phones\PhoneIndex;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class TwoFactorPolicy
{
    public const NONE = 'none';
    public const CODE = 'code';
    public const ENROLL = 'enroll';
    public const REFUSE = 'refuse';
    public const LET_IN = 'let_in';

    /** @var ConfigInterface */
    private $config;

    /** @var TwoFactorStore */
    private $store;

    /** @var PhoneIndex */
    private $index;

    /** @var callable|null (int $user_id): array, the users row; null = the database */
    private $users;

    /**
     * @param callable|null $users (int $user_id): array{user_id,user_type,status,company_id,is_root,phone}, used by
     *                             requirementOf() when the caller has the id only
     */
    public function __construct(
        ConfigInterface $config,
        TwoFactorStore $store,
        PhoneIndex $index,
        ?callable $users = null
    ) {
        $this->config = $config;
        $this->store = $store;
        $this->index = $index;
        $this->users = $users;
    }

    /**
     * True when a 2FA switch is on and the break-glass constant is not set: the only case in which any login can be
     * held. Settings only, no database.
     */
    public function anyOn(): bool
    {
        return !$this->breakGlass()
            && ($this->config->twofaCustomers() !== 'off'
                || $this->config->twofaVendors() !== 'off'
                || $this->config->twofaAdmins() !== 'off');
    }

    /** The phone index the customer enrolment checks (kept for the callers that need the unique-number rule). */
    public function index(): PhoneIndex
    {
        return $this->index;
    }

    /** The requirement of an account the caller knows by id only. */
    public function requirementOf(int $user_id): string
    {
        $user = $this->users === null ? [] : (array) call_user_func($this->users, $user_id);

        return $user === [] ? self::NONE : $this->requirement($user);
    }

    /**
     * @param array<string,mixed> $user users row: user_id, user_type, company_id, is_root, phone
     */
    public function requirement(array $user): string
    {
        if ($this->breakGlass()) {
            return self::NONE;
        }
        $switch = $this->typeSwitch($user);
        if ($switch === 'off') {
            return self::NONE;
        }
        $user_id = (int) ($user['user_id'] ?? 0);
        $row = $this->store->row($user_id);
        if ($row !== null && (string) $row['enabled'] === 'Y') {
            // An enrolled account needs its code: a row without a phone cannot get one.
            return trim((string) $row['phone']) !== '' ? self::CODE : $this->cannotGetCode($user);
        }
        if ($switch !== 'required') {
            return self::NONE;
        }

        return $this->usablePhone((string) ($user['phone'] ?? '')) ? self::ENROLL : $this->cannotGetCode($user);
    }

    /** off | optional | required for the account's type; any type that is not covered is off. */
    public function typeSwitch(array $user): string
    {
        switch ((string) ($user['user_type'] ?? '')) {
            case 'C':
                return $this->config->twofaCustomers();
            case 'V':
                return $this->config->twofaVendors();
            case 'A':
                return $this->config->twofaAdmins();
        }

        return 'off';
    }

    /**
     * P3-52 (5): a user who needs a code and cannot get one is refused, except a root administrator while no other
     * active root administrator has an enrolled row (let in with the password only; the caller raises the alert).
     *
     * @param array<string,mixed> $user
     */
    private function cannotGetCode(array $user): string
    {
        $root = (string) ($user['user_type'] ?? '') === 'A'
            && (string) ($user['is_root'] ?? 'N') === 'Y'
            && (int) ($user['company_id'] ?? 0) === 0;
        if ($root && $this->store->enrolledRootAdmins((int) ($user['user_id'] ?? 0)) === 0) {
            return self::LET_IN;
        }

        return self::REFUSE;
    }

    private function usablePhone(string $raw): bool
    {
        return $raw !== '' && PhoneFormat::fromRaw($raw, $this->config->defaultCountryCode()) !== '';
    }

    /** The break-glass constant of P3-52 (4): define('KWTSMS_2FA_OFF', true) in config.local.php. */
    protected function breakGlass(): bool
    {
        return defined('KWTSMS_2FA_OFF') && (bool) constant('KWTSMS_2FA_OFF');
    }
}
