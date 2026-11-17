<?php

use PHPUnit\Framework\TestCase;

/**
 * Klarna order lines sent to Frisbii must:
 *  - use the UNIT price as 'amount' (Frisbii multiplies by quantity),
 *  - be in the payment currency,
 *  - add up exactly to the order total, otherwise not be sent at all.
 */
class ReepayOrderLinesTest extends TestCase
{
    private static function item($name, $unitInclTax, $unitTax, $qty)
    {
        return (object) ['product_name' => $name, 'product_final_price' => $unitInclTax, 'product_tax' => $unitTax, 'product_quantity' => $qty];
    }

    private static function bt(array $overrides = [])
    {
        return (object) array_merge(['order_shipment' => 0, 'order_shipment_tax' => 0, 'order_payment' => 0, 'order_payment_tax' => 0], $overrides);
    }

    private static function sameCurrency()
    {
        return function ($amount) { return $amount; };
    }

    public function testSingleItemUsesUnitPriceInMinorUnits(): void
    {
        $lines = ReepayOrderLines::build([self::item('Default product', 79.98, 5.23, 1)], self::bt(), self::sameCurrency(), 79.98);

        $this->assertCount(1, $lines);
        $this->assertSame(7998, $lines[0]['amount']);
        $this->assertSame(1, $lines[0]['quantity']);
        $this->assertTrue($lines[0]['amount_incl_vat']);
        $this->assertSame('Default product', $lines[0]['ordertext']);
    }

    public function testQuantityIsNotDoubleCounted(): void
    {
        // 2 x 79.98 = 159.96: amount must be the UNIT price, Frisbii multiplies by quantity
        $lines = ReepayOrderLines::build([self::item('Default product', 79.98, 5.23, 2)], self::bt(), self::sameCurrency(), 159.96);

        $this->assertSame(7998, $lines[0]['amount']);
        $this->assertSame(2, $lines[0]['quantity']);
    }

    public function testVatRateIsComputedFromUnitPrice(): void
    {
        // 125.00 incl. 25.00 tax -> 25 % on 100.00
        $lines = ReepayOrderLines::build([self::item('Shirt', 125.00, 25.00, 3)], self::bt(), self::sameCurrency(), 375.00);

        $this->assertSame(0.25, $lines[0]['vat']);
    }

    public function testAmountsAreConvertedToPaymentCurrency(): void
    {
        // shop in DKK, payment in EUR (1 DKK = 0.134 EUR in this fake)
        $toEur = function ($amount) { return $amount * 0.134; };
        $lines = ReepayOrderLines::build([self::item('Default product', 79.98, 5.23, 1)], self::bt(), $toEur, round(79.98 * 0.134, 2));

        $this->assertSame(1072, $lines[0]['amount']); // 10.72 EUR, not 7998
    }

    public function testShipmentAndPaymentFeeAreIncludedWithTax(): void
    {
        // VirtueMart stores order_shipment / order_payment WITHOUT tax, tax separately
        $bt = self::bt(['order_shipment' => 40.00, 'order_shipment_tax' => 10.00, 'order_payment' => 8.00, 'order_payment_tax' => 2.00]);
        $lines = ReepayOrderLines::build([self::item('Default product', 79.98, 5.23, 1)], $bt, self::sameCurrency(), 139.98);

        $this->assertCount(3, $lines);
        $this->assertSame(['Default product', 'Shipping', 'Payment fee'], array_column($lines, 'ordertext'));
        $this->assertSame(5000, $lines[1]['amount']);
        $this->assertSame(0.25, $lines[1]['vat']);
        $this->assertSame(1000, $lines[2]['amount']);
    }

    public function testLinesThatDoNotAddUpToTheTotalAreDropped(): void
    {
        // e.g. a coupon reduced the order total: lines would overcharge, so send none (caller falls back to amount)
        $lines = ReepayOrderLines::build([self::item('Default product', 79.98, 5.23, 1)], self::bt(), self::sameCurrency(), 69.98);

        $this->assertSame([], $lines);
    }

    public function testOrderItemNameIsUsedAsLineText(): void
    {
        // order_item_name is the name stored on the order; product_name is the CURRENT catalog name
        $item = (object) ['order_item_name' => 'Shirt (ordered name)', 'product_name' => 'Shirt (renamed later)',
                          'product_final_price' => 79.98, 'product_tax' => 5.23, 'product_quantity' => 1];
        $lines = ReepayOrderLines::build([$item], self::bt(), self::sameCurrency(), 79.98);

        $this->assertSame('Shirt (ordered name)', $lines[0]['ordertext']);
    }

    public function testDeletedProductStillHasLineText(): void
    {
        // product deleted from catalog: VirtueMart can't add product_name, order_item_name is still there
        $item = (object) ['order_item_name' => 'Old product', 'product_final_price' => 79.98, 'product_tax' => 5.23, 'product_quantity' => 1];
        $lines = ReepayOrderLines::build([$item], self::bt(), self::sameCurrency(), 79.98);

        $this->assertSame('Old product', $lines[0]['ordertext']);
    }

    public function testEmptyItemsGiveNoLines(): void
    {
        $this->assertSame([], ReepayOrderLines::build([], self::bt(), self::sameCurrency(), 0));
    }

    public function testRequestSendsOrderLinesWithoutAmount(): void
    {
        $data = $this->orderData()->orderLines([['ordertext' => 'x', 'amount' => 7998, 'vat' => 0.07, 'quantity' => 1, 'amount_incl_vat' => true]])->toArray();

        $this->assertArrayHasKey('order_lines', $data);
        $this->assertArrayNotHasKey('amount', $data, 'Frisbii rejects amount together with order_lines (422)');
    }

    public function testRequestSendsAmountWhenThereAreNoOrderLines(): void
    {
        $data = $this->orderData()->toArray();

        $this->assertSame(7998, $data['amount']);
        $this->assertArrayNotHasKey('order_lines', $data);
    }

    public function testRequestSendsAmountWhenOrderLinesAreEmpty(): void
    {
        $data = $this->orderData()->orderLines([])->toArray();

        $this->assertSame(7998, $data['amount']);
        $this->assertArrayNotHasKey('order_lines', $data);
    }

    private function orderData()
    {
        return (new InvoiceOrderData())
            ->handle('ORDER1')
            ->amount(79.98)
            ->currency('DKK')
            ->customer((new InvoiceOrderCustomerData())->handle('a@example.com')->email('a@example.com'))
            ->billingAddress((new InvoiceOrderBillingAddress())->email('a@example.com'));
    }
}
