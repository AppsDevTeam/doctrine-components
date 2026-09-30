<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject\Filters;

use ADT\DoctrineComponents\QueryObject\Filters\IsActiveFilter;
use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\ActiveAuthorQueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\LazyActiveAuthorQueryObject;

final class IsActiveFilterTest extends DatabaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->loadFixtures();
	}

	public function testConstantValue(): void
	{
		self::assertSame('isActiveFilter', IsActiveFilter::IS_ACTIVE_FILTER);
	}

	public function testTheQueryObjectImplementsTheInterface(): void
	{
		self::assertInstanceOf(IsActiveFilter::class, new ActiveAuthorQueryObject($this->em));
	}

	public function testByIsActiveDefaultsToTrue(): void
	{
		self::assertDqlContains(
			'WHERE e.isActive = :by_isActive',
			(new ActiveAuthorQueryObject($this->em))->createQueryBuilder(),
		);
	}

	public function testTheDefaultFilterReturnsOnlyActiveRows(): void
	{
		self::assertSame(
			[FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_BEATA, FixtureLoader::AUTHOR_DAVID],
			self::idsOf((new ActiveAuthorQueryObject($this->em))->fetch()),
		);
	}

	public function testByIsActiveWithFalseReplacesTheDefaultCondition(): void
	{
		$qb = (new ActiveAuthorQueryObject($this->em))->byIsActive(false)->createQueryBuilder();

		self::assertDqlContains('WHERE e.isActive = :by_isActive', $qb);
		self::assertSame(['by_isActive' => false], self::paramMap($qb));
	}

	public function testByIsActiveWithFalseReturnsTheInactiveRows(): void
	{
		self::assertSame(
			[FixtureLoader::AUTHOR_CYRIL, FixtureLoader::AUTHOR_EVA],
			self::idsOf((new ActiveAuthorQueryObject($this->em))->byIsActive(false)->fetch()),
		);
	}

	public function testByIsActiveReturnsTheQueryObject(): void
	{
		$qo = new ActiveAuthorQueryObject($this->em);

		self::assertSame($qo, $qo->byIsActive(false));
	}

	public function testDisableIsActiveFilterReturnsTheQueryObject(): void
	{
		$qo = new ActiveAuthorQueryObject($this->em);

		self::assertSame($qo, $qo->disableIsActiveFilter());
	}

	public function testByIsActiveRegistersTheFilterUnderTheNamedKey(): void
	{
		self::assertSame(
			[IsActiveFilter::IS_ACTIVE_FILTER],
			(new ActiveAuthorQueryObject($this->em))->getFilterKeys(),
		);
	}

	public function testDisableIsActiveFilterRemovesTheFilter(): void
	{
		$qo = (new ActiveAuthorQueryObject($this->em))->disableIsActiveFilter();

		self::assertSame([], $qo->getFilterKeys());
		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e ORDER BY e.id ASC',
			$qo->createQueryBuilder(),
		);
		self::assertSame([1, 2, 3, 4, 5], self::idsOf($qo->fetch()));
	}

	public function testDisableIsActiveFilterAlsoRemovesAnExplicitByIsActive(): void
	{
		$qo = (new ActiveAuthorQueryObject($this->em))->byIsActive(false)->disableIsActiveFilter();

		self::assertSame([], $qo->getFilterKeys());
		self::assertSame([1, 2, 3, 4, 5], self::idsOf($qo->fetch()));
	}

	public function testDisableIsActiveFilterIsIdempotent(): void
	{
		$qo = (new ActiveAuthorQueryObject($this->em))->disableIsActiveFilter()->disableIsActiveFilter();

		self::assertSame([], $qo->getFilterKeys());
	}

	public function testALazilyRegisteredFilterAppliesTheCondition(): void
	{
		// regrese: byIsActive() se pod klíčem IS_ACTIVE_FILTER nahradí za právě vykonávaný
		// callback, takže se podmínka nesmí zapomenout přidat do query
		self::assertDqlContains(
			'WHERE e.isActive = :by_isActive',
			(new LazyActiveAuthorQueryObject($this->em))->createQueryBuilder(),
		);
	}

	public function testALazilyRegisteredFilterReturnsOnlyActiveRows(): void
	{
		self::assertSame(
			[FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_BEATA, FixtureLoader::AUTHOR_DAVID],
			self::idsOf((new LazyActiveAuthorQueryObject($this->em))->fetch()),
		);
	}

	public function testALazilyRegisteredFilterDoesNotStackConditions(): void
	{
		$qb = (new LazyActiveAuthorQueryObject($this->em))->createQueryBuilder();

		self::assertSame(1, substr_count(self::normalizeDql($qb->getDQL()), 'e.isActive'));
		self::assertSame(['by_isActive' => true], self::paramMap($qb));
	}

	public function testAnExplicitByIsActiveReplacesTheLazilyRegisteredFilter(): void
	{
		$qb = (new LazyActiveAuthorQueryObject($this->em))->byIsActive(false)->createQueryBuilder();

		self::assertSame(1, substr_count(self::normalizeDql($qb->getDQL()), 'e.isActive'));
		self::assertSame(['by_isActive' => false], self::paramMap($qb));
	}

	public function testDisableIsActiveFilterRemovesTheLazilyRegisteredFilter(): void
	{
		$qo = (new LazyActiveAuthorQueryObject($this->em))->disableIsActiveFilter();

		self::assertSame([], $qo->getFilterKeys());
		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e ORDER BY e.id ASC',
			$qo->createQueryBuilder(),
		);
		self::assertSame([1, 2, 3, 4, 5], self::idsOf($qo->fetch()));
	}

	public function testRepeatedByIsActiveDoesNotStackConditions(): void
	{
		$qb = (new ActiveAuthorQueryObject($this->em))
			->byIsActive(false)
			->byIsActive(true)
			->createQueryBuilder();

		self::assertSame(1, substr_count(self::normalizeDql($qb->getDQL()), 'e.isActive'));
		self::assertSame(['by_isActive' => true], self::paramMap($qb));
	}
}
