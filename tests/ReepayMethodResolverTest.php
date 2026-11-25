<?php

use PHPUnit\Framework\TestCase;

/**
 * The payment method used for a return or webhook must be the one stored on the order,
 * not the pm parameter from the request URL.
 */
class ReepayMethodResolverTest extends TestCase
{
    public function testMatchingPmUsesTheStoredMethod(): void
    {
        $this->assertSame(
            ['id' => 2, 'pmMismatch' => false, 'reason' => null],
            ReepayMethodResolver::resolve(2, 2)
        );
    }

    public function testDifferentPmStillUsesTheStoredMethodAndFlagsTheMismatch(): void
    {
        $this->assertSame(
            ['id' => 2, 'pmMismatch' => true, 'reason' => null],
            ReepayMethodResolver::resolve(3, 2)
        );
    }

    public function testNumericStringsAreCompared(): void
    {
        // vRequest::getInt() gives an int, the DB column comes back as a string
        $this->assertSame(
            ['id' => 3, 'pmMismatch' => false, 'reason' => null],
            ReepayMethodResolver::resolve(3, '3')
        );
        $this->assertSame(
            ['id' => 3, 'pmMismatch' => true, 'reason' => null],
            ReepayMethodResolver::resolve('2', '3')
        );
    }

    public function testMissingPmStillUsesTheStoredMethod(): void
    {
        $this->assertSame(
            ['id' => 2, 'pmMismatch' => true, 'reason' => null],
            ReepayMethodResolver::resolve(0, 2)
        );
        $this->assertSame(
            ['id' => 2, 'pmMismatch' => true, 'reason' => null],
            ReepayMethodResolver::resolve(null, 2)
        );
    }

    public function testMissingStoredMethodIsRejected(): void
    {
        foreach ([null, 0, '0', '', 'abc', -1] as $stored) {
            $this->assertSame(
                ['id' => null, 'pmMismatch' => false, 'reason' => ReepayMethodResolver::MISSING_ORDER_METHOD],
                ReepayMethodResolver::resolve(2, $stored),
                'stored=' . var_export($stored, true)
            );
        }
    }
}
