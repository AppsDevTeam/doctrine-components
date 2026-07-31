<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Entity;

use ADT\DoctrineComponents\Entities\Entity;
use ADT\DoctrineComponents\Entities\Traits\Identifier;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tag')]
class Tag implements Entity
{
	use Identifier;

	#[ORM\Column(type: Types::STRING, length: 255)]
	protected string $name;

	/** @var Collection<int, Author> */
	#[ORM\ManyToMany(targetEntity: Author::class, mappedBy: 'tags')]
	protected Collection $authors;

	public function __construct(string $name)
	{
		$this->name = $name;
		$this->authors = new ArrayCollection();
	}

	public function getName(): string
	{
		return $this->name;
	}

	/** @return Collection<int, Author> */
	public function getAuthors(): Collection
	{
		return $this->authors;
	}
}
