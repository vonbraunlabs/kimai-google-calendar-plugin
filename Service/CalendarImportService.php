<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Service;

use App\Entity\Timesheet;
use App\Timesheet\TimesheetService;
use App\Validator\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarLink;
use KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarLinkRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Loads the Google events and completed tasks of a period as review rows,
 * and registers the rows the user confirmed as timesheet records.
 */
class CalendarImportService
{
    private const IGNORED_EVENT_TYPES = ['workingLocation', 'outOfOffice', 'birthday', 'fromGmail'];

    public function __construct(
        private readonly GoogleApiClient $client,
        private readonly TargetResolver $resolver,
        private readonly TimesheetService $timesheetService,
        private readonly GoogleCalendarLinkRepository $linkRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the review rows of the period, ordered by begin, with guessed project, activity and description.
     *
     * @return ImportItem[]
     * @throws GoogleApiException
     */
    public function loadItems(GoogleCalendarAccount $account, \DateTimeInterface $from, \DateTimeInterface $until): array
    {
        $timezone = new \DateTimeZone($account->getUser()->getTimezone());
        $items = [];

        try {
            if ($account->isSyncEvents()) {
                foreach ($this->client->listEvents($account, $from, $until) as $event) {
                    if (($item = $this->createEventItem($account, $event, $timezone)) !== null) {
                        $items[] = $item;
                    }
                }
            }

            if ($account->isSyncTasks()) {
                foreach ($this->client->listTaskLists($account) as $taskList) {
                    foreach ($this->client->listCompletedTasks($account, $taskList['id'], $from, $until) as $task) {
                        if (($item = $this->createTaskItem($account, $task, $timezone)) !== null) {
                            $items[] = $item;
                        }
                    }
                }
            }
        } catch (GoogleApiException $ex) {
            if ($ex->isAuthorizationLost()) {
                $account->disconnect();
            }
            throw $ex;
        } finally {
            // the access token might have been refreshed (or the account disconnected)
            $this->entityManager->persist($account);
            $this->entityManager->flush();
        }

        usort($items, fn (ImportItem $a, ImportItem $b) => $a->begin <=> $b->begin);

        $links = $this->linkRepository->findByKeys($account, array_map(fn (ImportItem $item) => $item->getKey(), $items));
        $now = new \DateTime();

        foreach ($items as $item) {
            $item->timesheet = ($links[$item->getKey()] ?? null)?->getTimesheet();
            if ($item->isRegistered()) {
                continue;
            }

            [$item->project, $item->projectSource] = $this->resolver->guessProject($item->title, $item->sourceDescription, $account->getMappingRules());
            [$item->activity, $item->activitySource] = $this->resolver->guessActivity($item->project, $item->title, $item->sourceDescription, $account->getMappingRules());
            $item->description = $item->meeting
                ? $this->translator->trans('gcal.meeting_description', ['%title%' => $item->title])
                : $item->title;
            // running or future items stay unselected
            $item->selected = $item->project !== null && $item->activity !== null && $item->end <= $now;
        }

        return $items;
    }

    /**
     * Applies the values the user entered in the review table to the rows.
     *
     * @param ImportItem[] $items
     * @param array<string, array<string, mixed>> $rows indexed by item key
     */
    public function applyInput(GoogleCalendarAccount $account, array $items, array $rows): void
    {
        $timezone = new \DateTimeZone($account->getUser()->getTimezone());

        foreach ($items as $item) {
            if ($item->isRegistered()) {
                continue;
            }

            // only rows the user actually submitted (and checked) are registered
            $row = $rows[$item->getKey()] ?? null;
            if (!\is_array($row)) {
                $item->selected = false;
                continue;
            }

            $item->selected = !empty($row['selected']);
            $item->project = $this->resolver->findProject(isset($row['project']) ? (int) $row['project'] : null);
            $item->activity = $this->resolver->findActivity(isset($row['activity']) ? (int) $row['activity'] : null, $item->project);
            $item->projectSource = $item->activitySource = null;
            $item->description = trim((string) ($row['description'] ?? ''));
            $item->begin = $this->parseDate($row['begin'] ?? null, $timezone) ?? $item->begin;
            $item->end = $this->parseDate($row['end'] ?? null, $timezone) ?? $item->end;
        }
    }

    /**
     * Creates timesheet records for all selected rows. Rows that fail keep an error message.
     *
     * @param ImportItem[] $items
     * @return int number of created records
     */
    public function register(GoogleCalendarAccount $account, array $items): int
    {
        $created = 0;
        $links = $this->linkRepository->findByKeys($account, array_map(fn (ImportItem $item) => $item->getKey(), $items));

        foreach ($items as $item) {
            if (!$item->selected || $item->isRegistered()) {
                continue;
            }

            if ($item->project === null || $item->activity === null) {
                $item->error = $this->translator->trans('gcal.error_missing_target');
                continue;
            }

            if ($item->end <= $item->begin) {
                $item->error = $this->translator->trans('gcal.error_end_before_begin');
                continue;
            }

            try {
                $timesheet = new Timesheet();
                $timesheet->setUser($account->getUser());
                $timesheet->setBegin(clone $item->begin);
                $timesheet->setEnd(clone $item->end);
                $timesheet->setDuration($item->end->getTimestamp() - $item->begin->getTimestamp());
                $timesheet->setProject($item->project);
                $timesheet->setActivity($item->activity);
                $timesheet->setDescription($item->description !== '' ? $item->description : $item->title);
                $this->timesheetService->saveNewTimesheet($timesheet);

                // a link without timesheet exists if the record was deleted in Kimai, it is re-used
                $link = $links[$item->getKey()] ?? new GoogleCalendarLink($account, $item->type, $item->sourceId);
                $link->setTimesheet($timesheet);
                $link->setTitle($item->title);
                $this->entityManager->persist($link);
                $this->entityManager->flush();

                $item->timesheet = $timesheet;
                $created++;
            } catch (ValidationFailedException $ex) {
                $messages = [];
                foreach ($ex->getViolations() as $violation) {
                    $messages[] = $violation->getMessage();
                }
                $item->error = implode(' ', $messages) ?: $ex->getMessage();
            } catch (\Exception $ex) {
                $this->logger->error('Google Calendar import failed: ' . $ex->getMessage(), ['exception' => $ex]);
                $item->error = $ex->getMessage();
            }

            // a failed flush closes the entity manager, the remaining rows cannot be saved
            if (!$this->entityManager->isOpen()) {
                break;
            }
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function createEventItem(GoogleCalendarAccount $account, array $event, \DateTimeZone $timezone): ?ImportItem
    {
        if (($event['status'] ?? '') === 'cancelled'
            || !isset($event['start']['dateTime'], $event['end']['dateTime'])
            || \in_array($event['eventType'] ?? 'default', self::IGNORED_EVENT_TYPES, true)
            || ($account->isSkipFree() && ($event['transparency'] ?? 'opaque') === 'transparent')) {
            return null;
        }

        $meeting = false;
        foreach ($event['attendees'] ?? [] as $attendee) {
            if (($attendee['self'] ?? false) === true) {
                if ($account->isSkipDeclined() && ($attendee['responseStatus'] ?? '') === 'declined') {
                    return null;
                }
            } elseif (($attendee['resource'] ?? false) !== true) {
                $meeting = true;
            }
        }

        return new ImportItem(
            GoogleCalendarLink::TYPE_EVENT,
            $event['id'],
            trim($event['summary'] ?? '') ?: $this->translator->trans('gcal.no_title'),
            $this->toPlainText($event['description'] ?? ''),
            (new \DateTime($event['start']['dateTime']))->setTimezone($timezone),
            (new \DateTime($event['end']['dateTime']))->setTimezone($timezone),
            $meeting,
        );
    }

    /**
     * @param array<string, mixed> $task
     */
    private function createTaskItem(GoogleCalendarAccount $account, array $task, \DateTimeZone $timezone): ?ImportItem
    {
        if (($task['deleted'] ?? false) === true || ($task['status'] ?? '') !== 'completed' || empty($task['completed'])) {
            return null;
        }

        $end = (new \DateTime($task['completed']))->setTimezone($timezone);

        return new ImportItem(
            GoogleCalendarLink::TYPE_TASK,
            $task['id'],
            trim($task['title'] ?? '') ?: $this->translator->trans('gcal.no_title'),
            trim($task['notes'] ?? ''),
            (clone $end)->modify(\sprintf('-%d minutes', $account->getTaskDuration())),
            $end,
            false,
        );
    }

    private function toPlainText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>|<\/p>|<\/li>/i', "\n", $html);

        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5));
    }

    private function parseDate(mixed $value, \DateTimeZone $timezone): ?\DateTime
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d\TH:i', $value, $timezone);

        return $date === false ? null : $date;
    }
}
