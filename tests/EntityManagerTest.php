<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\EntityManager;
use ADT\DoctrineComponents\Entities\Entity;
use ADT\DoctrineComponents\Logging\LoggingMiddleware;
use ADT\DoctrineComponents\SqlLogger;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Publisher;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Tag;
use ADT\DoctrineComponents\Tests\Fixtures\EntityManagerFactory;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\PublisherMarker;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Exception;

final class EntityManagerTest extends DatabaseTestCase
{
	private EntityManager $decorated;

	protected function setUp(): void
	{
		parent::setUp();

		$this->decorated = new EntityManager($this->em);
		FixtureLoader::load($this->decorated);
	}

	protected function tearDown(): void
	{
		EntityManager::$isFlushAllowed = true;

		parent::tearDown();
	}

	public function testItIsADoctrineDecorator(): void
	{
		self::assertInstanceOf(EntityManagerDecorator::class, $this->decorated);
	}

	public function testFlushIsAllowedByDefault(): void
	{
		self::assertTrue(EntityManager::$isFlushAllowed);
	}

	public function testFlushPersistsChanges(): void
	{
		$this->decorated->persist(new Author('Franta Nový'));
		$this->decorated->flush();
		$this->decorated->clear();

		self::assertSame('Franta Nový', $this->decorated->find(Author::class, 6)->getName());
	}

	public function testFlushThrowsWhenDisabled(): void
	{
		EntityManager::$isFlushAllowed = false;

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('You cannot use flush.');

		$this->decorated->flush();
	}

	public function testFlushDoesNotPersistAnythingWhenDisabled(): void
	{
		EntityManager::$isFlushAllowed = false;
		$this->decorated->persist(new Author('Nikdo'));

		try {
			$this->decorated->flush();
		} catch (Exception) {
		}

		EntityManager::$isFlushAllowed = true;
		$this->decorated->clear();

		self::assertNull($this->decorated->find(Author::class, 6));
	}

	public function testFlushIsWrappedInATransaction(): void
	{
		$logger = new SqlLogger([]);
		$em = new EntityManager(EntityManagerFactory::create([new LoggingMiddleware($logger)]));

		$em->persist(new Author('Transakce'));
		$em->flush();

		$messages = array_map(static fn(object $query) => $query->sql, $logger->getQueries());

		self::assertContains('Beginning transaction', $messages);
		self::assertContains('Committing transaction', $messages);

		$em->getConnection()->close();
	}

	public function testIsPossibleToDeleteEntityReturnsFalseWhenAForeignKeyBlocksIt(): void
	{
		$em = new EntityManager(EntityManagerFactory::create(foreignKeys: true));
		FixtureLoader::load($em);

		self::assertFalse($em->isPossibleToDeleteEntity($em->find(Author::class, FixtureLoader::AUTHOR_ADAM)));

		$em->getConnection()->close();
	}

	public function testIsPossibleToDeleteEntityReturnsTrueWhenNothingReferencesIt(): void
	{
		$em = new EntityManager(EntityManagerFactory::create(foreignKeys: true));
		FixtureLoader::load($em);

		self::assertTrue($em->isPossibleToDeleteEntity($em->find(Author::class, FixtureLoader::AUTHOR_CYRIL)));

		$em->getConnection()->close();
	}

	public function testIsPossibleToDeleteEntityRollsBackTheProbeDelete(): void
	{
		$em = new EntityManager(EntityManagerFactory::create(foreignKeys: true));
		FixtureLoader::load($em);

		$em->isPossibleToDeleteEntity($em->find(Author::class, FixtureLoader::AUTHOR_CYRIL));
		$em->clear();

		self::assertNotNull($em->find(Author::class, FixtureLoader::AUTHOR_CYRIL));

		$em->getConnection()->close();
	}

	public function testFindEntityClassByInterfaceReturnsTheImplementingEntity(): void
	{
		self::assertSame(Publisher::class, $this->decorated->findEntityClassByInterface(PublisherMarker::class));
	}

	public function testFindEntityClassByInterfaceReturnsOneOfSeveralImplementations(): void
	{
		self::assertContains(
			$this->decorated->findEntityClassByInterface(Entity::class),
			[Author::class, Publisher::class, Tag::class, \ADT\DoctrineComponents\Tests\Fixtures\Entity\Book::class],
		);
	}

