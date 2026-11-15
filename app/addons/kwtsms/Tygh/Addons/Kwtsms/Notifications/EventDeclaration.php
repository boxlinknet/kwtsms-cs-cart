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
 *            2026-11-12 02:02 +03: task 596 M3.0. Format v2 gains 'addon' (the owning add-on, P3-62: the provider drops
 *            the declaration while that add-on is inactive, EventsSchemaExtender::withActiveOwners()) and dedupe
 *            'required' (fail closed when the key does not resolve, P3-64); a declaration that maps any path under
 *            PUBLIC_INPUT_PATHS (review, post, message text, requester name, certificate text or code) is refused
 *            (P3-63), as is 'required' without a key; dedupeKey() resolves the reserved tokens {event} and {hour}.
 * USAGE:     $decl = EventDeclaration::fromArray('acme.shipped', $array, $is_mve);   // null = not usable
 *            $decl->dedupeKey($data, 'C');   // 'shipment:7:C'; '' = no dedupe or a path that is missing
 *            $decl->dedupeKey($data, 'A', 'call_requests.request_created', time());   // {event}, {hour}
 *            EventDeclaration::refusal($array);   // [] or ['reason' => 'public_input', 'path' => 'last_message']
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

    /**
     * 2026-11-12 02:02 +03: task 596 M3.0 (P3-63). Paths of public input (text a storefront visitor typed, or a bearer
     * code) that never become SMS text, a dedupe key or a data path. A declared path is refused when one of these
     * appears in it as whole segments, at any depth ("message" refuses "message", "post_data.message" and
     * "thread.message", not "message_id"): the safe direction for a third-party declaration.
     */
    public const PUBLIC_INPUT_PATHS = [
        'last_message', 'message', 'post_data',
        'product_review_data.message', 'product_review_data.advantages', 'product_review_data.disadvantages',
        'product_review_data.comment',
        'call_request_data.name',
        'certificate_data.message', 'certificate_data.recipient', 'certificate_data.sender',
        'gift_cert_code',
        // 2026-11-12 04:49 +03: task 596 M3.0 fix round 1 (I2). More customer-typed text: the reviewer's name and city,
        // the call request notes, the return comment, and the whole segments comment, notes and reply at any depth. A
        // denylist: each M3 event task adds the free-text fields of its own event.
        'product_review_data.name', 'product_review_data.user_data.name', 'product_review_data.city',
        'call_request_data.notes', 'return_info.comment',
        'comment', 'notes', 'reply',
        // 2026-11-14 17:25 +03: Task M3.5 (B5), task 596. The thread subject and the author name a vendor thread
        // carries (typed by the customer), as whole segments at any depth.
        'subject', 'message_author',
    ];

    /** 2026-11-12 02:02 +03: task 596 M3.0 (P3-62). An add-on id as CS-Cart stores it. */
    public const ADDON_PATTERN = '/^[a-z0-9_]{1,64}$/D';

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

    /**
     * @var string 2026-11-14 21:32 +03: task 596 M3.5 review I1. Vendor cap bucket ('' = none): the V send of this
     * event keeps the 10/day ceiling and the quota reserve, with no antibot effect.
     */
    private $vendor_cap = '';

    /** @var string What the last dedupeKey() call could not resolve ('' = nothing) */
    private $key_error = '';

    /** @var string 2026-11-12 02:02 +03: task 596 M3.0 (P3-62). The owning add-on id; '' = a core or own event. */
    private $addon = '';

    /** @var bool 2026-11-12 02:02 +03: task 596 M3.0 (P3-64). True = an unresolved dedupe key means no SMS. */
    private $required = false;

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
        // 2026-11-12 02:02 +03: task 596 M3.0 (P3-63, P3-64). Public input anywhere, or 'required' without a key: the
        // whole declaration is refused (the provider writes the declaration.refused debug row once per request).
        if (self::refusal($decl) !== []) {
            return null;
        }
        // 2026-10-23 06:01 +03: Cleanup CA1-8 (A4 M8), task 596. Only strings are compared: a nested array in
        // `receivers` made array_intersect() raise "Array to string conversion" on PHP 7.4.
        $receivers = array_values(array_intersect(
            EventsSchemaExtender::receivers($is_mve),
            array_filter($decl['receivers'], 'is_string')
        ));
        // 2026-11-15 01:51 +03: task 596 M3.6 (P3-67). V survives only on an event of the MVE allow-list
        // (EventsSchemaExtender::MVE_VENDOR_EVENTS); on any other event the V receiver is dropped, and a declaration
        // that had only V is not usable.
        if (!EventsSchemaExtender::vendorEvent($event_id)) {
            $receivers = array_values(array_diff($receivers, ['V']));
        }
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

        // 2026-10-23 06:01 +03: Cleanup CA1-8 (A4 M5), task 596. The dedupe key is stored in kwtsms_dispatch_keys and
        // shown as the duplicate reason: a declaration whose key names the path of a secret placeholder (or a child of
        // it) is refused.
        if ($key !== '' && preg_match_all('/\{([^{}]*)\}/', $key, $refs) > 0) {
            foreach ($secret as $name) {
                $masked_path = $placeholders[$name];
                foreach ($refs[1] as $ref) {
                    if ($ref === $masked_path || strpos($ref, $masked_path . '.') === 0) {
                        // 2026-10-23 08:23 +03: Task CA1 fix round 1 (minor 4), task 596. One line saying why.
                        error_log('kwtsms: declaration ' . $event_id . ' refused: its dedupe key uses the path of the'
                            . ' secret placeholder ' . $name . ' (' . $masked_path . ')');

                        return null;
                    }
                }
            }
        }

        $group = isset($decl['group']) && is_string($decl['group'])
            && preg_match('/^[A-Za-z0-9_.]{1,128}$/D', $decl['group']) === 1 ? $decl['group'] : '';
        $data_class = isset($decl['data_class']) && is_string($decl['data_class'])
            && preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $decl['data_class']) === 1
            ? ltrim($decl['data_class'], '\\') : '';

        $parsed = new self(
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
        // 2026-11-12 02:02 +03: task 596 M3.0. The owner (validated; withActiveOwners() drops a malformed one before
        // this) and the fail-closed flag (any truthy value: refusing to send is the safe reading).
        $parsed->addon = isset($decl['addon']) && is_string($decl['addon'])
            && preg_match(self::ADDON_PATTERN, $decl['addon']) === 1 ? $decl['addon'] : '';
        $parsed->required = $key !== '' && !empty($decl['dedupe']['required']);
        // 2026-11-14 21:32 +03: task 596 M3.5 review I1. `vendor_cap` => the bucket name of the V ceiling
        // (a-z and _ only).
        $parsed->vendor_cap = isset($decl['vendor_cap']) && is_string($decl['vendor_cap'])
            && preg_match('/^[a-z_]{1,40}$/D', $decl['vendor_cap']) === 1 ? $decl['vendor_cap'] : '';

        return $parsed;
    }

    /**
     * 2026-11-12 02:02 +03: task 596 M3.0 (P3-63, P3-64). Why a raw declaration is refused: ['reason' =>
     * 'public_input', 'path' => <the declared path>] when a placeholder, a path key or a dedupe key token names a path
     * under PUBLIC_INPUT_PATHS; ['reason' => 'required_without_key', 'path' => ''] when dedupe.required is set without
     * a key; [] when it is not refused for these reasons.
     *
     * @param array<mixed,mixed> $decl
     *
     * @return array{reason?:string,path?:string}
     */
    public static function refusal(array $decl): array
    {
        $paths = [];
        if (isset($decl['placeholders']) && is_array($decl['placeholders'])) {
            foreach ($decl['placeholders'] as $path) {
                $paths[] = $path;
            }
        }
        foreach (self::PATH_KEYS as $key) {
            $paths[] = $decl[$key] ?? null;
        }
        $dedupe = isset($decl['dedupe']) && is_array($decl['dedupe']) ? $decl['dedupe'] : [];
        $template = isset($dedupe['key']) && is_string($dedupe['key']) ? $dedupe['key'] : '';
        if ($template !== '' && preg_match_all('/\{([^{}]*)\}/', $template, $refs) > 0) {
            foreach ($refs[1] as $ref) {
                $paths[] = $ref;
            }
        }
        foreach ($paths as $path) {
            if (is_string($path) && self::isPublicInput($path)) {
                return ['reason' => 'public_input', 'path' => $path];
            }
        }
        if (!empty($dedupe['required']) && $template === '') {
            return ['reason' => 'required_without_key', 'path' => ''];
        }

        return [];
    }

    /**
     * 2026-11-12 02:02 +03: task 596 M3.0 (P3-63). True when a PUBLIC_INPUT_PATHS entry appears in $path as whole
     * segments.
     */
    public static function isPublicInput(string $path): bool
    {
        $wrapped = '.' . $path . '.';
        foreach (self::PUBLIC_INPUT_PATHS as $entry) {
            if (strpos($wrapped, '.' . $entry . '.') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 2026-11-12 02:02 +03: task 596 M3.0 (P3-62). The owning add-on id; '' = a core event or one of ours.
     */
    public function addon(): string
    {
        return $this->addon;
    }

    /**
     * 2026-11-12 02:02 +03: task 596 M3.0 (P3-64). True when an unresolved dedupe key must stop the SMS (fail closed).
     */
    public function required(): bool
    {
        return $this->required;
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
     * 2026-11-12 02:02 +03: task 596 M3.0 (P3-64). Reserved tokens before any path: {receiver}, {event} (the event id
     * given, else the declaration's own) and {hour} (intdiv($now ?? time(), 3600), a clock-hour bucket). Old callers
     * that pass two arguments keep working.
     *
     * @param array<string,mixed> $data
     */
    public function dedupeKey(array $data, string $receiver, string $event_id = '', ?int $now = null): string
    {
        $this->key_error = '';
        if ($this->dedupe_key === '') {
            return '';
        }
        $bad = '';
        $event = $event_id !== '' ? $event_id : $this->event_id;
        $hour = (string) intdiv($now ?? time(), 3600);
        $key = (string) preg_replace_callback(
            '/\{([^{}]*)\}/',
            function (array $m) use ($data, $receiver, $event, $hour, &$bad): string {
                if ($m[1] === 'receiver') {
                    return $receiver;
                }
                // 2026-11-12 02:02 +03: task 596 M3.0 (P3-64). The reserved tokens.
                if ($m[1] === 'event') {
                    return $event;
                }
                if ($m[1] === 'hour') {
                    return $hour;
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
     * 2026-10-23 03:41 +03: Task A12 item 4 (R53, R24), task 596. The literal start of the dedupe key template up to
     * and including its first colon ("updated:" of "updated:{order_info.order_id}:{receiver}"); '' when the event has
     * no dedupe key or the template does not start with a lower-case word and a colon.
     */
    public function dedupePrefix(): string
    {
        return preg_match('/^[a-z][a-z0-9_]*:/', $this->dedupe_key, $m) === 1 ? $m[0] : '';
    }

    /**
     * Seconds a dedupe key blocks a repeat; 0 = permanent.
     */
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
     * 2026-11-14 21:32 +03: task 596 M3.5 review I1. The V guard bucket of an event that is not a public form:
     * the V send keeps the daily ceiling and the quota reserve, the antibot scenario is not involved. '' = none.
     */
    public function vendorCap(): string
    {
        return $this->vendor_cap;
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
