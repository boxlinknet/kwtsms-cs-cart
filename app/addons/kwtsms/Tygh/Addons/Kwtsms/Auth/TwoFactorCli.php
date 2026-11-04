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

    public function __construct(ConfigInterface $config, TwoFactorStore $store, ?DebugLog $log = null)
    {
        $this->config = $config;
        $this->store = $store;
        $this->log = $log;
    }

    /**
     * @param array<string,mixed> $request The command line options (user_id, types)
     *
     * @return string[] One line per change, or one usage / error line
     */
    public function run(array $request): array
    {
        $lines = [];
        $user_id = isset($request['user_id']) && is_scalar($request['user_id']) ? (string) $request['user_id'] : '';
        $types = isset($request['types']) && is_scalar($request['types']) ? (string) $request['types'] : '';
        if ($user_id === '' && $types === '') {
            return ['kwtsms_twofa: nothing to do. Use --user_id=<id> or --types=admins|vendors|customers'];
        }
        if ($user_id !== '') {
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $user_id) !== 1) {
                $lines[] = 'kwtsms_twofa: --user_id must be a positive whole number';
            } else {
                $this->store->delete((int) $user_id);
                $lines[] = 'kwtsms_twofa: account ' . $user_id . ': 2FA cleared';
                $this->debug(['user_id' => (int) $user_id]);
            }
        }
        if ($types !== '') {
            if (!isset(self::TYPES[$types])) {
                $lines[] = 'kwtsms_twofa: --types must be admins, vendors or customers';
            } else {
                $key = self::TYPES[$types];
                $rejected = $this->config->save('update_codes', [$key => 'off']);
                $lines[] = $rejected === []
                    ? 'kwtsms_twofa: ' . $key . ' set to off'
                    : 'kwtsms_twofa: ' . $key . ' could not be set to off';
                $this->debug(['switch' => $key, 'saved' => $rejected === []]);
            }
        }

        return $lines;
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
