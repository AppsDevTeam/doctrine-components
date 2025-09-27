<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Entities;

use ADT\DoctrineComponents\Entities\Traits\Identifier;

abstract class BaseEntity implements Entity
{
	use Identifier;

	public function isNew(): bool
	{
		return !$this->getId();
	}
}
