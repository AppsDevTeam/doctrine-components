<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Logging;

use ADT\DoctrineComponents\Logging\LoggingMiddleware;
use ADT\DoctrineComponents\Logging\Statement;
use ADT\DoctrineComponents\SqlLogger;
use ADT\DoctrineComponents\Tests\Fixtures\EntityManagerFactory;
use ADT\DoctrineComponents\Tests\TestCase;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\ParameterType;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;

final class StatementTest extends TestCase
{
	public function testItIsADbalStatementMiddleware(): void
	{
		self::assertInstanceOf(AbstractStatementMiddleware::class, $this->createStatement());
	}

	public function testSqlWithoutParametersIsReturnedUnchanged(): void
	{
		$sql = 'SELECT * FROM author WHERE name LIKE \'%a%\' AND id = ?';

		self::assertSame($sql, $this->createStatement()->formatSql($sql, [], []));
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, mixed>, 2: array<int, ParameterType>, 3: string}>
	 */
	public static function provideFormattedSql(): array
	{
		return [
			'string is quoted' => [
				'SELECT ?',
				[1 => 'Adam'],
				[1 => ParameterType::STRING],
				"SELECT 'Adam'",
			],
			'integer is inlined' => [
				'SELECT ?',
				[1 => 42],
				[1 => ParameterType::INTEGER],
				'SELECT 42',
			],
			'float is inlined' => [
				'SELECT ?',
				[1 => 1.5],
				[1 => ParameterType::STRING],
				'SELECT 1.5',
			],
			'null becomes empty' => [
				'SELECT ?',
				[1 => null],
				[1 => ParameterType::NULL],
				'SELECT ',
			],
			'boolean is inlined' => [
				'SELECT ?',
				[1 => true],
				[1 => ParameterType::BOOLEAN],
				'SELECT 1',
			],
			'multiple placeholders keep their order' => [
				'SELECT ?, ?',
				[1 => 'a', 2 => 'b'],
				[1 => ParameterType::STRING, 2 => ParameterType::STRING],
				"SELECT 'a', 'b'",
			],
			'percent signs are preserved' => [
				'SELECT * FROM author WHERE name LIKE ?',
				[1 => '%Nov%'],
				[1 => ParameterType::STRING],
				"SELECT * FROM author WHERE name LIKE '%Nov%'",
			],
		];
	}

	/**
	 * @param array<int, mixed> $params
	 * @param array<int, ParameterType> $types
	 */
	#[DataProvider('provideFormattedSql')]
	public function testFormatSql(string $sql, array $params, array $types, string $expected): void
	{
		self::assertSame($expected, $this->createStatement()->formatSql($sql, $params, $types));
	}

	public function testQuotesInsideAValueAreEscapedBySqlite(): void
	{
		self::requireSqlite();

		self::assertSame(
			"SELECT 'O''Brien'",
			$this->createStatement()->formatSql('SELECT ?', [1 => "O'Brien"], [1 => ParameterType::STRING]),
		);
	}

	public function testQuotesInsideAValueAreEscapedByMysql(): void
	{
		self::requireMysql();

		self::assertSame(
			"SELECT 'O\\'Brien'",
			$this->createStatement()->formatSql('SELECT ?', [1 => "O'Brien"], [1 => ParameterType::STRING]),
		);
	}

	public function testNamedParametersCannotBeFormatted(): void
	{
		$statement = $this->createStatement();

		$isolated = self::runIsolated(
			static fn() => $statement->formatSql('SELECT :name', ['name' => 'Adam'], ['name' => ParameterType::STRING]),
		);

		self::assertInstanceOf(Error::class, $isolated['throwable']);
		self::assertStringContainsString('getDatabasePlatform', $isolated['throwable']->getMessage());
	}

	public function testArrayParameterTypesCannotBeFormatted(): void
	{
		$statement = $this->createStatement();

		$isolated = self::runIsolated(
			static fn() => $statement->formatSql('SELECT ?', [1 => [1, 2]], [1 => ArrayParameterType::INTEGER]),
		);

		self::assertInstanceOf(Error::class, $isolated['throwable']);
		self::assertStringContainsString('getDatabasePlatform', $isolated['throwable']->getMessage());
	}

	private function createStatement(string $sql = 'SELECT 1'): Statement
	{
		$driver = (new LoggingMiddleware(new SqlLogger([])))
			->wrap(EntityManagerFactory::createConnection()->getDriver());

		$statement = $driver->connect(EntityManagerFactory::connectionParams())->prepare($sql);
		self::assertInstanceOf(Statement::class, $statement);

		return $statement;
	}
}
