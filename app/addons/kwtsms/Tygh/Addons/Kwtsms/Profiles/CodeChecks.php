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
