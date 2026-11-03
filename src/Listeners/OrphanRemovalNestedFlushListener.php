<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Listeners;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

/**
 * Umožní flush v postFlush listeneru po flushi, který odstranil orphan (vazba s orphanRemoval: true).
 *
 * Doctrine v UnitOfWork::commit() orphany smaže, seznam `orphanRemovals` ale vyprázdní až v postCommitCleanup(),
 * tedy AŽ PO události postFlush. Když listener v postFlush flushne znovu, vnořený commit zavolá remove() na stejné
 * orphany podruhé. U soft-deleteable entit (Gedmo je v postFlush odpojí) to skončí
 * "Detached entity … cannot be removed" a celé uložení se odroluje.
 *
 * Listener si pamatuje entity, které šly do remove() (preRemove - orphany ho dostávají ještě před onFlush),
 * a na začátku dalšího flushe (preFlush - běží před zpracováním orphanů) jim zruší naplánované orphan removal,
 * protože v předchozím commitu už zpracované byly. U entity, která orphanem není, je to no-op.
 * Na pořadí listenerů nezáleží.
 *
 * Pozor: UnitOfWork::cancelOrphanRemoval() je interní API ORM - při upgradu Doctrine ověřit
 * (OrphanRemovalNestedFlushListenerTest). Pokud ORM začne orphany čistit před postFlush, listener odstranit.
 */
class OrphanRemovalNestedFlushListener implements EventSubscriber
{
	/** @var array<int, object> entity, které prošly remove() od posledního flushe */
	private array $removed = [];

	public function getSubscribedEvents(): array
	{
		return [Events::preRemove, Events::preFlush];
	}

	public function preRemove(PreRemoveEventArgs $args): void
	{
		$entity = $args->getObject();
		// držíme referenci, aby se spl_object_id nemohlo recyklovat pro jiný objekt
		$this->removed[spl_object_id($entity)] = $entity;
	}

	public function preFlush(PreFlushEventArgs $args): void
	{
		if ($this->removed === []) {
			return;
		}

		$uow = $args->getObjectManager()->getUnitOfWork();
		foreach ($this->removed as $entity) {
			$uow->cancelOrphanRemoval($entity);
		}

		$this->removed = [];
	}
}
