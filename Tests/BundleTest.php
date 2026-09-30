<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests;

use App\Plugin\PluginInterface;
use App\Plugin\PluginMetadata;
use KimaiPlugin\GoogleCalendarBundle\Command\InstallCommand;
use KimaiPlugin\GoogleCalendarBundle\Controller\GoogleCalendarController;
use KimaiPlugin\GoogleCalendarBundle\DependencyInjection\GoogleCalendarExtension;
use KimaiPlugin\GoogleCalendarBundle\GoogleCalendarBundle;
use KimaiPlugin\GoogleCalendarBundle\Service\CalendarImportService;
use KimaiPlugin\GoogleCalendarBundle\Service\ImportItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\GoogleCalendarBundle
 * @covers \KimaiPlugin\GoogleCalendarBundle\DependencyInjection\GoogleCalendarExtension
 * @covers \KimaiPlugin\GoogleCalendarBundle\Command\InstallCommand
 */
class BundleTest extends TestCase
{
    public function testBundleIsAKimaiPluginWithValidMetadata(): void
    {
        $bundle = new GoogleCalendarBundle();

        self::assertInstanceOf(PluginInterface::class, $bundle);
        $meta = PluginMetadata::createFromPath($bundle->getPath());
        self::assertSame('Google Calendar', $meta->getName());
        self::assertSame('vonbraunlabs/kimai-google-calendar-bundle', $meta->getPackage());
    }

    public function testExtensionRegistersTheServices(): void
    {
        $container = new ContainerBuilder();
        (new GoogleCalendarExtension())->load([], $container);

        self::assertTrue($container->hasDefinition(CalendarImportService::class));
        self::assertTrue($container->hasDefinition(GoogleCalendarController::class));
        self::assertTrue($container->hasDefinition(GoogleCalendarBundle::class), 'plugins are found through the PluginInterface tag');
        self::assertTrue($container->getDefinition(ImportItem::class)->hasTag('container.excluded'), 'value objects are not services');
        self::assertFalse($container->getDefinition(CalendarImportService::class)->hasTag('container.excluded'));
    }

    public function testInstallCommand(): void
    {
        $command = new InstallCommand();
        self::assertSame('kimai:bundle:google-calendar:install', $command->getName());

        $method = new \ReflectionMethod($command, 'getMigrationConfigFilename');
        self::assertFileExists($method->invoke($command));
    }
}
