<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\DI;

use ADT\DoctrineComponents\Console\MigrateCommand;
use ADT\DoctrineComponents\Listeners\MissingEntityRemovalListener;
use ADT\DoctrineComponents\Listeners\OrphanRemovalNestedFlushListener;
use ADT\DoctrineComponents\Listeners\TraitIndexListener;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand as DoctrineMigrateCommand;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * ORM doplňky doctrine-components.
 *
 * Registruje TraitIndexListener, OrphanRemovalNestedFlushListener a MissingEntityRemovalListener -
 * nettrine/orm si je najde podle typu (EventSubscriber) a navěsí na event manager, takže na pořadí
 * rozšíření nezáleží.
 *
 * S `expandContract: true` nahradí `migrations:migrate` z nettrine/migrations příkazem pro Expand and
 * Contract pattern (ADT\DoctrineComponents\Console\MigrateCommand) - definice si nechá jméno i tag
 * `console.command`, mění se jen třída.
 *
 * ```neon
 * extensions:
 *     doctrineComponents: ADT\DoctrineComponents\DI\DoctrineComponentsExtension
 *
 * doctrineComponents:
 *     # SQL filtry skrývající soft-smazané řádky - remove() vazby na takový řádek je no-op (MissingEntityRemovalListener)
 *     softDeleteFilters: [soft-deleteable]
 *     # migrations:migrate s fázemi expand/contract (ContractMigration)
 *     expandContract: true
 * ```
 */
class DoctrineComponentsExtension extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'softDeleteFilters' => Expect::listOf('string')->default([]),
			'expandContract' => Expect::bool(false),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		/** @var \stdClass $config */
		$config = $this->getConfig();

		$builder->addDefinition($this->prefix('traitIndexListener'))
			->setFactory(TraitIndexListener::class)
			->setAutowired(false);

		$builder->addDefinition($this->prefix('orphanRemovalNestedFlushListener'))
			->setFactory(OrphanRemovalNestedFlushListener::class)
			->setAutowired(false);

		$builder->addDefinition($this->prefix('missingEntityRemovalListener'))
			->setFactory(MissingEntityRemovalListener::class, [$config->softDeleteFilters])
			->setAutowired(false);
	}

	public function beforeCompile(): void
	{
		/** @var \stdClass $config */
		$config = $this->getConfig();
		if (!$config->expandContract) {
			return;
		}

		if (!class_exists(DoctrineMigrateCommand::class)) {
			throw new \LogicException(sprintf('%s.expandContract needs doctrine/migrations.', $this->name));
		}

		// Registered by nettrine/migrations in its loadConfiguration and found by type - the name of the
		// definition is nettrine's business
		$definitions = $this->getContainerBuilder()->findByType(DoctrineMigrateCommand::class);
		if ($definitions === []) {
			throw new \LogicException(sprintf('%s.expandContract: no migrations:migrate command (%s) is registered - add nettrine/migrations first.', $this->name, DoctrineMigrateCommand::class));
		}
		foreach ($definitions as $definition) {
			if ($definition instanceof ServiceDefinition) {
				$definition->setFactory(MigrateCommand::class)->setType(MigrateCommand::class);
			}
		}
	}
}
