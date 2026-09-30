<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Logging;

use ADT\DoctrineComponents\Logging\Connection;
use ADT\DoctrineComponents\Logging\Driver;
use ADT\DoctrineComponents\Logging\LoggingMiddleware;
use ADT\DoctrineComponents\Logging\Statement;
use ADT\DoctrineComponents\QueryObject\QueryObjectByMode as Mode;
use ADT\DoctrineComponents\SqlLogger;
use ADT\DoctrineComponents\Tests\Fixtures\EntityManagerFactory;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use ADT\DoctrineComponents\Tests\TestCase;
use Doctrine\DBAL\Driver as DbalDriver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

final class LoggingMiddlewareTest extends TestCase
{
	private SqlLogger $logger;

	private EntityManagerInterface $em;

	protected function setUp(): void
	{
		parent::setUp();

		$this->logger = new SqlLogger([]);
		$this->em = EntityManagerFactory::create([new LoggingMiddleware($this->logger)]);
	}

	protected function tearDown(): void
	{
		$this->em->getConnection()->close();

		parent::tearDown();
	}

	public function testMiddlewareImplementsTheDbalInterface(): void
	{
		self::assertInstanceOf(Middleware::class, new LoggingMiddleware($this->logger));
	}

	public function testMiddlewareWrapsTheDriver(): void
	{
		self::assertInstanceOf(Driver::class, $this->wrappedDriver());
	}

	public function testConnectingIsLoggedWithMaskedPassword(): void
	{
		$params = EntityManagerFactory::connectionParams();
		$params['password'] = 'secret';

		$expected = $params;
		$expected['password'] = '<redacted>';

		self::runIsolated(fn() => $this->wrappedDriver()->connect($params));

		self::assertSame($expected, $this->logger->getParams());
		self::assertNotContains('secret', $this->logger->getParams());
	}

	public function testConnectingWithoutAPasswordIsLoggedUnchanged(): void
	{
		$params = EntityManagerFactory::connectionParams();
		unset($params['password']);

		self::runIsolated(fn() => $this->wrappedDriver()->connect($params));

		self::assertSame($params, $this->logger->getParams());
	}

	public function testPreparedStatementsAreWrapped(): void
	{
		$connection = $this->wrappedDriver()->connect(EntityManagerFactory::connectionParams());

		self::assertInstanceOf(Statement::class, $connection->prepare('SELECT 1'));
	}

	public function testStatementParametersAreLoggedInBindingOrder(): void
	{
		$connection = $this->wrappedDriver()->connect(EntityManagerFactory::connectionParams());
		$statement = $connection->prepare('SELECT ?, ?');
		$statement->bindValue(1, 'a', ParameterType::STRING);
		$statement->bindValue(2, 'b', ParameterType::STRING);
		$statement->execute();

		self::assertContains("SELECT 'a', 'b'", $this->loggedSql());
	}

	public function testQueryWithoutParametersIsLoggedVerbatim(): void
	{
		$this->em->getConnection()->executeQuery('SELECT 1');

		self::assertContains('SELECT 1', $this->loggedSql());
	}

	public function testStatementWithoutParametersIsLoggedVerbatim(): void
	{
		$this->em->getConnection()->executeStatement('DELETE FROM book WHERE 1 = 0');

		self::assertContains('DELETE FROM book WHERE 1 = 0', $this->loggedSql());
	}

	public function testEveryLoggedQueryHasANonNegativeDuration(): void
	{
		$this->em->getConnection()->executeQuery('SELECT 1');

		foreach ($this->logger->getQueries() as $_query) {
			self::assertIsFloat($_query->duration);
			self::assertGreaterThanOrEqual(0.0, $_query->duration);
		}
	}

	public function testTotalTimeIsTheSumOfAllDurations(): void
	{
		$this->em->getConnection()->executeQuery('SELECT 1');
		$this->em->getConnection()->executeQuery('SELECT 2');

		$sum = array_sum(array_map(static fn(object $query) => $query->duration, $this->logger->getQueries()));

		self::assertSame($sum, $this->logger->getTotalTime());
	}

	public function testTransactionsAreLogged(): void
	{
		$connection = $this->em->getConnection();

		$connection->beginTransaction();
		$connection->commit();
		$connection->beginTransaction();
		$connection->rollBack();

		self::assertSame(
			['Beginning transaction', 'Committing transaction', 'Beginning transaction', 'Rolling back transaction'],
			array_values(array_filter($this->loggedSql(), static fn(string $sql) => str_contains($sql, 'transaction'))),
		);
	}

	public function testTransactionsStillWork(): void
	{
		FixtureLoader::load($this->em);
		$connection = $this->em->getConnection();

		$connection->beginTransaction();
		$connection->executeStatement('DELETE FROM book');
		$connection->rollBack();

		self::assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM book'));
	}

	public function testStringParametersAreQuotedInTheLoggedSql(): void
	{
		$this->em->getConnection()->executeQuery('SELECT ?', ['Adam Novák']);

		self::assertContains("SELECT 'Adam Novák'", $this->loggedSql());
	}

	public function testIntegerParametersAreInlinedWithoutQuotes(): void
	{
		$this->em->getConnection()->executeQuery('SELECT ?', [42]);

		self::assertContains('SELECT 42', $this->loggedSql());
	}

	public function testPercentSignsInParametersAreNotConsumedByTheFormatter(): void
	{
		FixtureLoader::load($this->em);

		(new AuthorQueryObject($this->em))->by('name', 'Nov', Mode::CONTAINS)->fetch();

		$likeQueries = array_values(array_filter($this->loggedSql(), static fn(string $sql) => str_contains($sql, 'LIKE')));

		self::assertCount(1, $likeQueries);
		self::assertStringContainsString("LIKE '%Nov%'", $likeQueries[0]);
	}

	public function testArrayParametersAreExpandedInTheLoggedSql(): void
	{
		FixtureLoader::load($this->em);

		(new AuthorQueryObject($this->em))->byId([1, 2, 3])->fetch();

		$inQueries = array_values(array_filter($this->loggedSql(), static fn(string $sql) => str_contains($sql, ' IN (')));

		self::assertCount(1, $inQueries);
		self::assertStringContainsString('IN (1, 2, 3)', $inQueries[0]);
	}

	public function testInsertParametersAreInlined(): void
	{
		FixtureLoader::load($this->em);

		$inserts = array_values(array_filter($this->loggedSql(), static fn(string $sql) => str_starts_with($sql, 'INSERT INTO author')));

		self::assertNotSame([], $inserts);
		self::assertStringContainsString("'Adam Novák'", $inserts[0]);
		self::assertStringNotContainsString('?', $inserts[0]);
	}

	public function testNullParametersAreInlinedAsEmpty(): void
	{
		$this->em->getConnection()->executeQuery('SELECT ? IS NULL', [null]);

		$matches = array_values(array_filter($this->loggedSql(), static fn(string $sql) => str_contains($sql, 'IS NULL')));

		self::assertSame(['SELECT  IS NULL'], $matches);
	}

	public function testLoggingDoesNotChangeQueryResults(): void
	{
		FixtureLoader::load($this->em);

		self::assertSame([1, 2, 3, 4, 5], self::idsOf((new AuthorQueryObject($this->em))->fetch()));
	}

	private function wrappedDriver(): DbalDriver
	{
		return (new LoggingMiddleware($this->logger))->wrap(EntityManagerFactory::createConnection()->getDriver());
	}

	/**
	 * @return string[]
	 */
	private function loggedSql(): array
	{
		return array_map(static fn(object $query) => $query->sql, $this->logger->getQueries());
	}
}
