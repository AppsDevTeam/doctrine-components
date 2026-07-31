<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

use Doctrine\ORM\QueryBuilder;

class HiddenSelectAuthorQueryObject extends AuthorQueryObject
{
	protected function initSelect(QueryBuilder $qb): void
	{
		parent::initSelect($qb);

		$qb->addSelect('e.rating AS HIDDEN hiddenRating');
	}

	protected function setDefaultOrder(): void
	{
		$this->orderBy('id', 'ASC');
	}
}
