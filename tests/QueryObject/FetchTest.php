<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\QueryObject\QueryObjectByMode as Mode;
use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\FilterModifiedSelectAuthorQueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\HiddenSelectAuthorQueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\ModifiedSelectAuthorQueryObject;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\TransactionRequiredException;
use Exception;
use Generator;

final class FetchTest extends DatabaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->loadFixtures();
	}

	public function testFetchReturnsAllEntities(): void
	{
		$authors = (new AuthorQueryObject($this->em))->fetch();

		self::assertCount(5, $authors);
		self::assertContainsOnlyInstancesOf(Author::class, $authors);
		self::assertSame([1, 2, 3, 4, 5], self::idsOf($authors));
	}

	public function testFetchAppliesLimit(): void
	{
		self::assertSame([1, 2], self::idsOf((new AuthorQueryObject($this->em))->fetch(2)));
	}

	public function testFetchAppliesLimitAndOffset(): void
	{
		self::assertSame([3, 4], self::idsOf((new AuthorQueryObject($this->em))->fetch(2, 2)));
	}

	public function testFetchAppliesOffsetWithoutLimit(): void
	{
		self::assertSame([3, 4, 5], self::idsOf((new AuthorQueryObject($this->em))->fetch(null, 2)));
	}

	public function testZeroLimitIsIgnored(): void
	{
		self::assertCount(5, (new AuthorQueryObject($this->em))->fetch(0));
	}

	public function testZeroOffsetIsIgnored(): void
	{
		self::assertSame([1, 2], self::idsOf((new AuthorQueryObject($this->em))->fetch(2, 0)));
	}

	public function testFetchOnAnEmptyResultReturnsAnEmptyArray(): void
	{
		self::assertSame([], (new AuthorQueryObject($this->em))->byId(999)->fetch());
	}

	public function testFetchWithLockRequiresAnOpenTransaction(): void
	{
		$this->expectException(TransactionRequiredException::class);

		(new AuthorQueryObject($this->em))->fetch(null, null, true);
	}

	public function testFetchWithLockInsideATransaction(): void
	{
		$this->em->getConnection()->beginTransaction();

		try {
			self::assertCount(5, (new AuthorQueryObject($this->em))->fetch(null, null, true));
		} finally {
			$this->em->getConnection()->rollBack();
		}
	}

	public function testFetchOneReturnsTheSingleEntity(): void
	{
		$author = (new AuthorQueryObject($this->em))->byId(FixtureLoader::AUTHOR_ADAM)->fetchOne();

		self::assertInstanceOf(Author::class, $author);
		self::assertSame('Adam Novák', $author->getName());
	}

	public function testFetchOneThrowsWhenThereIsNoResult(): void
	{
		$this->expectException(NoResultException::class);

		(new AuthorQueryObject($this->em))->byId(999)->fetchOne();
	}

	public function testFetchOneThrowsWhenThereAreMoreResults(): void
	{
		$this->expectException(NonUniqueResultException::class);

		(new AuthorQueryObject($this->em))->fetchOne();
	}

	public function testFetchOneWithoutStrictReturnsTheFirstResult(): void
	{
		self::assertSame('Adam Novák', (new AuthorQueryObject($this->em))->fetchOne(false)->getName());
	}

	public function testFetchOneWithoutStrictRespectsOrder(): void
	{
		self::assertSame(
			'Eva Nová',
			(new AuthorQueryObject($this->em))->orderBy('id', 'DESC')->fetchOne(false)->getName(),
		);
	}

	public function testFetchOneOrNullReturnsNullWhenThereIsNoResult(): void
	{
		self::assertNull((new AuthorQueryObject($this->em))->byId(999)->fetchOneOrNull());
	}

	public function testFetchOneOrNullReturnsTheEntity(): void
	{
		self::assertSame(
			'Cyril Velký',
			(new AuthorQueryObject($this->em))->byId(FixtureLoader::AUTHOR_CYRIL)->fetchOneOrNull()->getName(),
		);
	}

	public function testFetchOneOrNullStillThrowsOnMultipleResults(): void
	{
		$this->expectException(NonUniqueResultException::class);

		(new AuthorQueryObject($this->em))->fetchOneOrNull();
	}

	public function testFetchOneOrNullWithoutStrictReturnsTheFirstResult(): void
	{
		self::assertSame('Adam Novák', (new AuthorQueryObject($this->em))->fetchOneOrNull(false)->getName());
	}

	public function testFetchIterableYieldsEntities(): void
	{
		$iterable = (new AuthorQueryObject($this->em))->fetchIterable();

		self::assertInstanceOf(Generator::class, $iterable);
		self::assertSame([1, 2, 3, 4, 5], self::idsOf(iterator_to_array($iterable)));
	}

	public function testFetchIterableRejectsModifiedColumns(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Cannot call ADT\DoctrineComponents\QueryObject\QueryObject::fetchIterable on a query object with modified columns.');

		(new ModifiedSelectAuthorQueryObject($this->em))->fetchIterable();
	}

	public function testFetchIterableAllowsHiddenColumns(): void
	{
		self::assertSame(
			[1, 2, 3, 4, 5],
			self::idsOf(iterator_to_array((new HiddenSelectAuthorQueryObject($this->em))->fetchIterable())),
		);
	}

	public function testFetchPairsMapsKeyToValue(): void
	{
		self::assertSame(
			[
				1 => 'Adam Novák',
				2 => 'Beata Malá',
				3 => 'Cyril Velký',
				4 => 'David Adamec',
				5 => 'Eva Nová',
			],
			(new AuthorQueryObject($this->em))->fetchPairs('name', 'id'),
		);
	}

	public function testFetchPairsWithNullValueReturnsWholeEntities(): void
	{
		$pairs = (new AuthorQueryObject($this->em))->fetchPairs(null, 'id');

		self::assertSame([1, 2, 3, 4, 5], array_keys($pairs));
		self::assertContainsOnlyInstancesOf(Author::class, $pairs);
	}

	public function testFetchPairsRespectsFiltersAndOrder(): void
	{
		$pairs = (new AuthorQueryObject($this->em))
			->by('isActive', false)
			->orderBy('id', 'DESC')
			->fetchPairs('name', 'id');

		self::assertSame([5 => 'Eva Nová', 3 => 'Cyril Velký'], $pairs);
	}

	public function testFetchPairsRejectsNonScalarKeys(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('The key must not be of type `object`.');

		(new AuthorQueryObject($this->em))->fetchPairs('name', 'birthDate');
	}

	public function testFetchPairsRequiresAKey(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Parameter "$key" is required, there is nothing to key the result by.');

		(new AuthorQueryObject($this->em))->fetchPairs('name', null);
	}

	public function testFetchPairsOverlappingKeysKeepTheLastValue(): void
	{
		$pairs = (new AuthorQueryObject($this->em))->fetchPairs('name', 'isActive');

		self::assertSame([1 => 'David Adamec', 0 => 'Eva Nová'], $pairs);
	}

	public function testFetchFieldReturnsAScalarColumnKeyedByItself(): void
	{
		self::assertSame(
			[1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
			self::ksorted((new AuthorQueryObject($this->em))->fetchField('id')),
		);
	}

	public function testFetchFieldDeduplicatesValues(): void
	{
		self::assertSame(
			[0 => 0, 1 => 1],
			self::ksorted((new AuthorQueryObject($this->em))->fetchField('isActive')),
		);
	}

	public function testFetchFieldKeepsNullUnderAnEmptyKey(): void
	{
		$field = (new AuthorQueryObject($this->em))->fetchField('email');

		self::assertArrayHasKey('', $field);
		self::assertNull($field['']);
		self::assertCount(5, $field);
	}

	public function testFetchFieldUsesIdentityForAssociations(): void
	{
		self::assertSame(
			['' => null, 1 => 1, 2 => 2, 3 => 3],
			self::ksorted((new AuthorQueryObject($this->em))->fetchField('publisher')),
		);
	}

	public function testFetchFieldRespectsFilters(): void
	{
		self::assertSame(
			[3 => 3, 5 => 5],
			self::ksorted((new AuthorQueryObject($this->em))->by('isActive', false)->fetchField('id')),
		);
	}

	public function testFetchFieldIgnoresCustomSelectBecauseItBuildsItsOwnQuery(): void
	{
		self::assertSame(
			[1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
			self::ksorted((new ModifiedSelectAuthorQueryObject($this->em))->fetchField('id')),
		);
	}

	public function testFetchFieldRejectsSelectColumnsAddedByAFilter(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Cannot call fetchField on a query object with modified columns.');

		(new FilterModifiedSelectAuthorQueryObject($this->em))->fetchField('id');
	}

	public function testFetchFieldWithLockRequiresAnOpenTransaction(): void
	{
		$this->expectException(TransactionRequiredException::class);

		(new AuthorQueryObject($this->em))->fetchField('id', true);
	}

	public function testCountReturnsTheNumberOfRows(): void
	{
		self::assertSame(5, (new AuthorQueryObject($this->em))->count());
	}

	public function testCountRespectsFilters(): void
	{
		self::assertSame(2, (new AuthorQueryObject($this->em))->by('isActive', false)->count());
	}

	public function testCountIsZeroForAnEmptyResult(): void
	{
		self::assertSame(0, (new AuthorQueryObject($this->em))->byId(999)->count());
	}

	public function testCountIgnoresLimitAndOrder(): void
	{
		self::assertSame(5, (new AuthorQueryObject($this->em))->orderBy('name', 'DESC')->count());
	}

	public function testCountCountsDuplicatedRowsProducedByJoins(): void
	{
		self::assertSame(4, (new AuthorQueryObject($this->em))->by('books.title', 'kniha', Mode::CONTAINS)->count());
	}
}
