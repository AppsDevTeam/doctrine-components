<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\DI;

use ADT\DoctrineComponents\Listeners\MissingEntityRemovalListener;
use ADT\DoctrineComponents\Listeners\OrphanRemovalNestedFlushListener;
use ADT\DoctrineComponents\Listeners\TraitIndexListener;
use Nette\DI\CompilerExtension;

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
 * ```
 */
class DoctrineComponentsExtension extends CompilerExtension
{
	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();

		$builder->addDefinition($this->prefix('traitIndexListener'))
			->setFactory(TraitIndexListener::class)
			->setAutowired(false);

		$builder->addDefinition($this->prefix('orphanRemovalNestedFlushListener'))
			->setFactory(OrphanRemovalNestedFlushListener::class)
			->setAutowired(false);

		$builder->addDefinition($this->prefix('missingEntityRemovalListener'))
			->setFactory(MissingEntityRemovalListener::class)
			->setAutowired(false);
	}
}
