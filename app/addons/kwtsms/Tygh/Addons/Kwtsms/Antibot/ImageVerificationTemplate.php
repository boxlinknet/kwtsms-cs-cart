<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Antibot/ImageVerificationTemplate.php
 * CREATED:   2026-10-24 21:13 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Pure helper for the template map of core's add-on template overrides (fn_addon_template_overrides()): it
 *            points the override of the reCAPTCHA add-on for common/image_verification.tpl at the add-on's own
 *            template instead. Nothing else in the map changes.
 * WHY:       Task M1.7b (ruling P3-R7). The reCAPTCHA add-on is active on every stock install without keys, and its
 *            whole-file override renders a native image CAPTCHA (and never reaches the hook common:image_verification)
 *            for any driver that is not its own. Core takes the first override in add-on priority order (recaptcha
 *            1500, kwtSMS 4000), so ours cannot win by the normal route. While Turnstile is the active driver, the
 *            map entry is swapped, in memory for the request, for a template that renders Turnstile and falls back
 *            to the reCAPTCHA template for any other driver.
 * USAGE:     $map = ImageVerificationTemplate::prefer($map, 'file_exists');   // map: md5 hash => absolute path
 *            func.php fn_kwtsms_init_templater_post() reads and writes the map through core's Registry.
 * DEPENDS:   Nothing (a callable for file_exists, so a unit test needs no files).
 */

namespace Tygh\Addons\Kwtsms\Antibot;

class ImageVerificationTemplate
{
    /** Tail of the path of the reCAPTCHA add-on's override, relative to a theme's templates directory. */
    public const THEIRS = 'addons/recaptcha/overrides/common/image_verification.tpl';

    /** Tail of the path of our template, relative to a theme's templates directory. */
    public const OURS = 'addons/kwtsms/components/turnstile_image_verification.tpl';

    /**
     * Replaces every map entry that points at the reCAPTCHA override with our template, when that file exists beside
     * it in the same theme or in the base theme `responsive`; an entry whose replacement cannot be found stays
     * as it is (core's own behaviour).
     *
     * @param array<string,mixed> $map        Core's override map: md5 hash => absolute path (or another value)
     * @param callable            $file_exists (string $path): bool
     *
     * @return array<string,mixed>
     */
    public static function prefer(array $map, callable $file_exists): array
    {
        foreach ($map as $hash => $path) {
            if (!is_string($path) || substr($path, -strlen(self::THEIRS)) !== self::THEIRS) {
                continue;
            }
            $root = substr($path, 0, -strlen(self::THEIRS));
            $candidates = [$root . self::OURS];
            // A child theme has no copy of the add-on's templates: the base theme's copy serves.
            $base = (string) preg_replace(
                '#/design/themes/[^/]+/templates/$#',
                '/design/themes/responsive/templates/',
                $root
            );
            if ($base !== $root) {
                $candidates[] = $base . self::OURS;
            }
            foreach ($candidates as $candidate) {
                if (call_user_func($file_exists, $candidate)) {
                    $map[$hash] = $candidate;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * 2026-10-24 22:56 +03: Task M1.7b fix round 1 (review I1), task 596. True while any entry of the map still points
     * at the reCAPTCHA add-on's override, that is, while a swap that was meant to happen did not.
     *
     * @param array<string,mixed> $map
     */
    public static function stillTheirs(array $map): bool
    {
        foreach ($map as $path) {
            if (is_string($path) && substr($path, -strlen(self::THEIRS)) === self::THEIRS) {
                return true;
            }
        }

        return false;
    }
}
