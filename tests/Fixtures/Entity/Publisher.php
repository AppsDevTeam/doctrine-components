<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Entity;

use ADT\DoctrineComponents\Entities\Entity;
use ADT\DoctrineComponents\Entities\Traits\Identifier;
use ADT\DoctrineComponents\Tests\Fixtures\PublisherMarker;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'publisher')]
class Publisher implements Entity, PublisherMarker
{
	use Identifier;

	#[ORM\Column(type: Types::STRING, length: 255)]
	protected string $name;

	#[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
	protected ?string $country = null;

	/** @var Collection<int, Author> */
	#[ORM\OneToMany(targetEntity: Author::class, mappedBy: 'publisher')]
	protected Collection $authors;

	public function __construct(string $name, ?string $country = null)
	{
		$this->name = $name;
		$this->country = $country;
		$this->authors = new ArrayCollection();
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getCountry(): ?string
	{
		return $this->country;
	}

	/** @return Collection<int, Author> */
	public function getAuthors(): Collection
	{
		return $this->authors;
	}
}
