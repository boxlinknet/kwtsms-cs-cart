<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Profiles/CodeCheckInterface.php
 * CREATED:   2026-10-24 17:12 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The seam between the profile hooks and the SMS code checks of registration (B1) and phone change (B5).
 *            check() decides before a save whether the posted phone needs a verified code; afterSave() reports, after
 *            the save, which phone the saved account has now verified.
 * WHY:       Task M1.6 (P3-33). ProfileHooks::onUpdateProfile() is the only writer of kwtsms_phone_index; the code
 *            checks of M1.9, M1.10a and M1.10b never write the index themselves, they only tell it, through
 *            afterSave(), which phone was verified in this save.
 * USAGE:     $ctx = ['action' => 'add'|'update', 'user_id' => int, 'stored' => string (the stored account phone),
 *                    'session_hash' => string, 'ip' => string, 'storefront_id' => int, 'lang' => string];
 *            $refusal = $checks->check($ctx, $user_data);    // null = let the save go on; text = refuse, nothing saved
 *            $verified = $checks->afterSave($ctx, $user_data);   // the canonical phone verified in this save, or ''
 * DEPENDS:   Nothing. Implementations: Profiles\NoCodeCheck (M1.6), the real checks arrive in M1.9 to M1.10b.
 */

namespace Tygh\Addons\Kwtsms\Profiles;

interface CodeCheckInterface
{
    /**
     * @param array<string,mixed> $ctx       action, user_id, stored, session_hash, ip, storefront_id, lang
     * @param array<string,mixed> $user_data The posted user data (may be changed by reference)
     *
     * @return string|null null = the save may go on; a text = the reason it is refused
     */
    public function check(array $ctx, array &$user_data): ?string;

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $user_data The saved user data
     *
     * @return string The canonical phone this save verified, or ''
     */
    public function afterSave(array $ctx, array $user_data): string;
}
