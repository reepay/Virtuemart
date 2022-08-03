<?php

class Client {

    /**
     * @var false|resource
     */
    private $ch;

    private $privateKey;

    public function __construct($privateKey) {

        $this->privateKey = $privateKey;
        $this->ch = curl_init();

    }

    public function request ($url, array $params = null) {

        curl_setopt_array($this->ch, [
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 60
        ]);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent' => 'joomla-virtuemart/1.0'
        ];

        curl_setopt_array($this->ch, [
            CURLOPT_USERAGENT     => 'curl',
            CURLOPT_HTTPHEADER    => $headers,
            CURLOPT_USERPWD => "$this->privateKey:",
            CURLOPT_URL => $url
        ]);

        if (count($params) > 0) {
            $data      = json_encode($params, JSON_PRETTY_PRINT);
            $headers[] = 'Content-Length: ' . strlen($data);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($this->ch);

        $http_code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);

        if( 2 == intval($http_code / 100) ) {

            $result = ['status' => 'success', 'body' => json_decode( $response, true )];

        }else {

            $curl_error = curl_error( $this->ch );
            $result = ['status' => 'failure', 'error' => $response . $curl_error];
        }

        return $result;

    }

}