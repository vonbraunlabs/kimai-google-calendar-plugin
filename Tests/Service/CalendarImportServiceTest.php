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
use App\Entity\Timesheet;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarLink;
use KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarLinkRepository;
use KimaiPlugin\GoogleCalendarBundle\Service\CalendarImportService;
use KimaiPlugin\GoogleCalendarBundle\Service\GoogleApiClient;
use KimaiPlugin\GoogleCalendarBundle\Service\GoogleApiException;
use KimaiPlugin\GoogleCalendarBundle\Service\ImportItem;
use KimaiPlugin\GoogleCalendarBundle\Service\TargetResolver;
use KimaiPlugin\GoogleCalendarBundle\Tests\Fixtures;
use KimaiPlugin\GoogleCalendarBundle\Tests\KimaiMocks;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Service\CalendarImportService
 * @covers \KimaiPlugin\GoogleCalendarBundle\Service\ImportItem
 */
class CalendarImportServiceTest extends TestCase
{
    use KimaiMocks;

    private const ME = ['email' => 'me@example.com', 'self' => true, 'responseStatus' => 'accepted'];
    private const OTHER = ['email' => 'ana@example.com'];

    private Project $internal;
    private Project $acme;
    private Activity $meeting;
    private Activity $calls;

    private GoogleApiClient&MockObject $client;
    private EntityManagerInterface&MockObject $entityManager;
    /** @var array<string, GoogleCalendarLink> */
    private array $links = [];
    /** @var Timesheet[] */
    private array $saved = [];
    /** @var object[] */
    private array $persisted = [];
    private ?\Closure $validate = null;
    private ?\Closure $onSave = null;
    private bool $granted = true;
    private TargetResolver $resolver;

