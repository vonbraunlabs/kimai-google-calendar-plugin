<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarLink;

/**
 * @extends ServiceEntityRepository<GoogleCalendarLink>
 */
class GoogleCalendarLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleCalendarLink::class);
    }

    public function findBySource(GoogleCalendarAccount $account, string $type, string $sourceId): ?GoogleCalendarLink
    {
        return $this->findOneBy(['account' => $account, 'sourceType' => $type, 'sourceId' => $sourceId]);
    }

    /**
     * Returns the links of the given sources, indexed by "type:sourceId".
     *
     * @param string[] $keys list of "type:sourceId"
     * @return array<string, GoogleCalendarLink>
     */
    public function findByKeys(GoogleCalendarAccount $account, array $keys): array
    {
        if (\count($keys) === 0) {
            return [];
        }

        $links = $this->createQueryBuilder('l')
            ->leftJoin('l.timesheet', 't')
            ->addSelect('t')
            ->andWhere('l.account = :account')
            ->andWhere('l.sourceId IN (:ids)')
            ->setParameter('account', $account)
            ->setParameter('ids', array_map(fn (string $key) => explode(':', $key, 2)[1], $keys))
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($links as $link) {
            $key = $link->getSourceType() . ':' . $link->getSourceId();
            if (\in_array($key, $keys, true)) {
                $indexed[$key] = $link;
            }
        }

        return $indexed;
    }

    /**
     * @return GoogleCalendarLink[]
     */
    public function findLatest(GoogleCalendarAccount $account, int $limit = 25): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.timesheet', 't')
            ->addSelect('t')
            ->andWhere('l.account = :account')
            ->setParameter('account', $account)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
