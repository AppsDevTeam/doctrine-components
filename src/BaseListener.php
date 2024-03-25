<?php
declare(strict_types=1);

namespace ADT\DoctrineComponents;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Exception;

abstract class BaseListener implements EventSubscriber
{
	private static int $transactionsStartedCount = 0;
	private static bool $possibleChangesChecked = false;

	protected EntityManagerDecorator $em;

	/** @var callable[] */
	private array $postFlush = [];

	/** @var callable[] */
	protected array $entitiesToCompute = [];

	/** @var callable[] */
	protected array $entitiesToRecompute = [];

	public abstract function getSubscribedEvents();

	public function setEntityManager(EntityManagerDecorator $em)
	{
		$this->em = $em;
	}

	final public function onFlush(OnFlushEventArgs $eventArgs): void
	{
		self::$possibleChangesChecked = false;
		EntityManager::$isFlushAllowed = false;
		if (method_exists($this, 'onFlushCallback')) {
			$this->onFlushCallback($eventArgs);
		} else {
			throw new Exception('Implement onFlushCallback first.');
		}
		EntityManager::$isFlushAllowed = true;

		$this->recalculateEntities();
	}

	final public function prePersist(PrePersistEventArgs $eventArgs): void
	{
		self::$possibleChangesChecked = false;
		EntityManager::$isFlushAllowed = false;
		if (method_exists($this, 'prePersistCallback')) {
			$this->prePersistCallback($eventArgs);
		} else {
			throw new Exception('Implement prePersistCallback first.');
		}
		EntityManager::$isFlushAllowed = true;

		$this->recalculateEntities();
	}

	final public function postPersist(PostPersistEventArgs $eventArgs): void
	{
		self::$possibleChangesChecked = false;
		EntityManager::$isFlushAllowed = false;
		if (method_exists($this, 'postPersistCallback')) {
			$this->postPersistCallback($eventArgs);
		} else {
			throw new Exception('Implement postPersistCallback first.');
		}
		EntityManager::$isFlushAllowed = true;

		$this->recalculateEntities();
	}

	final public function preUpdate(PreUpdateEventArgs $eventArgs): void
	{
		self::$possibleChangesChecked = false;
		EntityManager::$isFlushAllowed = false;
		if (method_exists($this, 'preUpdateCallback')) {
			$this->preUpdateCallback($eventArgs);
		} else {
			throw new Exception('Implement preUpdateCallback first.');
		}
		EntityManager::$isFlushAllowed = true;

		$this->recalculateEntities();
	}

	final public function postUpdate(PostUpdateEventArgs $eventArgs): void
	{
		self::$possibleChangesChecked = false;
		EntityManager::$isFlushAllowed = false;
		if (method_exists($this, 'postUpdateCallback')) {
			$this->postUpdateCallback($eventArgs);
		} else {
			throw new Exception('Implement postUpdateCallback first.');
		}
		EntityManager::$isFlushAllowed = true;

		$this->recalculateEntities();
	}

	final public function postFlush(): void
	{
		$uow = $this->em->getUnitOfWork();

		if (!self::$possibleChangesChecked) {
			$uow->computeChangeSets();
			$changedEntities = [];
			foreach (array_merge($uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates()) as $_entity) {
				$changedEntities[] = $_entity::class . ' ' . $_entity->getId();
			}
			if (count($changedEntities) > 0) {
				throw new Exception('You probably did not recompute all changes:' . implode('; ', $changedEntities));
			}
			self::$possibleChangesChecked = true;
		}

		if (self::$transactionsStartedCount) {
			$this->commitTransaction();
		}
		$postFlush = $this->postFlush;
		$this->postFlush = [];
		foreach ($postFlush as $_callback) {
			$_callback();
		}
	}

	protected function startTransaction(): void
	{
		if (self::$transactionsStartedCount === 0) {
			$this->em->beginTransaction();
		}
		self::$transactionsStartedCount++;
	}

	protected function commitTransaction(): void
	{
		self::$transactionsStartedCount--;
		if (self::$transactionsStartedCount === -1) {
			throw new Exception('No transactions are started for commit');
		} elseif (self::$transactionsStartedCount === 0) {
			$this->em->commit();
		}
	}

	protected function isPropertyChanged($entity, $property): bool
	{
		$uow = $this->em->getUnitOfWork();
		$changeSet = $uow->getEntityChangeSet($entity);
		return isset($changeSet[$property]) && $changeSet[$property][0] !== $changeSet[$property][1];
	}

	protected function recalculateEntities(): void
	{
		$uniqueEntitiesToCompute = array_intersect_key($this->entitiesToCompute, array_unique(array_map('spl_object_id', $this->entitiesToCompute)));
		$this->entitiesToCompute = [];
		foreach ($uniqueEntitiesToCompute as $_entity) {
			$this->em->getUnitOfWork()->computeChangeSet($this->em->getClassMetadata($_entity::class), $_entity);
		}

		$uniqueEntitiesToRecalculate = array_intersect_key($this->entitiesToRecompute, array_unique(array_map('spl_object_id', $this->entitiesToRecompute)));
		$this->entitiesToRecompute = [];
		foreach ($uniqueEntitiesToRecalculate as $_entity) {
			$this->em->getUnitOfWork()->recomputeSingleEntityChangeSet($this->em->getClassMetadata($_entity::class), $_entity);
		}
	}

	final protected function addPostFlushCallback(callable $callback): void
	{
		if (!isset($this->getSubscribedEvents()['postFlush'])) {
			throw new \Exception ('Missing postFlush subsribed event.');
		}

		$this->postFlush[] = $callback;
	}
}
