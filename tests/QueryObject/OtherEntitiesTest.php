<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\QueryObject\QueryObjectByMode as Mode;
use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Book;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Publisher;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\BookQueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\PublisherQueryObject;

final class OtherEntitiesTest extends DatabaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->loadFixtures();
	}

	public function testBookQueryObjectUsesItsOwnEntity(): void
	{
		self::assertDqlSame(
			'SELECT e FROM ' . Book::class . ' e ORDER BY e.id ASC',
			(new BookQueryObject($this->em))->createQueryBuilder(),
		);
	}

	public function testBooksCanBeFetched(): void
	{
		self::assertSame([1, 2, 3, 4, 5], self::idsOf((new BookQueryObject($this->em))->fetch()));
	}

	public function testBooksCanBeFilteredByTheirManyToOneOwner(): void
	{
		$books = (new BookQueryObject($this->em))->by('author.name', 'Adam Novák')->fetch();

		self::assertSame([FixtureLoader::BOOK_ALFA, FixtureLoader::BOOK_BETA], self::idsOf($books));
	}

	public function testAnOrphanBookIsFoundByIsNull(): void
	{
		$books = (new BookQueryObject($this->em))->by('author', null)->fetch();

		self::assertSame([FixtureLoader::BOOK_ORPHAN], self::idsOf($books));
	}

	public function testBooksCanBeFilteredByPriceRange(): void
	{
		$books = (new BookQueryObject($this->em))->by('price', [100, 250], Mode::BETWEEN)->fetch();

		self::assertSame([FixtureLoader::BOOK_ALFA, FixtureLoader::BOOK_BETA], self::idsOf($books));
	}

	public function testPublisherQueryObjectUsesItsOwnEntity(): void
	{
		self::assertDqlSame(
			'SELECT e FROM ' . Publisher::class . ' e ORDER BY e.id ASC',
			(new PublisherQueryObject($this->em))->createQueryBuilder(),
		);
	}

	public function testPublishersCanBeFilteredByTheirOneToManyCollection(): void
	{
		$publishers = (new PublisherQueryObject($this->em))->by('authors.isActive', true)->fetch();

		self::assertSame([FixtureLoader::PUBLISHER_ALFA], self::idsOf($publishers));
	}

	public function testPublishersWithoutAuthorsAreFoundByIsEmpty(): void
	{
		self::assertSame([], (new PublisherQueryObject($this->em))->by('authors', null, Mode::IS_EMPTY)->fetch());
	}

	public function testPublisherCountUsesItsOwnCountExpression(): void
	{
		self::assertSame(3, (new PublisherQueryObject($this->em))->count());
	}

	public function testFetchPairsWorksForOtherEntities(): void
	{
		self::assertSame(
			[1 => 'Alfa', 2 => 'Beta', 3 => 'Gama'],
			(new PublisherQueryObject($this->em))->fetchPairs('name', 'id'),
		);
	}

	public function testFetchFieldWorksForOtherEntities(): void
	{
		self::assertSame(
			['' => null, 'CZ' => 'CZ', 'SK' => 'SK'],
			self::ksorted((new PublisherQueryObject($this->em))->fetchField('country')),
		);
	}
}
