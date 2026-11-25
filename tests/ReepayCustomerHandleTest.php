<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Frisbii customer handle must not be something the buyer can choose (like an email),
 * otherwise an order can be attached to another person's customer record.
 */
class ReepayCustomerHandleTest extends TestCase
{
    private const PATTERN = '/\A[a-zA-Z0-9_.\-@]{1,255}\z/';

    private function handle($userId, string $orderNumber, $stored = null, string $secret = 'secret-a'): string
    {
        return ReepayCustomerHandle::forOrder(ReepayCustomerHandle::shopKey($secret), $userId, $orderNumber, $stored);
    }

    public function testLoggedInCustomerKeepsOneHandleForAllOrders(): void
    {
        $this->assertSame($this->handle(706, 'AAA001'), $this->handle('706', 'BBB002'));
        $this->assertNotSame($this->handle(706, 'AAA001'), $this->handle(707, 'AAA001'));
    }

    public function testGuestOrdersAreKeptApart(): void
    {
        $this->assertNotSame($this->handle(0, 'AAA001'), $this->handle(0, 'BBB002'));
        $this->assertSame($this->handle(0, 'AAA001'), $this->handle(0, 'AAA001'));
    }

    public function testGuestOrderNumberCannotMatchACustomer(): void
    {
        $this->assertNotSame($this->handle(706, 'AAA001'), $this->handle(0, '706'));
        $this->assertNotSame($this->handle(706, 'AAA001'), $this->handle(0, 'u-706'));
    }

    public static function guestUserIds(): array
    {
        return ['zero' => [0], 'string zero' => ['0'], 'null' => [null], 'empty' => [''], 'negative' => [-5]];
    }

    #[DataProvider('guestUserIds')]
    public function testOrdersWithoutAUserAreTreatedAsGuests($userId): void
    {
        $this->assertSame($this->handle(0, 'AAA001'), $this->handle($userId, 'AAA001'));
    }

    public function testShopsWithDifferentSecretsDoNotCollide(): void
    {
        $this->assertNotSame($this->handle(706, 'A', null, 'secret-a'), $this->handle(706, 'A', null, 'secret-b'));
        $this->assertNotSame($this->handle(0, 'A', null, 'secret-a'), $this->handle(0, 'A', null, 'secret-b'));
    }

    public function testTheSecretIsNotPartOfTheHandle(): void
    {
        $this->assertStringNotContainsString('secret-a', $this->handle(706, 'A'));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', ReepayCustomerHandle::shopKey('secret-a'));
    }

    public static function unusualOrderNumbers(): array
    {
        return [['A+B'], ['A B'], ['A/B'], ["AB\n"], ['Å1'], [''], [str_repeat('x', 300)]];
    }

    #[DataProvider('unusualOrderNumbers')]
    public function testHandleIsAlwaysValid(string $orderNumber): void
    {
        $this->assertMatchesRegularExpression(self::PATTERN, $this->handle(0, $orderNumber));
    }

    public function testUnusualOrderNumbersStayDifferent(): void
    {
        $this->assertNotSame($this->handle(0, 'A+B'), $this->handle(0, 'A B'));
        $this->assertNotSame($this->handle(0, 'A+B'), $this->handle(0, 'AB'));
    }

    public function testRetryOfAnOrderKeepsTheHandleOfItsFirstSession(): void
    {
        // Frisbii answers "Customer cannot be changed on invoice" (error 99) otherwise
        $first = $this->handle(0, 'AAA001');
        $stored = json_encode(['order' => ['handle' => 'AAA001', 'customer' => ['handle' => $first]]]);
        $this->assertSame($first, $this->handle(706, 'AAA001', $stored), 'guest logs in before the retry');

        $old = json_encode(['order' => ['handle' => 'AAA001', 'customer' => ['handle' => 'a@example.com']]]);
        $this->assertSame('a@example.com', $this->handle(0, 'AAA001', $old), 'order started before the upgrade');
    }

    public static function unusableStoredRequests(): array
    {
        return [
            'null' => [null], 'false' => [false], 'empty' => [''], 'broken json' => ['{"order":'],
            'no handle' => ['{"order":{"handle":"AAA001"}}'],
            'not a string' => ['{"order":{"handle":"AAA001","customer":{"handle":["x"]}}}'],
            'invalid handle' => ['{"order":{"handle":"AAA001","customer":{"handle":"a+b@example.com"}}}'],
            'empty handle' => ['{"order":{"handle":"AAA001","customer":{"handle":""}}}'],
            'other order' => ['{"order":{"handle":"ZZZ999","customer":{"handle":"a@example.com"}}}'],
            'json string' => ['"order"'],
        ];
    }

    #[DataProvider('unusableStoredRequests')]
    public function testUnusableStoredRequestsAreIgnored($stored): void
    {
        $this->assertSame($this->handle(0, 'AAA001'), $this->handle(0, 'AAA001', $stored));
    }

    public function testEmailIsStillSentAsCustomerData(): void
    {
        $data = (new InvoiceOrderCustomerData())->handle('vm-x-o-A')->email('a+b@example.com')->toArray();
        $this->assertSame('vm-x-o-A', $data['handle']);
        $this->assertSame('a+b@example.com', $data['email']);
    }

    public static function missingHandles(): array
    {
        return ['not set' => [null], 'empty' => [''], 'invalid' => ['a+b@example.com']];
    }

    #[DataProvider('missingHandles')]
    public function testCustomerDataNeverFallsBackToEmailOrPhone($handle): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new InvoiceOrderCustomerData())->handle($handle)->email('a@example.com')->phoneNumber('+4512345678')->toArray();
    }
}
