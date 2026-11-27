<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Delete;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Comment
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	public function __construct(
		#[ORM\ManyToOne(targetEntity: Article::class)]
		#[ORM\JoinColumn(nullable: false)]
		public Article $article,
	) {
	}
}
