<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\DI;

use ADT\DoctrineComponents\Listeners\TraitIndexListener;
use Nette\DI\CompilerExtension;

/**
 * ORM doplňky doctrine-components.
 *
 * Registruje TraitIndexListener - nettrine/orm si ho najde podle typu (EventSubscriber)
 * a navěsí na event manager, takže na pořadí rozšíření nezáleží.
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
		$this->getContainerBuilder()->addDefinition($this->prefix('traitIndexListener'))
			->setFactory(TraitIndexListener::class)
			->setAutowired(false);
	}
}
