<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\Service;

use KimaiPlugin\GoogleCalendarBundle\Service\MappingRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Service\MappingRules
 */
class MappingRulesTest extends TestCase
{
    public function testParseSupportsProjectActivityAndActivityOnlyRules(): void
    {
        $rules = MappingRules::parse("# comment\n\nDaily => Internal / Meeting\r\nACME => ACME Website\nCode review => / Development\ninvalid line\n => Nothing\nEmpty =>  / ");

        self::assertSame([
            ['keyword' => 'Daily', 'project' => 'Internal', 'activity' => 'Meeting'],
            ['keyword' => 'ACME', 'project' => 'ACME Website', 'activity' => null],
            ['keyword' => 'Code review', 'project' => null, 'activity' => 'Development'],
        ], $rules);
    }

    public function testParseHandlesNull(): void
    {
        self::assertSame([], MappingRules::parse(null));
    }

    public function testMatchingIsCaseInsensitiveAndKeepsOrder(): void
    {
        $rules = "acme => ACME Website\ndaily => Internal / Meeting\nother => Other";

        $matching = MappingRules::matching($rules, 'ACME Daily sync');

        self::assertSame(['acme', 'daily'], array_column($matching, 'keyword'));
        self::assertSame([], MappingRules::matching($rules, 'Nothing here'));
    }
}
