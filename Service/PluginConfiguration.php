<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Service;

use App\Configuration\SystemConfiguration;

/**
 * Reads the OAuth client credentials, first from the Kimai system configuration,
 * then from the environment variables GOOGLE_CALENDAR_CLIENT_ID / GOOGLE_CALENDAR_CLIENT_SECRET.
 */
final class PluginConfiguration
{
    public const CLIENT_ID = 'google_calendar.client_id';
    public const CLIENT_SECRET = 'google_calendar.client_secret';

    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    public function getClientId(): ?string
    {
        return $this->read(self::CLIENT_ID, 'GOOGLE_CALENDAR_CLIENT_ID');
    }

    public function getClientSecret(): ?string
    {
        return $this->read(self::CLIENT_SECRET, 'GOOGLE_CALENDAR_CLIENT_SECRET');
    }

    public function isConfigured(): bool
    {
        return $this->getClientId() !== null && $this->getClientSecret() !== null;
    }

    private function read(string $key, string $env): ?string
    {
        $value = $this->configuration->find($key);
        if (\is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $value = $_SERVER[$env] ?? $_ENV[$env] ?? getenv($env);
        if (\is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }
}
