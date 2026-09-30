<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

use Doctrine\ORM\QueryBuilder;

class NamedFilterAuthorQueryObject extends AuthorQueryObject
{
	protected function init(): void
	{
		parent::init();

		$this->filter[self::FILTER_ACTIVE] = function (QueryBuilder $qb) {
			$qb->andWhere('e.isActive = :init_isActive')
				->setParameter('init_isActive', true);
		};

		$this->filter[self::FILTER_NAMED] = function (QueryBuilder $qb) {
			$qb->andWhere('e.name != :init_name')
				->setParameter('init_name', 'hidden');
		};
	}
}
