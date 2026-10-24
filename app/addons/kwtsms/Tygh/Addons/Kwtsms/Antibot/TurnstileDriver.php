<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Antibot/TurnstileDriver.php
 * CREATED:   2026-10-24 19:42 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Cloudflare Turnstile driver of the core antibot service (IAntibotDriver, IErrorableAntibotDriver).
 *            isSetUp() needs the Turnstile switch, both keys and no active recaptcha add-on. validateHttpRequest()
 *            reads the token field `cf-turnstile-response`, takes the expected action from the dispatch the server
 *            runs (never from a posted field) and hands both to TurnstileVerifier with the storefront hosts and the
 *            visitor's address from ClientIp.
 *            2026-10-24 21:10 +03: Task M1.7b (P3-R7, P3-R8), task 596. "No active recaptcha" became "no active AND
 *            configured recaptcha"; the SMS code request needs exactly its own action, core scenarios take any
 *            allow-listed one.
 * WHY:      Task M1.7a (PRD 6.15): the core antibot service takes one driver; with Turnstile configured it replaces
 *            the null driver, so every core scenario (register, login, checkout, ...) and our own `kwtsms_sms_code`
 *            scenario are checked by Cloudflare. init.php installs it only when isSetUp() is true.
 * USAGE:     new TurnstileDriver($config, new HttpTransport([TurnstileVerifier::HOST]));
 *            $driver->isSetUp(); $driver->validateHttpRequest($_REQUEST); $driver->getErrorMessage('register');
 *            The optional callables are test seams: (): bool recaptcha active, (): string[] storefront hosts,
 *            (): string visitor address.
 * DEPENDS:   Settings\ConfigInterface, Antibot\TurnstileVerifier, Http\HttpTransport, Security\ClientIp,
 *            core Tygh\Web\Antibot\{IAntibotDriver,IErrorableAntibotDriver}.
 */

namespace Tygh\Addons\Kwtsms\Antibot;

use Tygh\Addons\Kwtsms\Http\HttpTransport;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Web\Antibot\IAntibotDriver;
use Tygh\Web\Antibot\IErrorableAntibotDriver;

class TurnstileDriver implements IAntibotDriver, IErrorableAntibotDriver
{
    /** Form field the Turnstile widget fills with the token. */
    public const TOKEN_FIELD = 'cf-turnstile-response';

    /** The actions a widget may be rendered for; a dispatch that maps to none is checked as 'core'. */
    public const ACTIONS = ['register', 'login', 'checkout', 'track_orders', 'recover', 'kwtsms_sms_code', 'core'];

    /**
     * 2026-10-24 20:28 +03: M1.7a fix round 1 (M2), task 596. controller.mode the server is running => the Turnstile
     * action the token must carry. Decided on the server: the browser's claim is never read.
     */
    private const ACTION_BY_DISPATCH = [
        'profiles.update' => 'register',
        'checkout.add_profile' => 'register',
        'auth.login' => 'login',
        'checkout.place_order' => 'checkout',
        'orders.track_request' => 'track_orders',
        'auth.recover_password' => 'recover',
    ];

    /** @var ConfigInterface */
    private $config;

    /** @var HttpTransport */
    private $http;

    /** @var callable|null */
    private $recaptcha_active;

    /** @var callable|null */
    private $hosts;

    /** @var callable|null */
    private $remote_ip;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null 2026-10-24 20:28 +03: M1.7a fix round 1 (M2), task 596. (): string the expected action */
    private $action;

    public function __construct(
        ConfigInterface $config,
        HttpTransport $http,
        ?callable $recaptcha_active = null,
        ?callable $hosts = null,
        ?callable $remote_ip = null,
        ?DebugLog $log = null,
        ?callable $action = null
    ) {
        $this->config = $config;
        $this->http = $http;
        $this->recaptcha_active = $recaptcha_active;
        $this->hosts = $hosts;
        $this->remote_ip = $remote_ip;
        $this->log = $log;
        $this->action = $action;
    }

    /**
     * @return bool
     */
    public function isSetUp()
    {
        return $this->config->turnstileEnabled()
            && $this->config->turnstileSiteKey() !== ''
            && $this->config->turnstileSecret() !== ''
            && !$this->recaptchaActive();
    }

