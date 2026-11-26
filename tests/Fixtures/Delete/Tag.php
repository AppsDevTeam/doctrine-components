<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Delete;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Tag
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	/** @var Collection<int, Article> */
	#[ORM\ManyToMany(targetEntity: Article::class, mappedBy: 'tags')]
	public Collection $articles;

	public function __construct()
	{
		$this->articles = new ArrayCollection();
	}
}
