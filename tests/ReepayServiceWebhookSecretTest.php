<?php

use PHPUnit\Framework\TestCase;

class ReepayServiceWebhookSecretTest extends TestCase
{
    public function testExtractsTheSecretFromASuccessfulResponse()
    {
        $result = [
            'status' => 'success',
            'body' => ['secret' => 'whsec_abc123', 'urls' => []],
        ];

        $this->assertSame('whsec_abc123', ReepayService::extractSecretFromWebhooksResult($result));
    }

    public function testReturnsNullWhenTheApiCallFailed()
    {
        $result = ['status' => 'failure', 'error' => 'HTTP 401 Unauthorized'];

        $this->assertNull(ReepayService::extractSecretFromWebhooksResult($result));
    }

    public function testReturnsNullWhenTheSuccessfulResponseHasNoSecretField()
    {
        $result = ['status' => 'success', 'body' => ['urls' => []]];

        $this->assertNull(ReepayService::extractSecretFromWebhooksResult($result));
    }

    public function testReturnsNullForACompletelyMalformedResult()
    {
        $this->assertNull(ReepayService::extractSecretFromWebhooksResult(null));
        $this->assertNull(ReepayService::extractSecretFromWebhooksResult([]));
    }
}
