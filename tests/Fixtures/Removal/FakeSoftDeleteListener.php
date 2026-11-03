<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Removal;

use DateTimeImmutable;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Napodobuje Gedmo SoftDeleteableListener: v onFlush místo smazání nastaví deletedAt (čte a zapisuje
 * vlastnost entity, takže neinicializovanou proxy načte) a v postFlush soft-smazané entity odpojí.
 */
class FakeSoftDeleteListener implements EventSubscriber
{
	/** @var list<object> */
	private array $softDeleted = [];

	public function getSubscribedEvents(): array
	{
		return [Events::onFlush, Events::postFlush];
	}

	public function onFlush(OnFlushEventArgs $args): void
	{
		$em = $args->getObjectManager();
		$uow = $em->getUnitOfWork();

		foreach ($uow->getScheduledEntityDeletions() as $entity) {
			if (!$entity instanceof Attachment || $entity->deletedAt !== null) {
				continue;
			}

			$entity->deletedAt = new DateTimeImmutable();
			$em->persist($entity);
			$uow->propertyChanged($entity, 'deletedAt', null, $entity->deletedAt);
			$uow->scheduleExtraUpdate($entity, ['deletedAt' => [null, $entity->deletedAt]]);
			$this->softDeleted[] = $entity;
		}
	}

	public function postFlush(PostFlushEventArgs $args): void
	{
		foreach ($this->softDeleted as $entity) {
			$args->getObjectManager()->detach($entity);
		}
		$this->softDeleted = [];
	}
}
