<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\Service;

use App\Entity\Activity;
use App\Entity\Project;
use App\Repository\Query\ActivityFormTypeQuery;
use App\Repository\Query\ProjectFormTypeQuery;
use KimaiPlugin\GoogleCalendarBundle\Service\TargetResolver;
use KimaiPlugin\GoogleCalendarBundle\Tests\Fixtures;
use KimaiPlugin\GoogleCalendarBundle\Tests\KimaiMocks;
use PHPUnit\Framework\TestCase;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Service\TargetResolver
 */
class TargetResolverTest extends TestCase
{
    use KimaiMocks;

    private Project $internal;
    private Project $acmeWebsite;
    private Project $acme;
    private Project $qa;
    private Project $closed;
    private Activity $meeting;
    private Activity $development;
    private Activity $calls;
    private Activity $closedWork;

    protected function setUp(): void
    {
        $this->internal = Fixtures::project(1, 'Internal', 'INT');
        $this->acmeWebsite = Fixtures::project(2, 'ACME Website', 'P-100');
        $this->acme = Fixtures::project(3, 'ACME');
        $this->qa = Fixtures::project(4, 'QA');
        $this->closed = Fixtures::project(5, 'Closed', null, false);
        $this->meeting = Fixtures::activity(10, 'Meeting');
        $this->development = Fixtures::activity(11, 'Development', null, 'DEV');
        $this->calls = Fixtures::activity(12, 'Calls', $this->acmeWebsite, 'CALL');
        $this->closedWork = Fixtures::activity(13, 'Closed work', $this->closed);
    }

    /**
     * @param Project[]|null $projects
     * @param Activity[]|null $activities
     */
    private function resolver(?array $projects = null, ?array $activities = null, ?\Closure $inspectQueries = null): TargetResolver
    {
        $projects ??= [$this->internal, $this->acmeWebsite, $this->acme, $this->qa, $this->closed];
        $activities ??= [$this->meeting, $this->development, $this->calls, $this->closedWork];

        return $this->createTargetResolver($projects, $activities, $inspectQueries);
    }

    public function testLoadQueriesOnlyWhatTheUserMayBookOn(): void
    {
        $queries = [];
        $resolver = $this->resolver(null, null, function ($query) use (&$queries) {
            $queries[] = $query;
        });

        self::assertCount(5, $resolver->getProjects());
        self::assertCount(4, $resolver->getActivities());
        self::assertInstanceOf(ProjectFormTypeQuery::class, $queries[0]);
        self::assertNotNull($queries[0]->getUser());
        self::assertTrue($queries[0]->isIgnoreDate());
        self::assertInstanceOf(ActivityFormTypeQuery::class, $queries[1]);
        self::assertCount(5, $queries[1]->getProjects());
        self::assertNotNull($queries[1]->getUser());
    }

    public function testActivitiesPerProject(): void
    {
        $resolver = $this->resolver();

        self::assertSame(['Meeting', 'Development'], $this->names($resolver->getActivitiesFor(null)));
        self::assertSame(['Meeting', 'Development', 'Calls'], $this->names($resolver->getActivitiesFor($this->acmeWebsite)));
        self::assertSame(['Closed work'], $this->names($resolver->getActivitiesFor($this->closed)), 'project without global activities');
    }

    public function testFindByIdRespectsTheProject(): void
    {
        $resolver = $this->resolver();

        self::assertSame($this->acme, $resolver->findProject(3));
        self::assertNull($resolver->findProject(99));
        self::assertNull($resolver->findProject(null));
        self::assertSame($this->calls, $resolver->findActivity(12, $this->acmeWebsite));
        self::assertNull($resolver->findActivity(12, $this->internal), 'activity of another project');
        self::assertNull($resolver->findActivity(10, $this->closed), 'global activity on a project that disallows them');
    }

    public function testProjectNumberInTitleWinsOverNames(): void
    {
        self::assertSame([$this->acmeWebsite, TargetResolver::SOURCE_NUMBER], $this->resolver()->guessProject('P-100 kickoff with Internal team', '', null));
    }

    public function testProjectNameInTitleLongestMatchWins(): void
    {
        self::assertSame([$this->acmeWebsite, TargetResolver::SOURCE_TITLE], $this->resolver()->guessProject('Review acme website - calls', '', null));
        self::assertSame([$this->acme, TargetResolver::SOURCE_TITLE], $this->resolver()->guessProject('Fix ACME bug', '', null));
    }

    public function testProjectNameInDescription(): void
    {
        self::assertSame([$this->internal, TargetResolver::SOURCE_DESCRIPTION], $this->resolver()->guessProject('Planning', 'Sprint of project Internal', null));
    }

    public function testWholeWordsOnly(): void
    {
        self::assertSame([null, null], $this->resolver()->guessProject('Quarterly aqa review', 'INTERNALS', null));
    }

    public function testMappingRuleIsTheLastResortByNameOrNumber(): void
    {
        $resolver = $this->resolver();
        $rules = "standup => INT / Meeting\nsync => ACME Website";

        self::assertSame([$this->internal, TargetResolver::SOURCE_RULE], $resolver->guessProject('Daily standup', '', $rules));
        self::assertSame([$this->acmeWebsite, TargetResolver::SOURCE_RULE], $resolver->guessProject('Weekly sync', '', $rules));
        self::assertSame([$this->internal, TargetResolver::SOURCE_TITLE], $resolver->guessProject('Internal sync', '', $rules), 'names beat rules');
        self::assertSame([null, null], $resolver->guessProject('Weekly sync', '', "sync => Unknown project\nsync => / Meeting"));
    }

    public function testActivityGuessFollowsTheSameOrderWithinTheProject(): void
    {
        $resolver = $this->resolver();

        self::assertSame([$this->calls, TargetResolver::SOURCE_NUMBER], $resolver->guessActivity($this->acmeWebsite, 'CALL with client', '', null));
        self::assertSame([$this->calls, TargetResolver::SOURCE_TITLE], $resolver->guessActivity($this->acmeWebsite, 'Calls review', '', null));
        self::assertSame([$this->development, TargetResolver::SOURCE_DESCRIPTION], $resolver->guessActivity($this->internal, 'Pairing', 'development session', null));
        self::assertSame([$this->development, TargetResolver::SOURCE_RULE], $resolver->guessActivity(null, 'Code review', '', 'code review => / dev'));
        self::assertSame([null, null], $resolver->guessActivity($this->internal, 'Calls review', '', null), 'Calls belongs to another project');
    }

    /**
     * @param Activity[] $activities
     * @return string[]
     */
    private function names(array $activities): array
    {
        return array_map(fn (Activity $activity) => $activity->getName(), $activities);
    }
}
