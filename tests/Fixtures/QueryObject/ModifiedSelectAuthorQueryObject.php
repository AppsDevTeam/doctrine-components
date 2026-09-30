<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

use Doctrine\ORM\QueryBuilder;

class ModifiedSelectAuthorQueryObject extends AuthorQueryObject
{
	protected function initSelect(QueryBuilder $qb): void
	{
		parent::initSelect($qb);

		$qb->addSelect('e.rating AS rating');
	}
}
