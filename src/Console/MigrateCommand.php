<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Console;

use ADT\DoctrineComponents\Migrations\BreakingChangeDetector;
use ADT\DoctrineComponents\Migrations\ContractMigration;
use ADT\DoctrineComponents\Migrations\MigrationPhase;
use ADT\DoctrineComponents\Migrations\MigrationPhaseFilter;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Tools\Console\Command\DoctrineCommand;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand as DoctrineMigrateCommand;
use Doctrine\Migrations\Tools\Console\ConsoleInputMigratorConfigurationFactory;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Doctrine's `migrations:migrate` for the Expand and Contract pattern (ADT\DoctrineComponents\Migrations\ContractMigration).
 * Registered under the same name instead of Doctrine's command (DoctrineComponentsExtension, `expandContract: true`),
 * with the same arguments, options and output, so that existing scripts keep working. It differs in which pending
 * migrations it runs:
 *
 * - a contract migration whose file is missing in the running release (`current` of the Deployer layout, found
 *   from the file of the migration) is deferred: its code is not running yet, it runs at the next deploy
 * - `--phase=expand|contract` runs only the given phase, a contract migration then even if its code is not running
 * - the dry-run fails when an expand migration contains a change that breaks the old code
 *   (BreakingChangeDetector) - a deploy runs the dry-run first, so it stops before anything is run
 *
 * Migrating to a given version (`prev`, a FQCN) has no phases - Doctrine's command handles it.
 */
#[AsCommand(name: 'migrations:migrate', description: 'Execute the pending migrations by expand/contract phase, or a migration to a specified version.', aliases: ['migrate'])]
class MigrateCommand extends DoctrineCommand
{
	public function __construct(
		DependencyFactory $dependencyFactory,
		private readonly MigrationPhaseFilter $filter = new MigrationPhaseFilter(),
	) {
		parent::__construct($dependencyFactory);
	}

