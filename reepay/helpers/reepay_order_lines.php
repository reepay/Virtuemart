<?php

/**
 * Builds Frisbii order lines (used for Klarna) from a VirtueMart order.
 *
 * Frisbii rules:
 *  - 'amount' is the UNIT price in minor units; Frisbii multiplies it by 'quantity'.
 *  - With amount_incl_vat = true, 'vat' is the rate as a fraction (0.25 = 25 %).
 *  - Lines must be in the invoice (payment) currency, and their sum becomes the invoice amount.
 *
 * If the lines don't add up exactly to the order total (e.g. coupons or other discounts,
 * currency rounding), an empty array is returned so the caller sends a plain 'amount' instead
 * and the customer is never charged a different total than the order.
 *
 * Pure function: no Joomla/VirtueMart dependencies, so it can be unit tested.
 */
class ReepayOrderLines
{
    /**
     * @param array    $items           $order['items'] (objects with order_item_name, product_final_price,
     *                                  product_tax, product_quantity; prices per unit, incl. tax, shop currency)
     * @param object   $bt              $order['details']['BT'] (order_shipment, order_shipment_tax,
     *                                  order_payment, order_payment_tax; amounts excl. tax + tax, shop currency)
     * @param callable $toPaymentCurrency function(float $shopAmount): float
     * @param float    $orderTotalInPaymentCurrency the amount the order must be charged
     * @return array   Frisbii order_lines, or [] when they can't represent the order total exactly
     */
    public static function build(array $items, $bt, callable $toPaymentCurrency, $orderTotalInPaymentCurrency)
    {
        $lines = [];

        foreach ($items as $item) {
            $quantity = (int) $item->product_quantity;
            if ($quantity <= 0) {
                continue;
            }
            $lines[] = self::line(
                self::itemName($item),
                (float) $item->product_final_price,
                (float) $item->product_tax,
                $quantity,
                $toPaymentCurrency
            );
        }

        if (empty($lines)) {
            return [];
        }

        foreach ([['Shipping', 'order_shipment', 'order_shipment_tax'], ['Payment fee', 'order_payment', 'order_payment_tax']] as $extra) {
            [$text, $netField, $taxField] = $extra;
            $tax   = isset($bt->$taxField) ? (float) $bt->$taxField : 0.0;
            $gross = (isset($bt->$netField) ? (float) $bt->$netField : 0.0) + $tax;
            if (round($gross, 2) > 0) {
                $lines[] = self::line($text, $gross, $tax, 1, $toPaymentCurrency);
            }
        }

        $sum = 0;
        foreach ($lines as $line) {
            $sum += $line['amount'] * $line['quantity'];
        }

        if ($sum !== self::toMinorUnits($orderTotalInPaymentCurrency)) {
            return [];
        }

        return $lines;
    }

    /**
     * The name stored on the order (order_item_name). VirtueMart only adds product_name from the
     * current catalog, so it can differ (renamed) or be missing (deleted product).
     */
    private static function itemName($item)
    {
        foreach (['order_item_name', 'product_name'] as $field) {
            if (isset($item->$field) && trim((string) $item->$field) !== '') {
                return trim((string) $item->$field);
            }
        }

        return 'Item';
    }

    private static function line($text, $unitInclTax, $unitTax, $quantity, callable $toPaymentCurrency)
    {
        $unitExclTax = $unitInclTax - $unitTax;

        return [
            'ordertext'       => $text,
            'amount'          => self::toMinorUnits($toPaymentCurrency($unitInclTax)),
            'vat'             => $unitExclTax > 0 ? round($unitTax / $unitExclTax, 4) : 0,
            'quantity'        => $quantity,
            'amount_incl_vat' => true,
        ];
    }

    private static function toMinorUnits($amount)
    {
        return (int) round(round((float) $amount, 2) * 100);
    }
}
