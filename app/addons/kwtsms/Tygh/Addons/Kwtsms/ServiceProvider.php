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
 *            2026-10-21 01:13 +03, Task 1.7, task 596: kwtsms.api_client_for (a client on candidate credentials, for
 *            the login), kwtsms.sync (Gateway\SyncService) and kwtsms.cron (Cron\CronRunner):
 *            $sync = Tygh::$app['kwtsms.sync'];  $cron = Tygh::$app['kwtsms.cron'];
 *            Task 1.9 (transport and notification services) adds its entries here.
 * DEPENDS:   Pimple (CS-Cart core), the add-on's Settings, Gateway, Storage, Log, Queue, Alerts, Http and Sms classes.
 */

namespace Tygh\Addons\Kwtsms;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Tygh\Addons\Kwtsms\Alerts\AdminAlerts;
use Tygh\Addons\Kwtsms\Cron\CronRunner;
use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Gateway\SyncService;
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

        // 2026-10-21 01:13 +03: a client on a candidate pair, for SyncService::login() (nothing is saved before
        // /balance/ is OK). Same guard, transport and log as every other client of the request. Task 1.7, task 596.
        $app['kwtsms.api_client_for'] = $app->protect(
            function (string $user, string $pass, string $context) use ($app): ApiClient {
                return new ApiClient(
                    $user,
                    $pass,
                    new HttpTransport(),
                    $app['kwtsms.api_guard'],
                    $app['kwtsms.logs.debug'],
                    $context
                );
            }
        );

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

        // 2026-10-21 01:13 +03: Task 1.7, task 596. Login, logout, sync and the suspended-gateway retry. The
        // /balance/ refresh and the ERR003 probe stay in SmsService (ruling R25).
        $app['kwtsms.sync'] = function (Container $app): SyncService {
            return new SyncService(
                $app['kwtsms.config'],
                $app['kwtsms.state'],
                $app['kwtsms.api_client_factory'],
                $app['kwtsms.api_client_for'],
                $app['kwtsms.logs.debug'],
                $app['kwtsms.sms'],
                $app['kwtsms.alerts']
            );
        };

        // 2026-10-21 01:13 +03: the jobs of kwtsms_cron.run behind the core lock factory (PRD 4.4), Task 1.7,
        // task 596. The held-credits query counts the test messages of the last 30 days (index on created_at).
        $app['kwtsms.cron'] = function (Container $app): CronRunner {
            return new CronRunner(
                $app['kwtsms.config'],
                $app['kwtsms.state'],
                $app['kwtsms.sync'],
                [$app['kwtsms.sms'], 'drainDue'],
                $app['kwtsms.logs.store'],
                $app['kwtsms.logs.debug'],
                $app['kwtsms.storage'],
                function (string $name, int $ttl) use ($app) {
                    return $app['lock.factory']->createLock($name, $ttl);
                },
                $app['kwtsms.alerts'],
                [self::class, 'heldTestCredits']
            );
        };
    }

    /**
     * Test messages of the last 30 days that hold credits in the kwtSMS queue (PRD 6.13 test-mode notice).
     * 2026-10-21 01:13 +03: added, Task 1.7, task 596.
     *
     * @return array{messages: int, credits: float}
     */
    public static function heldTestCredits(): array
    {
        $row = db_get_row(
            'SELECT COUNT(*) AS messages, COALESCE(SUM(points_charged), 0) AS credits FROM ?:kwtsms_messages'
            . ' WHERE created_at >= ?i AND is_test = ?s AND points_charged > 0',
            time() - 30 * 86400,
            'Y'
        );

        return [
            'messages' => is_array($row) ? (int) ($row['messages'] ?? 0) : 0,
            'credits' => is_array($row) ? (float) ($row['credits'] ?? 0) : 0.0,
        ];
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
