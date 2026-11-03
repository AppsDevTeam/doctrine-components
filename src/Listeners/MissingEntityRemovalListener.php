<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Listeners;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

/**
 * remove() entity, jejíž řádek v databázi už není, je no-op místo EntityNotFoundException.
 *
 * Typicky orphanRemoval: vazba v paměti drží neinicializovanou proxy, ale řádek mezitím smazal jiný požadavek
 * (souběžné uložení), nebo ho skryje SQL filtr (Gedmo SoftDeleteable - vazba odkazuje na už soft-smazaný řádek).
 * Doctrine sama proxy při remove() nenačítá, ale listenery ano - Gedmo SoftDeleteable v onFlush na ni zapisuje
 * deletedAt - a načtení skončí EntityNotFoundException, která shodí celý flush.
 *
 * Listener proxy načte hned v preRemove; když to nejde, entitu odpojí. Doctrine ji pak do mazání vůbec nenaplánuje
 * (scheduleForDelete() přeskočí entitu mimo identity map) a v onFlush/postFlush ji už nikdo neuvidí. Řádek
 * smazaný je, nebo ho filtr považuje za smazaný, takže se nic neztratí.
 *
 * Detach nezastaví ostatní preRemove listenery téže události - listener, který v preRemove sám čte data
 * neinicializované proxy, ji musí přeskočit (isUninitializedObject()).
 */
class MissingEntityRemovalListener implements EventSubscriber
{
	public function getSubscribedEvents(): array
	{
		return [Events::preRemove];
	}

	public function preRemove(PreRemoveEventArgs $args): void
	{
		$entity = $args->getObject();
		$em = $args->getObjectManager();
		$uow = $em->getUnitOfWork();

		if (!$uow->isUninitializedObject($entity)) {
			return;
		}

		try {
			$uow->initializeObject($entity);
		} catch (EntityNotFoundException) {
			$em->detach($entity);
		}
	}
}
