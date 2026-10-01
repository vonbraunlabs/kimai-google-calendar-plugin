<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Event\SystemConfigurationEvent;
use App\Utils\MenuItemModel;
use KimaiPlugin\GoogleCalendarBundle\EventSubscriber\MenuSubscriber;
use KimaiPlugin\GoogleCalendarBundle\EventSubscriber\SystemConfigurationSubscriber;
use KimaiPlugin\GoogleCalendarBundle\Service\PluginConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\EventSubscriber\MenuSubscriber
 * @covers \KimaiPlugin\GoogleCalendarBundle\EventSubscriber\SystemConfigurationSubscriber
 */
class SubscribersTest extends TestCase
{
    private function menuSubscriber(bool $granted): MenuSubscriber
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->with('create_own_timesheet')->willReturn($granted);

        return new MenuSubscriber($auth);
    }

    public function testMenuEntryIsAddedToTheTimesheetMenu(): void
    {
        self::assertArrayHasKey(ConfigureMainMenuEvent::class, MenuSubscriber::getSubscribedEvents());

        $event = new ConfigureMainMenuEvent();
        $event->getMenu()->addChild(new MenuItemModel('times', 'time_tracking', null));
        $this->menuSubscriber(true)->onMenuConfigure($event);

        $entry = $event->findById('google_calendar');
        self::assertNotNull($entry);
        self::assertSame('google_calendar', $entry->getRoute());
        self::assertSame($event->getTimesheetMenu(), $entry->getParent());
    }

    public function testMenuFallsBackToTheMainMenu(): void
    {
        $event = new ConfigureMainMenuEvent();
        $this->menuSubscriber(true)->onMenuConfigure($event);

        self::assertSame($event->getMenu(), $event->findById('google_calendar')?->getParent());
    }

    public function testNoMenuWithoutPermission(): void
    {
        $event = new ConfigureMainMenuEvent();
        $this->menuSubscriber(false)->onMenuConfigure($event);

        self::assertNull($event->findById('google_calendar'));
    }

    public function testSystemConfigurationSection(): void
    {
        self::assertArrayHasKey(SystemConfigurationEvent::class, SystemConfigurationSubscriber::getSubscribedEvents());

        $event = new SystemConfigurationEvent([]);
        (new SystemConfigurationSubscriber())->onSystemConfiguration($event);

        $section = $event->getConfigurations()[0];
        self::assertSame('google_calendar', $section->getSection());
        self::assertSame('gcal.title', $section->getTranslation());
        self::assertSame('messages', $section->getTranslationDomain());
        self::assertNotNull($section->getConfigurationByName(PluginConfiguration::CLIENT_ID));
        self::assertNotNull($section->getConfigurationByName(PluginConfiguration::CLIENT_SECRET));
        self::assertFalse($section->getConfigurationByName(PluginConfiguration::CLIENT_SECRET)->isRequired());
    }
}
