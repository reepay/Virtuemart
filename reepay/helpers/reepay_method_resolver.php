<?php

/**
 * Decides which payment method an order is handled with on customer return and webhook.
 *
 * The method stored on the order always wins. The pm URL parameter only routes the request
 * to this plugin; it is compared so a difference can be logged, never used for the API key,
 * test mode, webhook secret or order statuses.
 *
 * Pure function: no Joomla/VirtueMart dependencies, so it can be unit tested.
 */
class ReepayMethodResolver
{
    const MISSING_ORDER_METHOD = 'missing_order_method';

    /**
     * @param int|string|null $requestPm The pm parameter from the request URL.
     * @param int|string|null $storedPm  virtuemart_paymentmethod_id stored for the order.
     * @return array{id:int|null, pmMismatch:bool, reason:string|null}
     */
    public static function resolve($requestPm, $storedPm)
    {
        $stored = self::toId($storedPm);

        if ($stored === null) {
            return ['id' => null, 'pmMismatch' => false, 'reason' => self::MISSING_ORDER_METHOD];
        }

        return ['id' => $stored, 'pmMismatch' => self::toId($requestPm) !== $stored, 'reason' => null];
    }

    /** Positive integer id, or null. */
    private static function toId($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }
        return null;
    }
}
