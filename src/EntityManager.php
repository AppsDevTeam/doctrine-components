<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Exception;

class EntityManager extends EntityManagerDecorator
{
	public static bool $isFlushAllowed = true;

	/**
	 * @throws Exception
	 */
	public function flush(): void
	{
		if (!self::$isFlushAllowed) {
			throw new Exception('You cannot use flush.');
		}

		// we wrap it into transaction because in onFlush event we can add something to background queue
		// in onFlush event, transaction is not started - doctrine starts transaction afterward
		// doctrine also starts only 1 transaction and then create save points
		$this->beginTransaction();
		parent::flush();
		$this->commit();
	}

	public function isPossibleToDeleteEntity(object $entity): bool
	{
		$bool = true;
		$this->beginTransaction();

		try {
			$this->lowLevelDelete($entity);
		} catch (ForeignKeyConstraintViolationException) {
			$bool = false;
		}

		$this->rollback();

		return $bool;
	}

	protected function lowLevelDelete(object $entity): void
	{
		$class = get_class($entity);
		$this->createQueryBuilder()
			->delete()
			->from($class, 'e')
			->andWhere('e = :entity')
			->setParameter('entity', $entity)
			->getQuery()
			->execute();
	}
}
