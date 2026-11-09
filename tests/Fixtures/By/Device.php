<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\By;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Device
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\Column]
	public int $number = 0;
}
