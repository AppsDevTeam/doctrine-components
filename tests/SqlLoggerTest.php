<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\SqlLogger;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use TypeError;

final class SqlLoggerTest extends TestCase
{
	public function testItIsAPsrLogger(): void
	{
		$logger = new SqlLogger([]);

		self::assertInstanceOf(LoggerInterface::class, $logger);
		self::assertInstanceOf(AbstractLogger::class, $logger);
	}

	public function testANewLoggerIsEmpty(): void
	{
		$logger = new SqlLogger([]);

		self::assertSame([], $logger->getQueries());
		self::assertSame([], $logger->getParams());
		self::assertSame(0.0, $logger->getTotalTime());
	}

	public function testDebugRecordsAQuery(): void
	{
		$logger = new SqlLogger([]);
		$logger->debug('SELECT 1', ['duration' => 0.5]);

		$queries = $logger->getQueries();

		self::assertCount(1, $queries);
		self::assertSame('SELECT 1', $queries[0]->sql);
		self::assertSame(0.5, $queries[0]->duration);
		self::assertSame([], $queries[0]->source);
	}

	public function testDebugKeepsInsertionOrder(): void
	{
		$logger = new SqlLogger([]);
		$logger->debug('SELECT 1', ['duration' => 0.1]);
		$logger->debug('SELECT 2', ['duration' => 0.2]);
		$logger->debug('SELECT 3', ['duration' => 0.3]);

		self::assertSame(
			['SELECT 1', 'SELECT 2', 'SELECT 3'],
			array_map(static fn(object $query) => $query->sql, $logger->getQueries()),
		);
	}

	public function testTotalTimeIsTheSumOfDurations(): void
	{
		$logger = new SqlLogger([]);
		$logger->debug('SELECT 1', ['duration' => 0.5]);
		$logger->debug('SELECT 2', ['duration' => 0.25]);

		self::assertSame(0.75, $logger->getTotalTime());
	}

	public function testNonDebugLevelsStoreConnectionParams(): void
	{
		$logger = new SqlLogger([]);
		$logger->info('Connecting', ['params' => ['host' => 'localhost', 'password' => '<redacted>']]);

		self::assertSame(['host' => 'localhost', 'password' => '<redacted>'], $logger->getParams());
		self::assertSame([], $logger->getQueries());
	}

	public function testTheLastConnectionParamsWin(): void
	{
		$logger = new SqlLogger([]);
		$logger->info('Connecting', ['params' => ['host' => 'first']]);
		$logger->info('Connecting', ['params' => ['host' => 'second']]);

		self::assertSame(['host' => 'second'], $logger->getParams());
	}

	public function testDebugWithoutDurationRecordsANullDuration(): void
	{
		$logger = new SqlLogger([]);

		$isolated = self::runIsolated(static fn() => $logger->debug('SELECT 1'));

		self::assertNull($isolated['throwable']);
		self::assertNull($logger->getQueries()[0]->duration);
		self::assertNotSame([], $isolated['errors']);
	}

	public function testNonDebugLevelWithoutParamsFails(): void
	{
		$logger = new SqlLogger([]);

		$isolated = self::runIsolated(static fn() => $logger->info('Connecting'));

		self::assertInstanceOf(TypeError::class, $isolated['throwable']);
	}

	public function testSourceIsEmptyWhenNoPathsAreConfigured(): void
	{
		$logger = new SqlLogger([]);
		$logger->debug('SELECT 1', ['duration' => 0.1]);

		self::assertSame([], $logger->getQueries()[0]->source);
	}

	public function testSourceCollectsBacktraceFramesFromConfiguredPaths(): void
	{
		$logger = new SqlLogger(['tests' . DIRECTORY_SEPARATOR . 'SqlLoggerTest.php']);
		$logger->debug('SELECT 1', ['duration' => 0.1]);

		$source = $logger->getQueries()[0]->source;

		self::assertNotSame([], $source);
		self::assertArrayHasKey('file', $source[0]);
		self::assertArrayHasKey('line', $source[0]);
		self::assertStringEndsWith('SqlLoggerTest.php', $source[0]['file']);
	}

	public function testSourceIgnoresFramesOutsideConfiguredPaths(): void
	{
		$logger = new SqlLogger(['this-path-does-not-exist']);
		$logger->debug('SELECT 1', ['duration' => 0.1]);

		self::assertSame([], $logger->getQueries()[0]->source);
	}

	public function testSourceSkipsBacktraceFramesWithoutAFileAndLine(): void
	{
		$logger = new SqlLogger([basename(__FILE__)]);
		$values = [3, 1, 2];

		usort($values, static function (int $a, int $b) use ($logger): int {
			$logger->debug('SELECT 1', ['duration' => 0.1]);

			return $a <=> $b;
		});

		$source = $logger->getQueries()[0]->source;

		self::assertNotSame([], $source);
		foreach ($source as $_frame) {
			self::assertArrayHasKey('file', $_frame);
			self::assertArrayHasKey('line', $_frame);
		}
	}

	public function testSourceCollectsEveryMatchingFrame(): void
	{
		$logger = new SqlLogger([basename(__FILE__), basename(__FILE__)]);
		$logger->debug('SELECT 1', ['duration' => 0.1]);

		self::assertGreaterThanOrEqual(2, count($logger->getQueries()[0]->source));
	}

	public function testGetSourceCanBeCalledDirectly(): void
	{
		self::assertSame([], (new SqlLogger([]))->getSource());
	}
}
