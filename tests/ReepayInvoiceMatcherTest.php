<?php

use PHPUnit\Framework\TestCase;

/**
 * The Frisbii invoice must match the VirtueMart order (amount in minor units + currency)
 * before the plugin confirms a payment. State alone is not enough.
 */
class ReepayInvoiceMatcherTest extends TestCase
{
    public function testMatchingAmountAndCurrencyReturnsNull(): void
    {
        $this->assertNull(ReepayInvoiceMatcher::mismatchReason(['amount' => 7998, 'currency' => 'DKK'], '79.98000', 'DKK'));
    }

    public function testDecimalTotalIsConvertedToMinorUnitsWithoutFloatErrors(): void
    {
        // 0.1 + 0.2 style float issues: 19.99 * 100 = 1998.9999999999998 in IEEE 754
        $this->assertNull(ReepayInvoiceMatcher::mismatchReason(['amount' => 1999, 'currency' => 'EUR'], 19.99, 'EUR'));
        $this->assertNull(ReepayInvoiceMatcher::mismatchReason(['amount' => 4900, 'currency' => 'EUR'], '49', 'EUR'));
    }

    public function testCurrencyComparisonIsCaseInsensitive(): void
    {
        $this->assertNull(ReepayInvoiceMatcher::mismatchReason(['amount' => 7998, 'currency' => 'dkk'], 79.98, 'DKK'));
    }

    public function testAmountOffByOneMinorUnitIsAMismatch(): void
    {
        $this->assertSame('amount_mismatch', ReepayInvoiceMatcher::mismatchReason(['amount' => 7997, 'currency' => 'DKK'], 79.98, 'DKK'));
    }

    public function testCheaperInvoiceIsAMismatch(): void
    {
        $this->assertSame('amount_mismatch', ReepayInvoiceMatcher::mismatchReason(['amount' => 100, 'currency' => 'DKK'], 799.00, 'DKK'));
    }

    public function testDifferentCurrencyIsAMismatch(): void
    {
        $this->assertSame('currency_mismatch', ReepayInvoiceMatcher::mismatchReason(['amount' => 7998, 'currency' => 'EUR'], 79.98, 'DKK'));
    }

    public function testMissingInvoiceFieldsAreRejected(): void
    {
        $this->assertSame('missing_data', ReepayInvoiceMatcher::mismatchReason(['currency' => 'DKK'], 79.98, 'DKK'));
        $this->assertSame('missing_data', ReepayInvoiceMatcher::mismatchReason(['amount' => 7998], 79.98, 'DKK'));
        $this->assertSame('missing_data', ReepayInvoiceMatcher::mismatchReason([], 79.98, 'DKK'));
    }

    public function testMissingExpectedValuesAreRejected(): void
    {
        $this->assertSame('missing_data', ReepayInvoiceMatcher::mismatchReason(['amount' => 7998, 'currency' => 'DKK'], null, 'DKK'));
        $this->assertSame('missing_data', ReepayInvoiceMatcher::mismatchReason(['amount' => 7998, 'currency' => 'DKK'], 79.98, null));
        $this->assertSame('missing_data', ReepayInvoiceMatcher::mismatchReason(['amount' => 7998, 'currency' => 'DKK'], 79.98, ''));
    }

    public function testNonNumericAmountIsRejected(): void
    {
        $this->assertSame('missing_data', ReepayInvoiceMatcher::mismatchReason(['amount' => 'abc', 'currency' => 'DKK'], 79.98, 'DKK'));
        $this->assertSame('missing_data', ReepayInvoiceMatcher::mismatchReason(['amount' => 7998, 'currency' => 'DKK'], 'abc', 'DKK'));
    }
}
