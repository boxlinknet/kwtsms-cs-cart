<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Antibot/TurnstileWidget.php
 * CREATED:   2026-10-24 21:10 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The data a storefront form needs to render the Cloudflare Turnstile widget: the site key, the Cloudflare
 *            action for the core scenario, and the widget language (ar or en). Never the secret key.
 * WHY:       Task M1.7b (P3-R8): the widget's data-action comes from the same server-side vocabulary the driver checks
 *            (TurnstileDriver::ACTIONS), and the site key is the only key that may reach a page.
 * USAGE:     $w = new TurnstileWidget($config);
 *            $w->data('login', 'ar');   // ['site_key' => '..', 'action' => 'login', 'language' => 'ar'], [] w/o key
 *            TurnstileWidget::actionForOption('kwtsms_sms_code');   // 'kwtsms_sms_code'
 * DEPENDS:   Settings\ConfigInterface, Antibot\TurnstileDriver (the action list).
 */

namespace Tygh\Addons\Kwtsms\Antibot;

use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class TurnstileWidget
{
    /** @var ConfigInterface */
    private $config;

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * The Cloudflare action of a core image-verification scenario: itself when it is on the allow-list of the driver,
     * 'core' for any other scenario (a new core scenario, or text that is not one).
     */
    public static function actionForOption(string $option): string
    {
        return in_array($option, TurnstileDriver::ACTIONS, true) && $option !== 'core' ? $option : 'core';
    }

    /**
     * @return array{site_key: string, action: string, language: string}|array{} [] while no site key is saved
     */
    public function data(string $option, string $lang_code): array
    {
        $site_key = $this->config->turnstileSiteKey();
        if ($site_key === '') {
            return [];
        }

        return [
            'site_key' => $site_key,
            'action' => self::actionForOption($option),
            'language' => strtolower(substr($lang_code, 0, 2)) === 'ar' ? 'ar' : 'en',
        ];
    }
}
