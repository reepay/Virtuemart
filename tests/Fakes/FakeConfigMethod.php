<?php

/**
 * Stands in for the VirtueMart payment-method config object ($method), which is a
 * VmTable instance in production. Only the pieces ReepayWebhookMethodSecretStore
 * touches are reproduced here: dynamic parameterable properties plus a store() call
 * that would persist payment_params back to #__virtuemart_paymentmethods.
 */
#[AllowDynamicProperties]
class FakeConfigMethod
{
    public $storeCallCount = 0;

    public function store()
    {
        $this->storeCallCount++;
        return true;
    }
}
