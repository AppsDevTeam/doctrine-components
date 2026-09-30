<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Entity;

use ADT\DoctrineComponents\Entities\Entity;
use ADT\DoctrineComponents\Entities\Traits\Identifier;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'author')]
class Author implements Entity
{
	use Identifier;

	#[ORM\Column(type: Types::STRING, length: 255)]
	protected string $name;

	#[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
	protected ?string $email = null;

	#[ORM\Column(type: Types::BOOLEAN)]
	protected bool $isActive = true;

	#[ORM\Column(type: Types::INTEGER, nullable: true)]
	protected ?int $rating = null;

	#[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
	protected ?DateTimeImmutable $birthDate = null;

	#[ORM\ManyToOne(targetEntity: Publisher::class, inversedBy: 'authors')]
	#[ORM\JoinColumn(nullable: true)]
	protected ?Publisher $publisher = null;

	/** @var Collection<int, Book> */
	#[ORM\OneToMany(targetEntity: Book::class, mappedBy: 'author')]
	protected Collection $books;

	/** @var Collection<int, Tag> */
	#[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'authors')]
	#[ORM\JoinTable(name: 'author_tag')]
	protected Collection $tags;

	public function __construct(string $name)
	{
		$this->name = $name;
		$this->books = new ArrayCollection();
		$this->tags = new ArrayCollection();
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function setName(string $name): static
	{
		$this->name = $name;
		return $this;
	}

	public function getEmail(): ?string
	{
		return $this->email;
	}

	public function setEmail(?string $email): static
	{
		$this->email = $email;
		return $this;
	}

	public function isActive(): bool
	{
		return $this->isActive;
	}

	public function getIsActive(): bool
	{
		return $this->isActive;
	}

	public function setIsActive(bool $isActive): static
	{
		$this->isActive = $isActive;
		return $this;
	}

	public function getRating(): ?int
	{
		return $this->rating;
	}

	public function setRating(?int $rating): static
	{
		$this->rating = $rating;
		return $this;
	}

	public function getBirthDate(): ?DateTimeImmutable
	{
		return $this->birthDate;
	}

	public function setBirthDate(?DateTimeImmutable $birthDate): static
	{
		$this->birthDate = $birthDate;
		return $this;
	}

	public function getPublisher(): ?Publisher
	{
		return $this->publisher;
	}

	public function setPublisher(?Publisher $publisher): static
	{
		$this->publisher = $publisher;
		return $this;
	}

	/** @return Collection<int, Book> */
	public function getBooks(): Collection
	{
		return $this->books;
	}

	public function addBook(Book $book): static
	{
		if (!$this->books->contains($book)) {
			$this->books->add($book);
		}
		$book->setAuthor($this);
		return $this;
	}

	/** @return Collection<int, Tag> */
	public function getTags(): Collection
	{
		return $this->tags;
	}

	public function addTag(Tag $tag): static
	{
		if (!$this->tags->contains($tag)) {
			$this->tags->add($tag);
		}
		return $this;
	}
}
