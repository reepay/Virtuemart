<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReepayWebhookVerifierTest extends TestCase
{
    private function payload(array $overrides = [])
    {
        return array_merge([
            'timestamp' => '1700000000',
            'id' => 'evt_123',
            'signature' => 'placeholder',
            'invoice' => 'order-42',
            'event_type' => 'invoice_authorized',
        ], $overrides);
    }

    private function signFor($timestamp, $id, $secret)
    {
        return hash_hmac('sha256', $timestamp . $id, $secret);
    }

    public function testAcceptsAValidSignatureAgainstTheCachedSecret()
    {
        $secret = 'cached-secret';
        $payload = $this->payload();
        $payload['signature'] = $this->signFor($payload['timestamp'], $payload['id'], $secret);

        $store = new FakeSecretStore($secret);
        $fetcherCalls = 0;
        $verifier = new ReepayWebhookVerifier($store, function () use (&$fetcherCalls) {
            $fetcherCalls++;
            return 'should-not-be-used';
        });

        $this->assertTrue($verifier->verify($payload));
        $this->assertSame(0, $fetcherCalls, 'a valid cached secret must not trigger an API refetch');
    }

    public function testRejectsAForgedSignatureAfterTheRotationRetryAlsoFails()
    {
        $payload = $this->payload(['signature' => 'not-a-real-signature']);

        $store = new FakeSecretStore('cached-secret');
        $verifier = new ReepayWebhookVerifier($store, function () {
            return 'freshly-fetched-secret';
        });

        $this->assertFalse($verifier->verify($payload));
        $this->assertSame(1, $store->invalidateCallCount);
        $this->assertSame(1, $store->setCachedSecretCallCount);
    }

    #[DataProvider('missingFieldProvider')]
    public function testRejectsAPayloadMissingARequiredField($fieldToRemove)
    {
        $payload = $this->payload();
        unset($payload[$fieldToRemove]);

        $store = new FakeSecretStore('cached-secret');
        $fetcherCalls = 0;
        $verifier = new ReepayWebhookVerifier($store, function () use (&$fetcherCalls) {
            $fetcherCalls++;
            return 'cached-secret';
        });

        $this->assertFalse($verifier->verify($payload));
        $this->assertSame(0, $fetcherCalls, 'a malformed payload must be rejected before any secret lookup');
    }

    public static function missingFieldProvider()
    {
        return [
            'missing timestamp' => ['timestamp'],
            'missing id' => ['id'],
            'missing signature' => ['signature'],
            'missing invoice' => ['invoice'],
            'missing event_type' => ['event_type'],
        ];
    }

    public function testFailsClosedWhenTheWebhookSecretCannotBeRetrieved()
    {
        $payload = $this->payload();

        $store = new FakeSecretStore(null); // nothing cached yet
        $verifier = new ReepayWebhookVerifier($store, function () {
            return null; // ReepayService::getWebhookSecret() failed
        });

        $this->assertFalse($verifier->verify($payload));
    }

    public function testSupportsWebhookSecretRotationByRefreshingOnceAndRetrying()
    {
        $oldSecret = 'old-secret';
        $newSecret = 'rotated-secret';
        $payload = $this->payload();
        $payload['signature'] = $this->signFor($payload['timestamp'], $payload['id'], $newSecret);

        $store = new FakeSecretStore($oldSecret);
        $verifier = new ReepayWebhookVerifier($store, function () use ($newSecret) {
            return $newSecret;
        });

        $this->assertTrue($verifier->verify($payload));
        $this->assertSame(1, $store->invalidateCallCount);
        $this->assertSame(1, $store->setCachedSecretCallCount);
        $this->assertSame(600, $store->lastTtlSeconds, 'the refreshed secret must be cached for no longer than 10 minutes');
    }
}
