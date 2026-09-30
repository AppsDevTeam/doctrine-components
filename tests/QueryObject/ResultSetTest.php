<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\QueryObject\PageIsOutOfRangeException;
use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use ArrayIterator;
use IteratorAggregate;
use Nette\Utils\Paginator;

final class ResultSetTest extends DatabaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->loadFixtures();
	}

	public function testImplementsIteratorAggregate(): void
	{
		self::assertInstanceOf(IteratorAggregate::class, (new AuthorQueryObject($this->em))->getResultSet(1, 2));
	}

	public function testFirstPageReturnsTheFirstItems(): void
	{
		$resultSet = (new AuthorQueryObject($this->em))->getResultSet(1, 2);

		self::assertSame([1, 2], self::idsOf(iterator_to_array($resultSet)));
	}

	public function testSecondPageAppliesTheOffset(): void
	{
		$resultSet = (new AuthorQueryObject($this->em))->getResultSet(2, 2);

		self::assertSame([3, 4], self::idsOf(iterator_to_array($resultSet)));
	}

	public function testLastPageMayBeIncomplete(): void
	{
		$resultSet = (new AuthorQueryObject($this->em))->getResultSet(3, 2);

		self::assertSame([5], self::idsOf(iterator_to_array($resultSet)));
	}

	public function testGetIteratorReturnsAnArrayIterator(): void
	{
		self::assertInstanceOf(ArrayIterator::class, (new AuthorQueryObject($this->em))->getResultSet(1, 2)->getIterator());
	}

	public function testIteratorIsCachedBetweenCalls(): void
	{
		$resultSet = (new AuthorQueryObject($this->em))->getResultSet(1, 2);

		self::assertSame($resultSet->getIterator(), $resultSet->getIterator());
	}

	public function testIteratorIsNotRebuiltWhenTheQueryObjectChanges(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$resultSet = $qo->getResultSet(1, 2);

		self::assertSame([1, 2], self::idsOf(iterator_to_array($resultSet)));

		$qo->byId(999);

		self::assertSame([1, 2], self::idsOf(iterator_to_array($resultSet)));
		self::assertSame([], $qo->fetch());
	}

	public function testCountReturnsTheTotalNumberOfRows(): void
	{
		self::assertSame(5, (new AuthorQueryObject($this->em))->getResultSet(1, 2)->count());
	}

	public function testCountIsNotAffectedByThePageSize(): void
	{
		self::assertSame(
			(new AuthorQueryObject($this->em))->getResultSet(1, 2)->count(),
			(new AuthorQueryObject($this->em))->getResultSet(1, 100)->count(),
		);
	}

	public function testCountRespectsFilters(): void
	{
		self::assertSame(2, (new AuthorQueryObject($this->em))->by('isActive', false)->getResultSet(1, 2)->count());
	}

	public function testCountIsCached(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$resultSet = $qo->getResultSet(1, 2);

		self::assertSame(5, $resultSet->count());

		$qo->by('isActive', false);

		self::assertSame(5, $resultSet->count());
		self::assertSame(2, $qo->count());
	}

	public function testGetPaginatorIsConfiguredFromTheQuery(): void
	{
		$paginator = (new AuthorQueryObject($this->em))->getResultSet(2, 2)->getPaginator();

		self::assertInstanceOf(Paginator::class, $paginator);
		self::assertSame(5, $paginator->getItemCount());
		self::assertSame(2, $paginator->getPage());
		self::assertSame(2, $paginator->getItemsPerPage());
		self::assertSame(3, $paginator->getPageCount());
		self::assertSame(1, $paginator->getFirstPage());
		self::assertSame(3, $paginator->getLastPage());
		self::assertSame(2, $paginator->getOffset());
	}

	public function testGetPaginatorIsCached(): void
	{
		$resultSet = (new AuthorQueryObject($this->em))->getResultSet(1, 2);

		self::assertSame($resultSet->getPaginator(), $resultSet->getPaginator());
	}

	public function testPageAboveTheLastOneThrows(): void
	{
		$this->expectException(PageIsOutOfRangeException::class);
		$this->expectExceptionMessage('Page number is out of range. Page number 99 is not in the range (1, 3)');

		(new AuthorQueryObject($this->em))->getResultSet(99, 2)->getPaginator();
	}

	public function testPageZeroThrows(): void
	{
		$this->expectException(PageIsOutOfRangeException::class);

		(new AuthorQueryObject($this->em))->getResultSet(0, 2)->getPaginator();
	}

	public function testNegativePageThrows(): void
	{
		$this->expectException(PageIsOutOfRangeException::class);

		(new AuthorQueryObject($this->em))->getResultSet(-1, 2)->getPaginator();
	}

	public function testFirstPageOfAnEmptyResultIsValid(): void
	{
		$resultSet = (new AuthorQueryObject($this->em))->byId(999)->getResultSet(1, 2);

		self::assertSame(0, $resultSet->count());
		self::assertSame([], iterator_to_array($resultSet));
		self::assertSame(1, $resultSet->getPaginator()->getPage());
	}

	public function testIteratingBeyondTheLastPageReturnsNoRows(): void
	{
		self::assertSame([], iterator_to_array((new AuthorQueryObject($this->em))->getResultSet(99, 2)));
	}
}
