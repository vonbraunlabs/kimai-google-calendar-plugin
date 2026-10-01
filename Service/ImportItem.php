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
use App\Entity\Timesheet;

/**
 * One row of the review table: a Google event or task, and what will be registered for it.
 */
final class ImportItem
{
    public ?Project $project = null;
    public ?string $projectSource = null;
    public ?Activity $activity = null;
    public ?string $activitySource = null;
    public string $description = '';
    public bool $selected = false;
    /** Already registered in Kimai */
    public ?Timesheet $timesheet = null;
    public ?string $error = null;

    public function __construct(
        public readonly string $type,
        public readonly string $sourceId,
        public readonly string $title,
        public readonly string $sourceDescription,
        public \DateTime $begin,
        public \DateTime $end,
        public readonly bool $meeting,
    ) {
    }

    public function getKey(): string
    {
        return $this->type . ':' . $this->sourceId;
    }

    public function isRegistered(): bool
    {
        return $this->timesheet !== null;
    }
}
