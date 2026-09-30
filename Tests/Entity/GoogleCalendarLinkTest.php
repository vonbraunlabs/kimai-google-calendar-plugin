<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\Entity;

use App\Entity\Timesheet;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarLink;
use KimaiPlugin\GoogleCalendarBundle\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarLink
 */
class GoogleCalendarLinkTest extends TestCase
{
    public function testLink(): void
    {
        $account = Fixtures::account();
        $timesheet = new Timesheet();
        $link = new GoogleCalendarLink($account, GoogleCalendarLink::TYPE_TASK, 'task-1');

        self::assertNull($link->getId());
        self::assertSame($account, $link->getAccount());
        self::assertSame('task', $link->getSourceType());
        self::assertSame('task-1', $link->getSourceId());
        self::assertNull($link->getTimesheet());
        self::assertNull($link->getTitle());
        self::assertEqualsWithDelta(time(), $link->getCreatedAt()->getTimestamp(), 5);

        $link->setTimesheet($timesheet);
        $link->setTitle(str_repeat('x', 300));

        self::assertSame($timesheet, $link->getTimesheet());
        self::assertSame(255, mb_strlen($link->getTitle()), 'title is cut to the column length');

        $link->setTitle(null);
        self::assertNull($link->getTitle());
    }
}
