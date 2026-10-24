<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Owner
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	protected ?int $id = null;
}
