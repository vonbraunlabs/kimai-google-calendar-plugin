<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Configuration\ConfigLoaderInterface;
use App\Configuration\SystemConfiguration;
use App\Entity\User;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;

/**
 * Builds Kimai entities in memory, no database involved.
 */
final class Fixtures
{
    public static function setId(object $entity, int $id): object
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);

        return $entity;
    }

    /**
     * Kimai's SystemConfiguration is final, so the real one is used with an in-memory loader.
     *
     * @param array<string, mixed> $values
     */
    public static function systemConfiguration(array $values = []): SystemConfiguration
    {
        $loader = new class($values) implements ConfigLoaderInterface {
            public function __construct(private readonly array $values)
            {
            }

            public function getConfigurations(): array
            {
                return $this->values;
            }
        };

        return new SystemConfiguration($loader);
    }

    public static function user(string $timezone = 'America/Sao_Paulo'): User
    {
        $user = new User();
        $user->setUserIdentifier('tester');
        $user->setTimezone($timezone);

        return $user;
    }

    public static function project(int $id, string $name, ?string $number = null, bool $globalActivities = true): Project
    {
        $customer = new Customer('Customer');
        $project = (new Project())->setName($name)->setCustomer($customer);
        $project->setNumber($number);
        $project->setGlobalActivities($globalActivities);

        return self::setId($project, $id);
    }

    public static function activity(int $id, string $name, ?Project $project = null, ?string $number = null): Activity
    {
        $activity = (new Activity())->setName($name)->setProject($project);
        $activity->setNumber($number);

        return self::setId($activity, $id);
    }

    public static function account(?User $user = null, bool $connected = true): GoogleCalendarAccount
    {
        $account = new GoogleCalendarAccount($user ?? self::user());
        if ($connected) {
            $account->setRefreshToken('refresh-token');
            $account->setAccessToken('access-token');
            $account->setTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
        }

        return $account;
    }
}
