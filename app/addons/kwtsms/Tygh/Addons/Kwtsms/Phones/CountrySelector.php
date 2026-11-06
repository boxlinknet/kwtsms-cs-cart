<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Phones/CountrySelector.php
 * CREATED:   2026-11-06 07:34 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The storefront phone country selector (C2): the countries it offers (the allowed ones of
 *            customer_sms_countries: ISO2, calling code, localized name), whether it is active (its switch AND the
 *            country check C3), the default country, the payload the browser script reads, and the allowed calling
 *            codes a posted kwtsms_cc[<field>] is checked against.
 * WHY:       Task M2.5 (P3-55, SafatMall C2). Customers should pick one of the countries the store really sends to.
 *            The list is only a convenience: the server re-reads the allowed list and decides (PhoneRules, the
 *            canonical form), never the browser.
 * USAGE:     $sel = new CountrySelector($config);  $sel->active();  $sel->countries('en');
 *            $sel->payload(CART_LANGUAGE, $settings_phone_validation_mode);  $sel->hintAllowed();
 * DEPENDS:   Settings\ConfigInterface, Sms\PhoneNormalizer (ISO and name tables); core country_descriptions for the
 *            localized names (a callable seam replaces it in tests).
 */

namespace Tygh\Addons\Kwtsms\Phones;

use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

final class CountrySelector
{
    /** The three core values of Settings > Appearance > phone_validation_mode. */
    public const MODES = ['phone_number_with_country_selection', 'international_format', 'any_digits'];

    /** @var ConfigInterface */
    private $config;

    /** @var callable|null (string $lang): array<string,string> ISO2 => name; null = core country_descriptions */
    private $names;

    /**
     * @param callable|null $names (string $lang): array<string,string>, ISO2 => country name in $lang (test seam)
     */
    public function __construct(ConfigInterface $config, ?callable $names = null)
    {
        $this->config = $config;
        $this->names = $names;
    }

    /** True when the selector is switched on AND the country check (C3) is on: Settings refuses the switch without C3. */
    public function active(): bool
    {
        return $this->config->phoneCountrySelector() && $this->config->phoneCountryCheck();
    }

    /**
     * The calling codes a posted kwtsms_cc is accepted for: the allowed countries while the selector is active, else
     * none (a posted value is then ignored).
     *
     * @return string[]
     */
    public function hintAllowed(): array
    {
        return $this->active() ? array_map('strval', $this->config->customerSmsCountries()) : [];
    }

    /** The preselected calling code: the store default when allowed, else the first allowed country. */
    public function defaultCc(): string
    {
        $allowed = array_map('strval', $this->config->customerSmsCountries());
        $default = (string) $this->config->defaultCountryCode();

        return in_array($default, $allowed, true) ? $default : (string) ($allowed[0] ?? $default);
    }

    /**
     * The allowed countries, the default one first, the others in the order of the setting.
     *
     * @return array<int,array{iso2:string,cc:string,name:string}>
     */
    public function countries(string $lang): array
    {
        $names = $this->namesFor($lang);
        $default = $this->defaultCc();
        $rows = [];
        foreach (array_map('strval', $this->config->customerSmsCountries()) as $cc) {
            $iso = (string) array_search($cc, PhoneNormalizer::ISO_CALLING_CODES, true);
            $name = $iso !== '' && isset($names[$iso]) && trim($names[$iso]) !== ''
                ? trim($names[$iso]) : (PhoneNormalizer::COUNTRY_NAMES[$cc] ?? ('+' . $cc));
            $row = ['iso2' => $iso, 'cc' => $cc, 'name' => $name];
            if ($cc === $default) {
                array_unshift($rows, $row);
            } else {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * What js/addons/kwtsms/phone_country.js reads: the countries, the default calling code and the core phone mode.
     *
     * @return array{countries:array<int,array{iso2:string,cc:string,name:string}>,default:string,mode:string}
     */
    public function payload(string $lang, string $mode): array
    {
        return [
            'countries' => $this->countries($lang),
            'default' => $this->defaultCc(),
            'mode' => in_array($mode, self::MODES, true) ? $mode : self::MODES[0],
        ];
    }

    /**
     * @return array<string,string> ISO2 => name; [] when it cannot be read (the English table then applies)
     */
    private function namesFor(string $lang): array
    {
        try {
            if ($this->names !== null) {
                return array_map('strval', (array) call_user_func($this->names, $lang));
            }
            if (!function_exists('db_get_hash_single_array')) {
                return [];
            }
            $names = db_get_hash_single_array(
                'SELECT code, country FROM ?:country_descriptions WHERE lang_code = ?s',
                ['code', 'country'],
                $lang
            );

            return is_array($names) ? array_map('strval', $names) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
