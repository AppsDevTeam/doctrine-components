<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\Console\MigrateCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * migrations:migrate with phases in the Deployer layout: `releases/1` is the running release (`current`), `releases/2`
 * the one being deployed - its migrations directory is the configured one.
 */
class MigrateCommandTest extends TestCase
{
	private const EXPAND = "CREATE TABLE customer (id INTEGER PRIMARY KEY, name VARCHAR(255), legacy_name VARCHAR(255))";
	private const CONTRACT = 'DROP TABLE legacy';

	private string $deployPath;
	private string $namespace;
	private Connection $connection;

	protected function setUp(): void
	{
		$this->deployPath = sys_get_temp_dir() . '/adt-dc-migrate-' . uniqid();
		mkdir("$this->deployPath/releases/1/migrations", 0777, true);
		mkdir("$this->deployPath/releases/2/migrations", 0777, true);
		symlink('releases/1', "$this->deployPath/current");
		// A class can be loaded only once per process - every test has its own namespace
		$this->namespace = 'Migrations' . substr(md5(uniqid()), 0, 8);
		$this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
	}

	protected function tearDown(): void
	{
		TestDirectory::remove($this->deployPath);
	}

	public function testContractMigrationOfTheNewReleaseWaitsForTheNextDeploy(): void
	{
		$this->writeMigration(2, 'Version1', self::EXPAND, 'DROP TABLE customer');
		$this->writeMigration(2, 'Version2', self::CONTRACT, contract: true);

		$dryRun = $this->migrate(['--dry-run' => true]);
		$this->assertSame(Command::SUCCESS, $dryRun->getStatusCode(), $this->display($dryRun));
		$this->assertStringContainsString("Contract migration $this->namespace\\Version2 waits for the next deploy", $this->display($dryRun));
		$this->assertStringContainsString("Migrating (dry-run) up: $this->namespace\\Version1 (expand)", $this->display($dryRun));
		$this->assertStringContainsString(self::EXPAND, $this->display($dryRun));
		$this->assertStringNotContainsString(self::CONTRACT, $this->display($dryRun));
		$this->assertSame([], $this->executed());

		$run = $this->migrate();
		$this->assertSame(Command::SUCCESS, $run->getStatusCode(), $this->display($run));
		$this->assertStringContainsString("Successfully migrated to version: $this->namespace\\Version1", $this->display($run));
		$this->assertSame(["$this->namespace\\Version1"], $this->executed());

		// Nothing else to run until the next deploy
		$again = $this->migrate();
		$this->assertStringContainsString('waits for the next deploy', $this->display($again));
		$this->assertStringContainsString('Already at the latest version', $this->display($again));
		$this->assertSame(["$this->namespace\\Version1"], $this->executed());

		// The next deploy: the release with the contract migration is the running one
		$this->writeMigration(1, 'Version2', self::CONTRACT, contract: true);
		$this->connection->executeStatement('CREATE TABLE legacy (id INTEGER)');
		$next = $this->migrate();
		$this->assertSame(Command::SUCCESS, $next->getStatusCode(), $this->display($next));
		$this->assertStringContainsString("Migrating up: $this->namespace\\Version2 (contract)", $this->display($next));
		$this->assertSame(["$this->namespace\\Version1", "$this->namespace\\Version2"], $this->executed());
	}

	public function testExplicitPhaseIsNotRestrictedByTheRunningRelease(): void
	{
		$this->writeMigration(2, 'Version1', self::EXPAND);
		$this->writeMigration(2, 'Version2', self::CONTRACT, contract: true);
		$this->connection->executeStatement('CREATE TABLE legacy (id INTEGER)');

		$contract = $this->migrate(['--phase' => 'contract']);
		$this->assertSame(Command::SUCCESS, $contract->getStatusCode(), $this->display($contract));
		$this->assertSame(["$this->namespace\\Version2"], $this->executed());

		$expand = $this->migrate(['--phase' => 'expand']);
		$this->assertSame(Command::SUCCESS, $expand->getStatusCode(), $this->display($expand));
		$this->assertSame(["$this->namespace\\Version1", "$this->namespace\\Version2"], $this->executed());

		$again = $this->migrate(['--phase' => 'expand']);
		$this->assertStringContainsString('Already at the latest version', $this->display($again));
		$this->assertStringContainsString('phase expand', $this->display($again));
	}

	public function testUnknownPhaseIsRejected(): void
	{
		$this->writeMigration(2, 'Version1', self::EXPAND);

		$run = $this->migrate(['--phase' => 'cleanup']);
		$this->assertSame(Command::INVALID, $run->getStatusCode());
		$this->assertStringContainsString('Unknown phase "cleanup"', $this->display($run));
	}

