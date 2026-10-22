<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/ShipmentSmsData.php
 * CREATED:   2026-10-22 17:59 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The data_class of the order.shipment_updated declaration: turns the raw tracking number an admin typed
 *            into the {tracking_number} of the SMS (trimmed, spaces collapsed, at most 40 characters, printable ASCII
 *            only, else empty).
 * WHY:       Phase 2 Task A5 (SafatMall F2, ruling R4). The tracking number is free text. A control character would
 *            corrupt the message, and any non-GSM character would turn the whole SMS to Unicode and cut a page from
 *            160 to 70 characters.
 * USAGE:     'data_class' => ShipmentSmsData::class in schemas/kwtsms/events.php; OrderNotifier builds it with no
 *            arguments and calls values($data, $receiver). ShipmentSmsData::trackingNumber($raw) is the pure cleaner.
 * DEPENDS:   Notifications\DeclaredSmsData. Pure PHP 7.4.
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Notifications\DeclaredSmsData;

final class ShipmentSmsData implements DeclaredSmsData
{
    /** Longest tracking number put into an SMS, in characters. */
    public const MAX_LENGTH = 40;

    /** Longest tracking URL put into an SMS, in characters (2026-10-22 task A5 fix round 1, task 596). */
    public const MAX_URL = 200;

    /**
     * 2026-10-22 18:37 +03: Task A5 fix round 1 (I1, M1), task 596. Besides the cleaned tracking number: carrier
     * and tracking_url
     * cleaned the same way (printable ASCII or empty, so no value turns a GSM-7 SMS into UCS-2), and shipment_info,
     * the sentence part the default texts use: carrier and tracking number are optional in core, so any
     * combination of them gives a natural sentence (English "Carrier: X. Tracking: Y.", Arabic "tracking Y"; the
     * Arabic default has no carrier, the 70-unit page has no room for it). The language is the order's.
     *
     * @param array<string,mixed> $data     The event data: shipment.tracking_number, shipment.carrier_info
     * @param string              $receiver C (the only receiver of the shipment SMS)
     *
     * @return array{placeholders:array<string,string>}
     */
    public function values(array $data, string $receiver): array
    {
        $shipment = isset($data['shipment']) && is_array($data['shipment']) ? $data['shipment'] : [];
        $info = isset($shipment['carrier_info']) && is_array($shipment['carrier_info'])
            ? $shipment['carrier_info'] : [];
        $tracking = self::trackingNumber(self::scalar($shipment['tracking_number'] ?? ''));
        $carrier = self::asciiText(self::scalar($info['name'] ?? ''), self::MAX_LENGTH);
        $url = self::asciiText(self::scalar($info['tracking_url'] ?? ''), self::MAX_URL);
        $lang = strtolower(self::scalar($data['order_info']['lang_code'] ?? ''));

        if ($lang === 'ar') {
            $details = $tracking !== '' ? "\u{062A}\u{062A}\u{0628}\u{0639} " . $tracking : '';
        } else {
            $parts = [];
            if ($carrier !== '') {
                $parts[] = 'Carrier: ' . $carrier . '.';
            }
            if ($tracking !== '') {
                $parts[] = 'Tracking: ' . $tracking . '.';
            }
            $details = implode(' ', $parts);
        }

        return ['placeholders' => [
            'tracking_number' => $tracking,
            'carrier' => $carrier,
            'tracking_url' => $url,
            'shipment_info' => $details,
        ]];
    }

    /**
     * 2026-10-22 18:37 +03: Task A5 fix round 1 (M1), task 596. Printable ASCII only (control characters removed,
     * white space
     * collapsed, cut to $max); a value with any non-ASCII character becomes '' (it would force UCS-2).
     */
    public static function asciiText(string $raw, int $max): string
    {
        if (preg_match('/[^\x00-\x7F]/', $raw) === 1) {
            return '';
        }
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return strlen($text) > $max ? rtrim(substr($text, 0, $max)) : $text;
    }

    /**
     * @param mixed $value
     */
    private static function scalar($value): string
    {
        return is_scalar($value) && !is_bool($value) ? (string) $value : '';
    }

    /**
     * Trim, collapse runs of whitespace to one space, cut to 40 characters; control characters are removed first.
     * Any byte outside printable ASCII (a non-ASCII character) makes the whole number '' (an unreadable number is
     * worse than none in an SMS).
     */
    public static function trackingNumber(string $raw): string
    {
        return self::asciiText($raw, self::MAX_LENGTH);
    }
}
