<?php

namespace ADT\DoctrineComponents\QueryObject;

use Generator;

interface QueryObjectInterface
{
	public function by(array|string $column, mixed $value, QueryObjectByMode $mode = QueryObjectByMode::AUTO): static;
	public function byId($id): static;

	public function orderBy(array|string $field, ?string $order = null): static;

	public function fetch(?int $limit = null): array;
	public function fetchIterable(): Generator;
	public function fetchOne(bool $strict = true): object;
	public function fetchOneOrNull(bool $strict = true): object|null;

	public function fetchPairs(?string $value, ?string $key): array;
	public function fetchField(string $field): array;
}
