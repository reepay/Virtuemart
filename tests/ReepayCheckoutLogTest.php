<?php

use PHPUnit\Framework\TestCase;

/**
 * Checkout log lines must be useful for support/QA (order, payment methods, amount, Frisbii result)
 * but must never contain customer personal data, URLs, keys or raw payloads.
 */
class ReepayCheckoutLogTest extends TestCase
{
    private static function request(array $orderExtra = [], array $methods = ['visa', 'click_to_pay'])
    {
        return [
            'order' => array_merge([
                'handle'          => 'ZSX9037',
                'currency'        => 'DKK',
                'customer'        => ['handle' => 'teddy@example.com', 'email' => 'teddy@example.com', 'first_name' => 'Test',
                                      'last_name' => 'User', 'address' => 'Road test', 'phone' => '0123456789'],
                'billing_address' => ['email' => 'teddy@example.com', 'address' => 'Road test', 'city' => 'New York',
                                      'postal_code' => '10001', 'first_name' => 'Test', 'last_name' => 'User'],
            ], $orderExtra),
            'settle'          => false,
            'accept_url'      => 'http://shop.example/index.php?option=com_virtuemart&ordernumber=ZSX9037',
            'cancel_url'      => 'http://shop.example/index.php?on=ZSX9037',
            'payment_methods' => $methods,
        ];
    }

    private static function assertNoPersonalData(string $line): void
    {
        foreach (['teddy@example.com', 'Road test', 'New York', '10001', '0123456789', 'Test', 'User', 'http', 'ordernumber'] as $secret) {
            self::assertStringNotContainsString($secret, $line, "log line leaks '$secret'");
        }
    }

    public function testRequestSummaryWithAmount(): void
    {
        $line = ReepayCheckoutLog::request(self::request(['amount' => 7998]));

        $this->assertSame('Checkout request: order=ZSX9037, payment_methods=visa,click_to_pay, amount=7998 DKK, settle=no', $line);
        self::assertNoPersonalData($line);
    }

    public function testRequestSummaryWithOrderLines(): void
    {
        $lines = [['ordertext' => 'Default product', 'amount' => 1070, 'quantity' => 2, 'vat' => 0.07, 'amount_incl_vat' => true],
                  ['ordertext' => 'Shipping', 'amount' => 500, 'quantity' => 1, 'vat' => 0.25, 'amount_incl_vat' => true]];
        $line = ReepayCheckoutLog::request(self::request(['currency' => 'EUR', 'order_lines' => $lines], ['klarna']));

        $this->assertSame('Checkout request: order=ZSX9037, payment_methods=klarna, order_lines=2 (total 2640 EUR), settle=no', $line);
        self::assertNoPersonalData($line);
    }

    public function testRequestSummaryFlagsAmountTogetherWithOrderLines(): void
    {
        $line = ReepayCheckoutLog::request(self::request(['amount' => 7998, 'order_lines' => [['amount' => 7998, 'quantity' => 1]]], ['klarna']));

        $this->assertStringContainsString('amount=7998 DKK', $line);
        $this->assertStringContainsString('order_lines=1 (total 7998 DKK)', $line);
    }

    public function testSuccessfulResponse(): void
    {
        $line = ReepayCheckoutLog::response('ZSX9037', ['status' => 'success', 'body' => ['id' => 'cs_8059e36ee4d0c4fe', 'url' => 'https://checkout.reepay.com/#/cs_8059e36ee4d0c4fe']]);

        $this->assertSame('Checkout response: order=ZSX9037, status=success, session=cs_8059e36ee4d0c4fe', $line);
    }

    public function testFrisbiiErrorResponse(): void
    {
        $error = json_encode(['error' => 'Unprocessable Content', 'message' => "parameter 'order' either amount or order_lines must be defined",
                              'path' => '/v1/session/charge', 'http_status' => 422, 'request_id' => '9d929df2fcedd35b760219c5fbc1e360']);
        $line = ReepayCheckoutLog::response('TTJ9039', ['status' => 'failure', 'error' => $error]);

        $this->assertSame("Checkout response: order=TTJ9039, status=failure, http=422, error=parameter 'order' either amount or order_lines must be defined, request_id=9d929df2fcedd35b760219c5fbc1e360", $line);
    }

    public function testFrisbiiErrorWithCode(): void
    {
        $error = json_encode(['error' => 'Bad Request', 'message' => 'No payment methods found', 'code' => 601, 'http_status' => 400, 'request_id' => 'abc']);
        $line = ReepayCheckoutLog::response('BYNA041', ['status' => 'failure', 'error' => $error]);

        $this->assertSame('Checkout response: order=BYNA041, status=failure, http=400, error=No payment methods found, code=601, request_id=abc', $line);
    }

    public function testTransportErrorIsShortenedAndHasNoUrls(): void
    {
        $line = ReepayCheckoutLog::response('X1', ['status' => 'failure', 'error' => 'SSL certificate problem: self-signed certificate for https://api.reepay.com/v1/session/charge?x=1 ' . str_repeat('x', 500)]);

        $this->assertStringStartsWith('Checkout response: order=X1, status=failure, error=SSL certificate problem: self-signed certificate', $line);
        $this->assertStringNotContainsString('http', $line);
        $this->assertLessThan(260, strlen($line));
    }

    public function testMissingDataDoesNotBreak(): void
    {
        $this->assertSame('Checkout request: order=n/a, payment_methods=n/a, amount=n/a, settle=no', ReepayCheckoutLog::request([]));
        $this->assertSame('Checkout response: order=n/a, status=n/a', ReepayCheckoutLog::response(null, null));
    }
}
