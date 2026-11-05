<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/TwoFactorCli.php
 * CREATED:   2026-11-04 22:51 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The command line break-glass of the two-step sign-in (P3-52 (4)): clears one account's 2FA (its row and
 *            backup codes) or sets the switch of a whole type to off, and says what it did, one line per change.
 * WHY:       Task M2.2a (precondition of every login hold, Global constraints "Break-glass first"; M2.2b completes it).
 *            When the SMS gateway or an administrator's phone is lost, the store owner reaches the server and turns the
 *            second step off without the web panel.
 * USAGE:     php admin.php --dispatch=kwtsms_twofa.cli_reset --user_id=<id>
 *            php admin.php --dispatch=kwtsms_twofa.cli_reset --types=admins|vendors|customers
 *            (the controller runs this only when PHP_SAPI is cli and CONSOLE is defined, else it answers 404).
 *            $lines = $cli->run(['user_id' => '12']);  // ['kwtsms_twofa: account 12: 2FA cleared']
 *            Every change writes the debug row twofa.cli_reset (ids and switch names only).
 * DEPENDS:   Settings\ConfigInterface (save update_codes), Auth\TwoFactorStore, Log\DebugLog (optional).
 *
 * 2026-11-05 07:26 +03: Task M2.2b (P3-52 (4)), task 596. status() is the exit status of the last run (0 = every change
 * done, 1 = a
 * usage or error line); an account id that does not exist is one error line and nothing is deleted. The fourth
 * constructor argument is the account lookup (int $user_id): bool, null = the users table.
 */

namespace Tygh\Addons\Kwtsms\Auth;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class TwoFactorCli
{
    private const TYPES = [
        'admins' => 'twofa_admins',
        'vendors' => 'twofa_vendors',
        'customers' => 'twofa_customers',
    ];

    /** @var ConfigInterface */
    private $config;

    /** @var TwoFactorStore */
    private $store;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null (int $user_id): bool */
    private $exists;

    /** @var callable|null 2026-11-05 11:27 +03: Task M2.2b fix 1 (review M3), task 596. (int $user_id): void, after a clear */
    private $on_reset;

    /** @var int */
    private $status = 0;

    public function __construct(
        ConfigInterface $config,
        TwoFactorStore $store,
        ?DebugLog $log = null,
        ?callable $exists = null,
        ?callable $on_reset = null
    ) {
        $this->on_reset = $on_reset;
        $this->config = $config;
        $this->store = $store;
        $this->log = $log;
        $this->exists = $exists;
    }

    /** 2026-11-05 07:26 +03: Task M2.2b, task 596. The exit status of the last run(): 0 = done, 1 = usage or error. */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * @param array<string,mixed> $request The command line options (user_id, types)
     *
     * @return string[] One line per change, or one usage / error line
     */
    public function run(array $request): array
    {
        $lines = [];
        $this->status = 0;
        $user_id = isset($request['user_id']) && is_scalar($request['user_id']) ? (string) $request['user_id'] : '';
        $types = isset($request['types']) && is_scalar($request['types']) ? (string) $request['types'] : '';
        if ($user_id === '' && $types === '') {
            $this->status = 1;

            return ['kwtsms_twofa: nothing to do. Use --user_id=<id> or --types=admins|vendors|customers'];
        }
        if ($user_id !== '') {
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $user_id) !== 1) {
                $lines[] = 'kwtsms_twofa: --user_id must be a positive whole number';
                $this->status = 1;
            } elseif (!$this->accountExists((int) $user_id)) {
                $lines[] = 'kwtsms_twofa: no account ' . $user_id;
                $this->status = 1;
            } else {
                $this->store->delete((int) $user_id);
                $lines[] = 'kwtsms_twofa: account ' . $user_id . ': 2FA cleared';
                $this->debug(['user_id' => (int) $user_id]);
                // 2026-11-05 11:27 +03: Task M2.2b fix 1 (review M3), task 596. The web reset tells the administrators
                // (twofa_reset); the console reset does too. A failing alert never changes the reset or its status.
                if ($this->on_reset !== null) {
                    try {
                        call_user_func($this->on_reset, (int) $user_id);
                    } catch (\Throwable $e) {
                        $this->debug(['user_id' => (int) $user_id, 'alert_failed' => get_class($e)]);
                    }
                }
            }
        }
        if ($types !== '') {
            if (!isset(self::TYPES[$types])) {
                $lines[] = 'kwtsms_twofa: --types must be admins, vendors or customers';
                $this->status = 1;
            } else {
                $key = self::TYPES[$types];
                $rejected = $this->config->save('update_codes', [$key => 'off']);
                $lines[] = $rejected === []
                    ? 'kwtsms_twofa: ' . $key . ' set to off'
                    : 'kwtsms_twofa: ' . $key . ' could not be set to off';
                $this->status = $rejected === [] ? $this->status : 1;
                $this->debug(['switch' => $key, 'saved' => $rejected === []]);
            }
        }

        return $lines;
    }

    private function accountExists(int $user_id): bool
    {
        if ($this->exists !== null) {
            return (bool) call_user_func($this->exists, $user_id);
        }

        return (int) db_get_field('SELECT COUNT(*) FROM ?:users WHERE user_id = ?i', $user_id) > 0;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function debug(array $context): void
    {
        if ($this->log !== null) {
            $this->log->log('info', 'twofa.cli_reset', 'twofa.cli_reset', $context);
        }
    }
}
