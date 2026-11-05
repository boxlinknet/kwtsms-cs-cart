<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/AdminTwoFactorGuard.php
 * CREATED:   2026-11-05 07:27 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The guard of the one save that can lock every administrator out: twofa_admins = required (P3-52 (1)).
 *            canRequire() accepts the save only when the saver is an active root administrator with a usable mobile,
 *            types the code sent to that mobile (proof of possession) and ticks the box when administrators without a
 *            usable mobile exist; sendProof() sends that code; withoutMobile() is the list the page shows;
 *            lastRootLetIn() is the exception of P3-52 (5).
 * WHY:       Task M2.2b (SafatMall B3). "Required" holds every administrator at sign-in. An administrator whose phone
 *            does not work, or one who never added a number, must not be the person who flips the switch without
 *            noticing, and the person who flips it must be able to receive the code themselves.
 * USAGE:     $guard = Tygh::$app['kwtsms.twofa_guard'];
 *            $guard->sendProof($saver_id, $session_hash, $ip, $storefront_id, 'en');   // body of the code request
 *            $r = $guard->canRequire($saver_id, $typed_code, $session_hash, $ip, $ack_missing);
 *            // array{ok:bool, error:string, without_mobile:array<int,array{user_id:int,email:string,name:string}>}
 *            // error: '' | not_root | no_mobile | ack_missing | proof_missing | proof_malformed | proof_wrong |
 *            //        proof_locked | proof_expired | error. attempts_left / retry_after are added for a wrong or
 *            //        locked proof. Any exception is 'error' (the save is refused, never let through).
 *            The proof code uses the account-scoped purpose 2fa_enroll with the engine key '<saver id>:<canonical
 *            phone>', so it is counted by the same wrong-attempt buckets and lockouts as the other staff codes and
 *            cannot be completed by another account. It is consumed by a successful check.
 *            Seams (constructor): users (int): array, admins (): array of users rows with email.
 * DEPENDS:   Settings\ConfigInterface, Auth\{TwoFactorPolicy,TwoFactorStore,TwoFactorFlow}, Codes\{OtpService,
 *            OtpResult,CodeRequest,CodeInput}, Phones\PhoneFormat, Log\DebugLog (optional); db_get_row / db_get_array.
 */

namespace Tygh\Addons\Kwtsms\Auth;

