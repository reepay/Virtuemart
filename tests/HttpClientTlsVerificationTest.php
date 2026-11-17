<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression guard: the Reepay HTTP client must never disable TLS
 * certificate verification. With verification off, anyone on the network
 * path can forge invoice states (orders marked as paid) and capture the
 * private API key sent via Basic auth.
 */
class HttpClientTlsVerificationTest extends TestCase
{
    private const CLIENT_FILE = __DIR__ . '/../reepay/helpers/http_client.php';

    private function clientSource(): string
    {
        $source = file_get_contents(self::CLIENT_FILE);
        $this->assertNotFalse($source, 'Could not read http_client.php');
        return $source;
    }

    public function testPeerVerificationIsNotDisabled(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/CURLOPT_SSL_VERIFYPEER\s*(=>|,)\s*(false|0)\b/i',
            $this->clientSource(),
            'CURLOPT_SSL_VERIFYPEER must not be disabled in the Reepay HTTP client'
        );
    }

    public function testVerificationIsExplicitlyEnabled(): void
    {
        $source = $this->clientSource();

        $this->assertMatchesRegularExpression(
            '/CURLOPT_SSL_VERIFYPEER\s*=>\s*true\b/i',
            $source,
            'CURLOPT_SSL_VERIFYPEER must be explicitly set to true'
        );
        $this->assertMatchesRegularExpression(
            '/CURLOPT_SSL_VERIFYHOST\s*=>\s*2\b/',
            $source,
            'CURLOPT_SSL_VERIFYHOST must be explicitly set to 2'
        );
    }

    public function testHostVerificationIsNotDisabled(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/CURLOPT_SSL_VERIFYHOST\s*(=>|,)\s*(false|0|1)\b/i',
            $this->clientSource(),
            'CURLOPT_SSL_VERIFYHOST must not be disabled or weakened in the Reepay HTTP client'
        );
    }
}
