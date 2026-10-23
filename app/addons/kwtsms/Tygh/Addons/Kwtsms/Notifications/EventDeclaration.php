<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Notifications/EventDeclaration.php
 * CREATED:   2026-10-22 17:11 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      One validated declaration of schemas/kwtsms/events.php, format v2 (PRD 5.2): receivers (C, A, V; V only in
 *            Multi-Vendor), placeholders, the dotted data paths (phone, country, lang, storefront, user_id,
 *            company_id, order), dedupe (key template, window, reason), secret placeholder names, opt_out, group and
 *            data_class.
 * WHY:       Phase 2 needs events that carry their own data (shipments, registrations, low stock). The 1.0.0 format
 *            (receivers + placeholders + phone) stays valid; every v2 key is optional and a wrong one is dropped, never
 *            fatal (a third-party declaration must not break the store).
 * USAGE:     $decl = EventDeclaration::fromArray('acme.shipped', $array, $is_mve);   // null = not usable
 *            $decl->dedupeKey($data, 'C');   // 'shipment:7:C'; '' = no dedupe or a path that is missing
 * DEPENDS:   Nothing (pure PHP 7.4).
 */

namespace Tygh\Addons\Kwtsms\Notifications;

final class EventDeclaration
{
    /** A dotted path into the event data. */
    public const PATH = '/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/D';

    /** The path-valued keys of the format, besides placeholders. */
    public const PATH_KEYS = ['phone', 'country', 'lang', 'storefront', 'user_id', 'company_id', 'order'];

    /** The path of the order array when a declaration names none. */
    public const DEFAULT_ORDER_PATH = 'order_info';

    /** @var string */
    private $event_id;

    /** @var string[] */
    private $receivers;

    /** @var array<string,string> name => dotted path */
    private $placeholders;

    /** @var array<string,string> key => dotted path */
    private $paths;

    /** @var string */
    private $dedupe_key;

    /** @var int */
    private $dedupe_window;

    /** @var string */
    private $dedupe_reason;

    /** @var string[] */
    private $secret;

    /** @var bool */
    private $opt_out;

    /** @var string */
    private $group;

    /** @var string */
    private $data_class;

    /** @var string 2026-10-22 20:27 +03: Task A6 fix round 1 (P2-R12), task 596. Antibot scenario a storefront send needs ('' = none). */
    private $public_form;

    /** @var string What the last dedupeKey() call could not resolve ('' = nothing) */
    private $key_error = '';

    /**
     * @param string[]              $receivers
     * @param array<string,string>  $placeholders
     * @param array<string,string>  $paths
     * @param string[]              $masked_names
     */
    private function __construct(
        string $event_id,
        array $receivers,
        array $placeholders,
        array $paths,
        string $dedupe_template,
        int $dedupe_window,
        string $dedupe_reason,
        array $masked_names,
        bool $opt_out,
        string $group,
        string $data_class,
        string $public_form = ''
    ) {
        $this->event_id = $event_id;
        $this->receivers = $receivers;
        $this->placeholders = $placeholders;
        $this->paths = $paths;
        $this->dedupe_key = $dedupe_template;
        $this->dedupe_window = $dedupe_window;
        $this->dedupe_reason = $dedupe_reason;
        $this->secret = $masked_names;
        $this->opt_out = $opt_out;
        $this->group = $group;
        $this->data_class = $data_class;
        $this->public_form = $public_form;
    }

    /**
     * @param array<mixed,mixed> $decl
     */
    public static function fromArray(string $event_id, array $decl, bool $is_mve): ?EventDeclaration
    {
        if ($event_id === '' || !isset($decl['receivers']) || !is_array($decl['receivers'])) {
            return null;
        }
        $receivers = array_values(array_intersect(EventsSchemaExtender::receivers($is_mve), $decl['receivers']));
        if ($receivers === []) {
            return null;
        }

        $placeholders = [];
        if (isset($decl['placeholders']) && is_array($decl['placeholders'])) {
            foreach ($decl['placeholders'] as $name => $path) {
                if (self::validName($name) && is_string($path) && preg_match(self::PATH, $path) === 1) {
                    $placeholders[(string) $name] = $path;
                }
            }
        }
        $paths = [];
        foreach (self::PATH_KEYS as $key) {
            if (isset($decl[$key]) && is_string($decl[$key]) && preg_match(self::PATH, $decl[$key]) === 1) {
                $paths[$key] = $decl[$key];
            }
        }
        if (!isset($paths['order'])) {
            $paths['order'] = self::DEFAULT_ORDER_PATH;
        }

        $key = '';
        $window = 0;
        $reason = '';
        if (isset($decl['dedupe']) && is_array($decl['dedupe']) && isset($decl['dedupe']['key'])) {
            if (is_string($decl['dedupe']['key']) && $decl['dedupe']['key'] !== '') {
                $key = $decl['dedupe']['key'];
                $window = isset($decl['dedupe']['window']) && is_numeric($decl['dedupe']['window'])
                    ? max(0, (int) $decl['dedupe']['window']) : 0;
                // 2026-10-22 17:11 +03: Ruling R5, Task A4, task 596: the attempt reason of a duplicate (A6 uses
                // recover_throttled); default is the key itself.
                $reason = isset($decl['dedupe']['reason']) && is_string($decl['dedupe']['reason'])
                    && preg_match('/^[a-z0-9_]{1,60}$/D', $decl['dedupe']['reason']) === 1
                    ? $decl['dedupe']['reason'] : '';
            }
        }

        $secret = [];
        if (isset($decl['secret']) && is_array($decl['secret'])) {
            foreach ($decl['secret'] as $name) {
                if (is_string($name) && isset($placeholders[$name])) {
                    $secret[$name] = $name;
                }
            }
        }

        $group = isset($decl['group']) && is_string($decl['group'])
            && preg_match('/^[A-Za-z0-9_.]{1,128}$/D', $decl['group']) === 1 ? $decl['group'] : '';
        $data_class = isset($decl['data_class']) && is_string($decl['data_class'])
            && preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $decl['data_class']) === 1
            ? ltrim($decl['data_class'], '\\') : '';

