<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Entity;

use App\Entity\Timesheet;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarLinkRepository;

/**
 * Remembers which Google event/task produced which timesheet, so imports are idempotent.
 *
 * If the timesheet gets deleted in Kimai, the link stays with timesheet = null: the item shows up
 * as pending again in the review table, and registering it re-uses this link (ADR-0001).
 */
#[ORM\Entity(repositoryClass: GoogleCalendarLinkRepository::class)]
#[ORM\Table(name: 'kimai2_google_calendar_links')]
#[ORM\UniqueConstraint(name: 'gcal_link_source_uniq', columns: ['account_id', 'source_type', 'source_id'])]
class GoogleCalendarLink
{
    public const TYPE_EVENT = 'event';
    public const TYPE_TASK = 'task';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GoogleCalendarAccount::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private GoogleCalendarAccount $account;

    #[ORM\ManyToOne(targetEntity: Timesheet::class)]
    #[ORM\JoinColumn(name: 'timesheet_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Timesheet $timesheet = null;

    #[ORM\Column(name: 'source_type', type: Types::STRING, length: 10, nullable: false)]
    private string $sourceType;

    #[ORM\Column(name: 'source_id', type: Types::STRING, length: 255, nullable: false)]
    private string $sourceId;

    #[ORM\Column(name: 'title', type: Types::STRING, length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE, nullable: false)]
    private \DateTimeImmutable $createdAt;

    public function __construct(GoogleCalendarAccount $account, string $sourceType, string $sourceId)
    {
        $this->account = $account;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): GoogleCalendarAccount
    {
        return $this->account;
    }

    public function getTimesheet(): ?Timesheet
    {
        return $this->timesheet;
    }

    public function setTimesheet(?Timesheet $timesheet): void
    {
        $this->timesheet = $timesheet;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title !== null ? mb_substr($title, 0, 255) : null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
