<?php

/**
 * Checks that a Frisbii invoice belongs to the VirtueMart order it is about to confirm,
 * by comparing amount (minor units) and currency, not just the invoice state.
 *
 * Pure function: no Joomla/VirtueMart dependencies, so it can be unit tested.
 */
class ReepayInvoiceMatcher
{
    const AMOUNT_MISMATCH   = 'amount_mismatch';
    const CURRENCY_MISMATCH = 'currency_mismatch';
    const MISSING_DATA      = 'missing_data';

    /**
     * @param array            $invoiceBody      The 'body' of ReepayService::getInvoice() (needs 'amount' and 'currency').
     * @param float|string|null $expectedTotal    Order total in payment currency as stored in payment_order_total (e.g. "79.98000").
     * @param string|null      $expectedCurrency ISO 4217 code as stored in payment_currency (e.g. "DKK").
     * @return string|null null if the invoice matches; otherwise one of the class constants.
     */
    public static function mismatchReason(array $invoiceBody, $expectedTotal, $expectedCurrency)
    {
        if (!isset($invoiceBody['amount'], $invoiceBody['currency'])
            || !is_numeric($invoiceBody['amount'])
            || $expectedTotal === null || !is_numeric($expectedTotal)
            || $expectedCurrency === null || trim((string) $expectedCurrency) === '') {
            return self::MISSING_DATA;
        }

        if ((int) $invoiceBody['amount'] !== self::toMinorUnits($expectedTotal)) {
            return self::AMOUNT_MISMATCH;
        }

        if (strtoupper(trim((string) $invoiceBody['currency'])) !== strtoupper(trim((string) $expectedCurrency))) {
            return self::CURRENCY_MISMATCH;
        }

        return null;
    }

    /**
     * Same conversion the plugin uses when sending amounts to Frisbii
     * (ReepayService::prepareAmount: round(amount, 2) * 100), rounded to an integer
     * so float artefacts like 19.99 * 100 = 1998.9999999 compare correctly.
     *
     * @param float|string $total
     * @return int
     */
    public static function toMinorUnits($total)
    {
        return (int) round(round((float) $total, 2) * 100);
    }
}
