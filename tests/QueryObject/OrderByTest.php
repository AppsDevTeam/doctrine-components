<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use Doctrine\ORM\QueryBuilder;
use Exception;

final class OrderByTest extends DatabaseTestCase
{
	public function testDefaultOrderComesFromSetDefaultOrder(): void
	{
		self::assertDqlContains('ORDER BY e.id ASC', (new AuthorQueryObject($this->em))->createQueryBuilder());
	}

	public function testSingleColumnWithDirection(): void
	{
		self::assertDqlContains(
			'ORDER BY e.name DESC',
			(new AuthorQueryObject($this->em))->orderBy('name', 'DESC')->createQueryBuilder(),
		);
	}

	public function testSingleColumnWithoutDirection(): void
	{
		self::assertDqlContains(
			'ORDER BY e.name',
			(new AuthorQueryObject($this->em))->orderBy('name')->createQueryBuilder(),
		);
	}

	public function testArrayOfColumns(): void
	{
		self::assertDqlContains(
			'ORDER BY e.name ASC, e.id DESC',
			(new AuthorQueryObject($this->em))->orderBy(['name' => 'ASC', 'id' => 'DESC'])->createQueryBuilder(),
		);
	}

	public function testOrderByReplacesThePreviousOrder(): void
	{
		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e ORDER BY e.email DESC',
			(new AuthorQueryObject($this->em))->orderBy('name', 'ASC')->orderBy('email', 'DESC')->createQueryBuilder(),
		);
	}

	public function testOrderByAddsJoinsForDotNotation(): void
	{
		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e LEFT JOIN e.publisher publisher ORDER BY publisher.name DESC',
			(new AuthorQueryObject($this->em))->orderBy('publisher.name', 'DESC')->createQueryBuilder(),
		);
	}

	public function testOrderByReusesAJoinAlreadyAddedByAFilter(): void
	{
		$qb = (new AuthorQueryObject($this->em))
			->by('publisher.country', 'CZ')
			->orderBy('publisher.name', 'ASC')
			->createQueryBuilder();

		self::assertSame(1, substr_count(self::normalizeDql($qb->getDQL()), 'LEFT JOIN e.publisher publisher'));
	}

	public function testOrderIsActuallyAppliedToResults(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))->orderBy('name', 'DESC')->fetch();

		self::assertSame(
			['Eva Nová', 'David Adamec', 'Cyril Velký', 'Beata Malá', 'Adam Novák'],
			self::namesOf($authors),
		);
	}

	public function testOrderByJoinedColumnIsAppliedToResults(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))
			->orderBy(['publisher.name' => 'ASC', 'id' => 'ASC'])
			->fetch();

		self::assertSame(
			[
				FixtureLoader::AUTHOR_DAVID,
				FixtureLoader::AUTHOR_ADAM,
				FixtureLoader::AUTHOR_BEATA,
				FixtureLoader::AUTHOR_CYRIL,
				FixtureLoader::AUTHOR_EVA,
			],
			self::idsOf($authors),
		);
	}

	public function testDisableDefaultOrderRemovesTheOrderByPart(): void
	{
		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e',
			(new AuthorQueryObject($this->em))->disableDefaultOrder()->createQueryBuilder(),
		);
	}

	public function testOrderByAfterDisableDefaultOrderWorks(): void
	{
		self::assertDqlContains(
			'ORDER BY e.name ASC',
			(new AuthorQueryObject($this->em))->disableDefaultOrder()->orderBy('name', 'ASC')->createQueryBuilder(),
		);
	}

	public function testDisableDefaultOrderAfterOrderByRemovesIt(): void
	{
		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e',
			(new AuthorQueryObject($this->em))->orderBy('name', 'ASC')->disableDefaultOrder()->createQueryBuilder(),
		);
	}

	public function testEntityAliasInFieldNameIsRejected(): void
	{
		$qo = (new AuthorQueryObject($this->em))->orderBy('e.name', 'ASC');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Do not use entity alias in field names.');

		$qo->createQueryBuilder();
	}

	public function testDirectionMustNotBeGivenTogetherWithAnArray(): void
	{
		$qo = (new AuthorQueryObject($this->em))->orderBy(['name' => 'ASC'], 'DESC');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Do not specify "$order" if "$field" is an array.');

		$qo->createQueryBuilder();
	}

	public function testEmptyArrayIsRejected(): void
	{
		$qo = (new AuthorQueryObject($this->em))->orderBy([]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Parameter "$field" cannot be empty.');

		$qo->createQueryBuilder();
	}

	public function testCustomOrderCallbackReplacesOrderBy(): void
	{
		$qo = (new AuthorQueryObject($this->em))->setOrder(function (QueryBuilder $qb) {
			$qb->addOrderBy('e.rating', 'DESC')->addOrderBy('e.id', 'ASC');
		});

		self::assertDqlContains('ORDER BY e.rating DESC, e.id ASC', $qo->createQueryBuilder());
	}

	public function testOrderCallbackIsNotAppliedWithoutSelectAndOrder(): void
	{
		self::assertDqlSame(
			'SELECT FROM ' . Author::class . ' e',
			(new AuthorQueryObject($this->em))->orderBy('name', 'ASC')->createQueryBuilder(false),
		);
	}
}
