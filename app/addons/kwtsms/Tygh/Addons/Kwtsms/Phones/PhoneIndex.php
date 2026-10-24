<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Phones/PhoneIndex.php
 * CREATED:   2026-10-24 17:11 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The phone index: one row per customer account in kwtsms_phone_index holding the canonical phone (P3-27)
 *            and, only while it is verified, the same value in the unique column verified_phone. Answers "who owns
 *            this phone" with one indexed SELECT.
 * WHY:       Task M1.6 (SafatMall C4 part, P3-11, P3-31, P3-33). core keeps phones as free text in users.phone, which
 *            cannot be searched in canonical form. C4 (one account per number), B1 and B5 (verified number) need an
 *            index; the unique column makes "one verified owner per number" a database fact, not a PHP race.
 * USAGE:     $index = Tygh::$app['kwtsms.phone_index'];
 *            $index->active();                       // any of phone_unique, register_code, phone_change_code is ON
 *            $index->upsert($user_id, '+96550123401', false);   // the ONE writer: ProfileHooks (P3-33)
 *            $index->ownerOf('+96550123401', $user_id);         // another account's id, or 0 ('' is always 0)
 *            $index->isVerified($user_id, '+96550123401');
 *            $index->delete($user_id);
 *            $index->enforcing();                    // phone_unique is ON and the backfill is done (P3-31)
 *            upsert() keeps a verified number verified when the saved phone is the same; a different phone is
 *            stored unverified. A verified claim that another account already holds (duplicate on verified_phone)
 *            stores the row unverified and writes a warning (no phone in it). Test seams (protected): the five
 *            database touches writeRow(), claimVerified(), readOwner(), readVerified(), removeRow().
 * DEPENDS:   Table kwtsms_phone_index (PK user_id, KEY phone, UNIQUE verified_phone); Settings\ConfigInterface;
 *            Storage\KeyValueStoreInterface (the backfill state); Log\DebugLog (optional).
 */

namespace Tygh\Addons\Kwtsms\Phones;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Storage\KeyValueStoreInterface;

class PhoneIndex
{
    /** @var ConfigInterface */
    private $config;

    /** @var KeyValueStoreInterface|null */
    private $store;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null */
    private $clock;

    public function __construct(
        ConfigInterface $config,
        ?KeyValueStoreInterface $store = null,
        ?DebugLog $log = null,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->store = $store;
        $this->log = $log;
        $this->clock = $clock;
    }

    /** True when any of the three features that need the index is ON (P3-31). */
    public static function anyFeatureOn(ConfigInterface $config): bool
    {
        return $config->phoneUnique() || $config->registerCode() || $config->phoneChangeCode();
    }

    public function active(): bool
    {
        return self::anyFeatureOn($this->config);
    }

    /**
     * Writes the account's row. $phone is the canonical form ('' = the account has no valid phone). A same-phone
     * save keeps an existing verification; a changed phone is stored unverified. $verified = true tries to claim
     * verified_phone; when another account holds it the row stays unverified and a warning is written.
     */
    public function upsert(int $user_id, string $phone, bool $verified): void
    {
        if ($user_id <= 0) {
            return;
        }
        $now = $this->now();
        $this->writeRow($user_id, $phone, $now);
        if (!$verified || $phone === '') {
            return;
        }
        if ($this->claimVerified($user_id, $phone, $now)) {
            return;
        }
        // The unique key refused: another account holds this number as its verified one. The row stays unverified.
        $this->warn('phone_index.verified_conflict', [
            'user_id' => $user_id,
            'owner_user_id' => $this->readOwner($phone, $user_id),
        ]);
    }

    /** The id of another account that has this phone indexed, or 0 ('' is nobody's). One indexed SELECT. */
    public function ownerOf(string $phone, int $except_user_id): int
    {
        if ($phone === '') {
            return 0;
        }

        return $this->readOwner($phone, $except_user_id);
    }

    /** True when the account's verified number is exactly $phone. */
    public function isVerified(int $user_id, string $phone): bool
    {
        return $user_id > 0 && $phone !== '' && $this->readVerified($user_id) === $phone;
    }

    public function delete(int $user_id): void
    {
        if ($user_id > 0) {
            $this->removeRow($user_id);
        }
    }

    /** C4 refuses a number only when phone_unique is ON and every existing phone is indexed (P3-31). */
    public function enforcing(): bool
    {
        if ($this->store === null || !$this->config->phoneUnique()) {
            return false;
        }

        return PhoneIndexBackfill::parseState($this->store->getFresh(PhoneIndexBackfill::KEY))['done'] === 1;
    }

    // ------------------------------------------------------------------ the database (replaced by tests)

    /**
     * Insert or refresh the row. The assignments run left to right, so verified_phone and verified_at still see the
     * old phone when they compare it with the new one.
     */
    protected function writeRow(int $user_id, string $phone, int $now): void
    {
        db_query(
            'INSERT INTO ?:kwtsms_phone_index (user_id, phone, verified_phone, verified_at, updated_at)'
            . ' VALUES (?i, ?s, NULL, 0, ?i)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' verified_phone = IF(phone = VALUES(phone), verified_phone, NULL),'
            . ' verified_at = IF(phone = VALUES(phone), verified_at, 0),'
            . ' phone = VALUES(phone), updated_at = VALUES(updated_at)',
            $user_id,
            $phone,
            $now
        );
    }

    /**
     * UPDATE IGNORE turns a duplicate on the unique key into "0 rows changed" instead of a database error.
     * 0 rows is also the answer for a number that was already this account's verified one, which is a success.
     */
    protected function claimVerified(int $user_id, string $phone, int $now): bool
    {
        $changed = (int) db_query(
            'UPDATE IGNORE ?:kwtsms_phone_index SET verified_phone = ?s, verified_at = ?i WHERE user_id = ?i',
            $phone,
            $now,
            $user_id
        );

        return $changed > 0 || $this->readVerified($user_id) === $phone;
    }

    protected function readOwner(string $phone, int $except_user_id): int
    {
        return (int) db_get_field(
            'SELECT user_id FROM ?:kwtsms_phone_index WHERE phone = ?s AND user_id <> ?i ORDER BY user_id LIMIT 1',
            $phone,
            $except_user_id
        );
    }

    protected function readVerified(int $user_id): string
    {
        return (string) db_get_field('SELECT verified_phone FROM ?:kwtsms_phone_index WHERE user_id = ?i', $user_id);
    }

    protected function removeRow(int $user_id): void
    {
        db_query('DELETE FROM ?:kwtsms_phone_index WHERE user_id = ?i', $user_id);
    }

    // ------------------------------------------------------------------ helpers

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function warn(string $action, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log('warning', $action, $action, $context);
        }
    }
}
