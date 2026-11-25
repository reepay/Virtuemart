<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The accept URL may only show the order to the session that placed it or to the logged-in owner.
 */
class ReepayOrderAccessTest extends TestCase
{
    public function testSessionThatPlacedTheOrderMaySeeIt(): void
    {
        $this->assertTrue(ReepayOrderAccess::mayShowOrder(['AAAA01', 'NBA5011'], 'NBA5011', 0, 0));
    }

    public function testOtherOrEmptySessionMayNotSeeIt(): void
    {
        $this->assertFalse(ReepayOrderAccess::mayShowOrder([], 'NBA5011', 0, 0));
        $this->assertFalse(ReepayOrderAccess::mayShowOrder(['AAAA01'], 'NBA5011', 0, 0));
        $this->assertFalse(ReepayOrderAccess::mayShowOrder(null, 'NBA5011', 0, 0), 'broken session data');
        $this->assertFalse(ReepayOrderAccess::mayShowOrder('NBA5011', 'NBA5011', 0, 0), 'broken session data');
    }

    public function testOrderNumbersAreComparedExactly(): void
    {
        $this->assertFalse(ReepayOrderAccess::mayShowOrder(['NBA5011'], 'nba5011', 0, 0));
        $this->assertFalse(ReepayOrderAccess::mayShowOrder(['NBA5011'], 'NBA501', 0, 0));
        // Loose comparison would treat these numeric strings as equal
        $this->assertFalse(ReepayOrderAccess::mayShowOrder(['1E303'], '10E302', 0, 0));
    }

    public function testEmptyOrderNumberNeverMatches(): void
    {
        $this->assertFalse(ReepayOrderAccess::mayShowOrder([''], '', 0, 0));
        $this->assertFalse(ReepayOrderAccess::mayShowOrder([null], null, 706, 706));
    }

    public function testLoggedInOwnerMaySeeTheOrder(): void
    {
        $this->assertTrue(ReepayOrderAccess::mayShowOrder([], 'NBA5011', 706, '706'));
    }

    public function testOtherUserAndGuestsAreNotTheOwner(): void
    {
        $this->assertFalse(ReepayOrderAccess::mayShowOrder([], 'NBA5011', 705, 706));
        $this->assertFalse(ReepayOrderAccess::mayShowOrder([], 'NBA5011', 0, 0), 'guest id 0 is not an owner');
        $this->assertFalse(ReepayOrderAccess::mayShowOrder([], 'NBA5011', 0, null));
    }

    public function testRememberKeepsTheNewestTenWithoutDuplicates(): void
    {
        $list = [];
        for ($i = 1; $i <= 12; $i++) {
            $list = ReepayOrderAccess::remember($list, 'ORDER' . $i);
        }
        $this->assertCount(ReepayOrderAccess::MAX_ORDERS, $list);
        $this->assertSame('ORDER3', $list[0]);
        $this->assertSame('ORDER12', end($list));

        $list = ReepayOrderAccess::remember($list, 'ORDER3');
        $this->assertCount(ReepayOrderAccess::MAX_ORDERS, $list);
        $this->assertSame('ORDER3', end($list));
    }

    public function testRememberIgnoresEmptyNumbersAndBrokenSessionData(): void
    {
        $this->assertSame(['A1'], ReepayOrderAccess::remember(['A1'], ''));
        $this->assertSame(['A1'], ReepayOrderAccess::remember(null, 'A1'));
        $this->assertSame(['A1'], ReepayOrderAccess::remember('broken', 'A1'));
    }

    public static function linkProvider(): array
    {
        $link = 'index.php?option=com_virtuemart&view=orders&layout=details&order_number=NBA5011';

        return [
            'none'                         => ['none', 0, ''],
            'registered, guest order'      => ['registered', 0, ''],
            'registered, customer order'   => ['registered', 706, $link],
            'registered_only, guest order' => ['registered_only', 0, $link],
            'guests, guest order'          => ['guests', 0, $link . '&order_pass=p_x'],
            'guests, customer order'       => ['guests', 706, $link],
            'guestlink, guest order'       => ['guestlink', 0, $link . '&order_pass=p_x'],
            'guestlink, customer order'    => ['guestlink', 706, $link . '&order_pass=p_x'],
        ];
    }

    #[DataProvider('linkProvider')]
    public function testOrderLinkFollowsTheTrackingSettingAsBefore(string $tracking, int $orderUserId, string $expected): void
    {
        $this->assertSame($expected, ReepayOrderAccess::orderLink($tracking, 'NBA5011', 'p_x', $orderUserId));
    }
}
