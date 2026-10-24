<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Phones/PhoneRules.php
 * CREATED:   2026-10-24 15:29 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The server-side phone rule of AREA C (registration, checkout, profile): one typed phone becomes its
 *            canonical form, or is refused as invalid (not a mobile, wrong length, letters, e-mail) or as a country
 *            the store does not send to. Also the customer-facing text of each refusal and the GCC country set.
 * WHY:       Task M1.5 (SafatMall C3, P3-15). Core validates a phone in the browser only. The allowed countries are the
 *            existing customer_sms_countries setting (C1); the check runs only when phone_country_check is ON and only
 *            on a new or changed phone (P3-21), which the callers decide.
 * USAGE:     $r = (new PhoneRules($config))->check($posted_phone, $hint_cc);  // ['ok', 'phone', 'reason']
 *            $text = $rules->message($r['reason'], $config->customerSmsCountries());
 * DEPENDS:   Settings\ConfigInterface::customerSmsCountries(), Sms\PhoneNormalizer, Phones\PhoneFormat, __().
 */

namespace Tygh\Addons\Kwtsms\Phones;

use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

final class PhoneRules
{
    /** Kuwait, Saudi Arabia, United Arab Emirates, Bahrain, Qatar, Oman: what "Add GCC countries" ticks. */
    public const GCC = ['965', '966', '971', '973', '974', '968'];

    public const REASON_INVALID = 'invalid';
    public const REASON_COUNTRY = 'country_not_allowed';

    /** @var ConfigInterface */
    private $config;

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * @param string $raw      The phone as typed
     * @param string $hint_cc  Calling code for a number typed without one (see AccountPhone::hint())
     *
     * @return array{ok:bool,phone:string,reason:string} An empty input is ok with phone '' (required-ness is the
     *         profile field's own rule); ok has the canonical phone; not ok has reason invalid or country_not_allowed
     */
    public function check(string $raw, string $hint_cc): array
    {
        if (trim($raw) === '') {
            return ['ok' => true, 'phone' => '', 'reason' => ''];
        }
        $verdict = PhoneNormalizer::validateInput($raw, $hint_cc);
        if (!$verdict[0]) {
            return ['ok' => false, 'phone' => '', 'reason' => self::REASON_INVALID];
        }
        $cc = PhoneNormalizer::countryCode($verdict[2]);
        if ($cc === null || !in_array($cc, $this->config->customerSmsCountries(), true)) {
            return ['ok' => false, 'phone' => '', 'reason' => self::REASON_COUNTRY];
        }

        return ['ok' => true, 'phone' => PhoneFormat::canonical($verdict[2]), 'reason' => ''];
    }

    /**
     * The customer-facing text of a refusal ('' for any other reason).
     *
     * @param string[] $allowed_cc Calling codes shown in the country text, e.g. ['965']
     */
    public function message(string $reason, array $allowed_cc): string
    {
        if ($reason === self::REASON_INVALID) {
            return (string) __('kwtsms.phone.error_invalid');
        }
        if ($reason === self::REASON_COUNTRY) {
            $options = PhoneNormalizer::countryOptions();
            $names = [];
            foreach ($allowed_cc as $cc) {
                $cc = (string) $cc;
                $names[] = $options[$cc] ?? ('+' . $cc);
            }

            return (string) __('kwtsms.phone.error_country', ['[countries]' => implode(', ', $names)]);
        }

        return '';
    }
}