    /**
     * @param array<string,mixed> $http_request_data
     *
     * @return bool
     */
    public function validateHttpRequest(array $http_request_data)
    {
        $token = $http_request_data[self::TOKEN_FIELD] ?? '';
        if (!is_string($token) || $token === '') {
            return false;
        }
        // 2026-10-24 20:28 +03: M1.7a fix round 1 (M2), task 596. The action comes from the dispatch the server is
        // running, not from a posted field.
        // 2026-10-24 21:10 +03: Task M1.7b (ruling P3-R8), task 596. The SMS code request (it spends credits) needs
        // exactly its own action; a core scenario takes any action of the allow-list (a single-use token of 300 s is
        // low risk across core forms); an action outside the list is refused.
        $action = $this->expectedAction();
        $accepted = $action === 'kwtsms_sms_code' ? $action : self::ACTIONS;

        $verifier = new TurnstileVerifier(
            $this->http,
            $this->config->turnstileSecret(),
            $this->config->turnstileSiteKey(),
            $this->log
        );
        $result = $verifier->verify($token, $this->remoteIp(), $accepted, $this->allowedHosts());

        return $result['ok'];
    }

    /**
     * @param string $scenario
     *
     * @return string
     */
    public function getErrorMessage($scenario)
    {
        return function_exists('__') ? (string) __('kwtsms.turnstile.error') : 'kwtsms.turnstile.error';
    }

    /**
     * 2026-10-24 20:28 +03: M1.7a fix round 1 (M2), task 596. The action for a controller and mode ('core' when the
     * dispatch is not one of the protected forms; every mode of the kwtsms_code controller is the SMS-code request).
     */
    public static function actionForDispatch(string $controller, string $mode): string
    {
        if ($controller === 'kwtsms_code') {
            return 'kwtsms_sms_code';
        }

        return self::ACTION_BY_DISPATCH[$controller . '.' . $mode] ?? 'core';
    }

    private function expectedAction(): string
    {
        if ($this->action !== null) {
            return (string) call_user_func($this->action);
        }

        return self::actionForDispatch(
            (string) \Tygh\Registry::get('runtime.controller'),
            (string) \Tygh\Registry::get('runtime.mode')
        );
    }

    /**
     * 2026-10-24 21:10 +03: Task M1.7b (ruling P3-R7), task 596. The core reCAPTCHA add-on is active on every stock
     * install, with no keys. It only stands in Turnstile's way while it is ACTIVE and has a key pair saved (v2 site key
     * and secret, or v3 site key and secret); an active but unconfigured one is replaced (the add-on's template for
     * common/image_verification.tpl yields to ours, see func.php).
     *
     * @param string               $status   addons.recaptcha.status ('A' active)
     * @param array<string,mixed>  $settings addons.recaptcha settings
     */
    public static function recaptchaBlocks(string $status, array $settings): bool
    {
        if ($status !== 'A') {
            return false;
        }
        $has = static function (string $key) use ($settings): bool {
            return isset($settings[$key]) && is_string($settings[$key]) && trim($settings[$key]) !== '';
        };

        return ($has('recaptcha_site_key') && $has('recaptcha_secret'))
            || ($has('recaptcha_v3_site_key') && $has('recaptcha_v3_secret'));
    }

    private function recaptchaActive(): bool
    {
        if ($this->recaptcha_active !== null) {
            return (bool) call_user_func($this->recaptcha_active);
        }

        return self::recaptchaBlocks(
            (string) \Tygh\Registry::get('addons.recaptcha.status'),
            (array) \Tygh\Registry::get('addons.recaptcha')
        );
    }

    private function remoteIp(): string
    {
        return $this->remote_ip !== null ? (string) call_user_func($this->remote_ip) : ClientIp::current();
    }

    /**
     * Every storefront URL host (and the configured HTTP and HTTPS hosts), lower case, without port or path.
     *
     * @return string[]
     */
    private function allowedHosts(): array
    {
        if ($this->hosts !== null) {
            return array_values((array) call_user_func($this->hosts));
        }
        $urls = [
            (string) \Tygh\Registry::get('config.http_host'),
            (string) \Tygh\Registry::get('config.https_host'),
        ];
        try {
            $app = \Tygh\Tygh::$app;
            if (isset($app['storefront.repository'])) {
                // 2026-10-24 20:30 +03: M1.7a fix round 1 (M5), task 596. No limit: every storefront.
                [$storefronts] = $app['storefront.repository']->find([]);
                foreach ((array) $storefronts as $storefront) {
                    $urls[] = (string) $storefront->url;
                }
            }
        } catch (\Throwable $e) {
            // The configured hosts above still count.
            $urls = array_filter($urls, 'strlen');
        }
        $hosts = [];
        foreach ($urls as $url) {
            $host = strtolower((string) preg_replace('#^[a-z]+://#i', '', trim($url)));
            $host = (string) preg_replace('#[/:?].*$#', '', $host);
            if ($host !== '') {
                $hosts[$host] = $host;
            }
        }

        return array_values($hosts);
    }
}
