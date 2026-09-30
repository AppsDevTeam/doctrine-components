<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

use ADT\DoctrineComponents\Tests\Fixtures\Dto\AuthorNameDto;
use Doctrine\ORM\QueryBuilder;

class AuthorNameDtoQueryObject extends AuthorQueryObject
{
	private ?string $dtoClass = AuthorNameDto::class;

	public function setDTOClass(?string $dtoClass): static
	{
		$this->dtoClass = $dtoClass;
		return $this;
	}

	public function getDTOClass(): ?string
	{
		return $this->dtoClass;
	}

	protected function initSelect(QueryBuilder $qb): void
	{
		$qb->select('e.id AS id, e.name AS name');
	}
}
