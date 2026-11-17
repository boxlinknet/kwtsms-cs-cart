<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Events/CommunitySmsData.php
 * CREATED:   2026-11-14 17:25 +03
 * TASK:      596 M3.5
 * AUTHOR:    kwtSMS
 * WHAT:      The data_class of the review, discussion and vendor communication declarations
 *            (schemas/kwtsms/events.php):
 *            product_reviews.new_post, the six discussion.<type>.new_post and vendor_communication.message_received,
 *            .order_message_received and .vendor_to_admin_message_received. enrich() recognises the payload shape
 *            (review, discussion post or thread), fails closed without the id the text needs, and adds
 *            kwtsms_ctx.company_id (the vendor of the product, object or thread; 0 = the store) and kwtsms_ctx.scope
 *            (the throttle scope: the thread customer's id for the C text, '0' for A and V). For the C text of a
 *            vendor-to-customer thread it also adds kwtsms_ctx.{phone, lang, user_id} from the customer's account.
 *            values() computes {product_name} (catalog name), {object_name} (catalog, page or vendor name of the
 *            discussed object), {thread_id} and {order_id}. Nothing else is returned.
 * WHY:       Task M3.5 (B5, P3-63, P3-64). Reviews, posts and messages are typed by visitors and customers: their
 *            text, rating, author name and thread subject never become a placeholder, a key or a log value. The core
 *            payload has no customer phone, and a thread that is not between a vendor and a customer (vendor to
 *            admin) never texts a customer.
 * USAGE:     'data_class' => \Tygh\Addons\Kwtsms\Events\CommunitySmsData::class; OrderNotifier builds it with no
 *            arguments, calls enrich() before the declaration is read, then values($data, $receiver). A unit test
 *            replaces the two core reads with useUserLookup() and useProductLookup().
 * DEPENDS:   Notifications\{DeclaredSmsData,PayloadEnricher}. Core (read only, guarded): db_get_row() for the thread
 *            customer's row, fn_get_product_name() when a product id is present.
 */

namespace Tygh\Addons\Kwtsms\Events;

use Tygh\Addons\Kwtsms\Notifications\DeclaredSmsData;
use Tygh\Addons\Kwtsms\Notifications\PayloadEnricher;

final class CommunitySmsData implements DeclaredSmsData, PayloadEnricher
{
    /** Longest catalog name put into an SMS, in characters. */
    public const MAX_NAME = 40;

    /** The only thread type that may text a customer (core: CommunicationTypes::VENDOR_TO_CUSTOMER). */
    public const CUSTOMER_THREAD = 'vendor_to_customer';

    /** @var (callable(int): array<string,mixed>)|null replaces the customer row read (tests) */
    private static $user_lookup = null;

    /** @var (callable(int,string): string)|null replaces the catalog name read (tests) */
    private static $product_lookup = null;

    /**
     * @param (callable(int): array<string,mixed>)|null $lookup user id => [phone, lang_code, user_type]; null = core
     */
    public static function useUserLookup(?callable $lookup): void
    {
        self::$user_lookup = $lookup;
    }

    /**
     * @param (callable(int,string): string)|null $lookup (product id, lang) => name; null = core again
     */
    public static function useProductLookup(?callable $lookup): void
    {
        self::$product_lookup = $lookup;
    }

    /**
     * @param mixed $value
     */
    private static function id($value): int
    {
        return is_scalar($value) && !is_bool($value) && ctype_digit((string) $value) ? (int) $value : 0;
    }

    /**
     * @param array<string,mixed> $array
     *
     * @return array<string,mixed>
     */
    private static function part(array $array, string $key): array
    {
        return isset($array[$key]) && is_array($array[$key]) ? $array[$key] : [];
    }

    /**
     * review | discussion | thread; '' for a payload of no known shape. The shapes do not overlap: a review payload has
     * product_review_data, a post has post_data and object, a thread has communication_type.
     *
     * @param array<string,mixed> $payload
     */
    private static function shape(array $payload): string
    {
        if (isset($payload['product_review_data']) && is_array($payload['product_review_data'])) {
            return 'review';
        }
        if (
            isset($payload['post_data'], $payload['object'])
            && is_array($payload['post_data']) && is_array($payload['object'])
        ) {
            return 'discussion';
        }
        if (isset($payload['communication_type']) && is_scalar($payload['communication_type'])) {
            return 'thread';
        }

        return '';
    }

    /**
     * The product id of a review payload (the review row, else the product data); 0 when there is none.
     *
     * @param array<string,mixed> $payload
     */
    private static function reviewProduct(array $payload): int
    {
        $review = self::part($payload, 'product_review_data');
        $id = self::id($review['product_id'] ?? 0);
        if ($id <= 0) {
            $id = self::id(self::part($review, 'product')['product_id'] ?? 0);
        }
        if ($id <= 0) {
            $id = self::id(self::part($payload, 'product_data')['product_id'] ?? 0);
        }

        return $id;
    }

    /**
     * Fails closed for EVERY receiver: a payload of no known shape, a review without its id or product, a post
     * without its id, or a thread without its id throws, so OrderNotifier writes attempt invalid / platform_missing and
     * sends nothing, with no PHP warning. kwtsms_ctx.company_id is an int (0 = the store). kwtsms_ctx.scope is the
     * throttle scope. Only the C text of a vendor-to-customer thread gets phone, lang and user_id.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    public function enrich(array $payload, string $receiver): array
    {
        $shape = self::shape($payload);
        $company = 0;
        $ctx = ['scope' => '0'];
        if ($shape === 'review') {
            $review_id = self::id(self::part($payload, 'product_review_data')['product_review_id'] ?? 0);
            if ($review_id <= 0 || self::reviewProduct($payload) <= 0) {
                throw new \RuntimeException('review payload without a review or product id');
            }
            $company = self::id(self::part($payload, 'product_data')['company_id'] ?? 0);
        } elseif ($shape === 'discussion') {
            if (self::id($payload['post_id'] ?? 0) <= 0) {
                throw new \RuntimeException('discussion payload without a post id');
            }
            $company = self::id($payload['company_id'] ?? 0);
        } elseif ($shape === 'thread') {
            if (self::id($payload['thread_id'] ?? 0) <= 0) {
                throw new \RuntimeException('thread payload without a thread id');
            }
            $company = self::id($payload['company_id'] ?? 0);
            if ($receiver === 'C') {
                $ctx = $this->customer($payload) + $ctx;
            }
        } else {
            throw new \RuntimeException('payload of no known review, discussion or thread shape');
        }
        $ctx['company_id'] = $company;
        // 2026-11-14 21:32 +03: task 596 M3.5 review I2. The throttle company: the vendor for V and C, 0 for the
        // administrator, so a visitor who moves between vendors' products cannot make the administrator's SMS repeat
        // within the hour.
        $ctx['key_company'] = $receiver === 'A' ? 0 : $company;
        $payload['kwtsms_ctx'] = $ctx;

        return $payload;
    }

    /**
     * The C text's phone, language, user id and throttle scope; [] (so the SMS ends as no phone) unless the thread is
     * between a vendor and a customer, the last message is not the customer's own, and the thread user is a customer
     * account with a phone.
     *
     * @param array<string,mixed> $thread
     *
     * @return array<string,mixed>
     */
    private function customer(array $thread): array
    {
        $type = $thread['communication_type'] ?? '';
        if (!is_string($type) || $type !== self::CUSTOMER_THREAD) {
            return [];
        }
        // The customer's own message never texts the customer (core sets the same rule).
        if (($thread['last_message_user_type'] ?? '') === 'C') {
            return [];
        }
        $user_id = self::id($thread['user_id'] ?? 0);
        if ($user_id <= 1) {
            return [];
        }
        try {
            $row = self::$user_lookup !== null
                ? (self::$user_lookup)($user_id)
                : (function_exists('db_get_row')
                    ? db_get_row('SELECT phone, lang_code, user_type FROM ?:users WHERE user_id = ?i', $user_id) : []);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($row) || ($row['user_type'] ?? '') !== 'C') {
            return [];
        }
        $out = ['user_id' => $user_id, 'scope' => (string) $user_id];
        $phone = $row['phone'] ?? '';
        if (is_scalar($phone) && !is_bool($phone) && trim((string) $phone) !== '') {
            $out['phone'] = trim((string) $phone);
        }
        $lang = $row['lang_code'] ?? '';
        $lang = is_string($lang) ? strtolower($lang) : '';
        if (preg_match('/^[a-z]{2}$/D', $lang) === 1) {
            $out['lang'] = $lang;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data     The event data: the review, post or thread ids, kwtsms_lang
     * @param string              $receiver C, A or V
     *
     * @return array{placeholders:array<string,string>}
     */
    public function values(array $data, string $receiver): array
    {
        $lang = strtolower(trim(is_scalar($data['kwtsms_lang'] ?? null) ? (string) $data['kwtsms_lang'] : ''));
        if (preg_match('/^[a-z]{2}$/D', $lang) !== 1) {
            $lang = 'en';
        }
        $shape = self::shape($data);
        $out = [];
        if ($shape === 'review') {
            $fallback = self::name(self::part($data, 'product_data')['product'] ?? '');
            $out['product_name'] = self::product(self::reviewProduct($data), $lang, $fallback);
        } elseif ($shape === 'discussion') {
            $object = self::part($data, 'object');
            $out['object_name'] = self::name(self::part($data, 'object_data')['description'] ?? '');
            $out['thread_id'] = self::digits($object['thread_id'] ?? 0);
            if (($object['object_type'] ?? '') === 'O') {
                $out['order_id'] = self::digits($object['object_id'] ?? 0);
            }
        } elseif ($shape === 'thread') {
            $out['thread_id'] = self::digits($data['thread_id'] ?? 0);
            if (($data['object_type'] ?? '') === 'O') {
                $out['order_id'] = self::digits($data['object_id'] ?? 0);
            }
        }

        return ['placeholders' => $out];
    }

    /**
     * @param mixed $value
     */
    private static function digits($value): string
    {
        $id = self::id($value);

        return $id > 0 ? (string) $id : '';
    }

    /**
     * A catalog, page or vendor name: tags and control characters removed, cut to MAX_NAME characters.
     *
     * 2026-11-17 15:12 +03: FIX-A (branch review 1.4.0 security I2), task 596. Public: the back-in-stock text cleans
     * a vendor-controlled product name with this same helper.
     *
     * @param mixed $name
     */
    public static function name($name): string
    {
        $name = is_scalar($name) && !is_bool($name) ? (string) $name : '';
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', strip_tags($name)));

        return mb_strlen($name, 'UTF-8') > self::MAX_NAME
            ? rtrim(mb_substr($name, 0, self::MAX_NAME, 'UTF-8'))
            : $name;
    }

    private static function product(int $product_id, string $lang, string $fallback): string
    {
        if ($product_id <= 0) {
            return $fallback;
        }
        try {
            $name = self::$product_lookup !== null
                ? (self::$product_lookup)($product_id, $lang)
                : (function_exists('fn_get_product_name') ? fn_get_product_name($product_id, $lang) : '');
        } catch (\Throwable $e) {
            return $fallback;
        }
        $name = self::name(is_string($name) ? $name : '');

        return $name !== '' ? $name : $fallback;
    }
}
