<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\DistinctCountAuthorQueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\GroupedAuthorQueryObject;

final class CountTest extends DatabaseTestCase
{
	public function testDefaultCountExpressionUsesTheEntityAliasAndId(): void
	{
		self::assertSame('e.id', (new AuthorQueryObject($this->em))->callGetCountExpr());
	}

	public function testOverriddenCountExpressionIsUsed(): void
	{
		self::assertSame('DISTINCT e.id', (new DistinctCountAuthorQueryObject($this->em))->callGetCountExpr());
	}

	public function testCountReturnsAnInt(): void
	{
		$this->loadFixtures();

		self::assertSame(5, (new AuthorQueryObject($this->em))->count());
	}

	public function testCountExpressionCanBeOverridden(): void
	{
		$this->loadFixtures();

		self::assertSame(5, (new DistinctCountAuthorQueryObject($this->em))->count());
	}

	public function testDistinctCountExpressionDeduplicatesJoinedRows(): void
	{
		$this->loadFixtures();

		self::assertSame(
			3,
			(new DistinctCountAuthorQueryObject($this->em))
				->by('books.title', 'kniha', \ADT\DoctrineComponents\QueryObject\QueryObjectByMode::CONTAINS)
				->count(),
		);
	}

	public function testCountUsesPaginatorWhenTheQueryIsGrouped(): void
	{
		$this->loadFixtures();

		self::assertSame(4, (new GroupedAuthorQueryObject($this->em))->count());
	}

	public function testGroupedCountRespectsFilters(): void
	{
		$this->loadFixtures();

		self::assertSame(2, (new GroupedAuthorQueryObject($this->em))->by('isActive', false)->count());
	}

	public function testCountIsNotAffectedByTheDefaultOrder(): void
	{
		$this->loadFixtures();

		self::assertSame(
			(new AuthorQueryObject($this->em))->count(),
			(new AuthorQueryObject($this->em))->disableDefaultOrder()->count(),
		);
	}
}
