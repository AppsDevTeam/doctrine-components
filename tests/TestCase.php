<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\Tests\Fixtures\EntityManagerFactory;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Throwable;

abstract class TestCase extends PHPUnitTestCase
{
	protected static function requireMysql(): void
	{
		if (!EntityManagerFactory::isMysql()) {
			self::markTestSkipped('Requires MySQL, set DB_DRIVER=pdo_mysql to run it.');
		}
	}

	protected static function requireSqlite(): void
	{
		if (EntityManagerFactory::isMysql()) {
			self::markTestSkipped('Describes SQLite specific behaviour.');
		}
	}

	protected static function normalizeDql(string $dql): string
	{
		return trim((string) preg_replace('/\s+/', ' ', $dql));
	}

	protected static function assertDqlSame(string $expected, QueryBuilder|Query $query): void
	{
		self::assertSame(self::normalizeDql($expected), self::normalizeDql($query->getDQL()));
	}

	protected static function assertDqlContains(string $needle, QueryBuilder|Query $query): void
	{
		self::assertStringContainsString($needle, self::normalizeDql($query->getDQL()));
	}

	/**
	 * @return array<string, mixed>
	 */
	protected static function paramMap(QueryBuilder $qb): array
	{
		$params = [];
		foreach ($qb->getParameters() as $_parameter) {
			$params[(string) $_parameter->getName()] = $_parameter->getValue();
		}

		ksort($params);

		return $params;
	}

	/**
	 * @param object[] $entities
	 * @return int[]
	 */
	protected static function idsOf(array $entities): array
	{
		return array_map(static fn(object $entity) => (int) $entity->getId(), array_values($entities));
	}

	/**
	 * @param object[] $entities
	 * @return string[]
	 */
	protected static function namesOf(array $entities): array
	{
		return array_map(static fn(object $entity) => $entity->getName(), array_values($entities));
	}

	/**
	 * @param array<array-key, mixed> $array
	 * @return array<array-key, mixed>
	 */
	protected static function ksorted(array $array): array
	{
		ksort($array, SORT_STRING);

		return $array;
	}

	/**
	 * @return array{result: mixed, errors: string[], throwable: Throwable|null}
	 */
	protected static function runIsolated(callable $callback): array
	{
		$errors = [];
		set_error_handler(static function (int $severity, string $message) use (&$errors): bool {
			$errors[] = $message;
			return true;
		});

		$result = null;
		$throwable = null;

		try {
			$result = $callback();
		} catch (Throwable $e) {
			$throwable = $e;
		} finally {
			restore_error_handler();
		}

		return ['result' => $result, 'errors' => $errors, 'throwable' => $throwable];
	}
}
