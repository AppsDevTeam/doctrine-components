<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;

final class ByIdTest extends DatabaseTestCase
{
	public function testByIdIsNotAppliedWhenNeverCalled(): void
	{
		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e ORDER BY e.id ASC',
			(new AuthorQueryObject($this->em))->createQueryBuilder(),
		);
	}

	public function testScalarIdProducesInCondition(): void
	{
		$qb = (new AuthorQueryObject($this->em))->byId(3)->createQueryBuilder();

		self::assertDqlContains('WHERE e.id IN (:byIdFilter)', $qb);
		self::assertSame(['byIdFilter' => [3 => 3]], self::paramMap($qb));
	}

	public function testScalarIdFiltersRows(): void
	{
		$this->loadFixtures();

		self::assertSame(
			[FixtureLoader::AUTHOR_CYRIL],
			self::idsOf((new AuthorQueryObject($this->em))->byId(FixtureLoader::AUTHOR_CYRIL)->fetch()),
		);
	}

	public function testNumericStringIdIsAccepted(): void
	{
		$this->loadFixtures();

		self::assertSame(
			[FixtureLoader::AUTHOR_BEATA],
			self::idsOf((new AuthorQueryObject($this->em))->byId('2')->fetch()),
		);
	}

	public function testArrayOfIdsIsDeduplicatedAndKeyedById(): void
	{
		$qb = (new AuthorQueryObject($this->em))->byId([3, 4, 3])->createQueryBuilder();

		self::assertSame(['byIdFilter' => [3 => 3, 4 => 4]], self::paramMap($qb));
	}

	public function testArrayOfIdsFiltersRows(): void
	{
		$this->loadFixtures();

		self::assertSame(
			[FixtureLoader::AUTHOR_BEATA, FixtureLoader::AUTHOR_DAVID],
			self::idsOf((new AuthorQueryObject($this->em))->byId([4, 2])->fetch()),
		);
	}

	public function testEntityIsAccepted(): void
	{
		$this->loadFixtures();

		$author = $this->em->find(Author::class, FixtureLoader::AUTHOR_BEATA);

		self::assertSame(
			[FixtureLoader::AUTHOR_BEATA],
			self::idsOf((new AuthorQueryObject($this->em))->byId($author)->fetch()),
		);
	}

	public function testArrayOfEntitiesIsAccepted(): void
	{
		$this->loadFixtures();

		$authors = [
			$this->em->find(Author::class, FixtureLoader::AUTHOR_BEATA),
			$this->em->find(Author::class, FixtureLoader::AUTHOR_DAVID),
		];

		self::assertSame(
			[FixtureLoader::AUTHOR_BEATA, FixtureLoader::AUTHOR_DAVID],
			self::idsOf((new AuthorQueryObject($this->em))->byId($authors)->fetch()),
		);
	}

	public function testUnpersistedEntityMatchesNothing(): void
	{
		$this->loadFixtures();

		self::assertSame([], (new AuthorQueryObject($this->em))->byId(new Author('nobody'))->fetch());
	}

	public function testEmptyArrayProducesAnAlwaysFalseCondition(): void
	{
		$this->loadFixtures();

		$qb = (new AuthorQueryObject($this->em))->byId([])->createQueryBuilder();

		self::assertDqlContains('WHERE e.id IN (:byIdFilter)', $qb);
		self::assertSame(['byIdFilter' => []], self::paramMap($qb));
		self::assertSame([], (new AuthorQueryObject($this->em))->byId([])->fetch());
	}

	public function testNullProducesAnAlwaysFalseCondition(): void
	{
		$this->loadFixtures();

		$qb = (new AuthorQueryObject($this->em))->byId(null)->createQueryBuilder();

		self::assertDqlContains('WHERE e.id IN (:byIdFilter)', $qb);
		self::assertSame(['byIdFilter' => []], self::paramMap($qb));
		self::assertSame([], (new AuthorQueryObject($this->em))->byId(null)->fetch());
	}

	public function testRepeatedCallsAreMergedIntoOneCondition(): void
	{
		$this->loadFixtures();

		$qb = (new AuthorQueryObject($this->em))->byId(1)->byId([2, 3])->createQueryBuilder();

		self::assertSame(1, substr_count(self::normalizeDql($qb->getDQL()), 'e.id IN (:byIdFilter)'));
		self::assertSame(['byIdFilter' => [1 => 1, 2 => 2, 3 => 3]], self::paramMap($qb));
	}

	public function testEmptyArrayAfterAScalarDiscardsThePreviousIds(): void
	{
		$qb = (new AuthorQueryObject($this->em))->byId(1)->byId([])->createQueryBuilder();

		self::assertSame(['byIdFilter' => []], self::paramMap($qb));
	}

	public function testByIdIsCombinedWithOtherFiltersUsingAnd(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))
			->by('isActive', true)
			->byId([FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_CYRIL])
			->fetch();

		self::assertSame([FixtureLoader::AUTHOR_ADAM], self::idsOf($authors));
	}

	public function testByIdIsAppliedAfterFiltersSoItIsNeverOverwritten(): void
	{
		$qb = (new AuthorQueryObject($this->em))
			->byId(1)
			->by('name', 'x')
			->createQueryBuilder();

		self::assertDqlContains('WHERE e.name = :by_name AND e.id IN (:byIdFilter)', $qb);
	}

	public function testNullInsideAnArrayIsSkipped(): void
	{
		$qb = (new AuthorQueryObject($this->em))->byId([1, null])->createQueryBuilder();

		self::assertSame(['byIdFilter' => [1 => 1]], self::paramMap($qb));
	}

	public function testAnArrayOfOnlyNullsStillProducesAnAlwaysFalseCondition(): void
	{
		$this->loadFixtures();

		$qb = (new AuthorQueryObject($this->em))->byId([null, null])->createQueryBuilder();

		self::assertDqlContains('WHERE e.id IN (:byIdFilter)', $qb);
		self::assertSame(['byIdFilter' => []], self::paramMap($qb));
		self::assertSame([], (new AuthorQueryObject($this->em))->byId([null])->fetch());
	}

	public function testAnUnpersistedEntityStillProducesAnAlwaysFalseCondition(): void
	{
		$qb = (new AuthorQueryObject($this->em))->byId(new Author('nobody'))->createQueryBuilder();

		self::assertDqlContains('WHERE e.id IN (:byIdFilter)', $qb);
		self::assertSame(['byIdFilter' => []], self::paramMap($qb));
	}

	public function testAGeneratorOfIdsIsAccepted(): void
	{
		$this->loadFixtures();

		$ids = (static function (): \Generator {
			yield 2;
			yield 4;
		})();

		self::assertSame(
			[FixtureLoader::AUTHOR_BEATA, FixtureLoader::AUTHOR_DAVID],
			self::idsOf((new AuthorQueryObject($this->em))->byId($ids)->fetch()),
		);
	}

	public function testAnEmptyGeneratorProducesAnAlwaysFalseCondition(): void
	{
		$this->loadFixtures();

		$ids = (static function (): \Generator {
			return;
			yield 1;
		})();

		self::assertSame([], (new AuthorQueryObject($this->em))->byId($ids)->fetch());
	}
}
