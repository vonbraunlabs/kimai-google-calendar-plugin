<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Service;

/**
 * Whole-word, case-insensitive matching shared by project/activity numbers, names and
 * mapping-rule keywords (ADR-0003, ADR-0006).
 *
 * A word is delimited by anything that is not a letter or a digit, so "QA" matches
 * "[GPV0374][QA] Estabelecimento de fluxo" and "Testes de QA", but not "QUALIDADE".
 */
final class TextMatcher
{
    public static function containsWord(string $text, string $word): bool
    {
        if ($text === '' || $word === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1;
    }
}
