<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/RowCleaner.php
 * CREATED:   2026-10-20 21:33 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Turns an arbitrary array into a complete, safe row of one log table: unknown keys dropped,
 *            defaults applied, every value cast and limited by its column type, invalid UTF-8 repaired
 *            and 4-byte characters removed from text.
 * WHY:       PRD 8: the database connection is 3-byte utf8, so one emoji in a message or a reason would
 *            fail or truncate the insert; the columns have limits; request data may be of any type. All
 *            three logs write through this one class, so the rule exists once.
 * USAGE:     $row = RowCleaner::clean(Schema::MESSAGES, ['message' => $text, 'order_id' => '12', 'bogus' => 1]);
 *            RowCleaner::text($string);   // valid UTF-8 without 4-byte characters
 * DEPENDS:   Log\Schema, ext-mbstring (a declared requirement of the add-on).
 */

namespace Tygh\Addons\Kwtsms\Log;

class RowCleaner
{
    /** decimal(14,2) */
    private const MAX_DECIMAL = 999999999999.99;

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed> Every column of the table except the primary key, in table order
     *
     * @throws \InvalidArgumentException For a table that is not one of the three logs
     */
    public static function clean(string $table, array $row): array
    {
        $defaults = Schema::defaults($table);
        $clean = [];
        foreach (Schema::columns($table) as $column => $definition) {
            [$type, $limit] = $definition;
            if (array_key_exists($column, $row)) {
                $clean[$column] = self::value($type, $limit, $row[$column]);
            } elseif (array_key_exists($column, $defaults)) {
                $clean[$column] = $defaults[$column];
            } else {
                $clean[$column] = self::emptyValue($type);
            }
        }

        return $clean;
    }

    /**
     * Valid UTF-8 without 4-byte characters (emoji and other characters above U+FFFF). Invalid bytes
     * become "?".
     */
    public static function text(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        // After the repair the string is valid UTF-8: a 4-byte character is exactly F0..F4 plus three
        // continuation bytes.
        $stripped = preg_replace('/[\xF0-\xF4][\x80-\xBF]{3}/', '', $text);

        return $stripped === null ? '' : $stripped;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function value(string $type, int $limit, $value)
    {
        switch ($type) {
            case 'i':
                return self::integer($value, $limit);
            case 'd':
                return self::decimal($value);
            case 'sn':
                $string = self::string($value, $limit);

                return ($value === null || $string === '') ? null : $string;
            case 't':
                if ($value === null) {
                    return null;
                }

                return self::cut(self::text(self::scalar($value)), $limit);
            default:
                return self::string($value, $limit);
        }
    }

    /**
     * @return mixed
     */
    private static function emptyValue(string $type)
    {
        switch ($type) {
            case 'i':
                return 0;
            case 'd':
                return 0.0;
            case 's':
                return '';
            default:
                return null;
        }
    }

    /**
     * 2026-10-20 22:24 +03: a text longer than its column is cut on a character boundary and ends with the
     * visible ASCII marker " [truncated, N bytes]" (N = size of the cleaned text), the whole result within
     * $limit bytes, so the PRD 6.8 detail view can show the truncated flag (Task 1.4 fix round 1, task 596).
     */
    private static function cut(string $text, int $limit): string
    {
        $bytes = strlen($text);
        if ($bytes <= $limit) {
            return $text;
        }
        $marker = ' [truncated, ' . $bytes . ' bytes]';

        return mb_strcut($text, 0, $limit - strlen($marker), 'UTF-8') . $marker;
    }

    /**
     * @param mixed $value
     */
    private static function string($value, int $characters): string
    {
        return mb_substr(self::text(self::scalar($value)), 0, $characters, 'UTF-8');
    }

    /**
     * @param mixed $value
     */
    private static function scalar($value): string
    {
        return (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) ? (string) $value : '';
    }

    /**
     * @param mixed $value
     */
    private static function integer($value, int $max): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (!(is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
            return 0;
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            return 0;
        }

        return (int) max(0.0, min((float) $max, floor($number)));
    }

    /**
     * @param mixed $value
     */
    private static function decimal($value): float
    {
        if (!(is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
            return 0.0;
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            return 0.0;
        }

        return round(max(-self::MAX_DECIMAL, min(self::MAX_DECIMAL, $number)), 2);
    }
}
