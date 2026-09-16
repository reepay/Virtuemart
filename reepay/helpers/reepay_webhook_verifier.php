<?php

interface ReepayWebhookSecretStoreInterface
{
    /**
     * @return string|null The cached secret, or null if there is none or it has expired.
     */
    public function getCachedSecret();

    /**
     * @param string $secret
     * @param int $ttlSeconds
     * @return void
     */
    public function setCachedSecret($secret, $ttlSeconds);

    /**
     * Discards the cached secret so the next getCachedSecret() call returns null.
     *
     * @return void
     */
    public function invalidate();
}

/**
 * Verifies that a Frisbii/Reepay webhook notification was actually signed by Frisbii,
 * using HMAC-SHA256 over `timestamp . id`, before any order lookup or status change.
 *
 * Framework-independent by design: it depends only on ReepayWebhookSecretStoreInterface
 * and a secret-fetcher callable, so it can be unit tested without booting Joomla/VirtueMart.
 */
class ReepayWebhookVerifier
{
    const CACHE_TTL_SECONDS = 600;

    const REQUIRED_FIELDS = ['timestamp', 'id', 'signature', 'invoice', 'event_type'];

    /** @var ReepayWebhookSecretStoreInterface */
    private $secretStore;

    /** @var callable */
    private $secretFetcher;

    public function __construct(ReepayWebhookSecretStoreInterface $secretStore, callable $secretFetcher)
    {
        $this->secretStore = $secretStore;
        $this->secretFetcher = $secretFetcher;
    }

    /**
     * @param array $payload The decoded webhook JSON body.
     * @return bool True only if the payload is well-formed and its signature is valid.
     */
    public function verify(array $payload)
    {
        if (!$this->hasRequiredFields($payload)) {
            return false;
        }

        $secret = $this->secretStore->getCachedSecret();

        if (!is_string($secret) || $secret === '') {
            $secret = $this->fetchAndCache();
            if ($secret === null) {
                return false;
            }
        }

        if ($this->signatureMatches($payload, $secret)) {
            return true;
        }

        // Signature mismatch: the secret may have just been rotated. Refresh once and retry.
        $this->secretStore->invalidate();
        $secret = $this->fetchAndCache();
        if ($secret === null) {
            return false;
        }

        return $this->signatureMatches($payload, $secret);
    }

    private function hasRequiredFields(array $payload)
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '') {
                return false;
            }
        }

        return true;
    }

    private function signatureMatches(array $payload, $secret)
    {
        $expected = hash_hmac('sha256', $payload['timestamp'] . $payload['id'], $secret);

        return hash_equals($expected, (string) $payload['signature']);
    }

    private function fetchAndCache()
    {
        $secret = call_user_func($this->secretFetcher);

        if (empty($secret) || !is_string($secret)) {
            return null;
        }

        $this->secretStore->setCachedSecret($secret, self::CACHE_TTL_SECONDS);

        return $secret;
    }
}
