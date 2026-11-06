<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Vendors/VendorPhoneCode.php
 * CREATED:   2026-11-06 18:31 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      A vendor administrator proves that the company's SMS number is theirs: request() sends a code to the
 *            canonical companies.phone, verify() checks it and, when it is right, confirms the number
 *            (VendorPhones::approve() with approved_by = the vendor administrator).
 * WHY:       Task M2.6 (SafatMall F4, ruling P3-56). Until now only a root administrator could approve a vendor's SMS
 *            number (P2-5). With vendor_phone_self_verify ON the vendor does it itself with the SMS code engine; root
 *            approval stays as an override (Open 7) and a changed company phone still revokes the confirmation (P2-5,
 *            VendorPhones::onCompanyUpdated). The company id is an ARGUMENT here: the controller reads it from the
 *            session of the signed-in vendor administrator, never from the request, and every key of the code (the
 *            engine subject, the SMS target, the approval) is derived from that id and the company's own phone, so a
 *            vendor of company Y can neither ask for nor use a code of company X.
 * USAGE:     $svc = Tygh::$app['kwtsms.vendor_phone_code'];
 *            $svc->request($vendor_user_id, $company_id, $session_hash, $ip, $lang);   // {ok,...} or {ok:false,error}
 *            //   errors: off, no_vendor, no_company, not_active, no_phone, invalid, approved, then CodeRequest's
 *            //   (wait, unavailable, busy)
 *            $r = $svc->verify($vendor_user_id, $company_id, $typed, $session_hash, $ip);   // OtpResult, OK = done
 *            $svc->pageState($company_id);   // status none|approved|changed|no_phone|invalid, masked, can_send, ...
 *            VendorPhoneCode::subject($company_id, '+96550123401')   // the engine key: '<company>:<canonical phone>'
 * DEPENDS:   Settings\ConfigInterface (vendorPhoneSelfVerify), Vendors\VendorPhones, Codes\{OtpService,OtpResult,
 *            CodeRequest}, Phones\PhoneFormat, Auth\TwoFactorFlow::mask(), Log\DebugLog (optional).
 */

namespace Tygh\Addons\Kwtsms\Vendors;

