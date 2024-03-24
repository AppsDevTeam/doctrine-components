<?php declare(strict_types = 1);

namespace ADT\DoctrineComponents;

use Doctrine\ORM\Decorator\EntityManagerDecorator;

class EntityManager extends EntityManagerDecorator
{
	public static bool $isFlushAllowed = true;

	public function flush($entity = null): void
	{
		if (!self::$isFlushAllowed) {
			throw new \Exception('You cannot use flush.');
		}

		parent::flush($entity);
	}
}