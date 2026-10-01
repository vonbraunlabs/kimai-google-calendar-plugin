<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\Entity;

use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;
use KimaiPlugin\GoogleCalendarBundle\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount
 */
class GoogleCalendarAccountTest extends TestCase
{
    public function testDefaults(): void
    {
        $user = Fixtures::user();
        $account = new GoogleCalendarAccount($user);

        self::assertNull($account->getId());
        self::assertSame($user, $account->getUser());
        self::assertFalse($account->isConnected());
        self::assertSame('primary', $account->getCalendarId());
        self::assertTrue($account->isSyncEvents());
        self::assertFalse($account->isSyncTasks());
        self::assertSame(30, $account->getTaskDuration());
        self::assertTrue($account->isSkipDeclined());
        self::assertFalse($account->isSkipFree());
        self::assertNull($account->getMappingRules());
    }

    public function testSettersAndDisconnect(): void
    {
        $account = new GoogleCalendarAccount(Fixtures::user());
        $expires = new \DateTimeImmutable();
        $account->setGoogleEmail('me@example.com');
        $account->setAccessToken('a');
        $account->setRefreshToken('r');
        $account->setTokenExpiresAt($expires);
        $account->setCalendarId('  team@group  ');
        $account->setSyncEvents(false);
        $account->setSyncTasks(true);
        $account->setTaskDuration(15);
        $account->setSkipDeclined(false);
        $account->setSkipFree(true);
        $account->setMappingRules('a => b');

        self::assertTrue($account->isConnected());
        self::assertSame(['me@example.com', 'a', 'r', $expires], [$account->getGoogleEmail(), $account->getAccessToken(), $account->getRefreshToken(), $account->getTokenExpiresAt()]);
        self::assertSame('team@group', $account->getCalendarId());
        self::assertSame([false, true, 15, false, true, 'a => b'], [$account->isSyncEvents(), $account->isSyncTasks(), $account->getTaskDuration(), $account->isSkipDeclined(), $account->isSkipFree(), $account->getMappingRules()]);

        $account->setCalendarId('');
        $account->setTaskDuration(null);
        self::assertSame('primary', $account->getCalendarId());
        self::assertSame(30, $account->getTaskDuration());

        $account->disconnect();
        self::assertFalse($account->isConnected());
        self::assertNull($account->getAccessToken());
        self::assertNull($account->getTokenExpiresAt());
        self::assertNull($account->getGoogleEmail());
    }
}
