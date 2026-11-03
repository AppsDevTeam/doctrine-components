<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\DI;

use ADT\DoctrineComponents\Listeners\MissingEntityRemovalListener;
use ADT\DoctrineComponents\Listeners\OrphanRemovalNestedFlushListener;
use ADT\DoctrineComponents\Listeners\TraitIndexListener;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * ORM doplňky doctrine-components.
 *
 * Registruje TraitIndexListener, OrphanRemovalNestedFlushListener a MissingEntityRemovalListener -
 * nettrine/orm si je najde podle typu (EventSubscriber) a navěsí na event manager, takže na pořadí
 * rozšíření nezáleží.
 *
 * ```neon
 * extensions:
 *     doctrineComponents: ADT\DoctrineComponents\DI\DoctrineComponentsExtension
 *
 * doctrineComponents:
 *     # SQL filtry skrývající soft-smazané řádky - remove() vazby na takový řádek je no-op (MissingEntityRemovalListener)
 *     softDeleteFilters: [soft-deleteable]
 * ```
 */
class DoctrineComponentsExtension extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'softDeleteFilters' => Expect::listOf('string')->default([]),
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
}
