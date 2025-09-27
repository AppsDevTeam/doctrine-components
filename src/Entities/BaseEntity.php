<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Entities;

use ADT\DoctrineComponents\Entities\Traits\Identifier;
use Doctrine\ORM\Mapping\MappedSuperclass;

#[MappedSuperclass]
abstract class BaseEntity implements Entity
{
	use Identifier;

	public function isNew(): bool
	{
		return !$this->getId();
	}
}