	protected function configure(): void
	{
		// The arguments and options of Doctrine's command, --phase on top
		$this
			->addArgument('version', InputArgument::OPTIONAL, 'The version FQCN or alias (first, prev, next, latest) to migrate to. Anything but latest is handled by Doctrine\'s command, without phases.', 'latest')
			->addOption('phase', null, InputOption::VALUE_REQUIRED, 'Run only the migrations of the phase (expand or contract). A contract migration is then run even if its code is not running yet.')
			->addOption('write-sql', null, InputOption::VALUE_OPTIONAL, 'The path to output the migration SQL file. Defaults to current working directory.', false)
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Execute the migration as a dry run.')
			->addOption('query-time', null, InputOption::VALUE_NONE, 'Time all the queries individually.')
			->addOption('allow-no-migration', null, InputOption::VALUE_NONE, 'Do not throw an exception if no migration is available.')
			->addOption('all-or-nothing', null, InputOption::VALUE_OPTIONAL, 'Wrap the entire migration in a transaction.', ConsoleInputMigratorConfigurationFactory::ABSENT_CONFIG_VALUE)
			->addOption('no-all-or-nothing', null, InputOption::VALUE_NONE, 'Disable wrapping the entire migration in a transaction.')
			->setHelp(<<<'EOT'
The <info>%command.name%</info> command executes the pending migrations (Expand and Contract pattern):

    <info>%command.full_name%</info>

A migration is expand unless it implements <comment>ADT\DoctrineComponents\Migrations\ContractMigration</comment>.
Contract migrations whose code is not running yet (their file is missing in the running release, <comment>current</comment>
of the Deployer layout) wait for the next deploy. Outside of that layout everything pending runs.

Only one phase, a contract migration even if its code is not running yet:

    <info>%command.full_name% --phase=expand</info>
    <info>%command.full_name% --phase=contract</info>

The dry-run prints the SQL and fails when an expand migration breaks the old code (DROP, RENAME, SET NOT NULL):

    <info>%command.full_name% --dry-run -vv</info>

Migrating to a version or an alias is handled by Doctrine's command, without phases:

    <info>%command.full_name% prev</info>

The other options (<comment>--write-sql</comment>, <comment>--query-time</comment>, <comment>--allow-no-migration</comment>,
<comment>--all-or-nothing</comment>, <comment>--no-interaction</comment>) work as in Doctrine's command.
EOT);

		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$phaseOption = $input->getOption('phase');
		$phase = is_string($phaseOption) ? MigrationPhase::tryFrom($phaseOption) : null;
		if (is_string($phaseOption) && $phase === null) {
			$this->io->getErrorStyle()->error(sprintf('Unknown phase "%s", use expand or contract.', $phaseOption));

			return self::INVALID;
		}

		if ($input->getArgument('version') !== 'latest') {
			if ($phase !== null) {
				$this->io->getErrorStyle()->error('--phase cannot be combined with a version.');

				return self::INVALID;
			}
			// A rollback (prev) or a given version has no phases - Doctrine's command does it. The dependency
			// factory is frozen already (initialize), so the command keeps the logger of this one.
			$doctrine = new DoctrineMigrateCommand($this->getDependencyFactory());
			$doctrine->setApplication($this->getApplication());

			return $doctrine->run($input, $output);
		}

		$dependencyFactory = $this->getDependencyFactory();
		$migratorConfiguration = $dependencyFactory->getConsoleInputMigratorConfigurationFactory()->getMigratorConfiguration($input);
		$dryRun = $migratorConfiguration->isDryRun();

		$path = $input->getOption('write-sql') ?? getcwd();
		if (is_string($path) && !$this->isPathWritable($path)) {
			$this->io->error(sprintf('The path "%s" not writeable!', $path));

			return self::FAILURE;
		}

		$dependencyFactory->getMetadataStorage()->ensureInitialized();

		if (count($dependencyFactory->getMigrationRepository()->getMigrations()) === 0) {
			$message = 'The version "latest" couldn\'t be reached, there are no registered migrations.';
			if ($input->getOption('allow-no-migration')) {
				$this->io->warning($message);

				return self::SUCCESS;
			}
			$this->io->error($message);

			return self::FAILURE;
		}

		$statusCalculator = $dependencyFactory->getMigrationStatusCalculator();
		if (!$this->checkExecutedUnavailableMigrations($statusCalculator->getExecutedUnavailableMigrations(), $input)) {
			return 3;
		}

		$newMigrations = $statusCalculator->getNewMigrations();
		$selection = $this->filter->select($newMigrations, $phase);
		foreach ($selection->deferred as $version) {
			$this->io->text(sprintf('<comment>Contract migration %s waits for the next deploy: the code that no longer uses the old schema is not running yet.</comment>', $version));
		}
		if ($selection->withoutRelease !== []) {
			$this->io->text(sprintf('<comment>No running release found (releases/current layout), contract migration(s) %s run right away.</comment>', implode(', ', $selection->withoutRelease)));
		}

		if ($selection->versions === []) {
			$current = $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('current');
			// The same message as Doctrine's command - deploy scripts look for it
			$this->io->success(sprintf('Already at the latest version ("%s")%s', $current, $phase !== null ? ' - phase ' . $phase->value : ''));

			return self::SUCCESS;
		}

		$databaseName = (string) $dependencyFactory->getConnection()->getDatabase();
		$question = sprintf(
			'WARNING! You are about to execute a migration in database "%s" that could result in schema changes and data loss. Are you sure you wish to continue?',
			$databaseName === '' ? '<unnamed>' : $databaseName,
		);
		if (!$dryRun && !$this->canExecute($question, $input)) {
			$this->io->error('Migration cancelled!');

			return 3;
		}

		$plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanForVersions($selection->versions, Direction::UP);
		$dependencyFactory->getLogger()->notice(
			'Migrating' . ($dryRun ? ' (dry-run)' : '') . ' {direction}: {versions}',
			['direction' => $plan->getDirection(), 'versions' => $this->describe($selection->versions, $newMigrations)],
		);

		$sql = $dependencyFactory->getMigrator()->migrate($plan, $migratorConfiguration);

		if (is_string($path)) {
			$dependencyFactory->getQueryWriter()->write($path, $plan->getDirection(), $sql);
		}

		// After the run the SQL is executed already, so only the dry-run can stop a breaking expand migration -
		// every deploy runs it first
		if ($dryRun) {
			$breaking = [];
			foreach ($sql as $version => $queries) {
				if (MigrationPhase::of($newMigrations->getMigration(new Version((string) $version))->getMigration()) !== MigrationPhase::Expand) {
					continue;
				}
				foreach ($queries as $query) {
					$reason = BreakingChangeDetector::detect($query->getStatement());
					if ($reason !== null) {
						$breaking[] = "$version: $reason";
					}
				}
			}
			if ($breaking !== []) {
				$this->io->getErrorStyle()->error(array_merge(
					['Expand migration(s) break the code running on the old schema - until the release is switched and after a rollback:'],
					$breaking,
					['Move the change to a migration implementing ' . ContractMigration::class . ': it runs one deploy later, when the code that no longer needs the old schema is running.'],
				));

				return self::FAILURE;
			}
		}

		$this->io->success(sprintf('Successfully migrated to version: %s', $selection->versions[array_key_last($selection->versions)]));
		$this->io->newLine();

		return self::SUCCESS;
	}

	/**
	 * @param list<Version> $versions
	 */
	private function describe(array $versions, AvailableMigrationsList $migrations): string
	{
		return implode(', ', array_map(
			fn (Version $version) => $version . ' (' . MigrationPhase::of($migrations->getMigration($version)->getMigration())->value . ')',
			$versions,
		));
	}

	/**
	 * Same as in Doctrine's command.
	 */
	private function checkExecutedUnavailableMigrations(ExecutedMigrationsList $executedUnavailableMigrations, InputInterface $input): bool
	{
		if (count($executedUnavailableMigrations) === 0) {
			return true;
		}

		$this->io->warning(sprintf(
			'You have %s previously executed migrations in the database that are not registered migrations.',
			count($executedUnavailableMigrations),
		));
		foreach ($executedUnavailableMigrations->getItems() as $executedUnavailableMigration) {
			$this->io->text(sprintf(
				'<comment>>></comment> %s (<comment>%s</comment>)',
				$executedUnavailableMigration->getExecutedAt()?->format('Y-m-d H:i:s'),
				$executedUnavailableMigration->getVersion(),
			));
		}

		if (!$this->canExecute('Are you sure you wish to continue?', $input)) {
			$this->io->error('Migration cancelled!');

			return false;
		}

		return true;
	}

	private function isPathWritable(string $path): bool
	{
		return is_writable($path) || is_dir($path) || is_writable(dirname($path));
	}
}