    protected function setUp(): void
    {
        $this->internal = Fixtures::project(1, 'Internal', 'INT');
        $this->acme = Fixtures::project(2, 'ACME Website', 'P-100');
        $this->meeting = Fixtures::activity(10, 'Meeting');
        $this->calls = Fixtures::activity(11, 'Calls', $this->acme);
        $this->resolver = $this->createTargetResolver([$this->internal, $this->acme], [$this->meeting, $this->calls]);

        $this->client = $this->createMock(GoogleApiClient::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity) {
            $this->persisted[] = $entity;
        });
    }

    private function service(): CalendarImportService
    {
        $linkRepository = $this->createMock(GoogleCalendarLinkRepository::class);
        $linkRepository->method('findByKeys')->willReturnCallback(fn ($account, array $keys) => array_intersect_key($this->links, array_flip($keys)));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn (string $id, array $parameters = []) => strtr([
            'gcal.meeting_description' => "Participação na reunião '%title%'",
            'gcal.error_missing_target' => 'missing target',
            'gcal.error_end_before_begin' => 'end before begin',
        ][$id] ?? $id, $parameters));

        $timesheetService = $this->createTimesheetService(
            function (Timesheet $timesheet) {
                $this->onSave?->__invoke($timesheet);
                $this->saved[] = $timesheet;
            },
            fn (Timesheet $timesheet) => $this->validate?->__invoke($timesheet),
            $this->granted,
        );

        return new CalendarImportService($this->client, $this->resolver, $timesheetService, $linkRepository, $this->entityManager, $translator, new NullLogger());
    }

    private function event(string $id, string $title, string $start, string $end, array $extra = []): array
    {
        return array_merge(['id' => $id, 'status' => 'confirmed', 'summary' => $title, 'start' => ['dateTime' => $start], 'end' => ['dateTime' => $end]], $extra);
    }

    /**
     * @return array<string, ImportItem>
     */
    private function load(GoogleCalendarAccount $account, array $events, array $tasks = []): array
    {
        $this->client->method('listEvents')->willReturn($events);
        $this->client->method('listTaskLists')->willReturn([['id' => 'list-1']]);
        $this->client->method('listCompletedTasks')->willReturn($tasks);

        $items = $this->service()->loadItems($account, new \DateTime('2026-09-21'), new \DateTime('2026-09-29'));
        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item->sourceId] = $item;
        }

        return $indexed;
    }

    public function testEventsAreFilteredGuessedAndOrdered(): void
    {
        $account = Fixtures::account();
        $items = $this->load($account, [
            $this->event('late', 'Internal retro', '2026-09-24T10:00:00-03:00', '2026-09-24T11:00:00-03:00', ['attendees' => [self::ME, self::OTHER]]),
            $this->event('early', 'P-100 Calls review', '2026-09-22T13:00:00Z', '2026-09-22T13:30:00Z'),
            $this->event('cancelled', 'x', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z', ['status' => 'cancelled']),
            ['id' => 'allday', 'status' => 'confirmed', 'summary' => 'Holiday', 'start' => ['date' => '2026-09-25'], 'end' => ['date' => '2026-09-26']],
            $this->event('ooo', 'Away', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z', ['eventType' => 'outOfOffice']),
            $this->event('declined', 'Internal', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z', ['attendees' => [array_merge(self::ME, ['responseStatus' => 'declined'])]]),
            $this->event('free', 'Focus', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z', ['transparency' => 'transparent']),
            $this->event('room', 'Solo booking', '2026-09-23T10:00:00Z', '2026-09-23T11:00:00Z', ['attendees' => [self::ME, ['email' => 'room@resource', 'resource' => true]], 'description' => '<p>Work for <b>Internal</b></p><br>&amp; more']),
            $this->event('future', 'Internal planning', '2099-01-01T10:00:00Z', '2099-01-01T11:00:00Z'),
        ]);

        self::assertSame(['free', 'early', 'room', 'late', 'future'], array_keys($items));

        $early = $items['early'];
        self::assertSame('event:early', $early->getKey());
        self::assertSame('2026-09-22 10:00', $early->begin->format('Y-m-d H:i'), 'converted to the user timezone');
        self::assertSame([$this->acme, TargetResolver::SOURCE_NUMBER], [$early->project, $early->projectSource]);
        self::assertSame([$this->calls, TargetResolver::SOURCE_TITLE], [$early->activity, $early->activitySource]);
        self::assertSame('P-100 Calls review', $early->description, 'no attendees: plain title');
        self::assertTrue($early->selected);
        self::assertFalse($early->isRegistered());

        self::assertSame("Participação na reunião 'Internal retro'", $items['late']->description);
        self::assertFalse($items['late']->selected, 'no activity identified');

        self::assertFalse($items['room']->meeting, 'resources are not attendees');
        self::assertSame("Work for Internal\n\n& more", $items['room']->sourceDescription);
        self::assertSame($this->internal, $items['room']->project);

        self::assertFalse($items['future']->selected, 'future items are never pre-selected');
    }

    public function testFilterOptionsAreRespected(): void
    {
        $account = Fixtures::account();
        $account->setSkipDeclined(false);
        $account->setSkipFree(true);

        $items = $this->load($account, [
            $this->event('declined', 'Declined', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z', ['attendees' => [array_merge(self::ME, ['responseStatus' => 'declined'])]]),
            $this->event('free', 'Focus', '2026-09-22T12:00:00Z', '2026-09-22T13:00:00Z', ['transparency' => 'transparent']),
        ]);

        self::assertSame(['declined'], array_keys($items));
    }

    public function testCompletedTasksEndAtCompletionTime(): void
    {
        $account = Fixtures::account();
        $account->setSyncEvents(false);
        $account->setSyncTasks(true);
        $account->setTaskDuration(45);

        $items = $this->load($account, [], [
            ['id' => 't1', 'title' => 'Fix ACME Website bug', 'notes' => 'see ticket', 'status' => 'completed', 'completed' => '2026-09-24T18:00:00.000Z'],
            ['id' => 't2', 'title' => 'Deleted', 'status' => 'completed', 'completed' => '2026-09-24T18:00:00.000Z', 'deleted' => true],
            ['id' => 't3', 'title' => 'Open', 'status' => 'needsAction'],
            ['id' => 't4', 'status' => 'completed', 'completed' => '2026-09-23T18:00:00.000Z'],
        ]);

        self::assertSame(['t4', 't1'], array_keys($items));
        self::assertSame(GoogleCalendarLink::TYPE_TASK, $items['t1']->type);
        self::assertSame('2026-09-24 14:15', $items['t1']->begin->format('Y-m-d H:i'));
        self::assertSame('2026-09-24 15:00', $items['t1']->end->format('Y-m-d H:i'));
        self::assertSame('Fix ACME Website bug', $items['t1']->description, 'tasks use the plain title');
        self::assertSame('see ticket', $items['t1']->sourceDescription);
        self::assertSame('(no title)', $items['t4']->title);
    }

    public function testEventsAreNotRequestedWhenDisabled(): void
    {
        $account = Fixtures::account();
        $account->setSyncEvents(false);
        $this->client->expects(self::never())->method('listEvents');
        $this->client->expects(self::never())->method('listTaskLists');

        self::assertSame([], $this->service()->loadItems($account, new \DateTime('2026-09-21'), new \DateTime('2026-09-29')));
    }

    public function testRegisteredItemsAreNotGuessed(): void
    {
        $account = Fixtures::account();
        $timesheet = new Timesheet();
        $link = new GoogleCalendarLink($account, GoogleCalendarLink::TYPE_EVENT, 'done');
        $link->setTimesheet($timesheet);
        $this->links['event:done'] = $link;

        $items = $this->load($account, [$this->event('done', 'Internal', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z')]);

        self::assertTrue($items['done']->isRegistered());
        self::assertSame($timesheet, $items['done']->timesheet);
        self::assertNull($items['done']->project);
        self::assertFalse($items['done']->selected);
    }

    public function testLostAuthorizationDisconnectsAndStillPersists(): void
    {
        $account = Fixtures::account();
        $this->client->method('listEvents')->willThrowException(new GoogleApiException('revoked', true));
        $this->entityManager->expects(self::once())->method('flush');

        try {
            $this->service()->loadItems($account, new \DateTime(), new \DateTime());
            self::fail('Expected exception');
        } catch (GoogleApiException) {
            self::assertFalse($account->isConnected());
            self::assertSame([$account], $this->persisted);
        }
    }

    public function testOtherApiErrorsKeepTheConnection(): void
    {
        $account = Fixtures::account();
        $this->client->method('listEvents')->willThrowException(new GoogleApiException('rate limit'));

        $this->expectException(GoogleApiException::class);
        try {
            $this->service()->loadItems($account, new \DateTime(), new \DateTime());
        } finally {
            self::assertTrue($account->isConnected());
        }
    }

    public function testApplyInputOnlyUsesSubmittedRows(): void
    {
        $account = Fixtures::account();
        $items = $this->load($account, [
            $this->event('a', 'P-100 Calls', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z'),
            $this->event('b', 'Internal', '2026-09-22T12:00:00Z', '2026-09-22T13:00:00Z'),
            $this->event('c', 'P-100 Calls again', '2026-09-22T14:00:00Z', '2026-09-22T15:00:00Z'),
        ]);
        self::assertTrue($items['c']->selected);

        $this->service()->applyInput($account, array_values($items), [
            'event:a' => ['selected' => '1', 'project' => '1', 'activity' => '10', 'description' => ' edited ', 'begin' => '2026-09-22T07:15', 'end' => 'garbage'],
            'event:b' => ['project' => '99', 'activity' => '11'],
        ]);

        self::assertTrue($items['a']->selected);
        self::assertSame([$this->internal, $this->meeting, 'edited'], [$items['a']->project, $items['a']->activity, $items['a']->description]);
        self::assertNull($items['a']->projectSource);
        self::assertSame('2026-09-22 07:15 America/Sao_Paulo', $items['a']->begin->format('Y-m-d H:i e'));
        self::assertSame('2026-09-22 08:00', $items['a']->end->format('Y-m-d H:i'), 'invalid date keeps the original');
        self::assertFalse($items['b']->selected);
        self::assertNull($items['b']->project, 'unknown project');
        self::assertNull($items['b']->activity, 'activity of another project');
        self::assertFalse($items['c']->selected, 'rows that were not submitted are never registered');
    }

    public function testRegisterCreatesRecordsAndLinks(): void
    {
        $account = Fixtures::account();
        $deleted = new GoogleCalendarLink($account, GoogleCalendarLink::TYPE_EVENT, 'b');
        $this->links['event:b'] = $deleted;
        $items = $this->load($account, [
            $this->event('a', 'P-100 Calls', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z'),
            $this->event('b', 'P-100 Calls again', '2026-09-22T12:00:00Z', '2026-09-22T12:30:00Z'),
            $this->event('c', 'Not selected', '2026-09-22T14:00:00Z', '2026-09-22T15:00:00Z'),
        ]);
        $items['b']->description = '';

        $created = $this->service()->register($account, array_values($items));

        self::assertSame(2, $created);
        self::assertCount(2, $this->saved);
        $timesheet = $items['a']->timesheet;
        self::assertSame($account->getUser(), $timesheet->getUser());
        self::assertSame([$this->acme, $this->calls], [$timesheet->getProject(), $timesheet->getActivity()]);
        self::assertSame('P-100 Calls', $timesheet->getDescription());
        self::assertSame(3600, $timesheet->getDuration());
        self::assertSame('2026-09-22 07:00', $timesheet->getBegin()->format('Y-m-d H:i'));
        self::assertSame('P-100 Calls again', $items['b']->timesheet->getDescription(), 'empty description falls back to the title');
        self::assertSame($items['b']->timesheet, $deleted->getTimesheet(), 'link of a record deleted in Kimai is re-used');
        self::assertNull($items['c']->timesheet);

        $links = array_values(array_filter($this->persisted, fn ($e) => $e instanceof GoogleCalendarLink));
        self::assertSame(['a', 'b'], array_map(fn (GoogleCalendarLink $l) => $l->getSourceId(), $links));
        self::assertSame('P-100 Calls', $links[0]->getTitle());
    }

    public function testRegisterReportsErrorsPerRow(): void
    {
        $account = Fixtures::account();
        $items = $this->load($account, [
            $this->event('missing', 'Nothing to guess', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z'),
            $this->event('reversed', 'P-100 Calls', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z'),
            $this->event('invalid', 'P-100 Calls invalid', '2026-09-22T12:00:00Z', '2026-09-22T13:00:00Z'),
            $this->event('broken', 'P-100 Calls broken', '2026-09-22T14:00:00Z', '2026-09-22T15:00:00Z'),
        ]);
        $items['missing']->selected = true;
        $items['reversed']->end = (clone $items['reversed']->begin)->modify('-1 minute');
        $this->validate = fn (Timesheet $t) => $t->getDescription() === 'P-100 Calls invalid'
            ? new ConstraintViolationList([new ConstraintViolation('Overlapping record.', null, [], null, 'begin', null)])
            : new ConstraintViolationList();
        $this->onSave = function (Timesheet $t) {
            if ($t->getDescription() === 'P-100 Calls broken') {
                throw new \RuntimeException('Database is down');
            }
        };

        $created = $this->service()->register($account, array_values($items));

        self::assertSame(0, $created);
        self::assertSame('missing target', $items['missing']->error);
        self::assertSame('end before begin', $items['reversed']->error);
        self::assertSame('Overlapping record.', $items['invalid']->error);
        self::assertSame('Database is down', $items['broken']->error);
    }

    public function testRegisterRespectsKimaiPermissions(): void
    {
        $account = Fixtures::account();
        $this->granted = false;
        $items = $this->load($account, [$this->event('a', 'P-100 Calls', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z')]);

        self::assertSame(0, $this->service()->register($account, array_values($items)));
        self::assertStringContainsString('not allowed', $items['a']->error);
        self::assertSame([], $this->saved);
    }

    public function testRegisterStopsWhenTheEntityManagerIsClosed(): void
    {
        $account = Fixtures::account();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);
        $this->entityManager = $entityManager;
        $items = $this->load($account, [
            $this->event('a', 'P-100 Calls', '2026-09-22T10:00:00Z', '2026-09-22T11:00:00Z'),
            $this->event('b', 'P-100 Calls again', '2026-09-22T12:00:00Z', '2026-09-22T13:00:00Z'),
        ]);

        self::assertSame(1, $this->service()->register($account, array_values($items)));
    }
}
