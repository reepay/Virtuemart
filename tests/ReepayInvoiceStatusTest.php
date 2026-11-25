<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The order status must follow what Frisbii reports for the invoice (state + refunded_amount),
 * not the payment method's instant-settle setting.
 */
class ReepayInvoiceStatusTest extends TestCase
{
    private function method(): FakeConfigMethod
    {
        $method = new FakeConfigMethod();
        $method->status_authorized = 'U';
        $method->status_settled    = 'C';
        $method->status_cancelled  = 'X';
        $method->status_refunded   = 'R';
        return $method;
    }

    private function webhookConfig(string $eventType): array
    {
        return ReepayWebhookDispatcher::buildTable($this->method())[$eventType];
    }

    // -------------------------------------------------------------------------
    // fromInvoice()
    // -------------------------------------------------------------------------

    public static function invoiceProvider(): array
    {
        return [
            'authorized'                => [['state' => 'authorized', 'amount' => 71756], 'authorized', 'authorized'],
            'settled'                   => [['state' => 'settled', 'amount' => 71756, 'refunded_amount' => 0], 'settled', 'settled'],
            'settled, no refund field'  => [['state' => 'settled', 'amount' => 71756], 'settled', 'settled'],
            'partially refunded'        => [['state' => 'settled', 'amount' => 71756, 'refunded_amount' => 1000], 'partially_refunded', 'settled'],
            'fully refunded'            => [['state' => 'settled', 'amount' => 71756, 'refunded_amount' => 71756], 'refunded', 'refunded'],
            'numeric strings'           => [['state' => 'settled', 'amount' => '71756', 'refunded_amount' => '71756'], 'refunded', 'refunded'],
        ];
    }

    #[DataProvider('invoiceProvider')]
    public function testFromInvoice(array $body, string $kind, string $paymentStatus): void
    {
        $this->assertSame(['kind' => $kind, 'payment_status' => $paymentStatus], ReepayInvoiceStatus::fromInvoice($body));
    }

    public function testUnknownOrMissingStateIsNotHandled(): void
    {
        $this->assertNull(ReepayInvoiceStatus::fromInvoice(['state' => 'failed', 'amount' => 7475]));
        $this->assertNull(ReepayInvoiceStatus::fromInvoice(['state' => 'cancelled', 'amount' => 7475]));
        $this->assertNull(ReepayInvoiceStatus::fromInvoice(['amount' => 7475]));
        $this->assertNull(ReepayInvoiceStatus::fromInvoice([]));
    }

    // -------------------------------------------------------------------------
    // forCustomerReturn(): independent of instant settle
    // -------------------------------------------------------------------------

    public function testAuthorizedInvoiceGivesAuthorizedStatusEvenWithInstantSettle(): void
    {
        $method = $this->method();
        $method->instant_settle = 1;

        $this->assertSame(
            ['order_status' => 'U', 'payment_status' => 'authorized', 'comment_key' => 'VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_AUTHORIZED'],
            ReepayInvoiceStatus::forCustomerReturn(['state' => 'authorized', 'amount' => 29899], $method)
        );
    }

    public function testSettledInvoiceGivesSettledStatusEvenWithoutInstantSettle(): void
    {
        $method = $this->method();
        $method->instant_settle = 0;

        $this->assertSame(
            ['order_status' => 'C', 'payment_status' => 'settled', 'comment_key' => 'VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_SETTLED'],
            ReepayInvoiceStatus::forCustomerReturn(['state' => 'settled', 'amount' => 29899], $method)
        );
    }

    public function testCustomerReturnIgnoresStatesItCannotHandle(): void
    {
        $this->assertNull(ReepayInvoiceStatus::forCustomerReturn(['state' => 'failed', 'amount' => 7475], $this->method()));
    }

    // -------------------------------------------------------------------------
    // forWebhook()
    // -------------------------------------------------------------------------

    public function testAuthorizedWebhookForAnAlreadySettledInvoiceSetsSettled(): void
    {
        $result = ReepayInvoiceStatus::forWebhook($this->webhookConfig('invoice_authorized'), 'invoice_authorized',
            ['state' => 'settled', 'amount' => 71756], $this->method());

        $this->assertFalse($result['partialRefund']);
        $this->assertSame('C', $result['config']['targetOrderStatus']);
        $this->assertSame('settled', $result['config']['newPaymentStatus']);
        $this->assertSame('VMPAYMENT_REEPAY_ORDER_COMMENT_PAYMENT_SETTLED_WEBHOOK', $result['config']['orderNoteKey']);
    }

    public function testAuthorizedWebhookForAnAuthorizedInvoiceIsUnchanged(): void
    {
        $config = $this->webhookConfig('invoice_authorized');
        $result = ReepayInvoiceStatus::forWebhook($config, 'invoice_authorized', ['state' => 'authorized', 'amount' => 71756], $this->method());

        $this->assertSame($config, $result['config']);
        $this->assertFalse($result['partialRefund']);
    }

