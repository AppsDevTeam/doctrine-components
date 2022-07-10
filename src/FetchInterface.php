<?php

namespace ADT\DoctrineComponents;

/**
 * @template TEntity of object
 */
interface FetchInterface
{
	/** @return TEntity[] */
	public function fetch(?int $limit = null): array;

	/** @return TEntity[] */
	public function fetchIterable(): \Generator;

	/** @return TEntity */
	public function fetchOne(bool $strict = true): object;

	/** @return TEntity|null */
	public function fetchOneOrNull(bool $strict = true): object|null;
}