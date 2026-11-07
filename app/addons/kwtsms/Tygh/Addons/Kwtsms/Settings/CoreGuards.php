<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Settings/CoreGuards.php
 * CREATED:   2026-11-07 23:55 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Feature-detection probes of the running CS-Cart core: can the core enforce what a security feature of the
 *            add-on promises? (G1: the hold of the second sign-in step; later guards: recovery keys, the checkout
 *            hooks, the theme template anchors.) No version number is ever compared; each probe looks at the
 *            functions, signatures and hook calls the add-on depends on.
 * WHY:       M2.10 static compatibility check (CS-Cart 4.20.1, official sources only, no 4.20.1 core to read). A core
 *            that does not fire login_user_pre by reference would let every non-password sign-in skip the second
 *            step without a trace. The add-on refuses to turn such a feature ON instead of failing open at login.
 *            Probes run on the Settings page (render and save), never on a customer request.
 * USAGE:     $guards = new CoreGuards();  $guards->twofaEnforceable();
 *            Tests subclass it and replace the protected seams (functionExists(), passesByReference(), ...).
 * DEPENDS:   PHP Reflection; CS-Cart core functions only as probe targets (fn_login_user, fn_set_hook, ...).
 */

namespace Tygh\Addons\Kwtsms\Settings;

class CoreGuards
{
    /**
     * 2026-11-07 23:55 +03: M2.10 G1, task 596. True when the core can enforce the second sign-in step: fn_login_user()
     * calls the hook login_user_pre, fn_set_hook() passes its arguments by reference (so $user_id = 0 reaches the
     * caller), and the two functions of the password-login mirror exist. "Cannot tell" (no core loaded, source
     * unreadable) is true: it is not "missing".
     */
    public function twofaEnforceable(): bool
    {
        if (!$this->functionExists('fn_login_user')) {
            return true;
        }
        if ($this->hookFires('fn_login_user', 'login_user_pre') === false) {
            return false;
        }
        if ($this->passesByReference('fn_set_hook') === false) {
            return false;
        }

        return $this->functionExists('fn_auth_routines') && $this->functionExists('fn_user_password_verify');
    }

    /** Whether a function exists (test seam). */
    protected function functionExists(string $name): bool
    {
        return function_exists($name);
    }

    /**
     * Whether the body of $function calls fn_set_hook() with $hook. Null = cannot tell (function or source missing).
     */
    protected function hookFires(string $function, string $hook): ?bool
    {
        $source = $this->functionSource($function);
        if ($source === null) {
            return null;
        }

        return strpos($source, "'" . $hook . "'") !== false || strpos($source, '"' . $hook . '"') !== false;
    }

    /** The source text of a function body (test seam); null when it cannot be read. */
    protected function functionSource(string $function): ?string
    {
        try {
            $r = new \ReflectionFunction($function);
            $file = (string) $r->getFileName();
            if ($file === '' || !is_readable($file)) {
                return null;
            }
            $first = (int) $r->getStartLine();
            $lines = array_slice((array) file($file), $first - 1, (int) $r->getEndLine() - $first + 1);

            return implode('', $lines);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether the last parameter of $function is passed by reference (fn_set_hook(string $hook, &...$args)). Null =
     * cannot tell.
     */
    protected function passesByReference(string $function): ?bool
    {
        try {
            $params = (new \ReflectionFunction($function))->getParameters();
        } catch (\Throwable $e) {
            return null;
        }
        if ($params === []) {
            return false;
        }

        return end($params)->isPassedByReference();
    }
}
