<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarLink;
use KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarAccountRepository;
use KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarLinkRepository;
use KimaiPlugin\GoogleCalendarBundle\Tests\Fixtures;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarAccountRepository
 * @covers \KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarLinkRepository
 */
class RepositoriesTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    /** @var array<string, mixed> */
    private array $parameters = [];

    private function registry(string $class, array $queryResult = [], mixed $findOneBy = null): ManagerRegistry
    {
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn($queryResult);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'leftJoin', 'addSelect', 'andWhere', 'orderBy', 'setMaxResults'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('setParameter')->willReturnCallback(function (string $key, mixed $value) use ($qb) {
            $this->parameters[$key] = $value;

            return $qb;
        });
        $qb->method('getQuery')->willReturn($query);

        $persister = $this->createMock(\Doctrine\ORM\Persisters\Entity\EntityPersister::class);
        $persister->method('load')->willReturn($findOneBy);
        $unitOfWork = $this->createMock(\Doctrine\ORM\UnitOfWork::class);
        $unitOfWork->method('getEntityPersister')->willReturn($persister);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getClassMetadata')->willReturn(new ClassMetadata($class));
        $this->entityManager->method('createQueryBuilder')->willReturn($qb);
        $this->entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        return $registry;
    }

    public function testAccountRepository(): void
    {
        $existing = Fixtures::account();
        $user = Fixtures::user();

        $repository = new GoogleCalendarAccountRepository($this->registry(GoogleCalendarAccount::class, [], $existing));
        self::assertSame($existing, $repository->findByUser($user));
        self::assertSame($existing, $repository->getOrCreate($user));

        $repository = new GoogleCalendarAccountRepository($this->registry(GoogleCalendarAccount::class));
        $created = $repository->getOrCreate($user);
        self::assertSame($user, $created->getUser());
        self::assertNull($created->getId());

        $this->entityManager->expects(self::once())->method('persist')->with($created);
        $this->entityManager->expects(self::once())->method('flush');
        $repository->save($created);
    }

    public function testLinkRepositoryFindsBySourceAndKeys(): void
    {
        $account = Fixtures::account();
        $event = new GoogleCalendarLink($account, 'event', 'shared-id');
        $task = new GoogleCalendarLink($account, 'task', 'shared-id');
        $other = new GoogleCalendarLink($account, 'event', 'other');

        $repository = new GoogleCalendarLinkRepository($this->registry(GoogleCalendarLink::class, [$event, $task, $other], $event));

        self::assertSame($event, $repository->findBySource($account, 'event', 'shared-id'));
        self::assertSame([], $repository->findByKeys($account, []));
        self::assertSame(['event:shared-id' => $event], $repository->findByKeys($account, ['event:shared-id']), 'same id of another type is ignored');
        self::assertSame(['shared-id'], $this->parameters['ids']);
        self::assertSame($account, $this->parameters['account']);
        self::assertSame([$event, $task, $other], $repository->findLatest($account, 10));
    }
}
