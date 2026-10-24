<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Antibot/TurnstileDriver.php
 * CREATED:   2026-10-24 19:42 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Cloudflare Turnstile driver of the core antibot service (IAntibotDriver, IErrorableAntibotDriver).
 *            isSetUp() needs the Turnstile switch, both keys and no active recaptcha add-on. validateHttpRequest()
 *            reads the token field `cf-turnstile-response` and the claimed action `kwtsms_turnstile_action` (mapped
 *            through an allow-list) and hands them to TurnstileVerifier with the storefront hosts and the visitor's
 *            address from ClientIp.
 * WHY:       Task M1.7a (PRD 6.15): the core antibot service takes one driver; with Turnstile configured it replaces
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

    /** Form field with the action the widget was rendered for. */
    public const ACTION_FIELD = 'kwtsms_turnstile_action';

    /** The actions a widget may be rendered for; anything else is checked as 'core'. */
    public const ACTIONS = ['register', 'login', 'checkout', 'track_orders', 'recover', 'kwtsms_sms_code', 'core'];

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

    public function __construct(
        ConfigInterface $config,
        HttpTransport $http,
        ?callable $recaptcha_active = null,
        ?callable $hosts = null,
        ?callable $remote_ip = null,
        ?DebugLog $log = null
    ) {
        $this->config = $config;
        $this->http = $http;
        $this->recaptcha_active = $recaptcha_active;
        $this->hosts = $hosts;
        $this->remote_ip = $remote_ip;
        $this->log = $log;
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
        $claimed = $http_request_data[self::ACTION_FIELD] ?? '';
        $action = is_string($claimed) && in_array($claimed, self::ACTIONS, true) ? $claimed : 'core';

        $verifier = new TurnstileVerifier(
            $this->http,
            $this->config->turnstileSecret(),
            $this->config->turnstileSiteKey(),
            $this->log
        );
        $result = $verifier->verify($token, $this->remoteIp(), $action, $this->allowedHosts());

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

    private function recaptchaActive(): bool
    {
        if ($this->recaptcha_active !== null) {
            return (bool) call_user_func($this->recaptcha_active);
        }

        return (string) \Tygh\Registry::get('addons.recaptcha.status') === 'A';
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
                [$storefronts] = $app['storefront.repository']->find(['items_per_page' => 1000]);
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
