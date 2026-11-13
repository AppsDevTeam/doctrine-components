<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\Migrations\ContractMigration;
use ADT\DoctrineComponents\Migrations\MigrationPhase;
use ADT\DoctrineComponents\Migrations\MigrationPhaseFilter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Selection by phase. The migrations are anonymous classes of this file, which lies outside of a releases/current
 * layout - the deferring of contract migrations of a not yet running release is covered by MigrateCommandTest.
 */
class MigrationPhaseFilterTest extends TestCase
{
	public function testWithoutPhaseSelectsEverything(): void
	{
		$selection = (new MigrationPhaseFilter())->select($this->migrations(), null);

		$this->assertSame(['1', '2', '3'], $this->versions($selection->versions));
		$this->assertSame([], $selection->deferred);
		$this->assertSame(['2'], $this->versions($selection->withoutRelease));
	}

	public function testPhaseSelectsOnlyMatchingMigrations(): void
	{
		$expand = (new MigrationPhaseFilter())->select($this->migrations(), MigrationPhase::Expand);
		$this->assertSame(['1', '3'], $this->versions($expand->versions));
		$this->assertSame([], $expand->withoutRelease);

		$contract = (new MigrationPhaseFilter())->select($this->migrations(), MigrationPhase::Contract);
		$this->assertSame(['2'], $this->versions($contract->versions));
		$this->assertSame([], $contract->withoutRelease);
	}

	private function migrations(): AvailableMigrationsList
	{
		$connection = $this->createStub(Connection::class);
		$logger = new NullLogger();

		$expand = new class ($connection, $logger) extends AbstractMigration {
			public function up(Schema $schema): void
			{
			}
		};
		$contract = new class ($connection, $logger) extends AbstractMigration implements ContractMigration {
			public function up(Schema $schema): void
			{
			}
		};

		return new AvailableMigrationsList([
			new AvailableMigration(new Version('1'), $expand),
			new AvailableMigration(new Version('2'), $contract),
			new AvailableMigration(new Version('3'), $expand),
		]);
	}

	/**
	 * @param list<Version> $versions
	 * @return list<string>
	 */
	private function versions(array $versions): array
	{
		return array_map(strval(...), $versions);
	}
}
