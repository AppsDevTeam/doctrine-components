<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\Logging\LoggingMiddleware;
use ADT\DoctrineComponents\QueryObject\QueryObject;
use ADT\DoctrineComponents\SqlLogger;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Book;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Publisher;
use ADT\DoctrineComponents\Tests\Fixtures\EntityManagerFactory;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\CountingPostFetchQueryObject;
use ADT\DoctrineComponents\Tests\TestCase;
use ArrayIterator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Exception;
use ReflectionProperty;

final class PostFetchTest extends TestCase
{
	private SqlLogger $logger;

	private EntityManagerInterface $em;

	protected function setUp(): void
	{
		parent::setUp();

		$this->logger = new SqlLogger([]);
		$this->em = EntityManagerFactory::create([new LoggingMiddleware($this->logger)]);

		FixtureLoader::load($this->em);
		$this->em->clear();
	}

	protected function tearDown(): void
	{
		$this->em->getConnection()->close();

		parent::tearDown();
	}

	public function testAddPostFetchRegistersTheFieldName(): void
	{
		$qo = (new AuthorQueryObject($this->em))->addPostFetch('books')->addPostFetch('publisher');

		self::assertSame(['books', 'publisher'], $qo->getPostFetchFields());
	}

	public function testAddPostFetchKeepsDuplicates(): void
	{
		$qo = (new AuthorQueryObject($this->em))->addPostFetch('books')->addPostFetch('books');

		self::assertSame(['books', 'books'], $qo->getPostFetchFields());
	}

	public function testFetchReturnsAllEntitiesWithPostFetchRegistered(): void
	{
		$authors = (new AuthorQueryObject($this->em))->addPostFetch('books')->fetch();

		self::assertSame([1, 2, 3, 4, 5], self::idsOf($authors));
	}

	public function testPostFetchWithoutRegisteredFieldsIsANoop(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$authors = $qo->fetch();

		$queriesBefore = $this->queryCount();
		$qo->postFetch(new ArrayIterator($authors));

		self::assertSame($queriesBefore, $this->queryCount());
	}

	public function testDoPostFetchReturnsEarlyForAnEmptyRootEntityList(): void
	{
		$queriesBefore = $this->queryCount();

		QueryObject::doPostFetch($this->em, [], ['books']);

		self::assertSame($queriesBefore, $this->queryCount());
	}

	public function testDoPostFetchIgnoresValuesThatAreNotEntities(): void
	{
		$queriesBefore = $this->queryCount();

		QueryObject::doPostFetch($this->em, [['not an entity']], ['books']);

		self::assertSame($queriesBefore, $this->queryCount());
	}

	public function testOneToManyCollectionsAreInitialized(): void
	{
		$authors = (new AuthorQueryObject($this->em))->addPostFetch('books')->fetch();

		foreach ($authors as $_author) {
			self::assertTrue(self::isCollectionInitialized($_author, 'books'));
		}
	}

	public function testManyToManyCollectionsAreInitialized(): void
	{
		$authors = (new AuthorQueryObject($this->em))->addPostFetch('tags')->fetch();

		foreach ($authors as $_author) {
			self::assertTrue(self::isCollectionInitialized($_author, 'tags'));
		}
	}

	public function testPrefetchedOneToManyCollectionsHoldTheCorrectEntities(): void
	{
		$authors = (new AuthorQueryObject($this->em))->addPostFetch('books')->fetch();

		self::assertSame(
			[
				FixtureLoader::AUTHOR_ADAM => [FixtureLoader::BOOK_ALFA, FixtureLoader::BOOK_BETA],
				FixtureLoader::AUTHOR_BEATA => [FixtureLoader::BOOK_GAMA],
				FixtureLoader::AUTHOR_CYRIL => [],
				FixtureLoader::AUTHOR_DAVID => [FixtureLoader::BOOK_DELTA],
				FixtureLoader::AUTHOR_EVA => [],
			],
			self::collectionMap($authors, 'getBooks'),
		);
	}

	public function testPrefetchedManyToManyCollectionsHoldTheCorrectEntities(): void
	{
		$authors = (new AuthorQueryObject($this->em))->addPostFetch('tags')->fetch();

		self::assertSame(
			[
				FixtureLoader::AUTHOR_ADAM => [FixtureLoader::TAG_PHP, FixtureLoader::TAG_SQL],
				FixtureLoader::AUTHOR_BEATA => [FixtureLoader::TAG_SQL],
				FixtureLoader::AUTHOR_CYRIL => [],
				FixtureLoader::AUTHOR_DAVID => [FixtureLoader::TAG_JS],
				FixtureLoader::AUTHOR_EVA => [FixtureLoader::TAG_PHP],
			],
			self::collectionMap($authors, 'getTags'),
		);
	}

	public function testPrefetchingOneToManyRemovesTheNPlusOneQueries(): void
	{
		$withoutPostFetch = $this->countQueriesWhile(function (): void {
			foreach ((new AuthorQueryObject($this->em))->fetch() as $_author) {
				$_author->getBooks()->toArray();
			}
		});

		$this->em->clear();

		$withPostFetch = $this->countQueriesWhile(function (): void {
			foreach ((new AuthorQueryObject($this->em))->addPostFetch('books')->fetch() as $_author) {
				$_author->getBooks()->toArray();
			}
		});

		self::assertSame(6, $withoutPostFetch);
		self::assertSame(2, $withPostFetch);
	}

