<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/ServiceProvider.php
 * CREATED:   2026-10-21 00:19 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Registers the add-on's services in the CS-Cart container (Tygh::$app), one shared instance of each per
 *            request: kwtsms.storage, kwtsms.state, kwtsms.config, kwtsms.logs.store, kwtsms.logs.debug,
 *            kwtsms.logs.messages, kwtsms.logs.attempts, kwtsms.outbox, kwtsms.api_guard,
 *            kwtsms.api_client_factory, kwtsms.alerts and kwtsms.sms.
 * WHY:       Ruling R2: SmsService, fn_kwtsms_send(), the cron (Task 1.7) and the notification transport (Task 1.9)
 *            need the same wiring. One ApiGuard per request is shared by every ApiClient of that request, because the
 *            "a transport failure already happened in this request" flag lives on the guard (PRD 6.2: every later
 *            send of the request is deferred without an HTTP call). GatewayState writes its suspension under the
 *            guard's per-database lock (Task 1.5 review carry). Services are built lazily: registering costs nothing
 *            on requests that send no SMS.
 * USAGE:     init.php: Tygh::$app->register(new ServiceProvider());
 *            $sms = Tygh::$app['kwtsms.sms'];                                    // SmsService
 *            $client = Tygh::$app['kwtsms.api_client_factory']('admin');        // ApiClient (web|admin|cli timeouts)
 *            Tasks 1.7 (kwtsms.sync) and 1.9 (transport and notification services) add their entries here.
 * DEPENDS:   Pimple (CS-Cart core), the add-on's Settings, Gateway, Storage, Log, Queue, Alerts, Http and Sms classes.
 */

namespace Tygh\Addons\Kwtsms;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Tygh\Addons\Kwtsms\Alerts\AdminAlerts;
use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Http\HttpTransport;
use Tygh\Addons\Kwtsms\Log\AttemptLog;
use Tygh\Addons\Kwtsms\Log\DbLogStore;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Log\MessageLog;
use Tygh\Addons\Kwtsms\Queue\Outbox;
use Tygh\Addons\Kwtsms\Settings\Config;
use Tygh\Addons\Kwtsms\Settings\CsCartSettingsBackend;
use Tygh\Addons\Kwtsms\Sms\ApiClient;
use Tygh\Addons\Kwtsms\Sms\ApiGuard;
use Tygh\Addons\Kwtsms\Sms\SmsService;
use Tygh\Addons\Kwtsms\Storage\StorageDataStore;

class ServiceProvider implements ServiceProviderInterface
{
    /**
     * @param Container $app
     */
    public function register(Container $app)
    {
        $app['kwtsms.storage'] = function (): StorageDataStore {
            return new StorageDataStore();
        };

        $app['kwtsms.state'] = function (Container $app): GatewayState {
            // The locker resolves the guard only when a suspension is written, so there is no construction cycle.
            return new GatewayState($app['kwtsms.storage'], null, function (callable $write) use ($app): bool {
                return $app['kwtsms.api_guard']->runLocked($write);
            });
        };

        $app['kwtsms.config'] = function (Container $app): Config {
            return new Config(new CsCartSettingsBackend(), $app['kwtsms.state']);
        };

        $app['kwtsms.logs.store'] = function (): DbLogStore {
            return new DbLogStore();
        };

        $app['kwtsms.logs.debug'] = function (Container $app): DebugLog {
            return new DebugLog($app['kwtsms.logs.store'], $app['kwtsms.config'], [self::class, 'actorId']);
        };

        $app['kwtsms.logs.messages'] = function (Container $app): MessageLog {
            return new MessageLog($app['kwtsms.logs.store'], $app['kwtsms.config']);
        };

        $app['kwtsms.logs.attempts'] = function (Container $app): AttemptLog {
            return new AttemptLog($app['kwtsms.logs.store']);
        };

        $app['kwtsms.outbox'] = function (): Outbox {
            return new Outbox();
        };

        // One per request: the per-request transport-failure flag lives on it.
        $app['kwtsms.api_guard'] = function (Container $app): ApiGuard {
            return new ApiGuard($app['kwtsms.storage'], $app['kwtsms.state']);
        };

        $app['kwtsms.api_client_factory'] = $app->protect(function (string $context) use ($app): ApiClient {
            /** @var Config $config */
            $config = $app['kwtsms.config'];

            return new ApiClient(
                $config->apiUsername(),
                $config->password(),
                new HttpTransport(),
                $app['kwtsms.api_guard'],
                $app['kwtsms.logs.debug'],
                $context
            );
        });

        $app['kwtsms.alerts'] = function (Container $app): AdminAlerts {
            return new AdminAlerts($app['kwtsms.storage'], $app['kwtsms.config'], $app['kwtsms.logs.debug']);
        };

        $app['kwtsms.sms'] = function (Container $app): SmsService {
            return new SmsService(
                $app['kwtsms.config'],
                $app['kwtsms.state'],
                $app['kwtsms.api_client_factory'],
                $app['kwtsms.logs.messages'],
                $app['kwtsms.logs.attempts'],
                $app['kwtsms.logs.debug'],
                $app['kwtsms.outbox'],
                $app['kwtsms.alerts']
            );
        };
    }

    /**
     * The id of the user doing the request, for the debug log (0 in the cron, the CLI and for guests).
     */
    public static function actorId(): int
    {
        try {
            $app = \Tygh\Tygh::$app;
            if (!isset($app['session'])) {
                return 0;
            }
            $session = $app['session'];

            return isset($session['auth']['user_id']) ? (int) $session['auth']['user_id'] : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
