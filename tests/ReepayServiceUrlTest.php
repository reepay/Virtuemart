<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The invoice handle must stay one path segment of the Frisbii API URL, so it can never
 * point a request made with the merchant's key at another api.reepay.com/v1/... path.
 */
class ReepayServiceUrlTest extends TestCase
{
    /** Records the requests instead of sending them. */
    private function serviceWithRecorder(): array
    {
        $recorder = new class {
            public array $calls = [];
            public function request($url, array $params = [])
            {
                $this->calls[] = ['url' => $url, 'params' => $params];
                return ['status' => 'failure', 'error' => 'not sent'];
            }
        };
        $service = new ReepayService('dummy');
        $property = new ReflectionProperty(ReepayService::class, 'client');
        $property->setValue($service, $recorder);

        return [$service, $recorder];
    }

    public static function normalHandles(): array
    {
        return [
            'default order number' => ['FRHU059'],
            'digits' => ['12345'],
            'dash, underscore, dot, tilde' => ['order-42_a.b~c'],
        ];
    }

    #[DataProvider('normalHandles')]
    public function testNormalOrderNumbersGiveTheSameUrlsAsBefore(string $handle): void
    {
        [$service, $recorder] = $this->serviceWithRecorder();
        $service->getInvoice($handle);
        $service->settle($handle, 717.56);
        $service->void($handle);

        $this->assertSame([
            'https://api.reepay.com/v1/invoice/' . $handle,
            'https://api.reepay.com/v1/charge/' . $handle . '/settle',
            'https://api.reepay.com/v1/charge/' . $handle . '/cancel',
        ], array_column($recorder->calls, 'url'));
    }

    public static function hostileHandles(): array
    {
        return [
            'traversal' => ['../customer/x', '..%2Fcustomer%2Fx'],
            'traversal to account' => ['../account', '..%2Faccount'],
            'slash' => ['a/b', 'a%2Fb'],
            'query and fragment' => ['x?y=1#z', 'x%3Fy%3D1%23z'],
            'percent' => ['100%', '100%25'],
            'already encoded slash' => ['a%2Fb', 'a%252Fb'],
            'space' => ['a b', 'a%20b'],
            'backslash' => ['a\\b', 'a%5Cb'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('hostileHandles')]
    public function testGetInvoiceKeepsTheHandleInOnePathSegment(string $handle, string $encoded): void
    {
        [$service, $recorder] = $this->serviceWithRecorder();
        $service->getInvoice($handle);

        $this->assertSame('https://api.reepay.com/v1/invoice/' . $encoded, $recorder->calls[0]['url']);
    }

    #[DataProvider('hostileHandles')]
    public function testSettleAndCancelKeepTheHandleInOnePathSegment(string $handle, string $encoded): void
    {
        [$service, $recorder] = $this->serviceWithRecorder();
        $service->settle($handle, 10);
        $service->void($handle);

        $this->assertSame('https://api.reepay.com/v1/charge/' . $encoded . '/settle', $recorder->calls[0]['url']);
        $this->assertSame('https://api.reepay.com/v1/charge/' . $encoded . '/cancel', $recorder->calls[1]['url']);
    }

    public function testRequestBodiesKeepTheHandleUnencoded(): void
    {
        [$service, $recorder] = $this->serviceWithRecorder();
        $service->refund('a/b', 10);
        $service->void('a/b');

        $this->assertSame('https://api.reepay.com/v1/refund', $recorder->calls[0]['url']);
        $this->assertSame(['invoice' => 'a/b', 'amount' => '1000'], $recorder->calls[0]['params']);
        $this->assertSame('a/b', $recorder->calls[1]['params']['invoice']);
    }
}
