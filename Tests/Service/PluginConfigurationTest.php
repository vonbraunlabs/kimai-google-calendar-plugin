<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\Service;

use KimaiPlugin\GoogleCalendarBundle\Service\PluginConfiguration;
use KimaiPlugin\GoogleCalendarBundle\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Service\PluginConfiguration
 */
class PluginConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['GOOGLE_CALENDAR_CLIENT_ID'], $_SERVER['GOOGLE_CALENDAR_CLIENT_SECRET']);
    }

    private function create(array $values): PluginConfiguration
    {
        return new PluginConfiguration(Fixtures::systemConfiguration($values));
    }

    public function testSystemConfigurationWins(): void
    {
        $_SERVER['GOOGLE_CALENDAR_CLIENT_ID'] = 'env-id';
        $configuration = $this->create([PluginConfiguration::CLIENT_ID => ' db-id ', PluginConfiguration::CLIENT_SECRET => 'db-secret']);

        self::assertSame('db-id', $configuration->getClientId());
        self::assertSame('db-secret', $configuration->getClientSecret());
        self::assertTrue($configuration->isConfigured());
    }

    public function testEnvironmentIsTheFallback(): void
    {
        $_SERVER['GOOGLE_CALENDAR_CLIENT_ID'] = 'env-id';
        $_SERVER['GOOGLE_CALENDAR_CLIENT_SECRET'] = 'env-secret';
        $configuration = $this->create([PluginConfiguration::CLIENT_ID => '   ']);

        self::assertSame('env-id', $configuration->getClientId());
        self::assertSame('env-secret', $configuration->getClientSecret());
    }

    public function testNotConfigured(): void
    {
        $configuration = $this->create([PluginConfiguration::CLIENT_ID => 'id']);

        self::assertNull($configuration->getClientSecret());
        self::assertFalse($configuration->isConfigured());
    }
}
