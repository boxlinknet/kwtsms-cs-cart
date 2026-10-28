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
 *            2026-10-21 01:58 +03, Task 1.9, task 596: the order SMS services and the notification transport
 *            event.transports.kwtsms (registered first in register()), and extendNotifications() for init.php:
 *            kwtsms.order_flags, kwtsms.dispatch_keys, kwtsms.templates, kwtsms.order_sms_data, kwtsms.order_source,
 *            kwtsms.checkout_rules, kwtsms.order_diagnostics, kwtsms.order_notifier, kwtsms.order_hooks,
 *            kwtsms.declared_events;  ServiceProvider::extendNotifications(Tygh::$app);  (guarded, see there)
 *            2026-10-21 04:21 +03, Task 1.9 fix round 1, task 596: the order lookups and the dispatch keys log
 *            their errors to kwtsms.logs.debug (item 2); the diagnostics write only while SMS is on and set up
 *            (item 4); a tripped extendNotifications() guard also raises the admin notice sms_not_attached after
 *            the response (item 5); prune_logs also prunes expired status dispatch keys (ruling R32, item 6).
 *            2026-10-22 19:09 +03, Task A6, task 596: kwtsms.profile_hooks (Profiles\ProfileHooks, the registration
 * alert).
 *            2026-10-22 21:27 +03, Task A7, task 596: kwtsms.low_stock (Orders\LowStockCollector); the order hooks
 *            take it as their last constructor argument.
 * DEPENDS:   Pimple (CS-Cart core), the add-on's Settings, Gateway, Storage, Log, Queue, Alerts, Http and Sms classes.
 */

namespace Tygh\Addons\Kwtsms;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Tygh\Addons\Kwtsms\Alerts\AdminAlerts;
use Tygh\Addons\Kwtsms\Antibot\SuspicionGate; // 2026-10-24 23:51 +03: Task M1.8a, task 596
use Tygh\Addons\Kwtsms\Codes\CodeRequest; // 2026-10-24 23:51 +03: Task M1.8a, task 596
use Tygh\Addons\Kwtsms\Phones\PhoneRules; // 2026-10-24 23:51 +03: Task M1.8a, task 596
use Tygh\Addons\Kwtsms\Antibot\TurnstileDriver; // 2026-10-24 19:42 +03: Task M1.7a, task 596
use Tygh\Addons\Kwtsms\Antibot\TurnstileVerifier; // 2026-10-24 19:42 +03: Task M1.7a, task 596
use Tygh\Addons\Kwtsms\Antibot\TurnstileWidget; // 2026-10-24 21:14 +03: Task M1.7b, task 596
use Tygh\Addons\Kwtsms\Alerts\LowBalanceSms;
use Tygh\Addons\Kwtsms\Alerts\StateLock; // 2026-10-23 05:47 +03: Task CA1-3, task 596
use Tygh\Addons\Kwtsms\Storefronts\SenderResolver;
use Tygh\Addons\Kwtsms\Storefronts\StorefrontRules;
use Tygh\Addons\Kwtsms\Codes\CodeAvailability; // 2026-10-24 12:37 +03: Task M1.4, task 596
use Tygh\Addons\Kwtsms\Codes\CodeHasher; // 2026-10-23 21:28 +03: Task M1.3, task 596
use Tygh\Addons\Kwtsms\Codes\CodeSender; // 2026-10-24 12:37 +03: Task M1.4, task 596
use Tygh\Addons\Kwtsms\Codes\OtpService; // 2026-10-23 21:28 +03: Task M1.3, task 596
use Tygh\Addons\Kwtsms\Cron\CronRunner;
use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Gateway\SyncService;
use Tygh\Addons\Kwtsms\Http\HttpTransport;
use Tygh\Addons\Kwtsms\Log\AttemptLog;
use Tygh\Addons\Kwtsms\Log\DbLogStore;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Log\MessageLog;
use Tygh\Addons\Kwtsms\Phones\PhoneIndex; // 2026-10-24 17:18 +03: Task M1.6, task 596
use Tygh\Addons\Kwtsms\Phones\PhoneIndexBackfill; // 2026-10-24 17:18 +03: Task M1.6, task 596
use Tygh\Addons\Kwtsms\Phones\PhoneLock; // 2026-10-25 04:37 +03: Task M1.9, task 596
use Tygh\Addons\Kwtsms\Profiles\CustomerPrefs; // 2026-10-23 00:54 +03: Task A10, task 596
use Tygh\Addons\Kwtsms\Profiles\CodeChecks; // 2026-10-28 11:06 +03: Task M1.10a, task 596 (replaces NoCodeCheck)
use Tygh\Addons\Kwtsms\Profiles\RegistrationCode; // 2026-10-28 11:06 +03: Task M1.10a, task 596
use Tygh\Addons\Kwtsms\Profiles\PhoneChange; // 2026-10-28 20:26 +03: Task M1.10b, task 596
use Tygh\Addons\Kwtsms\Profiles\ProfileHooks; // 2026-10-22 19:09 +03: Task A6, task 596
use Tygh\Addons\Kwtsms\Orders\LowStockCollector; // 2026-10-22 21:27 +03: Task A7, task 596
use Tygh\Addons\Kwtsms\Queue\Outbox;
use Tygh\Addons\Kwtsms\Security\RateLimiter; // 2026-10-23 19:55 +03: Task M1.2, task 596
use Tygh\Addons\Kwtsms\Settings\Config;
use Tygh\Addons\Kwtsms\Settings\CsCartSettingsBackend;
use Tygh\Addons\Kwtsms\Sms\ApiClient;
use Tygh\Addons\Kwtsms\Sms\ApiGuard;
use Tygh\Addons\Kwtsms\Sms\SmsService;
use Tygh\Addons\Kwtsms\Storage\StorageDataStore;
// 2026-10-21 01:58 +03: Task 1.9, task 596.
use Pimple\Exception\FrozenServiceException;
use Tygh\Addons\Kwtsms\Notifications\EventsSchemaExtender;
use Tygh\Addons\Kwtsms\Notifications\NotificationSettingsExtender;
use Tygh\Addons\Kwtsms\Notifications\OrderSmsData;
use Tygh\Addons\Kwtsms\Notifications\SmsTransport;
use Tygh\Addons\Kwtsms\Notifications\VendorPhoneSourceInterface;
// 2026-10-21 16:53 +03: AfterResponse decides the order SMS send mode (final review I2), task 596.
use Tygh\Addons\Kwtsms\Orders\AfterResponse;
use Tygh\Addons\Kwtsms\Orders\CheckoutRules;
use Tygh\Addons\Kwtsms\Orders\Diagnostics;
use Tygh\Addons\Kwtsms\Orders\DispatchKeys;
use Tygh\Addons\Kwtsms\Orders\NewOrderDetector;
use Tygh\Addons\Kwtsms\Orders\OrderHooks;
use Tygh\Addons\Kwtsms\Orders\OrderNotifier;
use Tygh\Addons\Kwtsms\Orders\OrderSource;
use Tygh\Addons\Kwtsms\Admin\OrderSmsSender; // 2026-10-23 02:06 +03: Task A11, task 596
use Tygh\Addons\Kwtsms\Orders\RuntimeFlags;
use Tygh\Addons\Kwtsms\Templates\TemplateRepository;
use Tygh\Addons\Kwtsms\Vendors\VendorPhones; // 2026-10-22 22:22 +03: Task A8, task 596
use Tygh\Addons\Kwtsms\Vendors\VendorQuota; // 2026-10-22 22:22 +03: Task A8, task 596
use Tygh\Notifications\EventIdProviders\IProvider;

