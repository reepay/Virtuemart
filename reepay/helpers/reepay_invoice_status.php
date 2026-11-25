<?php

/**
 * Decides an order's status from what Frisbii reports for the invoice, instead of from the
 * payment method's instant-settle setting.
 *
 * Frisbii keeps a refunded invoice in the state "settled" and reports refunds only in
 * refunded_amount (minor units), so a refund is full only when all that was settled is refunded.
 *
 * Pure functions: no Joomla/VirtueMart dependencies, so they can be unit tested.
 */
class ReepayInvoiceStatus
{
    const AUTHORIZED         = 'authorized';
    const SETTLED            = 'settled';
    const REFUNDED           = 'refunded';
    const PARTIALLY_REFUNDED = 'partially_refunded';

    /**
     * @param array $invoiceBody The 'body' of ReepayService::getInvoice() (state, amount, settled_amount, refunded_amount).
     * @return array{kind:string, payment_status:string}|null null for states this plugin doesn't map
     *         (created, pending, failed, cancelled, ...).
     */
    public static function fromInvoice(array $invoiceBody)
    {
        $state    = $invoiceBody['state'] ?? null;
        $settled  = self::settledMinor($invoiceBody);
        $refunded = (int) ($invoiceBody['refunded_amount'] ?? 0);

        if ($state === 'authorized') {
            return ['kind' => self::AUTHORIZED, 'payment_status' => 'authorized'];
        }

        if ($state === 'settled') {
            if ($refunded > 0 && $refunded >= $settled) {
                return ['kind' => self::REFUNDED, 'payment_status' => 'refunded'];
            }
            if ($refunded > 0) {
                return ['kind' => self::PARTIALLY_REFUNDED, 'payment_status' => 'settled'];
            }
            return ['kind' => self::SETTLED, 'payment_status' => 'settled'];
        }

        return null;
    }

    /**
     * Status for the customer return from the Frisbii payment page.
     *
     * @param array  $invoiceBody
     * @param object $method VirtueMart payment method (status_authorized, status_settled)
     * @return array{order_status:string, payment_status:string, comment_key:string}|null
     */
    public static function forCustomerReturn(array $invoiceBody, $method)
    {
        $decision = self::fromInvoice($invoiceBody);

        if ($decision === null) {
            return null;
        }

        if ($decision['kind'] === self::AUTHORIZED) {
            return [
                'order_status'   => $method->status_authorized,
                'payment_status' => 'authorized',
                'comment_key'    => 'VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_AUTHORIZED',
            ];
        }

        // Settled (a refund can't have happened yet on the customer return; treat it as settled)
        return [
            'order_status'   => $method->status_settled,
            'payment_status' => 'settled',
            'comment_key'    => 'VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_SETTLED',
        ];
    }

    /**
     * Adjusts a webhook transition (from ReepayWebhookDispatcher::resolve()) to the real invoice:
     *  - invoice_authorized for an invoice that is already settled -> settled status
     *  - invoice_refund for a partial refund -> targetOrderStatus null (keep the current order status), flag partialRefund
     *
     * @param array  $config      Transition config from ReepayWebhookDispatcher::resolve()
     * @param string $eventType
     * @param array  $invoiceBody
     * @param object $method      VirtueMart payment method (status_settled)
     * @return array{config:array, partialRefund:bool}
     */
    public static function forWebhook(array $config, $eventType, array $invoiceBody, $method)
    {
        $decision = self::fromInvoice($invoiceBody);
        $kind = $decision['kind'] ?? null;

        if ($eventType === 'invoice_authorized' && $kind === self::SETTLED) {
            $config['targetOrderStatus'] = $method->status_settled;
            $config['newPaymentStatus']  = 'settled';
            $config['orderNoteKey']      = 'VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_SETTLED_WEBHOOK';
            return ['config' => $config, 'partialRefund' => false];
        }

        if ($eventType === 'invoice_refund' && $kind === self::PARTIALLY_REFUNDED) {
            $config['targetOrderStatus'] = null; // caller keeps the order's current status (e.g. Shipped)
            $config['newPaymentStatus']  = 'settled';
            $config['orderNoteKey']      = 'VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_PARTIALLY_REFUNDED_WEBHOOK';
            return ['config' => $config, 'partialRefund' => true];
        }

        return ['config' => $config, 'partialRefund' => false];
    }

    /**
     * Amount (minor units) that can still be refunded on the invoice. Never negative.
     *
     * @param array $invoiceBody
     * @return int
     */
    public static function refundableMinor(array $invoiceBody)
    {
        $returned = (int) ($invoiceBody['refunded_amount'] ?? 0) + (int) ($invoiceBody['credited_amount'] ?? 0);

        return max(0, self::settledMinor($invoiceBody) - $returned);
    }

    /**
     * Amount (minor units) that was settled. It can be lower than the invoice amount after a
     * partial settle, and is not reduced by refunds.
     *
     * @param array $invoiceBody
     * @return int
     */
    public static function settledMinor(array $invoiceBody)
    {
        return (int) ($invoiceBody['settled_amount'] ?? $invoiceBody['amount'] ?? 0);
    }

    /**
     * A settle call only counts as successful when Frisbii doesn't report another state
     * (an expired authorization returns HTTP success with state "failed").
     *
     * @param array|null $result The return value of ReepayService::settle()
     * @return bool
     */
    public static function settleSucceeded($result)
    {
        if (!is_array($result) || ($result['status'] ?? null) !== 'success') {
            return false;
        }

        $state = $result['body']['state'] ?? null;

        return $state === null || $state === 'settled';
    }
}