	public function testDryRunFailsForAnExpandMigrationBreakingTheOldCode(): void
	{
		$this->writeMigration(2, 'Version1', self::EXPAND);
		$this->writeMigration(2, 'Version2', 'ALTER TABLE customer DROP COLUMN legacy_name');

		$run = $this->migrate(['--dry-run' => true]);
		$this->assertSame(Command::FAILURE, $run->getStatusCode());
		$this->assertStringContainsString("$this->namespace\\Version2: customer: DROP COLUMN legacy_name", $this->display($run));
		$this->assertStringContainsString('ADT\DoctrineComponents\Migrations\ContractMigration', $this->display($run));
		$this->assertSame([], $this->executed());
	}

	public function testDryRunAcceptsTheSameChangeInAContractMigration(): void
	{
		$this->writeMigration(2, 'Version1', self::EXPAND);
		$this->writeMigration(2, 'Version2', 'ALTER TABLE customer DROP COLUMN legacy_name', contract: true);

		$run = $this->migrate(['--dry-run' => true, '--phase' => 'contract']);
		$this->assertSame(Command::SUCCESS, $run->getStatusCode(), $this->display($run));
		$this->assertStringContainsString('DROP COLUMN legacy_name', $this->display($run));
	}

	public function testOutsideOfTheReleaseLayoutEverythingRuns(): void
	{
		mkdir("$this->deployPath/standalone/migrations", 0777, true);
		$this->writeMigration(2, 'Version1', self::EXPAND, directory: "$this->deployPath/standalone/migrations");
		$this->writeMigration(2, 'Version2', self::CONTRACT, contract: true, directory: "$this->deployPath/standalone/migrations");
		$this->connection->executeStatement('CREATE TABLE legacy (id INTEGER)');

		$run = $this->migrate(directory: "$this->deployPath/standalone/migrations");
		$this->assertSame(Command::SUCCESS, $run->getStatusCode(), $this->display($run));
		$this->assertStringContainsString("No running release found (releases/current layout), contract migration(s) $this->namespace\\Version2 run right away", $this->display($run));
		$this->assertSame(["$this->namespace\\Version1", "$this->namespace\\Version2"], $this->executed());
	}

	public function testVersionArgumentIsHandledByDoctrine(): void
	{
		$this->writeMigration(2, 'Version1', self::EXPAND, 'DROP TABLE customer');
		$this->migrate();
		$this->assertSame(["$this->namespace\\Version1"], $this->executed());

		$prev = $this->migrate(['version' => 'prev']);
		$this->assertSame(Command::SUCCESS, $prev->getStatusCode(), $this->display($prev));
		$this->assertSame([], $this->executed());
		$this->assertSame([], $this->connection->fetchAllAssociative("SELECT name FROM sqlite_master WHERE name = 'customer'"));

		$withPhase = $this->migrate(['version' => 'prev', '--phase' => 'expand']);
		$this->assertSame(Command::INVALID, $withPhase->getStatusCode());
	}

	public function testNoMigrationsAtAll(): void
	{
		$this->assertSame(Command::FAILURE, $this->migrate()->getStatusCode());
		$this->assertSame(Command::SUCCESS, $this->migrate(['--allow-no-migration' => true])->getStatusCode());
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function migrate(array $input = [], ?string $directory = null): CommandTester
	{
		// A new dependency factory for every run as in a real process: an executed migration instance is frozen
		$configuration = new Configuration();
		$configuration->addMigrationsDirectory($this->namespace, $directory ?? "$this->deployPath/releases/2/migrations");
		$dependencyFactory = DependencyFactory::fromConnection(
			new ExistingConfiguration($configuration),
			new ExistingConnection($this->connection),
		);

		$tester = new CommandTester(new MigrateCommand($dependencyFactory));
		$tester->execute($input, ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_DEBUG]);

		return $tester;
	}

	/**
	 * Display with the line wrapping of the SymfonyStyle blocks undone.
	 */
	private function display(CommandTester $tester): string
	{
		return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
	}

	private function writeMigration(int $release, string $class, string $up, string $down = '', bool $contract = false, ?string $directory = null): void
	{
		$implements = $contract ? ' implements \ADT\DoctrineComponents\Migrations\ContractMigration' : '';
		$downSql = $down === '' ? '' : "\$this->addSql('$down');";
		file_put_contents(($directory ?? "$this->deployPath/releases/$release/migrations") . "/$class.php", <<<PHP
			<?php
			namespace $this->namespace;
			use Doctrine\DBAL\Schema\Schema;
			use Doctrine\Migrations\AbstractMigration;
			final class $class extends AbstractMigration$implements
			{
				public function up(Schema \$schema): void { \$this->addSql('$up'); }
				public function down(Schema \$schema): void { $downSql }
			}
			PHP);
	}

	/**
	 * @return list<string>
	 */
	private function executed(): array
	{
		if ($this->connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'doctrine_migration_versions'") === false) {
			return [];
		}

		return $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version');
	}
}