use Tygh\Addons\Kwtsms\Auth\TwoFactorFlow;
use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Codes\OtpResult;
use Tygh\Addons\Kwtsms\Codes\OtpService;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Phones\PhoneFormat;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class VendorPhoneCode
{
    /** @var ConfigInterface */
    private $config;

    /** @var VendorPhones */
    private $phones;

    /** @var OtpService */
    private $otp;

    /** @var CodeRequest */
    private $codes;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null () => int the storefront id of the sender/template lookup; null = the request's storefront */
    private $storefront;

    public function __construct(
        ConfigInterface $config,
        VendorPhones $phones,
        OtpService $otp,
        CodeRequest $codes,
        ?DebugLog $log = null,
        ?callable $storefront = null
    ) {
        $this->config = $config;
        $this->phones = $phones;
        $this->otp = $otp;
        $this->codes = $codes;
        $this->log = $log;
        $this->storefront = $storefront;
    }

    /**
     * The engine key of a vendor code: the company and its canonical phone. At most 11 + 1 + 16 characters (the column
     * holds 32), so a code is never shared between two companies or two numbers.
     */
    public static function subject(int $company_id, string $canonical_phone): string
    {
        return $company_id . ':' . $canonical_phone;
    }

    /** True while the switch is ON (the caller adds the Multi-Vendor and vendor-session checks). */
    public function enabled(): bool
    {
        return $this->config->vendorPhoneSelfVerify();
    }

    /**
     * What the vendor page shows for the company.
     *
     * @return array{status:string,masked:string,masked_ltr:string,can_send:bool,approved_at:int,reason:string}
     */
    public function pageState(int $company_id): array
    {
        $state = $this->phones->state($company_id);
        $candidate = $this->phones->candidate($company_id);
        $phone = $candidate['ok'] ? PhoneFormat::canonical($candidate['phone']) : '';
        $shown = $phone !== '' ? $phone : PhoneFormat::canonical($state['normalized']);

        return [
            'status' => $state['status'],
            'masked' => $shown === '' ? '' : TwoFactorFlow::mask($shown),
            'masked_ltr' => $shown === '' ? '' : TwoFactorFlow::maskLtr($shown),
            'can_send' => $candidate['ok'] && $state['status'] !== 'approved',
            'approved_at' => $state['approved_at'],
            'reason' => $state['reason'],
        ];
    }

    /**
     * Sends a code to the company's own phone.
     *
     * @return array<string,mixed> the JSON-like body: ok, then retry_after/expires_in/message, or error
     */
    public function request(int $vendor_user_id, int $company_id, string $session_hash, string $ip, string $lang): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'off'];
        }
        if ($vendor_user_id <= 0 || $company_id <= 0 || $session_hash === '') {
            return ['ok' => false, 'error' => 'no_vendor'];
        }
        $refusal = $this->refusal($company_id);
        if ($refusal !== '') {
            return ['ok' => false, 'error' => $refusal];
        }
        $phone = PhoneFormat::canonical($this->phones->candidate($company_id)['phone']);
        try {
            $body = $this->codes->handleVendorPhone(
                $phone,
                $company_id,
                $vendor_user_id,
                self::subject($company_id, $phone),
                $session_hash,
                $ip,
                $this->storefrontId(),
                $lang
            );
        } catch (\Throwable $e) {
            $this->debug('vendor_phone.request_error', ['company_id' => $company_id, 'error' => get_class($e)]);

            return ['ok' => false, 'error' => 'unavailable'];
        }
        $this->debug('vendor_phone.request', ['company_id' => $company_id, 'ok' => !empty($body['ok'])]);

        return $body;
    }

    /**
     * Checks the typed code for the company's current phone; a right code (used up once) confirms the number.
     */
    public function verify(
        int $vendor_user_id,
        int $company_id,
        #[\SensitiveParameter]
        string $raw_code,
        string $session_hash,
        string $ip
    ): OtpResult {
        if (!$this->enabled() || $vendor_user_id <= 0 || $company_id <= 0 || $session_hash === '') {
            return OtpResult::of(OtpResult::NONE);
        }
        $candidate = $this->phones->candidate($company_id);
        if (!$candidate['ok']) {
            return OtpResult::of(OtpResult::NONE);
        }
        $subject = self::subject($company_id, PhoneFormat::canonical($candidate['phone']));
        $purpose = CodeRequest::PURPOSE_VENDOR_PHONE;
        $result = $this->otp->verify($purpose, $subject, $raw_code, $vendor_user_id, $session_hash, $ip);
        if ($result->status !== OtpResult::OK) {
            return $result;
        }
        // One code, one confirmation: only the request that uses the verified code up goes on.
        if (!$this->otp->consume(CodeRequest::PURPOSE_VENDOR_PHONE, $subject, $vendor_user_id, $session_hash)) {
            return OtpResult::of(OtpResult::NONE);
        }
        // 2026-11-06 23:49 +03: M2.6-fix I1, task 596. Approve only the number the code was verified for: when the
        // company phone moved since the check above, approve() refuses (reason 'changed').
        $approved = $this->phones->approve($company_id, $vendor_user_id, (string) $candidate['phone']);
        if (!$approved['ok']) {
            $this->debug('vendor_phone.approve_refused', [
                'company_id' => $company_id, 'reason' => $approved['reason'],
            ]);

            return OtpResult::of(OtpResult::NONE);
        }
        $this->info('vendor_phone.self_verified', ['company_id' => $company_id, 'vendor_user_id' => $vendor_user_id]);

        return $result;
    }

    /** '' when a code may be sent for the company, else the error key. */
    private function refusal(int $company_id): string
    {
        $candidate = $this->phones->candidate($company_id);
        if (!$candidate['ok']) {
            if ($candidate['reason'] === 'no_company' || $candidate['reason'] === 'not_active') {
                return $candidate['reason'];
            }

            return $this->phones->state($company_id)['status'] === 'no_phone' ? 'no_phone' : 'invalid';
        }

        return $this->phones->state($company_id)['status'] === 'approved' ? 'approved' : '';
    }

    private function storefrontId(): int
    {
        if ($this->storefront !== null) {
            return (int) call_user_func($this->storefront);
        }
        $storefront = isset(\Tygh\Tygh::$app['storefront']) ? \Tygh\Tygh::$app['storefront'] : null;

        return is_object($storefront) && isset($storefront->storefront_id) ? (int) $storefront->storefront_id : 0;
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

    /**
     * @param array<string,mixed> $context
     */
    private function info(string $action, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log('info', $action, $action, $context);
        }
    }
}
