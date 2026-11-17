<?php

class ReepayWebhookDispatcher
{
    /**
     * Builds the event-type dispatch table from the payment method config.
     *
     * @param object $method VirtueMart payment method with status_* properties.
     * @return array<string, array>
     */
    public static function buildTable(object $method): array
    {
        return [
            'invoice_authorized' => [
                'allowedPriorStatuses'  => ['pending'],
                'expectedInvoiceStates' => ['authorized', 'settled'],
                'targetOrderStatus'     => $method->status_authorized,
                'newPaymentStatus'      => 'authorized',
                'orderNoteKey'          => 'VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_AUTHORIZED_WEBHOOK',
                'clearCart'             => true,
            ],
            'invoice_settled' => [
                'allowedPriorStatuses'  => ['pending', 'authorized'],
                'expectedInvoiceStates' => ['settled'],
                'targetOrderStatus'     => $method->status_settled,
                'newPaymentStatus'      => 'settled',
                'orderNoteKey'          => 'VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_SETTLED_WEBHOOK',
                'clearCart'             => true,
            ],
            'invoice_cancelled' => [
                'allowedPriorStatuses'  => ['pending', 'authorized'],
                'expectedInvoiceStates' => ['cancelled'],
                'targetOrderStatus'     => $method->status_cancelled,
                'newPaymentStatus'      => 'cancelled',
                'orderNoteKey'          => 'VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_CANCELLED_WEBHOOK',
                'clearCart'             => false,
            ],
            'invoice_refund' => [
                'allowedPriorStatuses'  => ['settled'],
                'expectedInvoiceStates' => ['settled'],
                'targetOrderStatus'     => $method->status_refunded,
                'newPaymentStatus'      => 'refunded',
                'orderNoteKey'          => 'VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_REFUNDED_WEBHOOK',
                'clearCart'             => false,
            ],
        ];
    }

    /**
     * Resolves whether a webhook event should trigger a VirtueMart order update.
     *
     * Returns the transition config on success, or null when the event should be
     * skipped. On null, $skipReason is set to one of:
     *   'unrecognised_event_type' — event_type is not in the dispatch table
     *   'state_mismatch'          — Frisbii invoice state does not match the event
     *   'prior_status_disallowed' — VirtueMart payment_status is not a valid prior state
     *
     * @param string      $eventType            Frisbii event_type field.
     * @param string      $invoiceState         Frisbii invoice body.state field.
     * @param string|null $currentPaymentStatus VirtueMart payment_status, or null if not found.
     * @param array       $table                Dispatch table from buildTable().
     * @param string|null $skipReason           Set on null return; caller uses for logging.
     * @return array|null Transition config or null.
     */
    public static function resolve(
        string $eventType,
        string $invoiceState,
        ?string $currentPaymentStatus,
        array $table,
        string &$skipReason = null
    ): ?array {
        if (!isset($table[$eventType])) {
            $skipReason = 'unrecognised_event_type';
            return null;
        }

        $config = $table[$eventType];

        if (!in_array($invoiceState, $config['expectedInvoiceStates'])) {
            $skipReason = 'state_mismatch';
            return null;
        }

        if ($currentPaymentStatus === null || !in_array($currentPaymentStatus, $config['allowedPriorStatuses'])) {
            $skipReason = 'prior_status_disallowed';
            return null;
        }

        return $config;
    }
}
