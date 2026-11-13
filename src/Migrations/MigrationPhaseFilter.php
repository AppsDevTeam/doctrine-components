<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Migrations;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use ReflectionClass;

/**
 * Chooses the pending migrations to run by phase (see ContractMigration).
 */
final class MigrationPhaseFilter
{
	public function __construct(
		private readonly RunningReleaseLocator $runningReleaseLocator = new RunningReleaseLocator(),
	) {
	}

	/**
	 * @param MigrationPhase|null $phase null = both phases, but a contract migration whose file is missing in the
	 *                                   running release belongs to code that is not running yet and is deferred;
	 *                                   an explicitly requested phase is never restricted
	 */
	public function select(AvailableMigrationsList $newMigrations, ?MigrationPhase $phase): MigrationPhaseSelection
	{
		$versions = [];
		$deferred = [];
		$withoutRelease = [];
		foreach ($newMigrations->getItems() as $available) {
			$migrationPhase = MigrationPhase::of($available->getMigration());
			if ($phase !== null && $migrationPhase !== $phase) {
				continue;
			}

			if ($phase === null && $migrationPhase === MigrationPhase::Contract) {
				$released = $this->isReleased($available->getMigration());
				if ($released === false) {
					$deferred[] = $available->getVersion();
					continue;
				}
				if ($released === null) {
					$withoutRelease[] = $available->getVersion();
				}
			}

			$versions[] = $available->getVersion();
		}

		return new MigrationPhaseSelection($versions, $deferred, $withoutRelease);
	}

	/**
	 * @return bool|null null = no running release for the file (first deploy, local development)
	 */
	private function isReleased(AbstractMigration $migration): ?bool
	{
		$file = (new ReflectionClass($migration))->getFileName();
		if ($file === false) {
			return null;
		}
		$running = $this->runningReleaseLocator->locate($file);

		return $running === null ? null : is_file($running);
	}
}
