<?php

/**
 * Stands in for the VirtueMart payment-method config object ($method), which is a
 * VmTable instance in production. Only the pieces ReepayWebhookMethodSecretStore
 * touches are reproduced here: dynamic parameterable properties plus a store() call
 * that would persist payment_params back to #__virtuemart_paymentmethods.
 *
 * $_update mirrors VmTable's own public property of the same name: real VmTable::load()
 * sets it true but then immediately calls bind(), whose first line resets it to null -
 * so a plain store() right after load() always re-runs check(), including a
 * payment_name slug-uniqueness lookup that errors on installs missing that column
 * (found via live testing). store() here fails the same way the real class would if
 * the caller didn't compensate by setting $_update = true itself first.
 */
#[AllowDynamicProperties]
class FakeConfigMethod
{
    public $storeCallCount = 0;
    public $_update = null;

    public function store()
    {
        if ($this->_update !== true) {
            throw new \RuntimeException('store() called without $_update === true - would trigger check() and the slug-uniqueness bug in real VmTable');
        }

        $this->storeCallCount++;
        return true;
    }
}
