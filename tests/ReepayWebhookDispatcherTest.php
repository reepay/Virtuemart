<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReepayWebhookDispatcherTest extends TestCase
{
    private function makeMethod(): FakeConfigMethod
    {
        $method = new FakeConfigMethod();
        $method->status_authorized = 'P';
        $method->status_settled    = 'C';
        $method->status_cancelled  = 'X';
        $method->status_refunded   = 'R';
        return $method;
    }

    private function table(): array
    {
        return ReepayWebhookDispatcher::buildTable($this->makeMethod());
    }

    // -------------------------------------------------------------------------
    // Group A — invoice_authorized
    // -------------------------------------------------------------------------

    public function testAuthorizedFromPendingProceeds()
    {
        $config = ReepayWebhookDispatcher::resolve('invoice_authorized', 'authorized', 'pending', $this->table());

        $this->assertNotNull($config);
        $this->assertSame('authorized', $config['newPaymentStatus']);
        $this->assertSame('P', $config['targetOrderStatus']);
        $this->assertTrue($config['clearCart']);
    }

    public function testAuthorizedWithInvoiceStateSettledAlsoProceeds()
    {
        // Frisbii may report state=settled on the authorized event in instant-settle mode
        $config = ReepayWebhookDispatcher::resolve('invoice_authorized', 'settled', 'pending', $this->table());

        $this->assertNotNull($config);
        $this->assertSame('authorized', $config['newPaymentStatus']);
    }

    public function testAuthorizedRetrySkipped()
    {
        // Order is already authorized — idempotent skip
        $config = ReepayWebhookDispatcher::resolve('invoice_authorized', 'authorized', 'authorized', $this->table(), $reason);

        $this->assertNull($config);
        $this->assertSame('prior_status_disallowed', $reason);
    }

    // -------------------------------------------------------------------------
    // Group B — invoice_settled
    // -------------------------------------------------------------------------

    public function testSettledFromPendingProceeds()
    {
        $config = ReepayWebhookDispatcher::resolve('invoice_settled', 'settled', 'pending', $this->table());

        $this->assertNotNull($config);
        $this->assertSame('settled', $config['newPaymentStatus']);
        $this->assertSame('C', $config['targetOrderStatus']);
        $this->assertTrue($config['clearCart']);
    }

    public function testSettledFromAuthorizedProceeds()
    {
        // The MMEC022 scenario: settle webhook fires after the return-URL flow
        // already set payment_status=authorized. Must NOT be blocked.
        $config = ReepayWebhookDispatcher::resolve('invoice_settled', 'settled', 'authorized', $this->table());

        $this->assertNotNull($config);
        $this->assertSame('settled', $config['newPaymentStatus']);
    }

    public function testSettledRetrySkipped()
    {
        $config = ReepayWebhookDispatcher::resolve('invoice_settled', 'settled', 'settled', $this->table(), $reason);

        $this->assertNull($config);
        $this->assertSame('prior_status_disallowed', $reason);
    }

    public function testSettledWithWrongInvoiceStateSkipped()
    {
        // Frisbii sent invoice_settled but the invoice is still authorized — skip
        $config = ReepayWebhookDispatcher::resolve('invoice_settled', 'authorized', 'pending', $this->table(), $reason);

        $this->assertNull($config);
        $this->assertSame('state_mismatch', $reason);
    }

    // -------------------------------------------------------------------------
    // Group C — invoice_cancelled
    // -------------------------------------------------------------------------

    public function testCancelledFromPendingProceeds()
    {
        $config = ReepayWebhookDispatcher::resolve('invoice_cancelled', 'cancelled', 'pending', $this->table());

        $this->assertNotNull($config);
        $this->assertSame('cancelled', $config['newPaymentStatus']);
        $this->assertSame('X', $config['targetOrderStatus']);
        $this->assertFalse($config['clearCart']);
    }

    public function testCancelledFromAuthorizedProceeds()
    {
        $config = ReepayWebhookDispatcher::resolve('invoice_cancelled', 'cancelled', 'authorized', $this->table());

        $this->assertNotNull($config);
        $this->assertSame('cancelled', $config['newPaymentStatus']);
    }

    public function testCancelledRetrySkipped()
    {
        $config = ReepayWebhookDispatcher::resolve('invoice_cancelled', 'cancelled', 'cancelled', $this->table(), $reason);

        $this->assertNull($config);
        $this->assertSame('prior_status_disallowed', $reason);
    }

    public function testCancelledFromSettledBlocked()
    {
        // A settled invoice cannot be voided — settled is not in allowedPriorStatuses
        $config = ReepayWebhookDispatcher::resolve('invoice_cancelled', 'cancelled', 'settled', $this->table(), $reason);

        $this->assertNull($config);
        $this->assertSame('prior_status_disallowed', $reason);
    }

    // -------------------------------------------------------------------------
    // Group D — invoice_refund
    // -------------------------------------------------------------------------

    public function testRefundFromSettledProceeds()
    {
        // invoice state stays 'settled' after a refund — the event_type is authoritative
        $config = ReepayWebhookDispatcher::resolve('invoice_refund', 'settled', 'settled', $this->table());

        $this->assertNotNull($config);
        $this->assertSame('refunded', $config['newPaymentStatus']);
        $this->assertSame('R', $config['targetOrderStatus']);
        $this->assertFalse($config['clearCart']);
    }

    public function testRefundRetrySkipped()
    {
        $config = ReepayWebhookDispatcher::resolve('invoice_refund', 'settled', 'refunded', $this->table(), $reason);

        $this->assertNull($config);
        $this->assertSame('prior_status_disallowed', $reason);
    }

    public function testRefundFromAuthorizedBlocked()
    {
        $config = ReepayWebhookDispatcher::resolve('invoice_refund', 'settled', 'authorized', $this->table(), $reason);

        $this->assertNull($config);
        $this->assertSame('prior_status_disallowed', $reason);
    }

    public function testRefundFromPendingBlocked()
    {
        $config = ReepayWebhookDispatcher::resolve('invoice_refund', 'settled', 'pending', $this->table(), $reason);

        $this->assertNull($config);
        $this->assertSame('prior_status_disallowed', $reason);
    }

    // -------------------------------------------------------------------------
    // Group E — Edge cases
    // -------------------------------------------------------------------------

    public function testUnrecognisedEventTypeSkipped()
    {
        $config = ReepayWebhookDispatcher::resolve('invoice_dunning', 'dunning', 'pending', $this->table(), $reason);

        $this->assertNull($config);
        $this->assertSame('unrecognised_event_type', $reason);
    }

    public function testNullPaymentStatusSkipped()
    {
        // payment_data not found in VirtueMart — treat as no valid prior status
        $config = ReepayWebhookDispatcher::resolve('invoice_settled', 'settled', null, $this->table(), $reason);

        $this->assertNull($config);
        $this->assertSame('prior_status_disallowed', $reason);
    }

    // -------------------------------------------------------------------------
    // buildTable — config values wired to $method correctly
    // -------------------------------------------------------------------------

    #[DataProvider('statusFieldProvider')]
    public function testTargetOrderStatusIsTakenFromMethod(string $eventType, string $methodField)
    {
        $method = $this->makeMethod();
        $table  = ReepayWebhookDispatcher::buildTable($method);

        $this->assertSame($method->$methodField, $table[$eventType]['targetOrderStatus']);
    }

    public static function statusFieldProvider(): array
    {
        return [
            'authorized uses status_authorized' => ['invoice_authorized', 'status_authorized'],
            'settled uses status_settled'        => ['invoice_settled',    'status_settled'],
            'cancelled uses status_cancelled'    => ['invoice_cancelled',  'status_cancelled'],
            'refund uses status_refunded'        => ['invoice_refund',     'status_refunded'],
        ];
    }
}
