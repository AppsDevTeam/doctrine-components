<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Delete;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Article
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	/** @var Collection<int, Tag> vlastní sloupce spojovací tabulky nemají ON DELETE CASCADE */
	#[ORM\JoinTable(name: 'article_tag')]
	#[ORM\JoinColumn(name: 'article_id', referencedColumnName: 'id')]
	#[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id')]
	#[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'articles')]
	public Collection $tags;

	public function __construct()
	{
		$this->tags = new ArrayCollection();
	}
}
