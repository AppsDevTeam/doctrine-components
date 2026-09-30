<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

use Doctrine\ORM\QueryBuilder;

class GroupedAuthorQueryObject extends AuthorQueryObject
{
	protected function init(): void
	{
		parent::init();

		$this->filter[] = function (QueryBuilder $qb) {
			$qb->groupBy('e.publisher');
		};
	}
}
