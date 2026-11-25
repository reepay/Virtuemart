<?php

/**
 * Decides which Frisbii customer an order belongs to.
 *
 * The handle must not be something the buyer can choose. With the email as handle, anyone could
 * attach an order to another person's customer record by typing that person's email.
 */
class ReepayCustomerHandle
{
    /**
     * Short value that is fixed per shop, so shops sharing a Frisbii account don't share customers.
     *
     * @param string $secret The Joomla secret (never sent itself).
     * @return string
     */
    public static function shopKey($secret)
    {
        return substr(hash_hmac('sha256', 'reepay-customer', (string) $secret), 0, 16);
    }

    /**
     * @param string      $shopKey       From shopKey().
     * @param int|string  $userId        The logged-in owner of the order, 0 for a guest.
     * @param string      $orderNumber
     * @param string|null $storedRequest Request JSON stored for an earlier payment attempt on this order.
     * @return string
     */
    public static function forOrder($shopKey, $userId, $orderNumber, $storedRequest = null)
    {
        // Frisbii refuses another customer on an existing invoice, so a retry keeps the first handle
        $stored = self::storedHandle($storedRequest, (string) $orderNumber);
        if ($stored !== null) {
            return $stored;
        }

        if ((int) $userId > 0) {
            return 'vm-' . $shopKey . '-u-' . (int) $userId;
        }

        $guest = 'vm-' . $shopKey . '-o-' . $orderNumber;

        // Hashed instead of stripped: different order numbers must stay different
        return self::isValid($guest) ? $guest : 'vm-' . $shopKey . '-h-' . hash('sha256', (string) $orderNumber);
    }

    /**
     * @param mixed $handle
     * @return bool Whether Frisbii accepts it as a customer handle.
     */
    public static function isValid($handle)
    {
        return is_string($handle) && preg_match('/\A[a-zA-Z0-9_.\-@]{1,255}\z/', $handle) === 1;
    }

    private static function storedHandle($storedRequest, $orderNumber)
    {
        $data = is_string($storedRequest) ? json_decode($storedRequest, true) : null;
        if (!is_array($data) || ($data['order']['handle'] ?? null) !== $orderNumber) {
            return null;
        }

        $handle = $data['order']['customer']['handle'] ?? null;

        return self::isValid($handle) ? $handle : null;
    }
}
