<?php

require 'http_client.php';

class ReepayService
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var null
     */
    private $private_key = null;

    public function __construct($privateKey) {
        $this->setPrivateKey($privateKey);
        $this->client = new Client($privateKey);
    }

    public function setPrivateKey($key) {
        $this->private_key = $key;
    }

    /**
     *
     * @param Request $request
     * @return string []
     */
    public function createCheckoutSession($params)
    {
        return $this->client->request( 'https://checkout-api.reepay.com/v1/session/charge', $params);
    }

    /**
     * @param $invoice_handle
     * @param $amount
     * @return void
     */
    public function settle($invoice_handle, $amount)
    {
        $amount = $this->prepareAmount($amount);
        $data = [ 'amount' => $amount ];
        return $this->client->request('https://api.reepay.com/v1/charge/' . $invoice_handle . '/settle', $data);
    }

    /**
     * @param $invoice_handle
     * @return mixed
     */
    public function getInvoice($invoice_handle) {
        return $this->client->request('https://api.reepay.com/v1/invoice/' . $invoice_handle);
     }

    /**
     * @param $invoice_handle
     * @param $amount
     * @return void
     */
    public function refund($invoice_handle, $amount)
    {
        $amount = $this->prepareAmount($amount);
        $data = [
            'invoice' => $invoice_handle,
            'amount' => $amount
        ];
        return $this->client->request( 'https://api.reepay.com/v1/refund', $data);
    }

    /**
     * @param $invoice_handle
     * @return void
     */
    public function void($invoice_handle) {
        $data = [
            'invoice' => $invoice_handle,
        ];

        return $this->client->request('https://api.reepay.com/v1/charge/'. $invoice_handle .'/cancel', $data);
    }

    /**
     *
     * @return void
     */
    public function getWebhooks() {
        return $this->client->request('https://api.reepay.com/v1/account/webhook_settings');
    }

    private function prepareAmount($amount) {
        return (string) ( round( $amount, 2) * 100 );
    }

}
