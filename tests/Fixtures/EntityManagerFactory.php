<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures;

use ADT\DoctrineComponents\EntityManager as DecoratedEntityManager;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

final class EntityManagerFactory
{
	private const DRIVER_MYSQL = 'pdo_mysql';
	private const DRIVER_SQLITE = 'pdo_sqlite';

	private static bool $sharedSchemaCreated = false;

	public static function driver(): string
	{
		return getenv('DB_DRIVER') ?: self::DRIVER_SQLITE;
	}

	public static function isMysql(): bool
	{
		return self::driver() === self::DRIVER_MYSQL;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function connectionParams(): array
	{
		if (!self::isMysql()) {
			return ['driver' => self::DRIVER_SQLITE, 'memory' => true];
		}

		return [
			'driver' => self::DRIVER_MYSQL,
			'host' => getenv('DB_HOST') ?: '127.0.0.1',
			'port' => (int) (getenv('DB_PORT') ?: 3306),
			'user' => getenv('DB_USER') ?: 'root',
			'password' => getenv('DB_PASSWORD') ?: '',
			'dbname' => getenv('DB_NAME') ?: 'doctrine_components_test',
			'charset' => 'utf8mb4',
		];
	}

	public static function createConfiguration(): Configuration
	{
		$config = ORMSetup::createAttributeMetadataConfiguration(
			[__DIR__ . '/Entity'],
			true,
			sys_get_temp_dir() . '/adt-doctrine-components-proxies',
		);

		if (method_exists($config, 'enableNativeLazyObjects')) {
			$config->enableNativeLazyObjects(true);
		} elseif (method_exists($config, 'setLazyGhostObjectEnabled')) {
			$config->setLazyGhostObjectEnabled(true);
		}

		return $config;
	}

	/**
	 * @param Middleware[] $middlewares
	 */
	public static function createConnection(array $middlewares = []): Connection
	{
		$dbalConfig = new DbalConfiguration();
		if ($middlewares) {
			$dbalConfig->setMiddlewares($middlewares);
		}

		return DriverManager::getConnection(self::connectionParams(), $dbalConfig);
	}

	/**
	 * Every call returns an entity manager over an empty database, no matter which driver is used.
	 * SQLite gets a brand new in-memory database, MySQL keeps one schema per process and is emptied instead.
	 *
	 * @param Middleware[] $middlewares
	 */
	public static function create(array $middlewares = [], bool $foreignKeys = false): EntityManagerInterface
	{
		$em = new EntityManager(self::createConnection($middlewares), self::createConfiguration());

		if (self::isMysql()) {
			if (!self::$sharedSchemaCreated) {
				self::dropSchema($em);
				self::createSchema($em);
				self::$sharedSchemaCreated = true;
			}

			self::truncateAllTables($em);

			return $em;
		}

		if ($foreignKeys) {
			$em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
		}

		self::createSchema($em);

		return $em;
	}

	/**
	 * @param Middleware[] $middlewares
	 */
	public static function createDecorated(array $middlewares = [], bool $foreignKeys = false): DecoratedEntityManager
	{
		return new DecoratedEntityManager(self::create($middlewares, $foreignKeys));
	}

	public static function createSchema(EntityManagerInterface $em): void
	{
		(new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
	}

	public static function dropSchema(EntityManagerInterface $em): void
	{
		(new SchemaTool($em))->dropSchema($em->getMetadataFactory()->getAllMetadata());
	}

	public static function truncateAllTables(EntityManagerInterface $em): void
	{
		$connection = $em->getConnection();

		$connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
		foreach ($connection->createSchemaManager()->listTableNames() as $_table) {
			$connection->executeStatement('TRUNCATE TABLE ' . $connection->quoteIdentifier($_table));
		}
		$connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
	}
}
