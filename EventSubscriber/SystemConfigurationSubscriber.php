<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\EventSubscriber;

use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration;
use KimaiPlugin\GoogleCalendarBundle\Service\PluginConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Adds the Google OAuth client credentials to the Kimai system configuration.
 */
final class SystemConfigurationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigurationEvent::class => ['onSystemConfiguration', 100],
        ];
    }

    public function onSystemConfiguration(SystemConfigurationEvent $event): void
    {
        $event->addConfiguration(
            (new SystemConfiguration('google_calendar'))
                ->setTranslation('gcal.title')
                ->setTranslationDomain('messages')
                ->setConfiguration([
                    (new Configuration(PluginConfiguration::CLIENT_ID))
                        ->setLabel('gcal.client_id')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(TextType::class),
                    (new Configuration(PluginConfiguration::CLIENT_SECRET))
                        ->setLabel('gcal.client_secret')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(TextType::class),
                ])
        );
    }
}
