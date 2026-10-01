<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\Form;

use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;
use KimaiPlugin\GoogleCalendarBundle\Form\GoogleCalendarSettingsForm;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Form\GoogleCalendarSettingsForm
 */
class GoogleCalendarSettingsFormTest extends TestCase
{
    public function testFields(): void
    {
        $fields = [];
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $type, array $options) use ($builder, &$fields) {
            $fields[$name] = $options;

            return $builder;
        });

        (new GoogleCalendarSettingsForm())->buildForm($builder, ['calendars' => ['Mine (primary)' => 'primary']]);

        self::assertSame(['calendarId', 'mappingRules', 'syncEvents', 'skipDeclined', 'skipFree', 'syncTasks', 'taskDuration'], array_keys($fields));
        self::assertSame(['Mine (primary)' => 'primary'], $fields['calendarId']['choices']);
        self::assertFalse($fields['mappingRules']['required']);
    }

    public function testOptions(): void
    {
        $resolver = new OptionsResolver();
        (new GoogleCalendarSettingsForm())->configureOptions($resolver);

        $options = $resolver->resolve();
        self::assertSame(GoogleCalendarAccount::class, $options['data_class']);
        self::assertSame(['primary' => 'primary'], $options['calendars']);

        $this->expectException(\Symfony\Component\OptionsResolver\Exception\InvalidOptionsException::class);
        $resolver->resolve(['calendars' => 'primary']);
    }
}
