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
     * Also records the time as a refresh attempt (used after a failed fetch).
     *
     * @return void
     */
    public function invalidate();

    /**
     * @return int Unix time of the last refresh attempt (setCachedSecret() or invalidate()), 0 if never.
     */
    public function getLastRefreshAt();
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

    /**
     * Minimum seconds between two secret refreshes. A forged webhook looks like a rotated
     * secret, so without this limit every forged request would cost a Frisbii API call and a
     * database write.
     */
    const REFRESH_MIN_INTERVAL = 60;

    const REQUIRED_FIELDS = ['timestamp', 'id', 'signature', 'invoice', 'event_type'];

    /** @var ReepayWebhookSecretStoreInterface */
    private $secretStore;

    /** @var callable */
    private $secretFetcher;

    /** @var callable */
    private $now;

    /** @var bool */
    private $refreshSkipped = false;

    public function __construct(ReepayWebhookSecretStoreInterface $secretStore, callable $secretFetcher, ?callable $now = null)
    {
        $this->secretStore = $secretStore;
        $this->secretFetcher = $secretFetcher;
        $this->now = $now ?: 'time';
    }

    /**
     * @param array $payload The decoded webhook JSON body.
     * @return bool True only if the payload is well-formed and its signature is valid.
     */
    public function verify(array $payload)
    {
        $this->refreshSkipped = false;

        if (!$this->hasRequiredFields($payload)) {
            return false;
        }

        $secret = $this->secretStore->getCachedSecret();

        if (!is_string($secret) || $secret === '') {
            $secret = $this->refreshSecret();
            if ($secret === null) {
                return false;
            }
        }

        if ($this->signatureMatches($payload, $secret)) {
            return true;
        }

        // Signature mismatch: the secret may have just been rotated. Refresh (at most once per
        // REFRESH_MIN_INTERVAL) and retry.
        $secret = $this->refreshSecret();
        if ($secret === null) {
            return false;
        }

        return $this->signatureMatches($payload, $secret);
    }

    /**
     * True when the last verify() call wanted to refresh the secret but skipped it because
     * the previous refresh was less than REFRESH_MIN_INTERVAL seconds ago.
     *
     * @return bool
     */
    public function refreshSkipped()
    {
        return $this->refreshSkipped;
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

    /**
     * Fetches the secret from Frisbii and caches it (one store write), unless the last refresh
     * attempt was less than REFRESH_MIN_INTERVAL seconds ago. A failed fetch clears the cache
     * (fail closed) and also counts as a refresh attempt, so an unavailable API isn't hammered.
     *
     * @return string|null
     */
    private function refreshSecret()
    {
        $lastRefresh = (int) $this->secretStore->getLastRefreshAt();

        if ($lastRefresh > 0 && call_user_func($this->now) - $lastRefresh < self::REFRESH_MIN_INTERVAL) {
            $this->refreshSkipped = true;
            return null;
        }

        $secret = call_user_func($this->secretFetcher);

        if (empty($secret) || !is_string($secret)) {
            $this->secretStore->invalidate();
            return null;
        }

        $this->secretStore->setCachedSecret($secret, self::CACHE_TTL_SECONDS);

        return $secret;
    }
}
