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
        // One refresh, saved in a single write (no separate invalidate write when the fetch works)
        $this->assertSame(0, $store->invalidateCallCount);
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
        $this->assertSame(0, $store->invalidateCallCount);
        $this->assertSame(1, $store->setCachedSecretCallCount);
        $this->assertSame(600, $store->lastTtlSeconds, 'the refreshed secret must be cached for no longer than 10 minutes');
    }

    // -------------------------------------------------------------------------
    // Refresh limit: forged webhooks must not trigger an API call + DB write each
    // -------------------------------------------------------------------------

    private function verifierAt(FakeSecretStore $store, callable $fetcher)
    {
        return new ReepayWebhookVerifier($store, $fetcher, function () use ($store) {
            return $store->clock;
        });
    }

    public function testForgedWebhookWithinTheRefreshIntervalIsRejectedWithoutRefetch()
    {
        $store = new FakeSecretStore('cached-secret');
        $store->lastRefreshAt = $store->clock - 10; // refreshed 10 s ago
        $fetcherCalls = 0;
        $verifier = $this->verifierAt($store, function () use (&$fetcherCalls) {
            $fetcherCalls++;
            return 'cached-secret';
        });

        $this->assertFalse($verifier->verify($this->payload(['signature' => 'forged'])));
        $this->assertSame(0, $fetcherCalls, 'no Frisbii API call within the refresh interval');
        $this->assertSame(0, $store->setCachedSecretCallCount, 'no DB write within the refresh interval');
        $this->assertSame(0, $store->invalidateCallCount, 'no DB write within the refresh interval');
        $this->assertTrue($verifier->refreshSkipped());
    }

    public function testForgedWebhookAfterTheRefreshIntervalRefreshesOnce()
    {
        $store = new FakeSecretStore('cached-secret');
        $store->lastRefreshAt = $store->clock - ReepayWebhookVerifier::REFRESH_MIN_INTERVAL;
        $fetcherCalls = 0;
        $verifier = $this->verifierAt($store, function () use (&$fetcherCalls) {
            $fetcherCalls++;
            return 'cached-secret';
        });

        $this->assertFalse($verifier->verify($this->payload(['signature' => 'forged'])));
        $this->assertSame(1, $fetcherCalls);
        $this->assertSame(1, $store->setCachedSecretCallCount);
        $this->assertFalse($verifier->refreshSkipped());
    }

    public function testTenForgedWebhooksWithinAMinuteFetchTheSecretAtMostOnce()
    {
        $store = new FakeSecretStore('cached-secret');
        $fetcherCalls = 0;
        $verifier = $this->verifierAt($store, function () use (&$fetcherCalls) {
            $fetcherCalls++;
            return 'cached-secret';
        });

        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($verifier->verify($this->payload(['id' => 'evt_' . $i, 'signature' => 'forged'])));
            $store->clock += 5;
        }

        $this->assertSame(1, $fetcherCalls, '10 forged webhooks in 50 s may cause at most 1 Frisbii API call');
        $this->assertSame(1, $store->setCachedSecretCallCount + $store->invalidateCallCount, 'and at most 1 DB write');
    }

    public function testRealRotationAfterTheRefreshIntervalIsAccepted()
    {
        $newSecret = 'rotated-secret';
        $payload = $this->payload();
        $payload['signature'] = $this->signFor($payload['timestamp'], $payload['id'], $newSecret);

        $store = new FakeSecretStore('old-secret');
        $store->lastRefreshAt = $store->clock - 120;
        $verifier = $this->verifierAt($store, function () use ($newSecret) {
            return $newSecret;
        });

        $this->assertTrue($verifier->verify($payload));
        $this->assertSame($newSecret, $store->secret);
    }

    public function testFailedRefreshIsAlsoLimited()
    {
        $store = new FakeSecretStore('cached-secret');
        $fetcherCalls = 0;
        $verifier = $this->verifierAt($store, function () use (&$fetcherCalls) {
            $fetcherCalls++;
            return null; // Frisbii API unavailable
        });

        $this->assertFalse($verifier->verify($this->payload(['signature' => 'forged'])));
        $this->assertSame(1, $fetcherCalls);
        $this->assertSame(1, $store->invalidateCallCount, 'a failed refresh clears the cache (fail closed)');

        $store->clock += 30;
        $this->assertFalse($verifier->verify($this->payload(['signature' => 'forged'])));
        $this->assertSame(1, $fetcherCalls, 'no new API call within the interval even after a failed refresh');
        $this->assertTrue($verifier->refreshSkipped());
    }

    public function testValidSignatureWithCachedSecretIsAcceptedInsideTheInterval()
    {
        $secret = 'cached-secret';
        $payload = $this->payload();
        $payload['signature'] = $this->signFor($payload['timestamp'], $payload['id'], $secret);

        $store = new FakeSecretStore($secret);
        $store->lastRefreshAt = $store->clock - 1;
        $verifier = $this->verifierAt($store, function () {
            return 'should-not-be-used';
        });

        $this->assertTrue($verifier->verify($payload));
        $this->assertFalse($verifier->refreshSkipped());
    }
}
