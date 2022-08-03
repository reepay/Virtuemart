<?php

interface InvoiceDataInterface {
    public function toArray();
}

class InvoiceData implements InvoiceDataInterface
{
    private $order;
    private $settle;
    private $accept_url;
    private $cancel_url;
    private $payment_methods;

    public function order(InvoiceDataInterface $order) {
        $this->order = $order;
        return $this;
    }

    public function settle($settle) {
        $this->settle = $settle;
        return $this;
    }

    public function acceptUrl($acceptUrl) {
        $this->accept_url = $acceptUrl;
        return $this;
    }

    public function cancelUrl($cancelUrl) {
        $this->cancel_url = $cancelUrl;
        return $this;
    }

    public function paymentMethods(InvoiceDataInterface $paymentMehtods) {
        $this->payment_methods = $paymentMehtods;
        return $this;
    }

    public function toArray() {
        $return = ['order' => $this->order->toArray(),
            'settle' => $this->settle,
            'accept_url' => $this->accept_url,
            'cancel_url' =>  $this->cancel_url];

        if (is_array($this->payment_methods->toArray())) {
             $return['payment_methods'] = $this->payment_methods->toArray();
        }
        $return['key'] = $this->key;
       return $return;

    }

}


class InvoiceOrderBillingAddress implements InvoiceDataInterface
{
    private $email;
    private $address;
    private $address2;
    private $city;
    private $country;
    private $vat;
    private $first_name;
    private $last_name;
    private $postal_code;

    public function email($email) {

        $this->email = $email ?? null;
        return $this;
    }

    public function address($address) {
        $this->address = $address;
        return $this;
    }

    public function address2($address2) {
        $this->address2 = $address2;
        return $this;
    }

    public function city($city) {
        $this->city = $city;
        return $this;
    }

    public function country($country) {
        $this->country = $country;
        return $this;
    }

    public function vat($vat) {
        $this->vat = $vat;
        return $this;
    }

    public function firstName($firstName) {
        $this->first_name = $firstName;
        return $this;
    }

    public function lastName($lastName) {
        $this->last_name = $lastName;
        return $this;
    }

    public function postalCode($postalCode) {
        $this->postal_code = $postalCode;
        return $this;
    }

    public function toArray() {

        return [
            'email' => $this->email,
            'address' => $this->address,
            'address2' => $this->address2,
            'city' => $this->city,
            'country' => $this->country,
            'vat' => '',
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'postal_code' => $this->postal_code,
        ];
    }

}

class InvoiceOrderCustomerData implements InvoiceDataInterface
{

    private $test;
    private $handle;
    private $email;
    private $address;
    private $address2;
    private $city;
    private $country;
    private $vat;
    private $first_name;
    private $last_name;
    private $postal_code;
    private $phone_number;

    public function handle($handle) {
        $this->handle = $handle;
        return $this;
    }

    public function test($test) {
        $this->test = $test;
        return $this;
    }

    public function email($email) {
        $this->email = $email ?? null;
        return $this;
    }

    public function address($address) {
        $this->address = $address;
        return $this;
    }

    public function address2($address2) {
        $this->address2 = $address2;
        return $this;
    }

    public function city($city) {
        $this->city = $city;
        return $this;
    }

    public function country($country) {
        $this->country = $country;
        return $this;
    }

    public function var($vat) {
        $this->vat = $vat;
        return $this;
    }

    public function firstName($firstName) {
        $this->first_name = $firstName;
        return $this;
    }

    public function lastName($lastName) {
        $this->last_name = $lastName;
        return $this;
    }

    public function postalCode($postalCode) {
        $this->postal_code = $postalCode;
        return $this;
    }

    public function phoneNumber($phoneNumber) {
        $this->phone_number = $phoneNumber ?? null;
        return $this;
    }

    public function vat($vat) {
        $this->vat = $vat;
        return $this;
    }


    public function toArray() {

        return [
            'test' => $this->test,
            'handle' => $this->handle ?? $this->creatHandle(),
            'email' => $this->email,
            'address' => $this->address,
            'address2' => $this->address2,
            'city' => $this->city,
            'country' => $this->country,
            'vat' => '',
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'postal_code' => $this->postal_code,
        ];
    }

    private function creatHandle() {
        $handle = $this->email ??
            str_replace('+','', $this->phone_number) ??
            $this->generateHandle();

        if($this->validateHandle($handle)) {
            return $handle;
        } else {
            return $this->generateHandle();
        }
    }

    private function generateHandle() {
        return 'cust-' . time();
    }

    private function validateHandle($handle) {
        return preg_match('/^[a-zA-Z0-9_\.\-@]*$/', $handle);
    }
}

class InvoiceOrderData implements InvoiceDataInterface
{
    private $handle;
    private $amount;
    private $currency;
    private $customer;
    private $billingAddress;
    private $key;

    public function key($key) {
        $this->key = $key;
        return $this;
    }

    public function handle($handle) {
        $this->handle = $handle;
        return $this;
    }

    public function amount($amount) {
        $this->amount = $amount;
        return $this;
    }

    public function currency($currency) {
        $this->currency = $currency;
        return $this;
    }

    public function customer(InvoiceDataInterface $customer) {
        $this->customer = $customer;
        return $this;
    }

    public function billingAddress(InvoiceDataInterface $billingAddress) {
        $this->billingAddress = $billingAddress;
        return $this;
    }

    public function toArray() {
        return ['handle' => $this->prepareHandle($this->handle),
            'key' => $this->key,
            'amount' => $this->prepareAmount($this->amount),
            'currency' => $this->currency,
            'customer' => $this->customer->toArray(),
            'billing_address' => $this->billingAddress->toArray()
        ];
    }

    private function prepareAmount($amount) {
        return (string) ($amount * 100);
    }

    private function prepareHandle($handle) {
        if(!$this->validateHandle($handle)) {
            return $this->generateHandle();
        } else {
            return $handle;
        }
    }

    private function validateHandle($handle) {
        return preg_match('/^[a-zA-Z0-9_\.\-@]*$/', $handle);
    }

    private function generateHandle() {
        return 'invoice-' . time();
    }
}

class InvoiceOrderPaymentMethods implements InvoiceDataInterface
{
    private $payment_methods;

    public function __construct($payment_methods)
    {
        $this->payment_methods = $payment_methods;
    }

    public function toArray() {
        return $this->payment_methods;
    }
}
