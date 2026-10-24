<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/MessageLog.php
 * CREATED:   2026-10-20 21:35 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Writes one row of kwtsms_messages per API call: who, what, to whom, the raw request and reply,
 *            the msg-id, points charged and balance after.
 * WHY:       PRD 6.2 step 14 and D7: every SMS is stored as sent, except that OTP codes and reset links
 *            (secret_values) become ****** in the message and in both raw copies, and the row is flagged
 *            masked. PRD 10: the API username and password never appear in the stored raw request or reply
 *            in any form. The text is cleaned for the 3-byte database connection (RowCleaner). An audit
 *            write must not break the send that caused it: a failed insert returns 0.
 * USAGE:     $log = new MessageLog($store, $config);   (wired in Task 1.6)
 *            $id = $log->record([
 *                'source' => 'event', 'event_id' => 'kwtsms.order_placed.p12', 'receiver' => 'C',
 *                'recipients' => '96598765432', 'recipient_count' => 1, 'message' => 'Order 12 received',
 *                'status' => 'test_queued', 'is_test' => 'Y', 'api_msg_id' => '...', 'balance_after' => 148.0,
 *                'api_request' => $json_without_credentials, 'api_response' => $reply,
 *                'secret_values' => ['482913'],        // not a column: strings to mask
 *            ]);                                       // row id, or 0 when the write failed
 *            Columns: see Log\Schema (MESSAGES). created_at defaults to now.
 * DEPENDS:   Log\LogStoreInterface, Log\Redactor, Log\RowCleaner, Log\Schema, Settings\ConfigInterface.
 */

namespace Tygh\Addons\Kwtsms\Log;

use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class MessageLog
{
    use ReportsFailures;

    /** @var LogStoreInterface */
    private $store;

    /** @var ConfigInterface */
    private $config;

    /** @var callable|null returns the current Unix time */
    private $clock;

    /**
     * @param callable|null $clock Returns the current Unix time (tests); null = time()
     */
    public function __construct(LogStoreInterface $store, ConfigInterface $config, ?callable $clock = null)
    {
        $this->store = $store;
        $this->config = $config;
        $this->clock = $clock;
    }

    /**
     * 2026-10-24 14:40 +03: Task M1.4 fix round 1 (minor 3), task 596. The stored request with the secrets masked
     * in its
     * "message" field only (re-encoded, slashes and Unicode unescaped); a request that is not a JSON object with a
     * string
     * message is masked whole.
     *
     * @param array<mixed> $secrets
     */
    private static function maskRequest(
        string $request,
        #[\SensitiveParameter]
        array $secrets
    ): string {
        $decoded = json_decode($request, true);
        if (!is_array($decoded) || !isset($decoded['message']) || !is_string($decoded['message'])) {
            return Redactor::mask($request, $secrets);
        }
        $decoded['message'] = Redactor::mask($decoded['message'], $secrets);
        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : Redactor::mask($request, $secrets);
    }

    /**
     * @param array<string, mixed> $row Columns of kwtsms_messages plus the optional secret_values list
     *
     * @return int The new message_id, or 0 when the row could not be written
     */
    public function record(array $row): int
    {
        try {
            $secrets = isset($row['secret_values']) && is_array($row['secret_values']) ? $row['secret_values'] : [];
            unset($row['secret_values']);
            $row += ['created_at' => $this->clock === null ? time() : (int) call_user_func($this->clock)];

            // D7: mask first, in the SMS text and in both raw copies.
            $masked = ($row['masked'] ?? 'N') === 'Y';
            foreach (['message', 'api_request', 'api_response'] as $column) {
                if ($secrets !== [] && isset($row[$column]) && is_string($row[$column])) {
                    $before = $row[$column];
                    // 2026-10-24 14:40 +03: Task M1.4 fix round 1 (minor 3), task 596. In the stored request only the
                    // "message" field is masked: a 6-digit code can also occur inside the phone digits, a msg-id or a
                    // balance, and cutting it out there would show where it was. Not JSON: masked whole, as before.
                    $row[$column] = $column === 'api_request'
                        ? self::maskRequest($before, $secrets)
                        : Redactor::mask($before, $secrets);
                    $masked = $masked || $row[$column] !== $before;
                }
            }
            $row['masked'] = $masked ? 'Y' : 'N';

            // PRD 10: the raw copies never carry the credentials. The SMS text is stored as sent.
            $credentials = [$this->config->apiUsername(), $this->config->password()];
            foreach (['api_request', 'api_response'] as $column) {
                if (isset($row[$column]) && is_string($row[$column])) {
                    $row[$column] = Redactor::redactString($row[$column], $credentials);
                }
            }

            return $this->store->insert(Schema::MESSAGES, RowCleaner::clean(Schema::MESSAGES, $row));
        } catch (\Throwable $e) {
            $this->reportFailure($e);

            return 0;
        }
    }
}
