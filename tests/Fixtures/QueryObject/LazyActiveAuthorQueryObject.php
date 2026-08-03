<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

use ADT\DoctrineComponents\QueryObject\Filters\IsActiveFilter;
use ADT\DoctrineComponents\QueryObject\Filters\IsActiveFilterTrait;

/**
 * Registruje isActive filtr líně, stejně jako to dělá BaseQueryTrait v adt/fancyadmin:
 * callback pod klíčem IS_ACTIVE_FILTER teprve při skládání query zavolá byIsActive(),
 * které se pod tím samým klíčem nahradí skutečnou podmínkou.
 */
class LazyActiveAuthorQueryObject extends AuthorQueryObject implements IsActiveFilter
{
	use IsActiveFilterTrait;

	protected function init(): void
	{
		parent::init();

		$this->filter[IsActiveFilter::IS_ACTIVE_FILTER] = fn() => $this->byIsActive();
	}
}
