<?php

/**
 * One-line summaries of the Frisbii checkout-session request/response for the plugin log.
 *
 * Only what support/QA needs: order number, payment methods, amount or order-line total,
 * and Frisbii's result (HTTP status, message, code, request_id). Never customer data
 * (names, email, address, phone), URLs, keys or raw payloads.
 *
 * Pure functions: no Joomla/VirtueMart dependencies, so they can be unit tested.
 */
class ReepayCheckoutLog
{
    /**
     * @param array $input The array sent to ReepayService::createCheckoutSession().
     * @return string
     */
    public static function request($input)
    {
        $order = is_array($input) && isset($input['order']) && is_array($input['order']) ? $input['order'] : [];
        $currency = isset($order['currency']) ? (string) $order['currency'] : '';

        $parts = [
            'order=' . self::clean($order['handle'] ?? 'n/a'),
            'payment_methods=' . (!empty($input['payment_methods']) && is_array($input['payment_methods'])
                ? self::clean(implode(',', $input['payment_methods']))
                : 'n/a'),
        ];

        if (isset($order['amount'])) {
            $parts[] = 'amount=' . (int) $order['amount'] . ' ' . self::clean($currency);
        }

        if (!empty($order['order_lines']) && is_array($order['order_lines'])) {
            $total = 0;
            foreach ($order['order_lines'] as $line) {
                $total += (int) ($line['amount'] ?? 0) * (int) ($line['quantity'] ?? 1);
            }
            $parts[] = 'order_lines=' . count($order['order_lines']) . ' (total ' . $total . ' ' . self::clean($currency) . ')';
        }

        if (!isset($order['amount']) && empty($order['order_lines'])) {
            $parts[] = 'amount=n/a';
        }

        $parts[] = 'settle=' . (!empty($input['settle']) ? 'yes' : 'no');

        return 'Checkout request: ' . implode(', ', $parts);
    }

    /**
     * @param string|null $orderNumber
     * @param array|null  $result The return value of ReepayService::createCheckoutSession().
     * @return string
     */
    public static function response($orderNumber, $result)
    {
        $parts = [
            'order=' . self::clean($orderNumber ?? 'n/a'),
            'status=' . self::clean(is_array($result) && isset($result['status']) ? $result['status'] : 'n/a'),
        ];

        if (is_array($result) && ($result['status'] ?? '') === 'success') {
            if (!empty($result['body']['id'])) {
                $parts[] = 'session=' . self::clean($result['body']['id']);
            }
        } elseif (is_array($result) && isset($result['error'])) {
            $error = json_decode((string) $result['error'], true);
            if (is_array($error)) {
                if (isset($error['http_status'])) {
                    $parts[] = 'http=' . (int) $error['http_status'];
                }
                $parts[] = 'error=' . self::clean($error['message'] ?? $error['error'] ?? 'n/a');
                if (isset($error['code'])) {
                    $parts[] = 'code=' . self::clean($error['code']);
                }
                if (isset($error['request_id'])) {
                    $parts[] = 'request_id=' . self::clean($error['request_id']);
                }
            } else {
                // Transport error (e.g. cURL/TLS): keep the reason, drop URLs, keep it short
                $text = preg_replace('#\b\w+://\S+#', '', (string) $result['error']);
                $parts[] = 'error=' . substr(self::clean($text), 0, 150);
            }
        }

        return 'Checkout response: ' . implode(', ', $parts);
    }

    /** One line, no control characters, collapsed whitespace. */
    private static function clean($value)
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $value)));
    }
}
