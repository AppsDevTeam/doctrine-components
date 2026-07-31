<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

class DistinctCountAuthorQueryObject extends AuthorQueryObject
{
	protected function getCountExpr(): string
	{
		return 'DISTINCT e.id';
	}
}
