<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

class CustomAliasAuthorQueryObject extends AuthorQueryObject
{
	protected string $entityAlias = 'a';
}
