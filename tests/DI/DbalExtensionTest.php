<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\DI;

use ADT\DoctrineComponents\DI\DbalExtension;
use ADT\DoctrineComponents\Logging\Driver as LoggingDriver;
use ADT\DoctrineComponents\Logging\LoggingMiddleware;
use ADT\DoctrineComponents\SqlLogger;
use ADT\DoctrineComponents\Tests\Fixtures\RecordingBar;
use ADT\DoctrineComponents\Tests\TestCase;
use ADT\DoctrineComponents\Tracy\QueryPanel\QueryPanel;
use Doctrine\DBAL\Connection;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\DI\MissingServiceException;

final class DbalExtensionTest extends TestCase
{
	public function testItExtendsTheNettrineExtension(): void
	{
		self::assertInstanceOf(\Nettrine\DBAL\DI\DbalExtension::class, new DbalExtension(true));
	}

	public function testTheConnectionIsRegisteredWithThePanelEnabled(): void
	{
		$container = self::createContainer(true);

		self::assertInstanceOf(Connection::class, $container->getByType(Connection::class));
	}

	public function testTheSqlLoggerIsRegisteredWithThePanelEnabled(): void
	{
		$container = self::createContainer(true);

		self::assertInstanceOf(SqlLogger::class, $container->getByType(SqlLogger::class));
	}

	public function testTheSqlLoggerReceivesTheConfiguredSourcePaths(): void
	{
		$container = self::createContainer(true, [__DIR__]);
		$logger = $container->getByType(SqlLogger::class);

		self::assertSame(
			[__DIR__],
			(new \ReflectionProperty(SqlLogger::class, 'sourcePaths'))->getValue($logger),
		);

		$logger->debug('SELECT 1', ['duration' => 0.1]);

		self::assertStringStartsWith(__DIR__, $logger->getQueries()[0]->source[0]['file']);
	}

	public function testTheLoggingMiddlewareIsRegistered(): void
	{
		$container = self::createContainer(true);

		self::assertInstanceOf(LoggingMiddleware::class, $container->getByType(LoggingMiddleware::class));
	}

	public function testTheConnectionDriverIsWrappedByTheLoggingMiddleware(): void
	{
		$container = self::createContainer(true);
		$connection = $container->getByType(Connection::class);

		self::assertInstanceOf(LoggingDriver::class, $connection->getDriver());
	}

	public function testQueriesRunThroughTheContainerConnectionAreLogged(): void
	{
		$container = self::createContainer(true);
		$container->getByType(Connection::class)->executeQuery('SELECT 1');

		$logger = $container->getByType(SqlLogger::class);

		self::assertContains(
			'SELECT 1',
			array_map(static fn(object $query) => $query->sql, $logger->getQueries()),
		);
	}

	public function testTheTracyPanelIsRegisteredInTheBar(): void
	{
		$container = self::createContainer(true);

		self::assertCount(1, self::queryPanelsOf($container));
	}

	public function testThePanelUsesTheSameLoggerAsTheMiddleware(): void
	{
		$container = self::createContainer(true);
		$container->getByType(Connection::class)->executeQuery('SELECT 1');

		self::assertStringContainsString('SELECT 1', self::queryPanelsOf($container)[0]->getPanel());
	}

	public function testNothingIsRegisteredWithThePanelDisabled(): void
	{
		$container = self::createContainer(false);

		self::assertInstanceOf(Connection::class, $container->getByType(Connection::class));
		self::assertSame([], self::queryPanelsOf($container));
		self::assertNotInstanceOf(LoggingDriver::class, $container->getByType(Connection::class)->getDriver());
	}

	public function testTheSqlLoggerIsNotRegisteredWithThePanelDisabled(): void
	{
		$container = self::createContainer(false);

		$this->expectException(MissingServiceException::class);

		$container->getByType(SqlLogger::class);
	}

	/**
	 * @param string[] $sourcePaths
	 */
	private static function createContainer(bool $panel, array $sourcePaths = []): Container
	{
		$config = [
			'dbal' => [
				'debug' => [
					'panel' => $panel,
					'sourcePaths' => $sourcePaths,
				],
				'connections' => [
					'default' => [
						'driver' => 'pdo_sqlite',
						'path' => ':memory:',
					],
				],
			],
			'services' => [
				'tracy.bar' => RecordingBar::class,
			],
		];

		$tempDir = sys_get_temp_dir() . '/adt-doctrine-components-di';
		if (!is_dir($tempDir)) {
			mkdir($tempDir, 0777, true);
		}

		$loader = new ContainerLoader($tempDir, true);
		$class = $loader->load(
			static function (Compiler $compiler) use ($config): void {
				$compiler->addExtension('dbal', new DbalExtension(true));
				$compiler->addConfig($config);
			},
			serialize($config),
		);

		$container = new $class();
		$container->initialize();

		return $container;
	}

	/**
	 * @return QueryPanel[]
	 */
	private static function queryPanelsOf(Container $container): array
	{
		return array_values(array_filter(
			$container->getByType(RecordingBar::class)->addedPanels,
			static fn(object $panel) => $panel instanceof QueryPanel,
		));
	}
}
