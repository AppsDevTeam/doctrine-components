<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Entity;

use ADT\DoctrineComponents\Entities\Entity;
use ADT\DoctrineComponents\Entities\Traits\Identifier;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'book')]
class Book implements Entity
{
	use Identifier;

	#[ORM\Column(type: Types::STRING, length: 255)]
	protected string $title;

	#[ORM\Column(type: Types::INTEGER, nullable: true)]
	protected ?int $price = null;

	#[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
	protected ?DateTimeImmutable $publishedAt = null;

	#[ORM\ManyToOne(targetEntity: Author::class, inversedBy: 'books')]
	#[ORM\JoinColumn(nullable: true)]
	protected ?Author $author = null;

	public function __construct(string $title)
	{
		$this->title = $title;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): static
	{
		$this->title = $title;
		return $this;
	}

	public function getPrice(): ?int
	{
		return $this->price;
	}

	public function setPrice(?int $price): static
	{
		$this->price = $price;
		return $this;
	}

	public function getPublishedAt(): ?DateTimeImmutable
	{
		return $this->publishedAt;
	}

	public function setPublishedAt(?DateTimeImmutable $publishedAt): static
	{
		$this->publishedAt = $publishedAt;
		return $this;
	}

	public function getAuthor(): ?Author
	{
		return $this->author;
	}

	public function setAuthor(?Author $author): static
	{
		$this->author = $author;
		return $this;
	}
}
