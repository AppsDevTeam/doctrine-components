<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Invalid;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(fields: ['missing'])]
trait BrokenTrait
{
}

#[ORM\Entity]
class Broken
{
	use BrokenTrait;

	#[ORM\Id]
	#[ORM\Column]
	protected ?int $id = null;
}
