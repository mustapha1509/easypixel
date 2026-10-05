<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Minimal Meta Conversions API client.
 *
 * NOTE on "zero blocking": true non-blocking delivery needs a queue
 * (cron/webhook worker) so the shop never waits on Meta's servers. This
 * build uses a short cURL timeout (2s connect / 3s total) as a practical
 * middle ground. Swapping to a queued job is a drop-in change to
 * sendEvent() if checkout-time latency becomes a concern under load.
 */
class EasyPixelCapi
{
    const API_VERSION = 'v20.0';
    const LOG_LIMIT = 10;

    public static function hash($value)
    {
        $value = mb_strtolower(trim((string) $value));
        return hash('sha256', $value);
    }

    /**
     * @param string      $eventName   e.g. "Purchase", "AddToCart"
     * @param array       $customData  event-specific fields (value, currency, content_ids, ...)
     * @param array       $userData    already-hashed matching fields + client_ip_address/client_user_agent/fbp/fbc
     * @param string      $sourceUrl   the page the event happened on
     * @param string|null $eventId     shared ID with the browser pixel event, for deduplication
     * @param string|null $testCodeOverride
     * @return array{success: bool, response: string}
     */
    public static function sendEvent($eventName, array $customData, array $userData, $sourceUrl, $eventId = null, $testCodeOverride = null)
    {
        $pixelId = Configuration::get('EASYPIXEL_PIXEL_ID');
        $token = Configuration::get('EASYPIXEL_CAPI_TOKEN');

        if (!$pixelId || !$token) {
            return ['success' => false, 'response' => 'Missing Pixel ID or CAPI token'];
        }

        $eventId = $eventId ?: (self::normalizeEventName($eventName) . '_' . uniqid('', true));

        $payload = [
            'data' => [[
                'event_name' => $eventName,
                'event_time' => time(),
                'event_id' => $eventId,
                'event_source_url' => $sourceUrl,
                'action_source' => 'website',
                'user_data' => $userData,
                'custom_data' => $customData,
            ]],
        ];

        $testCode = $testCodeOverride ?: Configuration::get('EASYPIXEL_TEST_EVENT_CODE');
        if ($testCode) {
            $payload['test_event_code'] = $testCode;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events?access_token=%s',
            self::API_VERSION,
            $pixelId,
            urlencode($token)
        );

        $response = self::post($url, $payload);

        self::log($eventName, $eventId, $payload, $response['body'], $response['success']);

        return ['success' => $response['success'], 'response' => $response['body']];
    }

    private static function post($url, array $payload)
    {
        $body = json_encode($payload);

        if (!function_exists('curl_init')) {
            return ['success' => false, 'body' => 'cURL extension not available'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            return ['success' => false, 'body' => 'cURL error: ' . $error];
        }

        return ['success' => $httpCode >= 200 && $httpCode < 300, 'body' => $result];
    }

    private static function normalizeEventName($eventName)
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $eventName));
    }

    private static function log($eventName, $eventId, array $payload, $response, $success)
    {
        Db::getInstance()->insert('easypixel_capi_log', [
            'id_shop' => (int) Context::getContext()->shop->id,
            'event_name' => pSQL($eventName),
            'event_id' => pSQL($eventId),
            'payload' => pSQL(json_encode($payload), true),
            'response' => pSQL((string) $response, true),
            'success' => (int) $success,
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        // Keep only the last LOG_LIMIT rows.
        $prefix = _DB_PREFIX_ . 'easypixel_capi_log';
        Db::getInstance()->execute(
            "DELETE FROM `$prefix` WHERE id_log NOT IN (
                SELECT id_log FROM (SELECT id_log FROM `$prefix` ORDER BY id_log DESC LIMIT " . self::LOG_LIMIT . ") t
            )"
        );
    }
}
