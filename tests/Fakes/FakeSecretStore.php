<?php

class FakeSecretStore implements ReepayWebhookSecretStoreInterface
{
    /** @var string|null */
    public $secret;

    public $invalidateCallCount = 0;
    public $setCachedSecretCallCount = 0;
    public $lastTtlSeconds;

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
        $this->setCachedSecretCallCount++;
    }

    public function invalidate()
    {
        $this->secret = null;
        $this->invalidateCallCount++;
    }
}
