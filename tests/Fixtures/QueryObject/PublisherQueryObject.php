<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

use ADT\DoctrineComponents\QueryObject\QueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Publisher;

/**
 * @extends QueryObject<Publisher>
 */
class PublisherQueryObject extends QueryObject
{
	public function getEntityClass(): string
	{
		return Publisher::class;
	}

	protected function setDefaultOrder(): void
	{
		$this->orderBy('id', 'ASC');
	}
}
