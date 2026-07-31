<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Dto;

class AuthorNameDto
{
	private mixed $id = null;

	private ?string $name = null;

	public array $constructorArgument = [];

	public function __construct(array $row = [])
	{
		$this->constructorArgument = $row;
	}

	public function getId(): mixed
	{
		return $this->id;
	}

	public function getName(): ?string
	{
		return $this->name;
	}
}
