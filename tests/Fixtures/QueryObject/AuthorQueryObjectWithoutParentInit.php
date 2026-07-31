<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

class AuthorQueryObjectWithoutParentInit extends AuthorQueryObject
{
	protected function init(): void
	{
	}
}
