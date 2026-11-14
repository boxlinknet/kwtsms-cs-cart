<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/BackInStockRecipients.php
 * CREATED:   2026-11-14 09:45 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Turns the e-mail addresses of one back-in-stock dispatch (core's product.back_in_stock carries only
 *            e-mails) into the accounts that may get an SMS: registered (the e-mail is a user's), active
 *            (status A), customer (user_type C), with a phone on the account. Answers user id, raw phone and
 *            language, in subscription order.
 * WHY:       P3-65. Anyone can subscribe ANY e-mail address to a product, so the e-mail is a claim, not a consent:
 *            the SMS goes only to an account that owns that e-mail and has a phone, and the account is the one the
 *            dispatch key bis:<product>:<user> is built from. A guest address, a disabled account, an administrator,
 *            a vendor or an account without a phone get nothing. Ultimate with share_users = N: only the accounts of
 *            the dispatch's own company (another storefront's customer is never reached).
 *            The lookup is ONE SELECT per chunk of 500 e-mails on the users.email index (idx_email); the evidence
 *            (ANALYZE FORMAT=JSON on 200,000 users) is in the M3.3 report. No e-mail or phone is returned to a log:
 *            callers count only.
 * USAGE:     $accounts = (new BackInStockRecipients())->phones($payload['subscribers'], (int) $payload['company_id']);
 *            // [['user_id' => 7, 'phone' => '+965 5012 3402', 'lang' => 'ar'], ...] in subscription order
 *            BackInStockRecipients::lookupSql($emails, $company_id, $scoped) is the one statement (perf evidence).
 *            Tests override rows() and scoped() (no database).
 * DEPENDS:   CS-Cart db_get_array(), db_quote(), fn_allowed_for(), Registry settings.Stores.share_users; table users
 *            with the index idx_email.
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Registry;

class BackInStockRecipients
{
    /** E-mail addresses per SELECT (one statement per chunk). */
    public const CHUNK = 500;

    /**
     * The longest e-mail address read (RFC 5321 path limit); a longer string is not an address and is dropped before
     * the query.
     */
    public const MAX_EMAIL_LENGTH = 254;

    /** Most addresses one dispatch can make us look at (core stores one row per product and e-mail). */
    public const MAX_SUBSCRIBERS = 100000;

    /**
     * @param mixed $subscribers The `subscribers` of the event payload: a list of e-mail addresses
     *
     * @return array<int,array{user_id:int,phone:string,lang:string}> one entry per account, subscription order
     */
    public function phones($subscribers, int $company_id): array
    {
        $order = [];
        if (is_array($subscribers)) {
            foreach (array_slice($subscribers, 0, self::MAX_SUBSCRIBERS) as $email) {
                if (!is_string($email)) {
                    continue;
                }
                $email = strtolower(trim($email));
                if ($email === '' || strlen($email) > self::MAX_EMAIL_LENGTH || isset($order[$email])) {
                    continue;
                }
                $order[$email] = count($order);
            }
        }
        if ($order === []) {
            return [];
        }
        $scoped = $this->scoped();
        $found = [];
        foreach (array_chunk(array_keys($order), self::CHUNK) as $chunk) {
            foreach ($this->rows($chunk, $company_id, $scoped) as $row) {
                $email = strtolower((string) ($row['email'] ?? ''));
                $user_id = (int) ($row['user_id'] ?? 0);
                $phone = trim((string) ($row['phone'] ?? ''));
                // user_id <= 1 is the root administrator (never a customer); an empty phone is not usable.
                if (!isset($order[$email]) || $user_id <= 1 || $phone === '' || isset($found[$user_id])) {
                    continue;
                }
                $lang = strtolower(trim((string) ($row['lang_code'] ?? '')));
                $found[$user_id] = [
                    'pos' => $order[$email],
                    'user_id' => $user_id,
                    'phone' => $phone,
                    'lang' => preg_match('/^[a-z]{2}$/D', $lang) === 1 ? $lang : '',
                ];
            }
        }
        uasort($found, static function (array $a, array $b): int {
            return [$a['pos'], $a['user_id']] <=> [$b['pos'], $b['user_id']];
        });
        $out = [];
        foreach ($found as $account) {
            $out[] = ['user_id' => $account['user_id'], 'phone' => $account['phone'], 'lang' => $account['lang']];
        }

        return $out;
    }

    /**
     * The one statement: active customer accounts with a phone whose e-mail is in the chunk. Ultimate with
     * share_users = N adds the company of the dispatch.
     *
     * @param string[] $emails
     */
    public static function lookupSql(array $emails, int $company_id, bool $scoped): string
    {
        $sql = "SELECT user_id, email, phone, lang_code FROM ?:users WHERE email IN (?a) AND status = 'A'"
            . " AND user_type = 'C' AND phone <> ''";
        if ($scoped) {
            $sql .= ' AND company_id = ?i';
        }

        return (string) db_quote($sql, array_values($emails), $company_id);
    }

    /**
     * One SELECT. Seam of the unit tests.
     *
     * @param string[] $emails
     *
     * @return array<int,array<string,mixed>>
     */
    protected function rows(array $emails, int $company_id, bool $scoped): array
    {
        return (array) db_get_array(self::lookupSql($emails, $company_id, $scoped));
    }

    /**
     * True when only the accounts of the dispatch's company count: Ultimate with the customers not shared between
     * storefronts (Stores.share_users != Y). Multi-Vendor shares its customers.
     */
    protected function scoped(): bool
    {
        return function_exists('fn_allowed_for') && fn_allowed_for('ULTIMATE')
            && Registry::get('settings.Stores.share_users') !== 'Y';
    }
}