use Tygh\Addons\Kwtsms\Codes\CodeInput;
use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Codes\OtpResult;
use Tygh\Addons\Kwtsms\Codes\OtpService;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Phones\PhoneFormat;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class AdminTwoFactorGuard
{
    /** @var ConfigInterface */
    private $config;

    /** @var TwoFactorPolicy */
    private $policy;

    /** @var TwoFactorStore */
    private $store;

    /** @var OtpService */
    private $otp;

    /** @var CodeRequest|null */
    private $codes;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null */
    private $users;

    /** @var callable|null */
    private $admins;

    public function __construct(
        ConfigInterface $config,
        TwoFactorPolicy $policy,
        TwoFactorStore $store,
        OtpService $otp,
        ?CodeRequest $codes = null,
        ?DebugLog $log = null,
        ?callable $users = null,
        ?callable $admins = null
    ) {
        $this->config = $config;
        $this->policy = $policy;
        $this->store = $store;
        $this->otp = $otp;
        $this->codes = $codes;
        $this->log = $log;
        $this->users = $users;
        $this->admins = $admins;
    }

    /**
     * P3-52 (5): true for a root administrator while no other active root administrator has an enrolled row.
     *
     * @param array<string,mixed> $user
     */
    public function lastRootLetIn(array $user): bool
    {
        return $this->policy->letInAllowed($user);
    }

    /**
     * Whether the save of twofa_admins = required may go through.
     *
     * @return array{ok:bool,error:string,without_mobile:array<int,array<string,mixed>>}
     */
    public function canRequire(
        int $saver_id,
        #[\SensitiveParameter]
        string $proof_code,
        string $session_hash,
        string $ip,
        bool $ack_missing = false
    ): array {
        $out = ['ok' => false, 'error' => 'error', 'without_mobile' => []];
        try {
            $saver = $saver_id > 0 ? $this->readUser($saver_id) : [];
            if (!$this->isActiveRoot($saver)) {
                $out['error'] = 'not_root';

                return $out;
            }
            $phone = $this->usablePhone((string) ($saver['phone'] ?? ''));
            if ($phone === '') {
                $out['error'] = 'no_mobile';

                return $out;
            }
            $out['without_mobile'] = $this->withoutMobile();
            if ($out['without_mobile'] !== [] && !$ack_missing) {
                $out['error'] = 'ack_missing';

                return $out;
            }
            if (trim($proof_code) === '') {
                $out['error'] = 'proof_missing';

                return $out;
            }
            if (CodeInput::normalize($proof_code) === null) {
                $out['error'] = 'proof_malformed';

                return $out;
            }
            $subject = TwoFactorFlow::subject($saver_id, $phone);
            $result = $this->otp->verify(
                CodeRequest::PURPOSE_2FA_ENROLL,
                $subject,
                $proof_code,
                $saver_id,
                $session_hash,
                $ip
            );
            if ($result->status !== OtpResult::OK) {
                $out['error'] = $this->proofError($result->status);
                $out['attempts_left'] = (int) $result->attempts_left;
                $out['retry_after'] = (int) $result->retry_after;
                $this->debug('twofa.required_refused', ['user_id' => $saver_id, 'reason' => $out['error']]);

                return $out;
            }
            if (!$this->otp->consume(CodeRequest::PURPOSE_2FA_ENROLL, $subject, $saver_id, $session_hash)) {
                $out['error'] = 'proof_wrong';

                return $out;
            }
            $out['ok'] = true;
            $out['error'] = '';
            $this->debug('twofa.required_allowed', [
                'user_id' => $saver_id, 'without_mobile' => count($out['without_mobile']),
            ]);

            return $out;
        } catch (\Throwable $e) {
            // Fail closed: the save is refused.
            $this->debug('twofa.required_refused', ['user_id' => $saver_id, 'reason' => 'exception',
                'error' => get_class($e)]);
            $out['ok'] = false;
            $out['error'] = 'error';

            return $out;
        }
    }

    /**
     * The proof code to the saver's own mobile (the users row, never a request field).
     *
     * @return array<string,mixed> The body of CodeRequest::handleTwoFactor(), or a refusal body
     */
    public function sendProof(int $saver_id, string $session_hash, string $ip, int $storefront_id, string $lang): array
    {
        try {
            $saver = $saver_id > 0 ? $this->readUser($saver_id) : [];
            if (!$this->isActiveRoot($saver)) {
                return ['ok' => false, 'error' => 'not_root'];
            }
            $phone = $this->usablePhone((string) ($saver['phone'] ?? ''));
            if ($phone === '') {
                return ['ok' => false, 'error' => 'no_mobile'];
            }
            if ($this->codes === null) {
                return ['ok' => false, 'error' => 'unavailable'];
            }

            return $this->codes->handleTwoFactor(
                $phone,
                $saver_id,
                true,
                $session_hash,
                $ip,
                $storefront_id,
                $lang,
                CodeRequest::PURPOSE_2FA_ENROLL,
                TwoFactorFlow::subject($saver_id, $phone)
            );
        } catch (\Throwable $e) {
            $this->debug('twofa.required_proof_send', ['error' => get_class($e)]);

            return ['ok' => false, 'error' => 'unavailable'];
        }
    }

    /**
     * Active administrators (user_type A, any company) that could not get a code: no enabled 2FA row with a phone and
     * no usable mobile in users.phone.
     *
     * @return array<int,array{user_id:int,email:string,name:string}>
     */
    public function withoutMobile(): array
    {
        $out = [];
        foreach ($this->administrators() as $row) {
            $id = (int) ($row['user_id'] ?? 0);
            $enrolled = $this->store->row($id);
            $has_row = $enrolled !== null && (string) $enrolled['enabled'] === 'Y';
            if ($has_row && trim((string) $enrolled['phone']) !== '') {
                continue;
            }
            if ($this->usablePhone((string) ($row['phone'] ?? '')) !== '') {
                continue;
            }
            $out[] = [
                'user_id' => $id,
                'email' => (string) ($row['email'] ?? ''),
                'name' => trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? '')),
            ];
        }

        return $out;
    }

    private function proofError(string $status): string
    {
        switch ($status) {
            case OtpResult::LOCKED:
            case OtpResult::IP_LIMITED:
                return 'proof_locked';
            case OtpResult::EXPIRED:
            case OtpResult::NONE:
                return 'proof_expired';
            case OtpResult::MALFORMED:
                return 'proof_malformed';
        }

        return 'proof_wrong';
    }

    /** @param array<string,mixed> $user */
    private function isActiveRoot(array $user): bool
    {
        return $user !== []
            && (string) ($user['user_type'] ?? '') === 'A'
            && (string) ($user['is_root'] ?? 'N') === 'Y'
            && (int) ($user['company_id'] ?? 0) === 0
            && (string) ($user['status'] ?? '') === 'A';
    }

    private function usablePhone(string $raw): string
    {
        return $raw === '' ? '' : PhoneFormat::fromRaw($raw, $this->config->defaultCountryCode());
    }

    /**
     * @return array<string,mixed>
     */
    private function readUser(int $user_id): array
    {
        if ($this->users !== null) {
            return (array) call_user_func($this->users, $user_id);
        }
        $row = db_get_row(
            'SELECT user_id, user_type, status, company_id, is_root, phone FROM ?:users WHERE user_id = ?i',
            $user_id
        );

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function administrators(): array
    {
        if ($this->admins !== null) {
            return (array) call_user_func($this->admins);
        }

        return (array) db_get_array(
            "SELECT user_id, email, firstname, lastname, phone FROM ?:users WHERE user_type = 'A' AND status = 'A'"
            . ' ORDER BY user_id'
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private function debug(string $action, array $context): void
    {
        try {
            if ($this->log !== null) {
                $this->log->log('info', $action, $action, $context);
            }
        } catch (\Throwable $e) {
            return;
        }
    }
}
