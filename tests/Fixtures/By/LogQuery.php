<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\By;

use ADT\DoctrineComponents\QueryObject\QueryObject;

/**
 * @extends QueryObject<Log>
 */
class LogQuery extends QueryObject
{
	public function getEntityClass(): string
	{
		return Log::class;
	}

	protected function setDefaultOrder(): void
	{
		$this->orderBy('id');
	}
}
