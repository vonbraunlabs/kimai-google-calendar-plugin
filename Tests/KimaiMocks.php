<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests;

use App\Entity\Activity;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Repository\TimesheetRepository;
use App\Timesheet\TimesheetService;
use App\Timesheet\TrackingModeService;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use KimaiPlugin\GoogleCalendarBundle\Service\TargetResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Real Kimai services (some are final) wired with mocked collaborators: no database, no kernel.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
trait KimaiMocks
{
    /**
     * @param Project[] $projects
     * @param Activity[] $activities
     */
    protected function createTargetResolver(array $projects, array $activities, ?\Closure $inspectQueries = null): TargetResolver
    {
        $queryBuilder = function (array $result): QueryBuilder {
            $query = $this->createMock(Query::class);
            $query->method('getResult')->willReturn($result);
            $qb = $this->createMock(QueryBuilder::class);
            $qb->method('getQuery')->willReturn($query);

            return $qb;
        };

        $projectRepository = $this->createMock(ProjectRepository::class);
        $projectRepository->method('getQueryBuilderForFormType')->willReturnCallback(function ($query) use ($queryBuilder, $projects, $inspectQueries) {
            $inspectQueries?->__invoke($query);

            return $queryBuilder($projects);
        });
        $activityRepository = $this->createMock(ActivityRepository::class);
        $activityRepository->method('getQueryBuilderForFormType')->willReturnCallback(function ($query) use ($queryBuilder, $activities, $inspectQueries) {
            $inspectQueries?->__invoke($query);

            return $queryBuilder($activities);
        });

        $resolver = new TargetResolver($projectRepository, $activityRepository);
        $resolver->load(Fixtures::user());

        return $resolver;
    }

    /**
     * @param \Closure(Timesheet): void|null $onSave called when a record is saved
     * @param \Closure(Timesheet): ConstraintViolationListInterface|null $validate
     */
    protected function createTimesheetService(?\Closure $onSave = null, ?\Closure $validate = null, bool $granted = true): TimesheetService
    {
        $configuration = Fixtures::systemConfiguration();

        $repository = $this->createMock(TimesheetRepository::class);
        $repository->method('save')->willReturnCallback(function (Timesheet $timesheet) use ($onSave) {
            $onSave?->__invoke($timesheet);
        });

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(fn ($timesheet) => $validate?->__invoke($timesheet) ?? new ConstraintViolationList());

        // since Kimai 2.6x saving checks the "create" permission of the logged-in user
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn($granted);

        return new TimesheetService(
            $configuration,
            $repository,
            new TrackingModeService($configuration, []),
            $this->createMock(EventDispatcherInterface::class),
            $auth,
            $validator,
        );
    }
}
