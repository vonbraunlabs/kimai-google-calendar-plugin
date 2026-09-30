<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Service;

/**
 * Parses the user defined mapping rules, one per line:
 *
 *   keyword => Project
 *   keyword => Project / Activity
 *   keyword => / Activity
 *
 * Project and activity can be given by name or by number (code).
 * The keyword is matched as a whole word, case-insensitive, against the title (ADR-0006).
 * Lines starting with # are comments.
 */
final class MappingRules
{
    /**
     * @return array<int, array{keyword: string, project: string|null, activity: string|null}>
     */
    public static function parse(?string $rules): array
    {
        $parsed = [];

        foreach (preg_split('/\R/', (string) $rules) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=>')) {
                continue;
            }

            [$keyword, $target] = array_map('trim', explode('=>', $line, 2));
            $parts = array_map('trim', explode('/', $target, 2));
            $project = $parts[0] !== '' ? $parts[0] : null;
            $activity = ($parts[1] ?? '') !== '' ? $parts[1] : null;

            if ($keyword === '' || ($project === null && $activity === null)) {
                continue;
            }

            $parsed[] = ['keyword' => $keyword, 'project' => $project, 'activity' => $activity];
        }

        return $parsed;
    }

    /**
     * Returns all rules whose keyword is found as a whole word in the title, in their configured order.
     *
     * @return array<int, array{keyword: string, project: string|null, activity: string|null}>
     */
    public static function matching(?string $rules, string $title): array
    {
        return array_values(array_filter(
            self::parse($rules),
            fn (array $rule) => TextMatcher::containsWord($title, $rule['keyword'])
        ));
    }
}