class ServiceProvider implements ServiceProviderInterface
{
    /**
     * @param Container $app
     */
    public function register(Container $app)
    {
        // 2026-10-21 01:58 +03: Task 1.9, task 596. The SMS notification transport and the order SMS services. All
        // lazy: a request that changes no order and opens no Notifications page builds none of them.
        $this->registerOrderSms($app);

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
        // 2026-10-21 17:31 +03: #[\SensitiveParameter] on the candidate password (hidden from traces on PHP 8.2+;
        // a comment line on PHP 7.4), Task 1.17 review Important 2, task 596.
        $app['kwtsms.api_client_for'] = $app->protect(
            function (
                string $user,
                #[\SensitiveParameter]
                string $pass,
                string $context
            ) use ($app): ApiClient {
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
            // 2026-10-23 05:47 +03: Task CA1-3 (A9 M1), task 596. The state lock of the low-balance crossing key.
            return new AdminAlerts(
                $app['kwtsms.storage'],
                $app['kwtsms.config'],
                $app['kwtsms.logs.debug'],
                null,
                null,
                null,
                new StateLock()
            );
        };

        // 2026-10-22 14:53 +03: the per-storefront switches and Sender IDs, Task A2 (P2-1, P2-2), task 596.
        $app['kwtsms.storefront_rules'] = function (): StorefrontRules {
            return new StorefrontRules();
        };
        // 2026-10-23 00:54 +03: Task A10 (SafatMall F6), task 596. The customer's opt-out of order-status SMS.
        $app['kwtsms.customer_prefs'] = function (): CustomerPrefs {
            return new CustomerPrefs();
        };
        $app['kwtsms.sender_resolver'] = function (Container $app): SenderResolver {
            return new SenderResolver($app['kwtsms.config'], $app['kwtsms.state'], $app['kwtsms.alerts']);
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
                $app['kwtsms.alerts'],
                null,
                null,
                $app['kwtsms.sender_resolver'],
                // 2026-10-23 05:50 +03: Task CA1-5 (A8 M1, M3), task 596. The V gate of the drain and of a Resend: the
                // vendor's approved number and monthly quota are checked again right before the send. Built lazily.
                static function (int $company_id, array $phones, int $outbox_id) use ($app): ?array {
                    if (!$app['kwtsms.checkout_rules']->isMultiVendor()) {
                        return null;
                    }
                    $approved = ltrim($app['kwtsms.vendor_phones']->approvedPhone($company_id), '+');
                    foreach ($phones as $phone) {
                        if ($approved === '' || ltrim((string) $phone, '+') !== $approved) {
                            return ['no_phone', 'vendor_not_approved'];
                        }
                    }
                    if (!$app['kwtsms.vendor_quota']->allows($company_id, count($phones), $outbox_id)) {
                        return ['rate_limited', 'vendor_quota'];
                    }

                    return null;
                }
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

        // 2026-10-21 02:29 +03: the jobs of kwtsms_cron.run behind the core lock factory (PRD 4.4). The held-credits
        // query is DbLogStore::heldTestCredits() over the last 30 days (index on created_at, was ServiceProvider
        // SQL); prune_logs also deletes final outbox rows (Outbox::pruneFinal, ruling R28). Task 1.7 (first written
        // 2026-10-21 01:13 +03, changed in fix round 1), task 596.
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
                function () use ($app): array {
                    return $app['kwtsms.logs.store']->heldTestCredits(time() - 30 * 86400);
                },
                null,
                null,
                [$app['kwtsms.outbox'], 'pruneFinal'],
                // 2026-10-21 04:21 +03: expired status dispatch keys, ruling R32 (Task 1.9 fix round 1 item 6),
                // task 596.
                // 2026-10-23 03:41 +03: pruneWindowed (was pruneStatus), Task A12 item 4, task 596.
                [$app['kwtsms.dispatch_keys'], 'pruneWindowed'],
                // 2026-10-23 00:13 +03: Task A9 (R8, R22), task 596. The low-balance SMS, dispatched by the drain step.
                [$app['kwtsms.low_balance_sms'], 'dispatchPending'],
                // 2026-10-23 19:55 +03: Task M1.2, task 596. prune_logs also deletes SMS code rows older than a day and
                // idle unlocked rate-limit buckets older than two days.
                [$app['kwtsms.rate_limiter'], 'prune'],
                // 2026-10-23 21:28 +03: Task M1.3, task 596. pruneCodes() moved from the rate limiter to the code
                // store (OtpService). 2026-10-24 06:00 +03: static since fix round 1 (minor 9): no hasher, so no
                // crypt_key is needed to prune.
                [OtpService::class, 'pruneCodes'],
                // 2026-10-24 17:18 +03: Task M1.6 (P3-31), task 596. The job index_phones.
                [$app['kwtsms.phone_index_backfill'], 'run']
            );
        };
        // 2026-10-24 17:18 +03: Task M1.6 (P3-27, P3-31, P3-33), task 596. The phone index, its backfill and the code
        // check seam of the profile hooks (NoCodeCheck until M1.9 to M1.10b).
        $app['kwtsms.phone_index'] = function (Container $app): PhoneIndex {
            return new PhoneIndex($app['kwtsms.config'], $app['kwtsms.storage'], $app['kwtsms.logs.debug']);
        };
        $app['kwtsms.phone_index_backfill'] = function (Container $app): PhoneIndexBackfill {
            return new PhoneIndexBackfill($app['kwtsms.config'], $app['kwtsms.storage'], $app['kwtsms.logs.debug']);
        };
        // 2026-10-28 11:06 +03: Task M1.10a, task 596. The code checks are the composite of the enabled checks: the
        // registration code (B1); the phone change (B5, Task M1.10b) joins it. Was NoCodeCheck.
        $app['kwtsms.code_checks'] = function (Container $app): CodeChecks {
            return new CodeChecks([
                new RegistrationCode(
                    $app['kwtsms.config'],
                    $app['kwtsms.otp'],
                    [$app['kwtsms.config'], 'defaultCountryCode'],
                    null,
                    null,
                    null,
                    $app['kwtsms.phone_lock'] // 2026-10-28 18:50 +03: fix round 1 (I3), task 596
                ),
                // 2026-10-28 20:26 +03: Task M1.10b (B5, P3-17), task 596. The phone change code and the notice to
                // the old number; the actor is the logged-in user of the request.
                new PhoneChange(
                    $app['kwtsms.config'],
                    $app['kwtsms.otp'],
                    static function (string $old, int $user_id, string $lang, int $storefront_id) use ($app) {
                        return $app['kwtsms.code_sender']
                            ->sendPhoneChangedNotice($old, $user_id, $lang, $storefront_id);
                    },
                    [$app['kwtsms.config'], 'defaultCountryCode'],
                    static function () use ($app): int {
                        $session = $app['session'] ?? null;

                        return is_object($session) || is_array($session) ? (int) ($session['auth']['user_id'] ?? 0) : 0;
                    }
                ),
            ]);
        };
        // 2026-10-25 04:37 +03: Task M1.9 (C4, P3-11), task 596. The per-phone lock between the "is it free" check and
        // the index write.
        $app['kwtsms.phone_lock'] = function (): PhoneLock {
            return new PhoneLock();
        };
        // 2026-10-23 19:55 +03: Task M1.2 (P3-18, P3-19), task 596. The atomic rate-limit buckets (clock = time()).
        $app['kwtsms.rate_limiter'] = function (Container $app): RateLimiter {
            return new RateLimiter();
        };
        // 2026-10-23 21:28 +03: Task M1.3 (P3-1, P3-4), task 596. The SMS code engine. The HMAC key is derived from
        // config.crypt_key (a file, not the database), so a dump alone cannot brute-force the 10^6 code space; an
        // empty crypt_key is a broken store and refuses to build the service (callers fail closed).
        $app['kwtsms.otp_hasher'] = function (Container $app): CodeHasher {
            $crypt_key = (string) \Tygh\Registry::get('config.crypt_key');
            if ($crypt_key === '') {
                throw new \RuntimeException('kwtsms: config.crypt_key is empty, SMS codes cannot be hashed');
            }

            return new CodeHasher(hash('sha256', 'kwtsms-otp|' . $crypt_key), $app['kwtsms.logs.debug']);
        };
        $app['kwtsms.otp'] = function (Container $app): OtpService {
            return new OtpService(
                $app['kwtsms.rate_limiter'],
                $app['kwtsms.otp_hasher'],
                $app['kwtsms.config'],
                null,
                null,
                $app['kwtsms.logs.debug']
            );
        };
        // 2026-10-24 12:37 +03: Task M1.4 (SafatMall G2, H2, I3), task 596. The code SMS sender (send callable = the
        // one SmsService, built on first use) and the availability states of the code features. The storefront URL is
        // the storefront's own (its host becomes the Web OTP line), the default storefront's when the id is unknown.
        $app['kwtsms.code_sender'] = function (Container $app): CodeSender {
            return new CodeSender(
                static function (array $phones, string $text, array $context) use ($app) {
                    return $app['kwtsms.sms']->send($phones, $text, $context);
                },
                $app['kwtsms.templates'],
                $app['kwtsms.config'],
                static function (int $storefront_id) use ($app): string {
                    try {
                        if (isset($app['storefront.repository'])) {
                            $repository = $app['storefront.repository'];
                            $storefront = $storefront_id > 0 ? $repository->findById($storefront_id) : null;
                            $storefront = $storefront ?: $repository->findDefault();
                            if ($storefront && (string) $storefront->url !== '') {
                                return (string) $storefront->url;
                            }
                        }
                    } catch (\Throwable $e) {
                        return (string) \Tygh\Registry::get('config.http_host');
                    }

                    return (string) \Tygh\Registry::get('config.http_host');
                },
                static function (int $storefront_id, string $lang): string {
                    return \Tygh\Addons\Kwtsms\Templates\BrandNames::get($storefront_id, $lang);
                },
                $app['kwtsms.logs.debug'],
                null,
                [$app['kwtsms.outbox'], 'markExpired'],
                $app['kwtsms.logs.attempts'] // 2026-10-24 23:51 +03: Task M1.8a (M1.4 review minor 4), task 596
            );
        };
        // 2026-10-24 23:51 +03: Task M1.8a (SafatMall E1 to E3, I3), task 596. The SMS code request and what it needs:
        // the phone rule of AREA C (C3 texts), the "only when suspicious" CAPTCHA gate and the endpoint itself.
        $app['kwtsms.phone_rules'] = function (Container $app): PhoneRules {
            return new PhoneRules($app['kwtsms.config']);
        };
        $app['kwtsms.suspicion_gate'] = function (Container $app): SuspicionGate {
            return new SuspicionGate($app['kwtsms.rate_limiter'], $app['kwtsms.config']);
        };
        $app['kwtsms.code_request'] = function (Container $app): CodeRequest {
            return new CodeRequest(
                $app['kwtsms.config'],
                $app['kwtsms.rate_limiter'],
                $app['kwtsms.suspicion_gate'],
                $app['kwtsms.phone_rules'],
                $app['kwtsms.phone_index'],
                $app['kwtsms.code_availability'],
                $app['kwtsms.otp'],
                $app['kwtsms.code_sender'],
                $app['kwtsms.logs.debug']
            );
        };
        // 2026-10-24 19:42 +03: Task M1.7a, task 596. The Cloudflare Turnstile antibot driver (PRD 6.15). Its own
        // HttpTransport instance is the only way the add-on talks to challenges.cloudflare.com (never Tygh\Http).
        $app['kwtsms.turnstile_driver'] = function (Container $app): TurnstileDriver {
            return new TurnstileDriver(
                $app['kwtsms.config'],
                new HttpTransport([TurnstileVerifier::HOST]),
                null,
                null,
                null,
                $app['kwtsms.logs.debug']
            );
        };
        // 2026-10-24 21:14 +03: Task M1.7b (PRD 6.15), task 596. The data of the storefront Turnstile widget (site key,
        // action, language; never the secret).
        $app['kwtsms.turnstile_widget'] = function (Container $app): TurnstileWidget {
            return new TurnstileWidget($app['kwtsms.config']);
        };
        $app['kwtsms.code_availability'] = function (Container $app): CodeAvailability {
            return new CodeAvailability(
                $app['kwtsms.config'],
                $app['kwtsms.state'],
                $app['kwtsms.sender_resolver'],
                $app['kwtsms.alerts']
            );
        };
        // 2026-10-23 00:13 +03: Task A9 (R8), task 596. One crossing of the low-balance threshold, one
        // kwtsms.low_balance event through event.dispatcher with the stored switches (default rules); the admin
        // phone list and cap_admin_day apply.
        $app['kwtsms.low_balance_sms'] = function (Container $app): LowBalanceSms {
            return new LowBalanceSms(
                $app['kwtsms.storage'],
                $app['kwtsms.state'],
                static function (string $event_id, array $data) use ($app): void {
                    $provider = new class ($event_id . '.' . (int) $data['crossing_at']) implements IProvider {
                        /** @var string */
                        private $id;

                        public function __construct(string $id)
                        {
                            $this->id = $id;
                        }

                        public function getId()
                        {
                            return $this->id;
                        }
                    };
                    $storefront = $app['storefront'] ?? null;
                    $data['storefront_id'] = is_object($storefront) && isset($storefront->storefront_id)
                        ? (int) $storefront->storefront_id : 0;
                    $app['event.dispatcher']->dispatch($event_id, $data, null, $provider);
                },
                null,
                new StateLock() // 2026-10-23 05:47 +03: Task CA1-3 (A9 M1), task 596
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

    /**
     * The order SMS services and event.transports.kwtsms (PRD 4.1, 4.2).
     * 2026-10-21 01:58 +03: added, Task 1.9, task 596.
     */
    private function registerOrderSms(Container $app): void
    {
        $app['event.transports.kwtsms'] = function (Container $app): SmsTransport {
            return new SmsTransport(function () use ($app): OrderNotifier {
                return $app['kwtsms.order_notifier'];
            });
        };

        $app['kwtsms.order_flags'] = function (): RuntimeFlags {
            return new RuntimeFlags();
        };

        // 2026-10-21 04:21 +03: the order lookups log their errors (fix round 1 item 2), task 596.
        $app['kwtsms.dispatch_keys'] = function (Container $app): DispatchKeys {
            // 2026-10-23 03:41 +03: Task A12 item 4 (R8, R24), task 596. The declarations give the windowed prefixes.
            return new DispatchKeys(null, $app['kwtsms.logs.debug'], $app['kwtsms.declared_events']);
        };

        $app['kwtsms.templates'] = function (Container $app): TemplateRepository {
            $repository = new TemplateRepository($app['kwtsms.logs.debug']);
            // 2026-10-22 17:12 +03: Task A4, task 596. The declared events' rows and placeholders on the Templates
            // page.
            $repository->setDeclarations(
                EventsSchemaExtender::declarations($app['kwtsms.declared_events'], self::isMultiVendor())
            );

            return $repository;
        };

        // 2026-10-22 17:12 +03: Task A4, task 596. Approved vendor numbers (receiver V).
        // 2026-10-22 22:22 +03: Task A8 (P2-R10, R8), task 596. The A4 source that approved nobody is replaced by
        // Vendors\VendorPhones (the approvals of the Vendor SMS page); still a VendorPhoneSourceInterface.
        $app['kwtsms.vendor_phones'] = function (Container $app): VendorPhoneSourceInterface {
            return new VendorPhones($app['kwtsms.config'], $app['kwtsms.logs.debug']);
        };

        // 2026-10-22 22:22 +03: Task A8 (R8), task 596. The monthly quota of a vendor's SMS.
        $app['kwtsms.vendor_quota'] = function (Container $app): VendorQuota {
            return new VendorQuota($app['kwtsms.config']);
        };

        $app['kwtsms.order_sms_data'] = function (Container $app): OrderSmsData {
            return new OrderSmsData($app['kwtsms.logs.debug']);
        };

        $app['kwtsms.order_source'] = function (Container $app): OrderSource {
            return new OrderSource($app['kwtsms.logs.debug']);
        };

        // 2026-10-23 02:06 +03: the manual SMS of the admin order page, Task A11, task 596.
        $app['kwtsms.order_sms_sender'] = function (Container $app): OrderSmsSender {
            return new OrderSmsSender(
                $app['kwtsms.sms'],
                $app['kwtsms.config'],
                $app['kwtsms.order_source'],
                $app['kwtsms.logs.debug']
            );
        };

        // The kwtsms events schema (our kwtsms.order_placed and third-party declarations, PRD 5.2).
        $app['kwtsms.declared_events'] = function (): array {
            $schema = fn_get_schema('kwtsms', 'events');

            return is_array($schema) ? $schema : [];
        };

        $app['kwtsms.checkout_rules'] = function (Container $app): CheckoutRules {
            $mve = fn_allowed_for('MULTIVENDOR');

            return new CheckoutRules(
                $app['kwtsms.config'],
                $mve,
                $mve && function_exists('fn_are_company_orders_fulfilled_by_marketplace')
                    ? static function (int $company_id): bool {
                        return fn_are_company_orders_fulfilled_by_marketplace($company_id) === true;
                    }
                    : null
            );
        };

        $app['kwtsms.order_diagnostics'] = function (Container $app): Diagnostics {
            return new Diagnostics(
                $app['kwtsms.logs.attempts'],
                $app['kwtsms.config'],
                // The same (fail-closed) service the dispatcher and the Notifications page read.
                function (string $event_id, string $receiver) use ($app): bool {
                    $settings = $app['event.notification_settings'];

                    return !empty($settings[$event_id]['receivers'][$receiver][EventsSchemaExtender::TRANSPORT_ID]);
                },
                function (array $rules) use ($app) {
                    return $app['event.notification_settings.factory']->create($rules);
                },
                [$app['kwtsms.order_source'], 'profilePhone'],
                [$app['kwtsms.order_source'], 'actor'],
                // 2026-10-21 04:21 +03: rows only while SMS is on and the add-on is set up (fix round 1 item 4),
                // task 596.
                function () use ($app): bool {
                    $config = $app['kwtsms.config'];

                    return $config->enabled() && $app['kwtsms.state']->isConfigured($config)[0];
                },
                // 2026-10-21 08:04 +03: the reason the add-on is not set up, for blocked_not_configured (ruling R37),
                // task 596.
                function () use ($app): string {
                    $configured = $app['kwtsms.state']->isConfigured($app['kwtsms.config']);

                    return $configured[0] ? '' : (string) $configured[1];
                }
            );
        };

        $app['kwtsms.order_notifier'] = function (Container $app): OrderNotifier {
            return new OrderNotifier(
                $app['kwtsms.sms'],
                $app['kwtsms.config'],
                $app['kwtsms.templates'],
                $app['kwtsms.dispatch_keys'],
                $app['kwtsms.order_sms_data'],
                $app['kwtsms.checkout_rules'],
                $app['kwtsms.order_flags'],
                $app['kwtsms.order_diagnostics'],
                $app['kwtsms.order_source'],
                $app['kwtsms.logs.debug'],
                $app['kwtsms.declared_events'],
                // 2026-10-21 16:53 +03: fpm, litespeed, cron (heartbeat within 2 intervals) or inline (final
                // review I2, ruling R52), task 596.
                [new AfterResponse(null, [$app['kwtsms.state'], 'lastCron']), 'mode'],
                $app['kwtsms.storefront_rules'],
                $app['kwtsms.vendor_phones'],
                // 2026-10-22 20:27 +03: Task A6 fix round 1 (P2-R12), task 596. The core antibot scenario of a
                // public form
                // (Tygh\Web\Antibot::isValidationRequiredForScenario, app/Tygh/Web/Antibot.php:121-129).
                $app['kwtsms.antibot_gate'],
                // 2026-10-22 22:22 +03: Task A8 (R8, R11), task 596. The vendor's monthly quota.
                $app['kwtsms.vendor_quota'],
                // 2026-10-23 00:54 +03: Task A10 (R8, R10), task 596. The customer opt-out, checked after the
                // storefront switch.
                $app['kwtsms.customer_prefs'],
                // 2026-10-23 03:31 +03: Task A12 item 1 (R8, R53), task 596. A storefront request that sends inline
                // because the cron heartbeat is stale also drains up to 5 due outbox rows (2 s budget, web limits).
                static function () use ($app): void {
                    if (PHP_SAPI === 'cli') {
                        return;
                    }
                    $app['kwtsms.sms']->drainDue(5, 2.0, false);
                }
            );
        };

        // 2026-10-23 05:57 +03: Cleanup CA1-6 (A6 N2), task 596. The antibot gate as a service: the order notifier and
        // the Templates page both ask it (was an inline closure in the notifier wiring). Returns a callable.
        $app['kwtsms.antibot_gate'] = $app->protect(static function (string $scenario) use ($app): bool {
            // 2026-10-23 13:18 +03: final review A, Minor 3, task 596. A core without
            // Antibot::isValidationRequiredForScenario()
            // (unverified below 4.21.2) keeps the gate closed (no SMS) and logs once instead of throwing on every
            // registration.
            if (!isset($app['antibot'])) {
                return false;
            }
            if (!method_exists($app['antibot'], 'isValidationRequiredForScenario')) {
                static $logged = false;
                if (!$logged) {
                    $logged = true;
                    error_log('kwtsms: Antibot::isValidationRequiredForScenario() does not exist in this core: '
                        . 'the antibot-gated SMS stay off');
                }

                return false;
            }

            return (bool) $app['antibot']->isValidationRequiredForScenario($scenario);
        });

        $app['kwtsms.order_hooks'] = function (Container $app): OrderHooks {
            $flags = $app['kwtsms.order_flags'];
            $keys = $app['kwtsms.dispatch_keys'];
            $detector = new NewOrderDetector(
                NewOrderDetector::placedStatuses(
                    (array) fn_get_order_paid_statuses(),
                    $app['kwtsms.config']->placedStatusExtra()
                ),
                static function (int $order_id) use ($flags): bool {
                    return $flags->has('edit', $order_id);
                },
                static function (int $order_id) use ($keys): bool {
                    return $keys->hasOrderPlaced($order_id);
                }
            );

            return new OrderHooks(
                $detector,
                $flags,
                $app['kwtsms.order_diagnostics'],
                $app['kwtsms.checkout_rules'],
                $app['kwtsms.order_source'],
                function (string $event_id, array $data, array $rules, IProvider $provider) use ($app): void {
                    $app['event.dispatcher']->dispatch(
                        $event_id,
                        $data,
                        $app['event.notification_settings.factory']->create($rules),
                        $provider
                    );
                },
                $app['kwtsms.logs.debug'],
                // 2026-10-22 21:27 +03: Task A7, task 596. The low-stock collector, flushed after a status change.
                $app['kwtsms.low_stock']
            );
        };
        // 2026-10-22 21:27 +03: Task A7 (SafatMall F5 part, P2-10, R20), task 596. One collector per request: the
        // update_product_amount hook records the products that crossed the threshold; the first record() registers
        // a shutdown flush, change_order_status_post flushes earlier. The switch is read from the stored kwtsms rows
        // (fail-closed: an unreadable row is OFF) and SMS must be on and set up before any key is claimed.
        $app['kwtsms.low_stock'] = function (Container $app): LowStockCollector {
            $dispatch = static function (string $event_id, array $data, array $rules) use ($app): void {
                $first = $data['products'][0]['product_id'] ?? 0;
                $provider = new class ($event_id . '.' . (int) ($data['company_id'] ?? 0) . '.' . (int) $first
                    . '.' . substr(md5(uniqid('', true)), 0, 8)) implements IProvider {
                    /** @var string */
                    private $id;

                    public function __construct(string $id)
                    {
                        $this->id = $id;
                    }

                    public function getId()
                    {
                        return $this->id;
                    }
                };
                // 2026-10-23 08:33 +0300: cleanup CA2-3 (A7 M2), task 596. The order's storefront (set by the collector
                // on a flush that
                // follows an order status change) wins; the request's storefront is the fallback.
                if ((int) ($data['storefront_id'] ?? 0) <= 0) {
                    $storefront = $app['storefront'] ?? null;
                    $data['storefront_id'] = is_object($storefront) && isset($storefront->storefront_id)
                        ? (int) $storefront->storefront_id : 0;
                }
                $app['event.dispatcher']->dispatch(
                    $event_id,
                    $data,
                    $app['event.notification_settings.factory']->create($rules),
                    $provider
                );
            };

            return new LowStockCollector(
                $app['kwtsms.dispatch_keys'],
                static function (string $receiver) use ($app): bool {
                    try {
                        if (!$app['kwtsms.order_diagnostics']->active()) {
                            return false;
                        }
                        $rows = NotificationSettingsExtender::storedRows();

                        return !empty($rows[LowStockCollector::EVENT_ID][$receiver]);
                    } catch (\Throwable $e) {
                        return false;
                    }
                },
                static function (string $reason, array $product, string $receiver) use ($app): void {
                    $app['kwtsms.order_diagnostics']->recordOutcome(
                        OrderNotifier::DUPLICATE,
                        $reason,
                        ['company_id' => (int) ($product['company_id'] ?? 0)],
                        LowStockCollector::EVENT_ID,
                        $receiver,
                        $receiver === 'V' ? [] : null
                    );
                },
                $app['kwtsms.checkout_rules']->isMultiVendor(),
                null,
                null,
                $dispatch,
                // 2026-10-22 23:55 +03: Task A8 fix round 1 (M2), task 596. Approved number and quota room, checked
                // before a key is claimed.
                static function (int $company_id) use ($app): bool {
                    return $app['kwtsms.vendor_phones']->approvedPhone($company_id) !== ''
                        && $app['kwtsms.vendor_quota']->allows($company_id, 1);
                }
            );
        };
        // 2026-10-22 19:09 +03: Task A6 (registration alert, P2-7), task 596. The update_profile handler: dispatches
        // kwtsms.customer_registered through event.dispatcher with the stored switches (default rules) and its own
        // event id kwtsms.customer_registered.<user_id>.
        $app['kwtsms.profile_hooks'] = function (Container $app): ProfileHooks {
            return new ProfileHooks(
                static function (string $event_id, array $data, string $provider_id) use ($app): void {
                    $provider = new class ($provider_id) implements IProvider {
                        /** @var string */
                        private $id;

                        public function __construct(string $id)
                        {
                            $this->id = $id;
                        }

                        public function getId()
                        {
                            return $this->id;
                        }
                    };
                    $app['event.dispatcher']->dispatch($event_id, $data, null, $provider);
                },
                static function () use ($app): int {
                    $storefront = $app['storefront'] ?? null;

                    return is_object($storefront) && isset($storefront->storefront_id)
                        ? (int) $storefront->storefront_id : 0;
                },
                static function (): string {
                    return defined('AREA') ? (string) AREA : '';
                },
                // 2026-10-23 00:54 +03: Task A10 (SafatMall F6), task 596. The opt-out storage and the logged-in user
                // of the request.
                $app['kwtsms.customer_prefs'],
                static function () use ($app): int {
                    $session = $app['session'] ?? null;

                    return is_object($session) || is_array($session) ? (int) ($session['auth']['user_id'] ?? 0) : 0;
                },
                // 2026-10-24 17:18 +03: Task M1.6 (P3-33), task 596. The phone index, the code checks, the store
                // default
                // calling code and the request facts of the code-check context.
                $app['kwtsms.phone_index'],
                $app['kwtsms.code_checks'],
                [$app['kwtsms.config'], 'defaultCountryCode'],
                static function () use ($app): array {
                    $session = $app['session'] ?? null;
                    $sid = is_object($session) && method_exists($session, 'getID') ? (string) $session->getID() : '';

                    return [
                        'session_hash' => $sid === '' ? '' : hash('sha256', $sid),
                        'ip' => \Tygh\Addons\Kwtsms\Security\ClientIp::current(),
                        'lang' => defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : '',
                    ];
                },
                // 2026-10-24 18:46 +03: M1.6 fix round 1 (I2), task 596. The saved account as the database holds it
                // (the same source as the backfill: users.phone, the primary profile's b_phone and b_country).
                static function (int $user_id): ?array {
                    $row = db_get_row(
                        'SELECT u.user_type, u.phone, p.b_phone, p.b_country FROM ?:users u'
                        . ' LEFT JOIN ?:user_profiles p ON p.user_id = u.user_id AND p.profile_type = ?s'
                        . ' WHERE u.user_id = ?i',
                        'P',
                        $user_id
                    );

                    return is_array($row) && $row !== [] ? $row : null;
                },
                null, // $nested: the backtrace test of ProfileHooks
                // 2026-10-25 04:37 +03: Task M1.9 (C3, C4; P3-21), task 596. The phone checks of the forms and of
                // checkout: the settings, the rule, the lock and the stored account as the database holds it before
                // the save (users.phone and the edited profile's b_phone, s_phone and countries; no profile id = the
                // primary profile).
                $app['kwtsms.config'],
                $app['kwtsms.phone_rules'],
                $app['kwtsms.phone_lock'],
                static function (int $user_id, int $profile_id): ?array {
                    $row = db_get_row(
                        // 2026-10-25 07:12 +03: M1.9 fix round 1 (I1), task 596: profile_type for the
                        // checkout profile editor.
                        'SELECT u.user_type, u.phone, p.b_phone, p.s_phone, p.b_country, p.s_country, p.profile_type'
                        . ' FROM ?:users u'
                        . ' LEFT JOIN ?:user_profiles p ON p.user_id = u.user_id AND '
                        . ($profile_id > 0 ? 'p.profile_id = ?i' : 'p.profile_type = ?s')
                        . ' WHERE u.user_id = ?i',
                        $profile_id > 0 ? $profile_id : 'P',
                        $user_id
                    );

                    return is_array($row) && $row !== [] ? $row : null;
                }
            );
        };
    }

    /**
     * True in a Multi-Vendor store (false outside CS-Cart, as in the unit suites). 2026-10-22 17:14 +03: Task A4,
     * task 596.
     */
    private static function isMultiVendor(): bool
    {
        return function_exists('fn_allowed_for') && fn_allowed_for('MULTIVENDOR');
    }

    /**
     * Attaches the SMS transport to the merged events schema and makes its switches fail-closed (PRD 4.1), by
     * extending core's event.events_schema and event.notification_settings. Guarded: when a service was already
     * built in this request (another add-on read it during its own init) or Pimple refuses the extension, the
     * extension is skipped and an error is logged, never thrown (the store keeps working, without SMS columns).
     * 2026-10-21 01:58 +03: added, Task 1.9, task 596.
     * 2026-10-21 04:21 +03: a tripped guard also raises ONE admin notice (AdminAlerts sms_not_attached, at most
     * once a day) after the response, when every service works; $later runs it (null = register_shutdown_function).
     * Task 1.9 fix round 1 item 5, task 596.
     *
     * @param Container     $app   Tygh::$app (Tygh\Application)
     * @param callable|null $later (callable $job): void, runs $job after the response
     */
    public static function extendNotifications(Container $app, ?callable $later = null): void
    {
        $extensions = [
            'event.events_schema' => static function ($schema, Container $app) {
                try {
                    $extender = new EventsSchemaExtender($app['kwtsms.declared_events'], self::isMultiVendor());

                    return $extender->extend((array) $schema);
                } catch (\Throwable $e) {
                    self::logError('events schema extension failed', $e);

                    return $schema;
                }
            },
            'event.notification_settings' => static function ($settings, Container $app) {
                try {
                    $stored = NotificationSettingsExtender::storedRows();

                    return (new NotificationSettingsExtender())->extend((array) $settings, $stored);
                } catch (\Throwable $e) {
                    self::logError('notification settings extension failed, every SMS switch is off', $e);

                    return NotificationSettingsExtender::allOff((array) $settings);
                }
            },
        ];
        $skipped = [];
        foreach ($extensions as $id => $extension) {
            try {
                if (method_exists($app, 'hasInstance') && $app->hasInstance($id)) {
                    error_log('kwtsms: ' . $id . ' was built before the add-on could extend it;'
                        . ' SMS is not attached in this request');
                    $skipped[] = $id;
                    continue;
                }
                $app->extend($id, $extension);
            } catch (FrozenServiceException $e) {
                self::logError($id . ' is frozen; SMS is not attached in this request', $e);
                $skipped[] = $id;
            } catch (\Throwable $e) {
                self::logError($id . ' could not be extended', $e);
                $skipped[] = $id;
            }
        }
        if ($skipped !== []) {
            self::noticeLater($app, $skipped, $later);
        }
    }

    /**
     * Raises the admin notice sms_not_attached (throttled by AdminAlerts to one a day) after the response; never
     * throws. 2026-10-21 04:21 +03: added, Task 1.9 fix round 1 item 5, task 596.
     *
     * @param string[]      $skipped The services that were not extended
     * @param callable|null $later   (callable $job): void; null = register_shutdown_function
     */
    private static function noticeLater(Container $app, array $skipped, ?callable $later): void
    {
        $job = static function () use ($app, $skipped): void {
            try {
                if (isset($app['kwtsms.alerts'])) {
                    $app['kwtsms.alerts']->raise('sms_not_attached', ['[service]' => implode(', ', $skipped)]);
                }
            } catch (\Throwable $e) {
                self::logError('the sms_not_attached notice failed', $e);
            }
        };
        try {
            if ($later !== null) {
                call_user_func($later, $job);
            } else {
                register_shutdown_function($job);
            }
        } catch (\Throwable $e) {
            self::logError('the sms_not_attached notice could not be scheduled', $e);
        }
    }

    /**
     * 2026-10-21 01:58 +03: added, Task 1.9, task 596.
     */
    private static function logError(string $what, \Throwable $e): void
    {
        error_log('kwtsms: ' . $what . ': ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