	public function testFindEntityClassByInterfaceThrowsForAnUnimplementedInterface(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('There is no entity with interface "Countable".');

		$this->decorated->findEntityClassByInterface(\Countable::class);
	}

	public function testGetLockSendsTheExpectedStatementOnSqlite(): void
	{
		self::requireSqlite();

		try {
			$this->decorated->getLock('my-lock', 5);
			self::fail('GET_LOCK is not available on SQLite, an exception was expected.');
		} catch (DriverException $e) {
			self::assertSame('SELECT GET_LOCK(?, ?)', $e->getQuery()?->getSQL());
			self::assertSame(['my-lock', 5], $e->getQuery()?->getParams());
		}
	}

	public function testGetLockDefaultsToAnInfiniteTimeoutOnSqlite(): void
	{
		self::requireSqlite();

		try {
			$this->decorated->getLock('my-lock');
			self::fail('GET_LOCK is not available on SQLite, an exception was expected.');
		} catch (DriverException $e) {
			self::assertSame(['my-lock', -1], $e->getQuery()?->getParams());
		}
	}

	public function testReleaseLockSendsTheExpectedStatementOnSqlite(): void
	{
		self::requireSqlite();

		try {
			$this->decorated->releaseLock('my-lock');
			self::fail('RELEASE_LOCK is not available on SQLite, an exception was expected.');
		} catch (DriverException $e) {
			self::assertSame('SELECT RELEASE_LOCK(?)', $e->getQuery()?->getSQL());
			self::assertSame(['my-lock'], $e->getQuery()?->getParams());
		}
	}

	public function testGetLockAcquiresAndReleaseLockFreesANamedLock(): void
	{
		self::requireMysql();

		$connection = $this->decorated->getConnection();
		$name = 'adt-doctrine-components-test-lock';

		self::assertSame(1, (int) $connection->fetchOne('SELECT IS_FREE_LOCK(?)', [$name]));

		$this->decorated->getLock($name, 5);
		self::assertSame(0, (int) $connection->fetchOne('SELECT IS_FREE_LOCK(?)', [$name]));

		$this->decorated->releaseLock($name);
		self::assertSame(1, (int) $connection->fetchOne('SELECT IS_FREE_LOCK(?)', [$name]));
	}

	public function testGetLockIsReentrantForTheSameConnection(): void
	{
		self::requireMysql();

		$name = 'adt-doctrine-components-reentrant-lock';

		$this->decorated->getLock($name, 5);
		$this->decorated->getLock($name, 5);

		$this->decorated->releaseLock($name);
		$this->decorated->releaseLock($name);

		self::assertSame(
			1,
			(int) $this->decorated->getConnection()->fetchOne('SELECT IS_FREE_LOCK(?)', [$name]),
		);
	}

	public function testGetLockSendsTheExpectedStatementOnMysql(): void
	{
		self::requireMysql();

		$logger = new SqlLogger([]);
		$em = new EntityManager(EntityManagerFactory::create([new LoggingMiddleware($logger)]));

		$em->getLock('adt-doctrine-components-logged-lock', 5);
		$em->releaseLock('adt-doctrine-components-logged-lock');

		$logged = array_map(static fn(object $query) => $query->sql, $logger->getQueries());

		self::assertContains("SELECT GET_LOCK('adt-doctrine-components-logged-lock', 5)", $logged);
		self::assertContains("SELECT RELEASE_LOCK('adt-doctrine-components-logged-lock')", $logged);

		$em->getConnection()->close();
	}

	public function testGetLockDefaultsToAnInfiniteTimeoutOnMysql(): void
	{
		self::requireMysql();

		$logger = new SqlLogger([]);
		$em = new EntityManager(EntityManagerFactory::create([new LoggingMiddleware($logger)]));

		$em->getLock('adt-doctrine-components-default-timeout-lock');
		$em->releaseLock('adt-doctrine-components-default-timeout-lock');

		self::assertContains(
			"SELECT GET_LOCK('adt-doctrine-components-default-timeout-lock', -1)",
			array_map(static fn(object $query) => $query->sql, $logger->getQueries()),
		);

		$em->getConnection()->close();
	}

	public function testQueryObjectsCanUseTheDecoratedEntityManager(): void
	{
		$authors = (new Fixtures\QueryObject\AuthorQueryObject($this->decorated))->fetch();

		self::assertCount(5, $authors);
	}
}
