<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/RecoverByCode.php
 * CREATED:   2026-11-05 16:31 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Account recovery by an SMS code (P3-46, P3-53): complete() checks the recovery code blind (a phone
 *            without an account, a wrong, expired or foreign-session code all answer the same), consumes it,
 *            re-checks that the owner is still eligible, refuses when core's recovery key limit is reached, and only
 *            then hands over a core recovery key (fn_generate_ekey, type R, RECOVERY_PASSWORD_TTL) that is bound to
 *            the requesting session. keyUsableInSession() is the other half of the binding: a key issued here is
 *            accepted by core's recovery login only in the session it was issued for.
 * WHY:       Task M2.3 (SafatMall B4). This is an account-takeover surface: every doubtful state fails closed (no
 *            key), the key never appears in a URL, a log row, a debug row or a JSON answer, it is single use (core
 *            deletes it at the login), it dies with the core TTL, and an account with two-step sign-in meets its
 *            second factor again unless the recovery phone IS its two-step phone (then the recovery code was that
 *            factor and the session gets the one-shot done mark of P3-51).
 * USAGE:     $recover = Tygh::$app['kwtsms.recover_by_code'];
 *            $r = $recover->complete($raw_phone, $raw_code, $session_hash, $ip, $storefront_id);
 *            $r['status']: ok ($r['ekey'] is the key, only then), wrong (attempts_left), locked | ip_limited
 *            (retry_after), malformed (nothing counted), limit (core's 3 recovery keys exist), off (recover_code is
 *            OFF), error (the key could not be issued). Every other field is empty or 0.
 *            $recover->keyUsableInSession($ekey, $session_hash): bool  (false only for a bound key of another session)
 *            Test seams (constructor): $recovery_count (int $user_id): int, $issue (int $user_id, string
 *            $session_hash): string, $recovery_data (string $ekey): ?array, $clear_session (): void.
 * DEPENDS:   Settings\ConfigInterface, Phones\{PhoneRules,PhoneIndex}, Codes\{OtpService,OtpResult,CodeInput,
 *            BlindCodeRequest,CodeRequest}, Auth\{TwoFactorStore,PendingLogin}, Log\DebugLog (optional), core
 *            fn_recovery_password_get_ekeys_count(), fn_generate_ekey(), fn_get_ekeys(), RECOVERY_PASSWORD_EKEY_TYPE,
 *            RECOVERY_PASSWORD_TTL.
 */

namespace Tygh\Addons\Kwtsms\Auth;