	public function testPrefetchingToOneAssociationsRemovesTheNPlusOneQueries(): void
	{
		$withoutPostFetch = $this->countQueriesWhile(function (): void {
			foreach ((new AuthorQueryObject($this->em))->fetch() as $_author) {
				$_author->getPublisher()?->getName();
			}
		});

		$this->em->clear();

		$withPostFetch = $this->countQueriesWhile(function (): void {
			foreach ((new AuthorQueryObject($this->em))->addPostFetch('publisher')->fetch() as $_author) {
				$_author->getPublisher()?->getName();
			}
		});

		self::assertSame(4, $withoutPostFetch);
		self::assertSame(3, $withPostFetch);
	}

	public function testToOneAssociationsArePrefetchedAsRealEntities(): void
	{
		$authors = (new AuthorQueryObject($this->em))->addPostFetch('publisher')->fetch();

		self::assertInstanceOf(Publisher::class, $authors[0]->getPublisher());
		self::assertSame('Alfa', $authors[0]->getPublisher()->getName());
		self::assertNull($authors[3]->getPublisher());
	}

	public function testANestedPathPrefetchesBothLevels(): void
	{
		$authors = (new AuthorQueryObject($this->em))->addPostFetch('books.author')->fetch();

		self::assertTrue(self::isCollectionInitialized($authors[0], 'books'));
		self::assertContainsOnlyInstancesOf(Book::class, $authors[0]->getBooks()->toArray());
	}

	public function testSeveralFieldsCanBePrefetchedAtOnce(): void
	{
		$authors = (new AuthorQueryObject($this->em))
			->addPostFetch('books')
			->addPostFetch('tags')
			->addPostFetch('publisher')
			->fetch();

		self::assertTrue(self::isCollectionInitialized($authors[0], 'books'));
		self::assertTrue(self::isCollectionInitialized($authors[0], 'tags'));
		self::assertInstanceOf(Publisher::class, $authors[0]->getPublisher());
	}

	public function testDuplicateFieldNamesArePrefetchedOnlyOnce(): void
	{
		$once = $this->countQueriesWhile(function (): void {
			(new AuthorQueryObject($this->em))->addPostFetch('books')->fetch();
		});

		$this->em->clear();

		$twice = $this->countQueriesWhile(function (): void {
			(new AuthorQueryObject($this->em))->addPostFetch('books')->addPostFetch('books')->fetch();
		});

		self::assertSame($once, $twice);
	}

	public function testAnUnknownFieldNameThrows(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("PostFetch: Entita '" . Author::class . "' nemá pole 'thisFieldDoesNotExist'.");

		(new AuthorQueryObject($this->em))->addPostFetch('thisFieldDoesNotExist')->fetch();
	}

	public function testAnUnknownFieldNamePassedToDoPostFetchThrows(): void
	{
		$authors = (new AuthorQueryObject($this->em))->fetch();

		$this->expectException(Exception::class);
		$this->expectExceptionMessage("PostFetch: Entita '" . Author::class . "' nemá pole 'thisFieldDoesNotExist'.");

		QueryObject::doPostFetch($this->em, $authors, ['thisFieldDoesNotExist']);
	}

	public function testFetchRunsPostFetchExactlyOnce(): void
	{
		CountingPostFetchQueryObject::$calls = 0;

		(new CountingPostFetchQueryObject($this->em))->addPostFetch('books')->fetch();

		self::assertSame(1, CountingPostFetchQueryObject::$calls);
	}

	public function testFetchOneRunsPostFetchExactlyOnce(): void
	{
		CountingPostFetchQueryObject::$calls = 0;

		(new CountingPostFetchQueryObject($this->em))
			->addPostFetch('books')
			->byId(FixtureLoader::AUTHOR_ADAM)
			->fetchOne();

		self::assertSame(1, CountingPostFetchQueryObject::$calls);
	}

	public function testFetchOneOrNullRunsPostFetchExactlyOnce(): void
	{
		CountingPostFetchQueryObject::$calls = 0;

		(new CountingPostFetchQueryObject($this->em))
			->addPostFetch('books')
			->byId(FixtureLoader::AUTHOR_ADAM)
			->fetchOneOrNull();

		self::assertSame(1, CountingPostFetchQueryObject::$calls);
	}

	public function testFetchOneStillPrefetchesTheCollection(): void
	{
		$author = (new AuthorQueryObject($this->em))
			->addPostFetch('books')
			->byId(FixtureLoader::AUTHOR_ADAM)
			->fetchOne();

		self::assertTrue(self::isCollectionInitialized($author, 'books'));
	}

	public function testLazyLoadingStillWorksWithoutPostFetch(): void
	{
		$adam = (new AuthorQueryObject($this->em))->byId(FixtureLoader::AUTHOR_ADAM)->fetchOne();

		self::assertSame(
			[FixtureLoader::BOOK_ALFA, FixtureLoader::BOOK_BETA],
			self::idsOf($adam->getBooks()->toArray()),
		);
	}

	private function queryCount(): int
	{
		return count($this->logger->getQueries());
	}

	private function countQueriesWhile(callable $callback): int
	{
		$before = $this->queryCount();
		$callback();

		return $this->queryCount() - $before;
	}

	/**
	 * @param Author[] $authors
	 * @return array<int, int[]>
	 */
	private static function collectionMap(array $authors, string $getter): array
	{
		$map = [];
		foreach ($authors as $_author) {
			$ids = self::idsOf($_author->$getter()->toArray());
			sort($ids);
			$map[(int) $_author->getId()] = $ids;
		}

		return $map;
	}

	private static function isCollectionInitialized(Author $author, string $field): bool
	{
		$collection = (new ReflectionProperty(Author::class, $field))->getValue($author);

		return $collection instanceof PersistentCollection && $collection->isInitialized();
	}
}