    public function testPartialRefundWebhookDoesNotMarkTheOrderRefunded(): void
    {
        $result = ReepayInvoiceStatus::forWebhook($this->webhookConfig('invoice_refund'), 'invoice_refund',
            ['state' => 'settled', 'amount' => 71756, 'refunded_amount' => 1000], $this->method());

        $this->assertTrue($result['partialRefund']);
        $this->assertNull($result['config']['targetOrderStatus'], 'null = keep the current order status, e.g. Shipped');
        $this->assertSame('settled', $result['config']['newPaymentStatus']);
    }

    public function testFullRefundWebhookMarksTheOrderRefunded(): void
    {
        $config = $this->webhookConfig('invoice_refund');
        $result = ReepayInvoiceStatus::forWebhook($config, 'invoice_refund',
            ['state' => 'settled', 'amount' => 71756, 'refunded_amount' => 71756], $this->method());

        $this->assertFalse($result['partialRefund']);
        $this->assertSame('R', $result['config']['targetOrderStatus']);
        $this->assertSame('refunded', $result['config']['newPaymentStatus']);
    }

    public function testSettledAndCancelledWebhooksAreUnchanged(): void
    {
        foreach (['invoice_settled' => ['state' => 'settled', 'amount' => 71756], 'invoice_cancelled' => ['state' => 'cancelled', 'amount' => 71756]] as $event => $body) {
            $config = $this->webhookConfig($event);
            $result = ReepayInvoiceStatus::forWebhook($config, $event, $body, $this->method());
            $this->assertSame($config, $result['config'], $event);
            $this->assertFalse($result['partialRefund'], $event);
        }
    }

    // -------------------------------------------------------------------------
    // Admin actions
    // -------------------------------------------------------------------------

    public function testRefundableAmountIsWhatIsLeft(): void
    {
        $this->assertSame(71756, ReepayInvoiceStatus::refundableMinor(['amount' => 71756]));
        $this->assertSame(70756, ReepayInvoiceStatus::refundableMinor(['amount' => 71756, 'refunded_amount' => 1000]));
        $this->assertSame(0, ReepayInvoiceStatus::refundableMinor(['amount' => 71756, 'refunded_amount' => 71756]));
        $this->assertSame(0, ReepayInvoiceStatus::refundableMinor(['amount' => 1000, 'refunded_amount' => 5000]), 'never negative');
        $this->assertSame(0, ReepayInvoiceStatus::refundableMinor([]));
    }

    public function testRefundableAmountIsLimitedToWhatWasSettled(): void
    {
        $partly = ['state' => 'settled', 'amount' => 10000, 'settled_amount' => 6000];
        $this->assertSame(6000, ReepayInvoiceStatus::refundableMinor($partly));
        $this->assertSame(4000, ReepayInvoiceStatus::refundableMinor($partly + ['refunded_amount' => 2000]));
        $this->assertSame(0, ReepayInvoiceStatus::refundableMinor($partly + ['refunded_amount' => 6000]));
        $this->assertSame(0, ReepayInvoiceStatus::refundableMinor(['amount' => 10000, 'settled_amount' => 0]), 'nothing settled');
    }

    public function testCreditedAmountCannotBeRefundedAgain(): void
    {
        $body = ['amount' => 10000, 'settled_amount' => 10000, 'refunded_amount' => 2000, 'credited_amount' => 3000];
        $this->assertSame(5000, ReepayInvoiceStatus::refundableMinor($body));
    }

    public function testRefundingAllThatWasSettledIsAFullRefund(): void
    {
        $partly = ['state' => 'settled', 'amount' => 10000, 'settled_amount' => 6000];
        $this->assertSame(ReepayInvoiceStatus::REFUNDED, ReepayInvoiceStatus::fromInvoice($partly + ['refunded_amount' => 6000])['kind']);
        $this->assertSame(ReepayInvoiceStatus::PARTIALLY_REFUNDED, ReepayInvoiceStatus::fromInvoice($partly + ['refunded_amount' => 5999])['kind']);
    }

    public function testSettleIsOnlySuccessfulWhenFrisbiiReportsSettled(): void
    {
        $this->assertTrue(ReepayInvoiceStatus::settleSucceeded(['status' => 'success', 'body' => ['state' => 'settled']]));
        $this->assertTrue(ReepayInvoiceStatus::settleSucceeded(['status' => 'success', 'body' => []]), 'no state in the answer: trust the HTTP result');
        $this->assertFalse(ReepayInvoiceStatus::settleSucceeded(['status' => 'success', 'body' => ['state' => 'failed']]), 'expired authorization');
        $this->assertFalse(ReepayInvoiceStatus::settleSucceeded(['status' => 'failure', 'error' => '…']));
        $this->assertFalse(ReepayInvoiceStatus::settleSucceeded(null));
    }
}
