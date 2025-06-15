<?php

namespace ADT\DoctrineComponents\QueryObject\Filters;

interface IsActiveFilter
{
	const string IS_ACTIVE_FILTER = "isActiveFilter";

	public function byIsActive(bool $active = true): static;
	public function disableFilter(array|string $filter): static;
	public function disableIsActiveFilter(): static;
}
