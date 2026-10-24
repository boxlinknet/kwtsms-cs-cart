<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Profiles/NoCodeCheck.php
 * CREATED:   2026-10-24 17:12 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The code check that checks nothing: check() lets every save go on, afterSave() verifies no phone.
 * WHY:       Task M1.6 (P3-33). ProfileHooks needs a CodeCheckInterface from this task on; the real checks arrive in
 *            M1.9 to M1.10b. Until then every saved phone is indexed unverified.
 * USAGE:     new NoCodeCheck();   wired as 'kwtsms.code_checks' in ServiceProvider.
 * DEPENDS:   Profiles\CodeCheckInterface.
 */

namespace Tygh\Addons\Kwtsms\Profiles;

final class NoCodeCheck implements CodeCheckInterface
{
    public function check(array $ctx, array &$user_data): ?string
    {
        return null;
    }

    public function afterSave(array $ctx, array $user_data): string
    {
        return '';
    }
}
