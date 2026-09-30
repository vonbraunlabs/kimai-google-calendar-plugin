<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;

/**
 * @extends ServiceEntityRepository<GoogleCalendarAccount>
 */
class GoogleCalendarAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleCalendarAccount::class);
    }

    public function findByUser(User $user): ?GoogleCalendarAccount
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function getOrCreate(User $user): GoogleCalendarAccount
    {
        return $this->findByUser($user) ?? new GoogleCalendarAccount($user);
    }

    public function save(GoogleCalendarAccount $account): void
    {
        $this->getEntityManager()->persist($account);
        $this->getEntityManager()->flush();
    }
}
