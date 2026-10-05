## Changelog
[Unrelease]
- [Security] - Added HMAC-SHA256 signature verification for incoming Frisbii payment webhooks (plgVmOnPaymentNotification) before any invoice lookup or order status update. The webhook secret is fetched via ReepayService::getWebhooks(), cached per test/live mode for up to 10 minutes, and refreshed once automatically if the signature check fails (supports secret rotation). Malformed or unverifiable requests are rejected without disclosing why.
- [Fix] - Fixed several undefined property warnings raised on every checkout (plgVmConfirmedOrder): payment_currency was read before getPaymentCurrency() set it, reepay_instant_settle was read instead of the real field instant_settle (which also made reepay_payment_type always record "authorization" regardless of the actual setting), and the customer/billing address builder used address1/address2/firs_name instead of VirtueMart's real address_1/address_2/first_name fields. Also removed a leftover InvoiceData::$key reference that doesn't belong on that class.

v 1.2.1
- [Fix] - Fixed fatal error on PHP 8+ when calling API requests without parameters (e.g. getWebhooks()).
- [Docs] - Updated Readme with detailed step-by-step installation guide for Joomla.