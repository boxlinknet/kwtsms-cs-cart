<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Profiles/CodeChecks.php
 * CREATED:   2026-10-28 11:06 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The composite of the enabled SMS code checks (registration B1 now, phone change B5 in Task M1.10b).
 *            check() asks each check in order and returns the first refusal; afterSave() returns the first phone
 *            a check verified.
 * WHY:       Task M1.10a. ProfileHooks holds ONE CodeCheckInterface; B1 and B5 both live behind it.
 * USAGE:     new CodeChecks([$registration_code, ...]); active() is true while at least one check is switched ON, so
 *            ProfileHooks reads the stored account only when a check has work to do.
 * DEPENDS:   Profiles\CodeCheckInterface; checks that also have enabled(): bool (RegistrationCode).
 */

namespace Tygh\Addons\Kwtsms\Profiles;

final class CodeChecks implements CodeCheckInterface
{
    /** @var CodeCheckInterface[] */
    private $checks;

    /**
     * @param CodeCheckInterface[] $checks
     */
    public function __construct(array $checks)
    {
        $this->checks = array_values($checks);
    }

    /** True when at least one check is switched ON (a check without enabled() counts as ON). */
    public function active(): bool
    {
        foreach ($this->checks as $check) {
            if (!method_exists($check, 'enabled') || $check->enabled()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 2026-10-28 18:50 +03: Task M1.10a fix round 1 (review I3), task 596. Frees what the checks hold (phone locks).
     */
    public function release(): void
    {
        foreach ($this->checks as $check) {
            if (method_exists($check, 'release')) {
                $check->release();
            }
        }
    }

    /**
     * 2026-10-28 20:25 +03: Task M1.10b (B5), task 596. The refusal of the checkout profile editor (no code field
     * there): the first check that has one and refuses.
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $user_data
     */
    public function editorRefusal(array $ctx, array $user_data): ?string
    {
        foreach ($this->checks as $check) {
            if (method_exists($check, 'editorRefusal')) {
                $refusal = $check->editorRefusal($ctx, $user_data);
                if ($refusal !== null) {
                    return $refusal;
                }
            }
        }

        return null;
    }

    public function check(array $ctx, array &$user_data): ?string
    {
        foreach ($this->checks as $check) {
            $refusal = $check->check($ctx, $user_data);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    public function afterSave(array $ctx, array $user_data): string
    {
        foreach ($this->checks as $check) {
            $phone = $check->afterSave($ctx, $user_data);
            if ($phone !== '') {
                return $phone;
            }
        }

        return '';
    }
}
