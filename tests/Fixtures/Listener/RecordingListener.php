<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Listener;

use ADT\DoctrineComponents\BaseListener;
use ADT\DoctrineComponents\EntityManager;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

class RecordingListener extends BaseListener
{
	/** @var string[] */
	public array $calls = [];

	/** @var bool[] */
	public array $flushAllowedDuringCallback = [];

	/** @var callable|null */
	public $onFlushHook = null;

	public function getSubscribedEvents(): array
	{
		return [
			Events::onFlush,
			Events::prePersist,
			Events::postPersist,
			Events::preUpdate,
			Events::postUpdate,
			Events::postFlush,
		];
	}

	public function onFlushCallback(OnFlushEventArgs $eventArgs): void
	{
		$this->record('onFlush');

		if ($this->onFlushHook !== null) {
			($this->onFlushHook)($eventArgs);
		}
	}

	public function prePersistCallback(PrePersistEventArgs $eventArgs): void
	{
		$this->record('prePersist');
	}

	public function postPersistCallback(PostPersistEventArgs $eventArgs): void
	{
		$this->record('postPersist');
	}

	public function preUpdateCallback(PreUpdateEventArgs $eventArgs): void
	{
		$this->record('preUpdate');
	}

	public function postUpdateCallback(PostUpdateEventArgs $eventArgs): void
	{
		$this->record('postUpdate');
	}

	public function callAddPostFlushCallback(callable $callback): void
	{
		$this->addPostFlushCallback($callback);
	}

	public function callStartTransaction(): void
	{
		$this->startTransaction();
	}

	public function callCommitTransaction(): void
	{
		$this->commitTransaction();
	}

	public function callIsPropertyChanged(object $entity, array|string $property): bool
	{
		return $this->isPropertyChanged($entity, $property);
	}

	public function markForRecompute(object $entity): void
	{
		$this->entitiesToRecompute[] = $entity;
	}

	public function markForCompute(object $entity): void
	{
		$this->entitiesToCompute[] = $entity;
	}

	public static function resetStaticState(): void
	{
		(new \ReflectionProperty(BaseListener::class, 'transactionsStartedCount'))->setValue(null, 0);
		(new \ReflectionProperty(BaseListener::class, 'possibleChangesChecked'))->setValue(null, false);
	}

	private function record(string $event): void
	{
		$this->calls[] = $event;
		$this->flushAllowedDuringCallback[$event] = EntityManager::$isFlushAllowed;
	}
}
