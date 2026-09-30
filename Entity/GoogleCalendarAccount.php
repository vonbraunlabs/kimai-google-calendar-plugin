<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Entity;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarAccountRepository;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Google connection and import settings of one Kimai user.
 */
#[ORM\Entity(repositoryClass: GoogleCalendarAccountRepository::class)]
#[ORM\Table(name: 'kimai2_google_calendar_accounts')]
#[ORM\UniqueConstraint(name: 'gcal_account_user_uniq', columns: ['user_id'])]
class GoogleCalendarAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'google_email', type: Types::STRING, length: 180, nullable: true)]
    private ?string $googleEmail = null;

    /** Encrypted, see TokenEncryptor */
    #[ORM\Column(name: 'access_token', type: Types::TEXT, nullable: true)]
    private ?string $accessToken = null;

    /** Encrypted, see TokenEncryptor */
    #[ORM\Column(name: 'refresh_token', type: Types::TEXT, nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(name: 'token_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column(name: 'calendar_id', type: Types::STRING, length: 255, nullable: false)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $calendarId = 'primary';

    #[ORM\Column(name: 'sync_events', type: Types::BOOLEAN, nullable: false, options: ['default' => true])]
    private bool $syncEvents = true;

    #[ORM\Column(name: 'sync_tasks', type: Types::BOOLEAN, nullable: false, options: ['default' => false])]
    private bool $syncTasks = false;

    #[ORM\Column(name: 'task_duration', type: Types::INTEGER, nullable: false, options: ['default' => 30])]
    #[Assert\Range(min: 1, max: 1440)]
    private int $taskDuration = 30;

    #[ORM\Column(name: 'skip_declined', type: Types::BOOLEAN, nullable: false, options: ['default' => true])]
    private bool $skipDeclined = true;

    #[ORM\Column(name: 'skip_free', type: Types::BOOLEAN, nullable: false, options: ['default' => false])]
    private bool $skipFree = false;

    #[ORM\Column(name: 'mapping_rules', type: Types::TEXT, nullable: true)]
    private ?string $mappingRules = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isConnected(): bool
    {
        return $this->refreshToken !== null && $this->refreshToken !== '';
    }

    public function getGoogleEmail(): ?string
    {
        return $this->googleEmail;
    }

    public function setGoogleEmail(?string $googleEmail): void
    {
        $this->googleEmail = $googleEmail;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): void
    {
        $this->refreshToken = $refreshToken;
    }

    public function getTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeImmutable $tokenExpiresAt): void
    {
        $this->tokenExpiresAt = $tokenExpiresAt;
    }

    public function getCalendarId(): string
    {
        return $this->calendarId;
    }

    public function setCalendarId(?string $calendarId): void
    {
        $this->calendarId = trim((string) $calendarId) ?: 'primary';
    }

    public function isSyncEvents(): bool
    {
        return $this->syncEvents;
    }

    public function setSyncEvents(bool $syncEvents): void
    {
        $this->syncEvents = $syncEvents;
    }

    public function isSyncTasks(): bool
    {
        return $this->syncTasks;
    }

    public function setSyncTasks(bool $syncTasks): void
    {
        $this->syncTasks = $syncTasks;
    }

    public function getTaskDuration(): int
    {
        return $this->taskDuration;
    }

    public function setTaskDuration(?int $taskDuration): void
    {
        $this->taskDuration = $taskDuration ?? 30;
    }

    public function isSkipDeclined(): bool
    {
        return $this->skipDeclined;
    }

    public function setSkipDeclined(bool $skipDeclined): void
    {
        $this->skipDeclined = $skipDeclined;
    }

    public function isSkipFree(): bool
    {
        return $this->skipFree;
    }

    public function setSkipFree(bool $skipFree): void
    {
        $this->skipFree = $skipFree;
    }

    public function getMappingRules(): ?string
    {
        return $this->mappingRules;
    }

    public function setMappingRules(?string $mappingRules): void
    {
        $this->mappingRules = $mappingRules;
    }

    public function disconnect(): void
    {
        $this->accessToken = null;
        $this->refreshToken = null;
        $this->tokenExpiresAt = null;
        $this->googleEmail = null;
    }
}
