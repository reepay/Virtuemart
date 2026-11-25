<?php

class FakeSecretStore implements ReepayWebhookSecretStoreInterface
{
    /** @var string|null */
    public $secret;

    public $invalidateCallCount = 0;
    public $setCachedSecretCallCount = 0;
    public $lastTtlSeconds;

    /** Unix time of the last refresh attempt; 0 = never. */
    public $lastRefreshAt = 0;

    /** Fake "now", shared with the verifier in tests that need a clock. */
    public $clock = 1_700_000_000;

    public function __construct($initialSecret = null)
    {
        $this->secret = $initialSecret;
    }

    public function getCachedSecret()
    {
        return $this->secret;
    }

    public function setCachedSecret($secret, $ttlSeconds)
    {
        $this->secret = $secret;
        $this->lastTtlSeconds = $ttlSeconds;
        $this->lastRefreshAt = $this->clock;
        $this->setCachedSecretCallCount++;
    }

    public function invalidate()
    {
        $this->secret = null;
        $this->lastRefreshAt = $this->clock;
        $this->invalidateCallCount++;
    }

    public function getLastRefreshAt()
    {
        return $this->lastRefreshAt;
    }
}
