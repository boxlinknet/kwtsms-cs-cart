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

    /**
     * @param array<string,mixed> $data     The event data: shipment.tracking_number is read
     * @param string              $receiver C (the only receiver of the shipment SMS)
     *
     * @return array{placeholders:array<string,string>}
     */
    public function values(array $data, string $receiver): array
    {
        $raw = $data['shipment']['tracking_number'] ?? '';

        return ['placeholders' => [
            'tracking_number' => self::trackingNumber(is_scalar($raw) && !is_bool($raw) ? (string) $raw : ''),
        ]];
    }

    /**
     * Trim, collapse runs of whitespace to one space, cut to 40 characters; control characters are removed first.
     * Any byte outside printable ASCII (a non-ASCII character) makes the whole number '' (an unreadable number is
     * worse than none in an SMS).
     */
    public static function trackingNumber(string $raw): string
    {
        if (preg_match('/[^\x00-\x7F]/', $raw) === 1) {
            return '';
        }
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if (strlen($text) > self::MAX_LENGTH) {
            $text = rtrim(substr($text, 0, self::MAX_LENGTH));
        }

        return $text;
    }
}
