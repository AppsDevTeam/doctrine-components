<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(fields: ['createdAt'])]
trait Timestamped
{
	#[ORM\Column]
	protected DateTimeImmutable $createdAt;
}
