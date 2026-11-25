<?php

/**
 * Decides who may see an order on the accept URL (the page Frisbii returns the customer to).
 *
 * The URL only contains the order number, which can be guessed. The order is therefore only shown
 * to the session that placed it or to the logged-in customer who owns it.
 *
 * Pure functions: no Joomla/VirtueMart dependencies, so they can be unit tested.
 */
class ReepayOrderAccess
{
    const SESSION_KEY = 'reepay_orders';
    const MAX_ORDERS  = 10;

    /**
     * Adds an order number to the list kept in the session (newest last).
     *
     * @param mixed       $orderNumbers Current session value
     * @param string|null $orderNumber
     * @return string[]
     */
    public static function remember($orderNumbers, $orderNumber)
    {
        $list = is_array($orderNumbers) ? $orderNumbers : [];
        $orderNumber = (string) $orderNumber;

        if ($orderNumber === '') {
            return array_values($list);
        }

        $list = array_values(array_filter($list, function ($known) use ($orderNumber) {
            return $known !== $orderNumber;
        }));
        $list[] = $orderNumber;

        return array_slice($list, -self::MAX_ORDERS);
    }

    /**
     * @param mixed           $orderNumbers  Session value (order numbers placed in this session)
     * @param string|null     $orderNumber
     * @param int             $currentUserId Logged-in user, 0 for guests
     * @param int|string|null $orderUserId   User who owns the order, 0 for guest orders
     * @return bool
     */
    public static function mayShowOrder($orderNumbers, $orderNumber, $currentUserId, $orderUserId)
    {
        $orderNumber = (string) $orderNumber;

        if ($orderNumber === '') {
            return false;
        }

        if (is_array($orderNumbers) && in_array($orderNumber, $orderNumbers, true)) {
            return true;
        }

        return (int) $currentUserId > 0 && (int) $currentUserId === (int) $orderUserId;
    }

    /**
     * Order link according to the shop's order tracking setting, '' when there is none.
     *
     * @param string          $tracking    VirtueMart 'ordertracking'
     * @param string          $orderNumber
     * @param string          $orderPass
     * @param int|string|null $orderUserId
     * @return string
     */
    public static function orderLink($tracking, $orderNumber, $orderPass, $orderUserId)
    {
        if ($tracking === 'none' || ($tracking === 'registered' && empty($orderUserId))) {
            return '';
        }

        $link = 'index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $orderNumber;

        if ($tracking === 'guestlink' || ($tracking === 'guests' && empty($orderUserId))) {
            $link .= '&order_pass=' . $orderPass;
        }

        return $link;
    }
}
