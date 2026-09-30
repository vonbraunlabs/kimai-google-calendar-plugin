<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\Service;

use KimaiPlugin\GoogleCalendarBundle\Service\TextMatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Service\TextMatcher
 */
class TextMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideTexts(): iterable
    {
        yield 'between brackets' => ['[GPV0374][QA] Estabelecimento de fluxo', 'QA', true];
        yield 'at the end' => ['[GPV0377][Factum] Testes de QA', 'QA', true];
        yield 'prefix of a longer word' => ['QUALIDADE', 'QA', false];
        yield 'inside a word' => ['Quarterly aQAb', 'QA', false];
        yield 'case-insensitive' => ['testes de qa', 'QA', true];
        yield 'followed by a digit' => ['QA2 planning', 'QA', false];
        yield 'accented neighbour letter' => ['QAé', 'QA', false];
        yield 'several words' => ['Weekly code review', 'Code review', true];
        yield 'keyword with brackets' => ['[GPV0374][QA] fluxo', '[GPV0374]', true];
        yield 'regex characters are literal' => ['C++ training', 'C++', true];
        yield 'empty text' => ['', 'QA', false];
        yield 'empty word' => ['QA', '', false];
    }

    /**
     * @dataProvider provideTexts
     */
    public function testContainsWord(string $text, string $word, bool $expected): void
    {
        self::assertSame($expected, TextMatcher::containsWord($text, $word));
    }
}
