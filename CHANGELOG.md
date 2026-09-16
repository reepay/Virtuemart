## Changelog
[Unrelease]
- [Security] - Added HMAC-SHA256 signature verification for incoming Frisbii payment webhooks (plgVmOnPaymentNotification) before any invoice lookup or order status update. The webhook secret is fetched via ReepayService::getWebhooks(), cached per test/live mode for up to 10 minutes, and refreshed once automatically if the signature check fails (supports secret rotation). Malformed or unverifiable requests are rejected without disclosing why.

v 1.2.1
- [Fix] - Fixed fatal error on PHP 8+ when calling API requests without parameters (e.g. getWebhooks()).
- [Docs] - Updated Readme with detailed step-by-step installation guide for Joomla.