        return new self(
            $event_id,
            $receivers,
            $placeholders,
            $paths,
            $key,
            $window,
            $reason,
            array_values($secret),
            !empty($decl['opt_out']),
            $group,
            $data_class,
            // 2026-10-22 20:27 +03: Task A6 fix round 1 (P2-R12), task 596. `public_form` => antibot scenario (e.g.
            // register).
            isset($decl['public_form']) && is_string($decl['public_form'])
            && preg_match('/^[a-z_]{1,40}$/D', $decl['public_form']) === 1 ? $decl['public_form'] : ''
        );
    }

    public function eventId(): string
    {
        return $this->event_id;
    }

    /**
     * @return string[] C, A, V in that order
     */
    public function receivers(): array
    {
        return $this->receivers;
    }

    /**
     * @return array<string,string> placeholder name => dotted path
     */
    public function placeholders(): array
    {
        return $this->placeholders;
    }

    /**
     * The dotted path of one of PATH_KEYS ('order' defaults to order_info); '' when the declaration names none.
     */
    public function path(string $name): string
    {
        return $this->paths[$name] ?? '';
    }

    /**
     * The dedupe key of one dispatch: {a.b} is replaced by the scalar at that path of $data, {receiver} by the
     * receiver. '' when the declaration has no dedupe or when a token cannot be resolved (then
     * dedupeKeyError() names it, for the error row).
     *
     * @param array<string,mixed> $data
     */
    public function dedupeKey(array $data, string $receiver): string
    {
        $this->key_error = '';
        if ($this->dedupe_key === '') {
            return '';
        }
        $bad = '';
        $key = (string) preg_replace_callback(
            '/\{([^{}]*)\}/',
            function (array $m) use ($data, $receiver, &$bad): string {
                if ($m[1] === 'receiver') {
                    return $receiver;
                }
                $value = preg_match(self::PATH, $m[1]) === 1 ? self::value($data, $m[1]) : null;
                if (!is_scalar($value) || is_bool($value) || (string) $value === '') {
                    $bad = $bad === '' ? $m[1] : $bad;

                    return '';
                }

                return (string) $value;
            },
            $this->dedupe_key
        );
        if ($bad !== '') {
            $this->key_error = $bad;

            return '';
        }
        if (strpos($key, '{') !== false || strpos($key, '}') !== false) {
            $this->key_error = 'brace';

            return '';
        }

        return $key;
    }

    /**
     * The token the last dedupeKey() call could not resolve; '' when it resolved all of them.
     */
    public function dedupeKeyError(): string
    {
        return $this->key_error;
    }

    /**
     * Seconds a dedupe key blocks a repeat; 0 = permanent.
     */
    /**
     * 2026-10-23 03:41 +03: Task A12 item 4 (R53, R24), task 596. The literal start of the dedupe key template up to
     * and including its first colon ("updated:" of "updated:{order_info.order_id}:{receiver}"); '' when the event has
     * no dedupe key or the template does not start with a lower-case word and a colon.
     */
    public function dedupePrefix(): string
    {
        return preg_match('/^[a-z][a-z0-9_]*:/', $this->dedupe_key, $m) === 1 ? $m[0] : '';
    }

    public function dedupeWindow(): int
    {
        return $this->dedupe_window;
    }

    /**
     * The attempt reason of a duplicate; '' = the key itself.
     */
    public function dedupeReason(): string
    {
        return $this->dedupe_reason;
    }

    /**
     * @return string[] placeholder names whose value is masked in every log copy
     */
    public function secretNames(): array
    {
        return $this->secret;
    }

    public function optOut(): bool
    {
        return $this->opt_out;
    }

    /**
     * Language variable of the Templates page heading; '' = the shared "Other events" heading.
     */
    public function group(): string
    {
        return $this->group;
    }

    /**
     * 2026-10-22 20:27 +03: Task A6 fix round 1 (P2-R12), task 596. The core antibot scenario ("register") a send
     * that comes from a
     * storefront request needs; '' = the event is not a public-form event. Sends from the admin area are never gated.
     */
    public function publicForm(): string
    {
        return $this->public_form;
    }

    /**
     * Class name (no leading backslash) implementing DeclaredSmsData; '' = none.
     */
    public function dataClass(): string
    {
        return $this->data_class;
    }

    /**
     * A dotted path in the event data, null when missing.
     *
     * @param array<string,mixed> $data
     *
     * @return mixed|null
     */
    public static function value(array $data, string $path)
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param mixed $name
     */
    private static function validName($name): bool
    {
        return is_string($name) && preg_match('/^[a-z0-9_]{1,40}$/D', $name) === 1;
    }
}
