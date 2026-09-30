<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Form;

use App\Form\Type\YesNoType;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<GoogleCalendarAccount>
 */
final class GoogleCalendarSettingsForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('calendarId', ChoiceType::class, [
                'label' => 'gcal.calendar',
                'choices' => $options['calendars'],
                'required' => true,
            ])
            ->add('mappingRules', TextareaType::class, [
                'label' => 'gcal.mapping_rules',
                'help' => 'gcal.mapping_rules_help',
                'required' => false,
                'attr' => ['rows' => 5, 'placeholder' => "Daily => Internal / Meeting\nACME => ACME Website\nCode review => / Development"],
            ])
            ->add('syncEvents', YesNoType::class, [
                'label' => 'gcal.sync_events',
            ])
            ->add('skipDeclined', YesNoType::class, [
                'label' => 'gcal.skip_declined',
            ])
            ->add('skipFree', YesNoType::class, [
                'label' => 'gcal.skip_free',
                'help' => 'gcal.skip_free_help',
            ])
            ->add('syncTasks', YesNoType::class, [
                'label' => 'gcal.sync_tasks',
                'help' => 'gcal.sync_tasks_help',
            ])
            ->add('taskDuration', IntegerType::class, [
                'label' => 'gcal.task_duration',
                'attr' => ['min' => 1, 'max' => 1440],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GoogleCalendarAccount::class,
            'calendars' => ['primary' => 'primary'],
            'translation_domain' => 'messages',
        ]);
        $resolver->setAllowedTypes('calendars', 'array');
    }
}