use Tygh\Addons\Kwtsms\Codes\BlindCodeRequest;
use Tygh\Addons\Kwtsms\Codes\CodeInput;
use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Codes\OtpResult;
use Tygh\Addons\Kwtsms\Codes\OtpService;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Phones\PhoneIndex;
use Tygh\Addons\Kwtsms\Phones\PhoneRules;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class RecoverByCode
{
    /** Key of the session binding inside the ekeys.data column. */
    public const BIND_FIELD = 'kwtsms_sid';

    /** Seconds the recovery code counts as the second factor for the key login that follows. */
    public const DONE_SECONDS = 300;

    /** @var ConfigInterface */
    private $config;

    /** @var PhoneRules */
    private $rules;

    /** @var PhoneIndex */
    private $index;

    /** @var OtpService */
    private $otp;

    /** @var BlindCodeRequest */
    private $eligible;

    /** @var TwoFactorStore */
    private $twofa;

    /** @var PendingLogin */
    private $pending;

    /** @var callable|null */
    private $recovery_count;

    /** @var callable|null */
    private $issue;

    /** @var callable|null */
    private $recovery_data;

    /** @var callable|null */
    private $clear_session;

    /** @var DebugLog|null */
    private $log;

    /**
     * @param callable|null $recovery_count (int $user_id): int, the recovery keys the account has now; null = core
     * @param callable|null $issue         (int $user_id, string $session_hash): string, the new key; null = core
     * @param callable|null $recovery_data (string $ekey): ?array, the data of a live recovery key (null = no such key)
     * @param callable|null $clear_session (): void, drops the session state of the code flows; null = Tygh session
     */
    public function __construct(
        ConfigInterface $config,
        PhoneRules $rules,
        PhoneIndex $index,
        OtpService $otp,
        BlindCodeRequest $eligible,
        TwoFactorStore $twofa,
        PendingLogin $pending,
        ?callable $recovery_count = null,
        ?callable $issue = null,
        ?callable $recovery_data = null,
        ?DebugLog $log = null,
        ?callable $clear_session = null
    ) {
        $this->config = $config;
        $this->rules = $rules;
        $this->index = $index;
        $this->otp = $otp;
        $this->eligible = $eligible;
        $this->twofa = $twofa;
        $this->pending = $pending;
        $this->recovery_count = $recovery_count;
        $this->issue = $issue;
        $this->recovery_data = $recovery_data;
        $this->log = $log;
        $this->clear_session = $clear_session;
    }

    /**
     * @return array{status:string,ekey:string,attempts_left:int,retry_after:int}
     */
    public function complete(
        string $raw_phone,
        #[\SensitiveParameter]
        string $raw_code,
        string $session_hash,
        string $ip,
        int $storefront_id
    ): array {
        if (!$this->config->recoverCode()) {
            return $this->answer('off');
        }
        // 2026-11-05 20:40 +03: M2.3-fix item 3 (security review Minor), task 596. A key is bound to the session hash;
        // with no session id the hash is empty and a key bound to nothing must never be minted. Nothing is counted.
        if ($session_hash === '') {
            $this->debug('recover.code_refused', ['reason' => 'no_session']);

            return $this->answer('error');
        }
        // 2026-11-06 07:35 +03: Task M2.5 (C2, P3-55), task 596. Same hint as the code request (see LoginByCode).
        $check = $this->rules->check(
            $raw_phone,
            \Tygh\Addons\Kwtsms\Phones\AccountPhone::hint('phone', [], [], $this->config->defaultCountryCode())
        );
        $phone = $check['ok'] ? $check['phone'] : '';
        if ($phone === '' || CodeInput::normalize($raw_code) === null) {
            return $this->answer('malformed');
        }

        // The owner of the number as the index says now; 0 = nobody. The engine is asked either way, so a number with
        // no account is counted exactly like a wrong code (P3-46). A code issued to another owner never matches.
        $owner = $this->index->verifiedOwner($phone);
        $result = $this->otp->verifyBlind(CodeRequest::PURPOSE_RECOVER, $phone, $raw_code, $owner, $session_hash, $ip);
        switch ($result->status) {
            case OtpResult::OK:
                break;
            case OtpResult::LOCKED:
                return $this->answer('locked', 0, $result->retry_after);
            case OtpResult::IP_LIMITED:
                return $this->answer('ip_limited', 0, $result->retry_after);
            case OtpResult::WRONG:
                return $this->answer('wrong', $result->attempts_left);
            case OtpResult::MALFORMED:
                return $this->answer('malformed');
            default:
                return $this->wrong();
        }
        if ($owner <= 0) {
            return $this->wrong();
        }

        // 2026-11-05 20:38 +03: M2.3-fix item 2 (security review Minor), task 596. A read-only look at core's key limit
        // before the code is used up: a user at the limit keeps the code and can use it once a key expires. The check
        // after consume() below stays for the race of two parallel submissions.
        try {
            if ($this->keysOf($owner) >= $this->keyLimit()) {
                $this->debug('recover.code_refused', ['reason' => 'key_limit']);

                return $this->answer('limit');
            }
        } catch (\Throwable $e) {
            $this->debug('recover.code_refused', ['reason' => 'key_error', 'error' => get_class($e)]);

            return $this->answer('error');
        }

        // P3-53: the code is used up first (two parallel submissions: one wins); then the owner is checked again
        // (disabled, moved to another account or made ambiguous since the issue); then core's key limit; then the key.
        if (!$this->otp->consume(CodeRequest::PURPOSE_RECOVER, $phone, $owner, $session_hash)) {
            return $this->wrong();
        }
        $still = $this->eligible->eligibleOwner($phone, CodeRequest::PURPOSE_RECOVER, $this->eligible->companyScope());
        if ($still !== $owner) {
            $this->debug('recover.code_refused', ['reason' => 'owner_not_eligible']);

            return $this->wrong();
        }
        try {
            if ($this->keysOf($owner) >= $this->keyLimit()) {
                $this->debug('recover.code_refused', ['reason' => 'key_limit']);

                return $this->answer('limit');
            }
            $ekey = $this->issueKey($owner, $session_hash);
        } catch (\Throwable $e) {
            $this->debug('recover.code_refused', ['reason' => 'key_error', 'error' => get_class($e)]);

            return $this->answer('error');
        }
        if ($ekey === '') {
            $this->debug('recover.code_refused', ['reason' => 'key_empty']);

            return $this->answer('error');
        }
        $this->markSecondFactor($owner, $phone);
        $this->clearSession();
        $this->debug('recover.key_issued', ['user_id' => $owner, 'storefront_id' => $storefront_id]);

        return $this->answer('ok', 0, 0, $ekey);
    }

    /**
     * False only for a key that was issued by complete() for another session. A key without a binding (the e-mail
     * link, the 1.1.0 SMS link), an unknown key and an expired key are left to core, which says "not valid" itself.
     */
    public function keyUsableInSession(
        #[\SensitiveParameter]
        string $ekey,
        string $session_hash
    ): bool {
        if ($ekey === '') {
            return true;
        }
        try {
            $data = $this->keyData($ekey);
        } catch (\Throwable $e) {
            // 2026-11-05 20:34 +03: M2.3-fix item 1 (security review Minor), task 596. Core's read failed: look at the
            // stored data directly. A key that carries the binding (or whose data cannot be read at all) is refused;
            // an e-mail key without a binding still works.
            return !$this->directBinding($ekey);
        }
        if ($data === null || !isset($data[self::BIND_FIELD]) || !is_string($data[self::BIND_FIELD])) {
            return true;
        }

        return $session_hash !== '' && hash_equals($data[self::BIND_FIELD], $session_hash);
    }

    /**
     * 2026-11-05 20:34 +03: M2.3-fix item 1, task 596. True when the stored data of the key carries the session
     * binding, or when it cannot be read (fail closed). Reads ekeys.data with one query, no core key function.
     */
    protected function directBinding(
        #[\SensitiveParameter]
        string $ekey
    ): bool {
        return self::keyCarriesBinding($ekey);
    }

    /**
     * 2026-11-05 20:34 +03: M2.3-fix item 1, task 596. Static so the pre-controller can use it when the service itself
     * cannot be built. A failed read counts as bound (refuse).
     */
    public static function keyCarriesBinding(
        #[\SensitiveParameter]
        string $ekey
    ): bool {
        try {
            $raw = db_get_field(
                'SELECT data FROM ?:ekeys WHERE ekey = ?s AND object_type = ?s',
                $ekey,
                defined('RECOVERY_PASSWORD_EKEY_TYPE') ? RECOVERY_PASSWORD_EKEY_TYPE : 'R'
            );
        } catch (\Throwable $e) {
            return true;
        }

        return self::dataCarriesBinding($raw);
    }

    /**
     * 2026-11-05 20:34 +03: M2.3-fix item 1, task 596. The ekeys.data column is json_encode()d by core.
     *
     * @param mixed $raw
     */
    public static function dataCarriesBinding($raw): bool
    {
        return is_string($raw) && strpos($raw, '"' . self::BIND_FIELD . '"') !== false;
    }

    /**
     * A correct code that cannot give a key is answered as a wrong one, with the full tries (nothing was counted
     * against a wrong guess).
     *
     * @return array{status:string,ekey:string,attempts_left:int,retry_after:int}
     */
    private function wrong(): array
    {
        return $this->answer('wrong', $this->config->otpMaxAttempts());
    }

    /**
     * @return array{status:string,ekey:string,attempts_left:int,retry_after:int}
     */
    private function answer(
        string $status,
        int $attempts_left = 0,
        int $retry_after = 0,
        #[\SensitiveParameter]
        string $ekey = ''
    ): array {
        return [
            'status' => $status,
            'ekey' => $ekey,
            'attempts_left' => $attempts_left,
            'retry_after' => $retry_after,
        ];
    }

    /**
     * P3-53: the recovered account's two-step phone is the recovery phone, so the code just proved is its second
     * factor: the one-shot done mark lets the key login through the hold of P3-50. Another phone, no row or a
     * disabled row: no mark, the hold applies.
     */
    private function markSecondFactor(int $owner, string $phone): void
    {
        try {
            $row = $this->twofa->row($owner);
            if (is_array($row) && ($row['enabled'] ?? '') === 'Y' && (string) ($row['phone'] ?? '') === $phone) {
                $this->pending->markDone($owner, self::DONE_SECONDS);
            }
        } catch (\Throwable $e) {
            $this->debug('recover.code_refused', ['reason' => 'twofa_read_error']);
        }
    }

    private function keysOf(int $user_id): int
    {
        if ($this->recovery_count !== null) {
            return (int) call_user_func($this->recovery_count, $user_id);
        }

        return (int) fn_recovery_password_get_ekeys_count($user_id);
    }

    private function keyLimit(): int
    {
        return defined('RECOVERY_PASSWORD_EKEYS_LIMIT') ? (int) RECOVERY_PASSWORD_EKEYS_LIMIT : 3;
    }

    private function issueKey(int $user_id, string $session_hash): string
    {
        if ($this->issue !== null) {
            return (string) call_user_func($this->issue, $user_id, $session_hash);
        }
        if (!function_exists('fn_generate_ekey') || !defined('RECOVERY_PASSWORD_TTL')) {
            throw new \RuntimeException('kwtsms: core recovery keys are not available');
        }

        return (string) fn_generate_ekey(
            $user_id,
            RECOVERY_PASSWORD_EKEY_TYPE,
            RECOVERY_PASSWORD_TTL,
            '',
            [self::BIND_FIELD => $session_hash]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function keyData(
        #[\SensitiveParameter]
        string $ekey
    ): ?array {
        if ($this->recovery_data !== null) {
            $data = call_user_func($this->recovery_data, $ekey);

            return is_array($data) ? $data : null;
        }
        $rows = fn_get_ekeys([
            'ekey' => $ekey,
            'object_type' => RECOVERY_PASSWORD_EKEY_TYPE,
            'ttl' => defined('TIME') ? TIME : time(),
        ]);
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $row = reset($rows);

        return is_array($row) && isset($row['data']) && is_array($row['data']) ? $row['data'] : [];
    }

    private function clearSession(): void
    {
        if ($this->clear_session !== null) {
            call_user_func($this->clear_session);

            return;
        }
        $session = \Tygh\Tygh::$app['session'];
        unset($session[BlindCodeRequest::STEP_KEY], $session[CodeRequest::BYPASS_KEY]);
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
