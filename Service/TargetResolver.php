<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Service;

use App\Entity\Activity;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Repository\Query\ActivityFormTypeQuery;
use App\Repository\Query\ProjectFormTypeQuery;

/**
 * Knows the projects and activities a user may book on, and guesses them from an event/task.
 *
 * Order of the guess, for the project and then for the activity (within that project):
 *   1. number (code) found in the title
 *   2. name found in the title
 *   3. name found in the description
 *   4. first mapping rule whose keyword is found in the title
 *
 * Numbers, names and rule keywords are matched as whole words, case-insensitive (TextMatcher);
 * for numbers and names the longest match wins.
 */
class TargetResolver
{
    public const SOURCE_NUMBER = 'number';
    public const SOURCE_TITLE = 'title';
    public const SOURCE_DESCRIPTION = 'description';
    public const SOURCE_RULE = 'rule';

    /** @var Project[] */
    private array $projects = [];
    /** @var Activity[] */
    private array $activities = [];

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ActivityRepository $activityRepository,
    ) {
    }

    public function load(User $user): void
    {
        $projectQuery = new ProjectFormTypeQuery();
        $projectQuery->setUser($user);
        $projectQuery->setIgnoreDate(true);
        $this->projects = $this->projectRepository->getQueryBuilderForFormType($projectQuery)->getQuery()->getResult();

        $activityQuery = new ActivityFormTypeQuery(null, \count($this->projects) > 0 ? $this->projects : null);
        $activityQuery->setUser($user);
        $this->activities = $this->activityRepository->getQueryBuilderForFormType($activityQuery)->getQuery()->getResult();
    }

    /**
     * @return Project[]
     */
    public function getProjects(): array
    {
        return $this->projects;
    }

    /**
     * @return Activity[]
     */
    public function getActivities(): array
    {
        return $this->activities;
    }

    public function findProject(?int $id): ?Project
    {
        foreach ($this->projects as $project) {
            if ($project->getId() === $id) {
                return $project;
            }
        }

        return null;
    }

    public function findActivity(?int $id, ?Project $project): ?Activity
    {
        foreach ($this->getActivitiesFor($project) as $activity) {
            if ($activity->getId() === $id) {
                return $activity;
            }
        }

        return null;
    }

    /**
     * Activities that can be booked on the project: its own ones plus the global ones (if the project allows them).
     *
     * @return Activity[]
     */
    public function getActivitiesFor(?Project $project): array
    {
        return array_values(array_filter($this->activities, function (Activity $activity) use ($project) {
            if ($activity->isGlobal()) {
                return $project === null || $project->isGlobalActivities();
            }

            return $project !== null && $activity->getProject()?->getId() === $project->getId();
        }));
    }

    /**
     * @return array{0: Project|null, 1: string|null}
     */
    public function guessProject(string $title, string $description, ?string $rules): array
    {
        return $this->guess($this->projects, $title, $description, $rules, 'project');
    }

    /**
     * @return array{0: Activity|null, 1: string|null}
     */
    public function guessActivity(?Project $project, string $title, string $description, ?string $rules): array
    {
        return $this->guess($this->getActivitiesFor($project), $title, $description, $rules, 'activity');
    }

    /**
     * @template T of Project|Activity
     * @param T[] $candidates
     * @return array{0: T|null, 1: string|null}
     */
    private function guess(array $candidates, string $title, string $description, ?string $rules, string $ruleField): array
    {
        $numbers = $names = [];
        foreach ($candidates as $candidate) {
            if (($number = trim((string) $candidate->getNumber())) !== '') {
                $numbers[] = [$number, $candidate];
            }
            if (($name = trim((string) $candidate->getName())) !== '') {
                $names[] = [$name, $candidate];
            }
        }

        if (($found = $this->findInText($numbers, $title)) !== null) {
            return [$found, self::SOURCE_NUMBER];
        }
        if (($found = $this->findInText($names, $title)) !== null) {
            return [$found, self::SOURCE_TITLE];
        }
        if (($found = $this->findInText($names, $description)) !== null) {
            return [$found, self::SOURCE_DESCRIPTION];
        }

        foreach (MappingRules::matching($rules, $title) as $rule) {
            $wanted = $rule[$ruleField];
            if ($wanted === null) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (mb_strtolower($wanted) === mb_strtolower((string) $candidate->getName())
                    || ($candidate->getNumber() !== null && mb_strtolower($wanted) === mb_strtolower($candidate->getNumber()))) {
                    return [$candidate, self::SOURCE_RULE];
                }
            }
        }

        return [null, null];
    }

    /**
     * @template T
     * @param array<int, array{0: string, 1: T}> $needles
     * @return T|null
     */
    private function findInText(array $needles, string $text): mixed
    {
        if ($text === '') {
            return null;
        }

        // the longest match wins, so "ACME Website" beats "ACME"
        usort($needles, fn (array $a, array $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        foreach ($needles as [$needle, $candidate]) {
            if (TextMatcher::containsWord($text, $needle)) {
                return $candidate;
            }
        }

        return null;
    }
}
