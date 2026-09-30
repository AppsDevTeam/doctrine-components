<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

use ADT\DoctrineComponents\QueryObject\QueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use Closure;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends QueryObject<Author>
 */
class AuthorQueryObject extends QueryObject
{
	public const FILTER_ACTIVE = 'filter_active';
	public const FILTER_NAMED = 'filter_named';

	public function getEntityClass(): string
	{
		return Author::class;
	}

	protected function setDefaultOrder(): void
	{
		$this->orderBy('id', 'ASC');
	}

	public function addFilter(string $key, Closure $filter): static
	{
		$this->filter[$key] = $filter;
		return $this;
	}

	public function addAnonymousFilter(Closure $filter): static
	{
		$this->filter[] = $filter;
		return $this;
	}

	public function setOrder(?Closure $order): static
	{
		$this->order = $order;
		return $this;
	}

	public function addHint(string $name, mixed $value): static
	{
		$this->hints[$name] = $value;
		return $this;
	}

	public function callLeftJoin(QueryBuilder $qb, string $join, string $alias, ?string $conditionType = null, ?string $condition = null, ?string $indexBy = null): static
	{
		return $this->leftJoin($qb, $join, $alias, $conditionType, $condition, $indexBy);
	}

	public function callInnerJoin(QueryBuilder $qb, string $join, string $alias, ?string $conditionType = null, ?string $condition = null, ?string $indexBy = null): static
	{
		return $this->innerJoin($qb, $join, $alias, $conditionType, $condition, $indexBy);
	}

	public function callAddJoins(QueryBuilder $qb, array $columns): void
	{
		$this->addJoins($qb, $columns);
	}

	public function callAddColumnPrefix(?string $column = null): string
	{
		return $this->addColumnPrefix($column);
	}

	public function callGetJoinedEntityColumnName(string $column): string
	{
		return $this->getJoinedEntityColumnName($column);
	}

	public function callGetUniqueParamName(QueryBuilder $qb, string $paramName, bool $withSecondParam = false): string
	{
		return $this->getUniqueParamName($qb, $paramName, $withSecondParam);
	}

	public function callValidateFieldNames(array $fields): void
	{
		$this->validateFieldNames($fields);
	}

	public function getFilterKeys(): array
	{
		return array_keys($this->filter);
	}

	public function hasOrder(): bool
	{
		return $this->order !== null;
	}

	public function getPostFetchFields(): array
	{
		return $this->postFetch;
	}

	public function callGetCountExpr(): string
	{
		return $this->getCountExpr();
	}
}
