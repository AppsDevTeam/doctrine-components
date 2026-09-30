<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\QueryObject\QueryObjectInterface;
use ADT\DoctrineComponents\QueryObject\ResultSet;
use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\EntityManagerFactory;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObjectWithoutParentInit;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\CustomAliasAuthorQueryObject;
use Doctrine\ORM\Query;
use Exception;

final class ConstructionTest extends DatabaseTestCase
{
	public function testImplementsQueryObjectInterface(): void
	{
		self::assertInstanceOf(QueryObjectInterface::class, new AuthorQueryObject($this->em));
	}

	public function testConstructorThrowsWhenInitDoesNotCallParent(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Always call "parent::init()" when overriding the "init" method.');

		new AuthorQueryObjectWithoutParentInit($this->em);
	}

	public function testConstructorAppliesDefaultOrder(): void
	{
		self::assertTrue((new AuthorQueryObject($this->em))->hasOrder());
	}

	public function testGetEntityManagerReturnsInjectedInstance(): void
	{
		self::assertSame($this->em, (new AuthorQueryObject($this->em))->getEntityManager());
	}

	public function testSetEntityManagerReplacesInstanceAndReturnsSelf(): void
	{
		$other = EntityManagerFactory::create();
		$qo = new AuthorQueryObject($this->em);

		self::assertSame($qo, $qo->setEntityManager($other));
		self::assertSame($other, $qo->getEntityManager());

		$other->getConnection()->close();
	}

	public function testGetEntityClassReturnsMappedEntity(): void
	{
		self::assertSame(Author::class, (new AuthorQueryObject($this->em))->getEntityClass());
	}

	public function testGetDtoClassIsNullByDefault(): void
	{
		self::assertNull((new AuthorQueryObject($this->em))->getDTOClass());
	}

	public function testCreateQueryBuilderProducesSelectAndOrder(): void
	{
		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e ORDER BY e.id ASC',
			(new AuthorQueryObject($this->em))->createQueryBuilder(),
		);
	}

	public function testCreateQueryBuilderWithoutSelectAndOrderSkipsBoth(): void
	{
		self::assertDqlSame(
			'SELECT FROM ' . Author::class . ' e',
			(new AuthorQueryObject($this->em))->createQueryBuilder(false),
		);
	}

	public function testCreateQueryBuilderIsIdempotent(): void
	{
		$qo = (new AuthorQueryObject($this->em))->by(['name', 'publisher.name'], 'x');

		$first = $qo->createQueryBuilder();
		$second = $qo->createQueryBuilder();

		self::assertSame($first->getDQL(), $second->getDQL());
		self::assertSame(self::paramMap($first), self::paramMap($second));
		self::assertNotSame($first, $second);
	}

	public function testEntityAliasIsConfigurable(): void
	{
		self::assertDqlSame(
			'SELECT a FROM ' . Author::class . ' a ORDER BY a.id ASC',
			(new CustomAliasAuthorQueryObject($this->em))->createQueryBuilder(),
		);
	}

	public function testCustomEntityAliasIsUsedByByIdFilter(): void
	{
		self::assertDqlContains(
			'WHERE a.id IN (:byIdFilter)',
			(new CustomAliasAuthorQueryObject($this->em))->byId(1)->createQueryBuilder(),
		);
	}

	public function testCustomEntityAliasIsUsedByOrByIdFilter(): void
	{
		self::assertDqlContains(
			'WHERE a.name = :by_name OR a.id IN (:orByIdFilter)',
			(new CustomAliasAuthorQueryObject($this->em))->by('name', 'x')->orById(3)->createQueryBuilder(),
		);
	}

	public function testCustomEntityAliasProducesAnExecutableQuery(): void
	{
		$this->loadFixtures();

		self::assertSame(
			[1],
			self::idsOf((new CustomAliasAuthorQueryObject($this->em))->byId(1)->fetch()),
		);
	}

	public function testCustomEntityAliasWorksForFetchFieldAndCount(): void
	{
		$this->loadFixtures();

		$qo = new CustomAliasAuthorQueryObject($this->em);

		self::assertSame(5, $qo->count());
		self::assertSame(
			[1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
			self::ksorted((new CustomAliasAuthorQueryObject($this->em))->fetchField('id')),
		);
	}

	public function testGetQueryUsesGivenQueryBuilder(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qb = $qo->createQueryBuilder(false)->select('e.id');

		self::assertDqlSame('SELECT e.id FROM ' . Author::class . ' e', $qo->getQuery($qb));
	}

	public function testGetQueryAppliesScalarHint(): void
	{
		$query = (new AuthorQueryObject($this->em))
			->addHint(Query::HINT_REFRESH, true)
			->getQuery();

		self::assertTrue($query->getHint(Query::HINT_REFRESH));
	}

	public function testGetQueryResolvesCallableHint(): void
	{
		$query = (new AuthorQueryObject($this->em))
			->addHint('adt.callableHint', fn() => 'resolved')
			->getQuery();

		self::assertSame('resolved', $query->getHint('adt.callableHint'));
	}

	public function testGetQueryAppliesAllRegisteredHints(): void
	{
		$query = (new AuthorQueryObject($this->em))
			->addHint(Query::HINT_REFRESH, true)
			->addHint('adt.first', 1)
			->addHint('adt.second', fn() => 2)
			->getQuery();

		self::assertTrue($query->getHint(Query::HINT_REFRESH));
		self::assertSame(1, $query->getHint('adt.first'));
		self::assertSame(2, $query->getHint('adt.second'));
	}

	public function testNoHintsAreSetByDefault(): void
	{
		self::assertFalse((new AuthorQueryObject($this->em))->getQuery()->hasHint('adt.callableHint'));
	}

	public function testGetResultSetReturnsResultSet(): void
	{
		self::assertInstanceOf(ResultSet::class, (new AuthorQueryObject($this->em))->getResultSet(1, 10));
	}

	public function testFluentSettersReturnSameInstance(): void
	{
		$qo = new AuthorQueryObject($this->em);

		self::assertSame($qo, $qo->byId(1));
		self::assertSame($qo, $qo->orById(1));
		self::assertSame($qo, $qo->by('name', 'x'));
		self::assertSame($qo, $qo->orderBy('id'));
		self::assertSame($qo, $qo->disableFilter('nope'));
		self::assertSame($qo, $qo->disableDefaultOrder());
		self::assertSame($qo, $qo->addPostFetch('books'));
	}
}
