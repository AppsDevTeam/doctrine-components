<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

use ADT\DoctrineComponents\QueryObject\Filters\IsActiveFilter;
use ADT\DoctrineComponents\QueryObject\Filters\IsActiveFilterTrait;

class ActiveAuthorQueryObject extends AuthorQueryObject implements IsActiveFilter
{
	use IsActiveFilterTrait;

	protected function init(): void
	{
		parent::init();

		$this->byIsActive(true);
	}
}
