<?php

use PHPUnit\Framework\TestCase;

class ReepayWebhookMethodSecretStoreTest extends TestCase
{
    public function testReturnsNullWhenNothingIsCachedYet()
    {
        $method = new FakeConfigMethod();
        $store = new ReepayWebhookMethodSecretStore($method, 'test');

        $this->assertNull($store->getCachedSecret());
    }

    public function testSetCachedSecretPersistsToTheMethodAndStoresIt()
    {
        $method = new FakeConfigMethod();
        $now = 1_700_000_000;
        $store = new ReepayWebhookMethodSecretStore($method, 'test', function () use ($now) {
            return $now;
        });

        $store->setCachedSecret('a-secret', 600);

        $this->assertSame('a-secret', $method->reepay_webhook_secret_test);
        $this->assertSame($now + 600, $method->reepay_webhook_secret_test_expires);
        $this->assertSame(1, $method->storeCallCount, 'the secret must be persisted so it survives across requests');
    }

    public function testGetCachedSecretReturnsTheStoredSecretBeforeExpiry()
    {
        $method = new FakeConfigMethod();
        $now = 1_700_000_000;
        $store = new ReepayWebhookMethodSecretStore($method, 'test', function () use ($now) {
            return $now;
        });

        $store->setCachedSecret('a-secret', 600);

        $this->assertSame('a-secret', $store->getCachedSecret());
    }

    public function testGetCachedSecretReturnsNullOnceTheTenMinuteTtlHasElapsed()
    {
        $method = new FakeConfigMethod();
        $clock = ['now' => 1_700_000_000];
        $store = new ReepayWebhookMethodSecretStore($method, 'test', function () use (&$clock) {
            return $clock['now'];
        });

        $store->setCachedSecret('a-secret', 600);
        $clock['now'] += 601;

        $this->assertNull($store->getCachedSecret());
    }

    public function testInvalidateClearsTheCachedSecretAndPersistsTheClear()
    {
        $method = new FakeConfigMethod();
        $store = new ReepayWebhookMethodSecretStore($method, 'test');

        $store->setCachedSecret('a-secret', 600);
        $storeCallsBeforeInvalidate = $method->storeCallCount;
        $store->invalidate();

        $this->assertNull($store->getCachedSecret());
        $this->assertGreaterThan($storeCallsBeforeInvalidate, $method->storeCallCount);
    }

    public function testTestAndLiveModesUseSeparateCacheSlotsOnTheSameMethod()
    {
        $method = new FakeConfigMethod();
        $testStore = new ReepayWebhookMethodSecretStore($method, 'test');
        $liveStore = new ReepayWebhookMethodSecretStore($method, 'live');

        $testStore->setCachedSecret('test-secret', 600);
        $liveStore->setCachedSecret('live-secret', 600);

        $this->assertSame('test-secret', $testStore->getCachedSecret());
        $this->assertSame('live-secret', $liveStore->getCachedSecret());
    }
}
