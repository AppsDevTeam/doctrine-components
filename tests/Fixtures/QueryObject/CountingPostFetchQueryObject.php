<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

use Doctrine\ORM\EntityManagerInterface;

class CountingPostFetchQueryObject extends AuthorQueryObject
{
	public static int $calls = 0;

	public static function doPostFetch(EntityManagerInterface $em, array $rootEntities, array $fieldNames): void
	{
		static::$calls++;

		parent::doPostFetch($em, $rootEntities, $fieldNames);
	}
}
