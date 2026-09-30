<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;

final class OrByIdTest extends DatabaseTestCase
{
	public function testOrByIdBypassesOtherFilters(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))
			->by('isActive', true)
			->orById(FixtureLoader::AUTHOR_CYRIL)
			->fetch();

		self::assertSame(
			[
				FixtureLoader::AUTHOR_ADAM,
				FixtureLoader::AUTHOR_BEATA,
				FixtureLoader::AUTHOR_CYRIL,
				FixtureLoader::AUTHOR_DAVID,
			],
			self::idsOf($authors),
		);
	}

	public function testOrByIdProducesOrWhereWithItsOwnParameter(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('name', 'x')->orById(3)->createQueryBuilder();

		self::assertDqlContains('WHERE e.name = :by_name OR e.id IN (:orByIdFilter)', $qb);
		self::assertSame(['by_name' => 'x', 'orByIdFilter' => [3 => 3]], self::paramMap($qb));
	}

	public function testOrByIdIsIgnoredWhenThereIsNoOtherCondition(): void
	{
		$this->loadFixtures();

		$qb = (new AuthorQueryObject($this->em))->orById(FixtureLoader::AUTHOR_CYRIL)->createQueryBuilder();

		self::assertDqlSame('SELECT e FROM ' . Author::class . ' e ORDER BY e.id ASC', $qb);
		self::assertSame([], self::paramMap($qb));
	}

	public function testOrByIdIsCombinedWithById(): void
	{
		$this->loadFixtures();

		$qb = (new AuthorQueryObject($this->em))
			->byId(FixtureLoader::AUTHOR_ADAM)
			->orById(FixtureLoader::AUTHOR_CYRIL)
			->createQueryBuilder();

		self::assertDqlContains('WHERE e.id IN (:byIdFilter) OR e.id IN (:orByIdFilter)', $qb);
		self::assertSame(
			['byIdFilter' => [1 => 1], 'orByIdFilter' => [3 => 3]],
			self::paramMap($qb),
		);
		self::assertSame(
			[FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_CYRIL],
			self::idsOf(
				(new AuthorQueryObject($this->em))
					->byId(FixtureLoader::AUTHOR_ADAM)
					->orById(FixtureLoader::AUTHOR_CYRIL)
					->fetch(),
			),
		);
	}

	public function testOrByIdIsAppliedAfterAllFiltersAndById(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))
			->by('isActive', true)
			->byId([FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_CYRIL])
			->orById(FixtureLoader::AUTHOR_EVA)
			->fetch();

		self::assertSame([FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_EVA], self::idsOf($authors));
	}

	public function testArrayOfIdsIsDeduplicated(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('name', 'x')->orById([3, 4, 3])->createQueryBuilder();

		self::assertSame(['by_name' => 'x', 'orByIdFilter' => [3 => 3, 4 => 4]], self::paramMap($qb));
	}

	public function testEntityIsAccepted(): void
	{
		$this->loadFixtures();

		$cyril = $this->em->find(Author::class, FixtureLoader::AUTHOR_CYRIL);

		$authors = (new AuthorQueryObject($this->em))->by('isActive', true)->orById($cyril)->fetch();

		self::assertContains(FixtureLoader::AUTHOR_CYRIL, self::idsOf($authors));
	}

	public function testArrayOfEntitiesIsAccepted(): void
	{
		$this->loadFixtures();

		$entities = [
			$this->em->find(Author::class, FixtureLoader::AUTHOR_CYRIL),
			$this->em->find(Author::class, FixtureLoader::AUTHOR_EVA),
		];

		$qb = (new AuthorQueryObject($this->em))->by('isActive', true)->orById($entities)->createQueryBuilder();

		self::assertSame(
			['by_isActive' => true, 'orByIdFilter' => [3 => 3, 5 => 5]],
			self::paramMap($qb),
		);
	}

	public function testArrayMixingEntitiesIdsAndNullsIsAccepted(): void
	{
		$this->loadFixtures();

		$qb = (new AuthorQueryObject($this->em))
			->by('isActive', true)
			->orById([$this->em->find(Author::class, FixtureLoader::AUTHOR_CYRIL), null, 5])
			->createQueryBuilder();

		self::assertSame(
			['by_isActive' => true, 'orByIdFilter' => [3 => 3, 5 => 5]],
			self::paramMap($qb),
		);
	}

	public function testNullIsSkipped(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('name', 'x')->orById(null)->createQueryBuilder();

		self::assertDqlContains('WHERE e.name = :by_name', $qb);
		self::assertSame(['by_name' => 'x'], self::paramMap($qb));
	}

	public function testNullInsideAnArrayIsSkipped(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('name', 'x')->orById([null, 3, null])->createQueryBuilder();

		self::assertSame(['by_name' => 'x', 'orByIdFilter' => [3 => 3]], self::paramMap($qb));
	}

	public function testArrayOfOnlyNullsIsIgnored(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('name', 'x')->orById([null])->createQueryBuilder();

		self::assertDqlContains('WHERE e.name = :by_name', $qb);
		self::assertSame(['by_name' => 'x'], self::paramMap($qb));
	}

	public function testEmptyArrayIsIgnored(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('name', 'x')->orById([])->createQueryBuilder();

		self::assertSame(['by_name' => 'x'], self::paramMap($qb));
	}

	public function testRepeatedCallsAreMerged(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('name', 'x')->orById(3)->orById([4])->createQueryBuilder();

		self::assertSame(1, substr_count(self::normalizeDql($qb->getDQL()), 'e.id IN (:orByIdFilter)'));
		self::assertSame(['by_name' => 'x', 'orByIdFilter' => [3 => 3, 4 => 4]], self::paramMap($qb));
	}
}
