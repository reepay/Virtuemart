<?php

require_once __DIR__ . '/reepay_webhook_verifier.php';

/**
 * Caches the Frisbii webhook secret on the VirtueMart payment-method config row
 * (payment_params on #__virtuemart_paymentmethods), the same storage VirtueMart already
 * uses for private_key_test/private_key_live. This survives across requests regardless
 * of SAPI (unlike PHP session/static state), and is scoped per test/live mode so
 * rotating one does not clobber the other.
 *
 * $method is the VmTable-backed payment-method object (loaded via getVmPluginMethod());
 * only dynamic property access and store() are required, so it is duck-typed here to
 * keep this class free of any VirtueMart/Joomla class dependency.
 */
class ReepayWebhookMethodSecretStore implements ReepayWebhookSecretStoreInterface
{
    private $method;
    private $secretField;
    private $expiresField;

    /** @var callable */
    private $now;

    public function __construct($method, $mode, ?callable $now = null)
    {
        $this->method = $method;
        $this->secretField = 'reepay_webhook_secret_' . $mode;
        $this->expiresField = $this->secretField . '_expires';
        $this->now = $now ?: 'time';
    }

    public function getCachedSecret()
    {
        $secret = isset($this->method->{$this->secretField}) ? $this->method->{$this->secretField} : '';
        $expires = isset($this->method->{$this->expiresField}) ? (int) $this->method->{$this->expiresField} : 0;

        if (empty($secret) || $expires <= call_user_func($this->now)) {
            return null;
        }

        return $secret;
    }

    public function setCachedSecret($secret, $ttlSeconds)
    {
        $this->method->{$this->secretField} = $secret;
        $this->method->{$this->expiresField} = call_user_func($this->now) + $ttlSeconds;
        $this->storeExistingRow();
    }

    public function invalidate()
    {
        $this->method->{$this->secretField} = '';
        $this->method->{$this->expiresField} = 0;
        $this->storeExistingRow();
    }

    /**
     * VmTable::load() leaves $_update reset to null (bind() clears it right after
     * load() sets it), so a plain store() re-runs check() - including the payment_name
     * slug-uniqueness lookup, which errors on installs whose paymentmethods table
     * predates that column. $method here is always an already-loaded, existing row,
     * never a new one, so it's safe to tell VmTable that directly and skip check().
     */
    private function storeExistingRow()
    {
        $this->method->_update = true;
        $this->method->store();
    }
}
