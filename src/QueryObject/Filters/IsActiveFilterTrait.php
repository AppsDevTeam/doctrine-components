<?php

namespace ADT\DoctrineComponents\QueryObject\Filters;

use ADT\DoctrineComponents\QueryObject\QueryObjectByMode;

trait IsActiveFilterTrait
{
	abstract public function by(array|string $column, mixed $value = null, QueryObjectByMode $mode = QueryObjectByMode::AUTO): static;
	abstract public function disableFilter(array|string $filter): static;

	public function byIsActive(bool $isActive = true): static
	{
		return $this->by("isActive", $isActive);
	}

	public function disableIsActiveFilter(): static
	{
		$this->disableFilter(IsActiveFilter::IS_ACTIVE_FILTER);
		return $this;
	}
}
