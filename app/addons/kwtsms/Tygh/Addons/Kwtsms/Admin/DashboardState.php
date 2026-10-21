<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/DashboardState.php
 * CREATED:   2026-10-21 05:15 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The two small pieces of Dashboard state that live in storage: the time the add-on first saw the cron
 *            missing while SMS were on ("cron watch"), and the "Mark as done" flags of the optional setup steps.
 * WHY:       Task 1.11 fix round 1. (1) A cron that never ran was dated from the last sync, but the Dashboard's own
 *            fallback sync touches that time, so a dead cron was never reported. The watch time is written once and no
 *            sync, login or send ever touches it. (2) Ruling R36: the checklist must be completable. Default country,
 *            Brand name and Review SMS switches are things only the merchant can judge, so the merchant marks them
 *            done; the mark is kept per step.
 * USAGE:     $state = new DashboardState($app['kwtsms.storage']);
 *            $state->startCronWatch();             // once: first time SMS are on and no cron run is known
 *            $state->cronWatchSince();             // Unix time or null
 *            $state->mark('brand');                // true for a step that may be marked, false for any other id
 *            $state->marks();                      // ['brand' => 1792000000, ...]
 *            Storage key kwtsms_dashboard (JSON {"cron_watch": <unix>, "steps": {"<id>": <unix>}}); a read that is not
 *            valid JSON counts as empty.
 * DEPENDS:   Storage\KeyValueStoreInterface.
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Storage\KeyValueStoreInterface;

class DashboardState
{
    public const KEY = 'kwtsms_dashboard';

    /** The steps a merchant may mark as done: only the merchant can judge them (ruling R36). */
    public const MARKABLE = ['country', 'brand', 'switches'];

    /** @var KeyValueStoreInterface */
    private $store;

    /** @var callable|null */
    private $clock;

    /**
     * @param callable|null $clock Returns the current Unix time (tests); null = time()
     */
    public function __construct(KeyValueStoreInterface $store, ?callable $clock = null)
    {
        $this->store = $store;
        $this->clock = $clock;
    }

    /** When the add-on first saw the cron missing with SMS on (or when SMS were last switched on), or null. */
    public function cronWatchSince(): ?int
    {
        $at = $this->read()['cron_watch'] ?? null;

        return is_int($at) && $at > 0 ? $at : null;
    }

    /**
     * Writes the cron watch time. Without $restart an existing time is kept (written once); with it the time is set
     * again, for the moment SMS are switched on.
     *
     * @return int The watch time now stored
     */
    public function startCronWatch(bool $restart = false): int
    {
        $data = $this->read();
        $now = $this->now();
        if (!$restart && isset($data['cron_watch']) && is_int($data['cron_watch']) && $data['cron_watch'] > 0) {
            return $data['cron_watch'];
        }
        $data['cron_watch'] = $now;
        $this->write($data);

        return $now;
    }

    public function isMarked(string $step): bool
    {
        $steps = $this->read()['steps'] ?? [];

        return is_array($steps) && isset($steps[$step]);
    }

    /**
     * Marks an optional step as done.
     *
     * @return bool False for a step that can't be marked (nothing is written)
     */
    public function mark(string $step): bool
    {
        if (!in_array($step, self::MARKABLE, true)) {
            return false;
        }
        $data = $this->read();
        $steps = isset($data['steps']) && is_array($data['steps']) ? $data['steps'] : [];
        if (!isset($steps[$step])) {
            $steps[$step] = $this->now();
            $data['steps'] = $steps;
            $this->write($data);
        }

        return true;
    }

    /**
     * @return array<string,int> step id => time it was marked
     */
    public function marks(): array
    {
        $steps = $this->read()['steps'] ?? [];
        $out = [];
        if (is_array($steps)) {
            foreach (self::MARKABLE as $id) {
                if (isset($steps[$id]) && is_int($steps[$id])) {
                    $out[$id] = $steps[$id];
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function read(): array
    {
        $data = json_decode((string) $this->store->getFresh(self::KEY), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function write(array $data): void
    {
        $this->store->set(self::KEY, (string) json_encode($data), true);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }
}
